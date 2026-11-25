<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\HasWorkflowState;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use NeuronAI\Workflow\WorkflowState;

use function array_keys;
use function implode;
use function mb_check_encoding;
use function str_contains;
use function strlen;

/**
 * Loads the instructions of a skill, or a file bundled with it.
 *
 * When the agent bootstraps its tools, every skill tool adds its skills to one
 * register shared through the workflow state: the model sees a single "skill"
 * tool listing the skills of every registered source.
 */
class SkillTool extends Tool implements HasRunKey, HasWorkflowState
{
    use TrackByInputs;

    /**
     * The maximum size of a bundled file, in bytes.
     */
    public const MAX_FILE_BYTES = 1048576;

    /**
     * The skills of the source that created the tool. Used when the tool was never bound to a workflow state.
     */
    protected SkillRegister $own;

    /**
     * The register shared with the other skill tools through the workflow state.
     */
    protected ?SkillRegister $bound = null;

    /**
     * @param array<string, Skill> $skills
     */
    public function __construct(array $skills)
    {
        parent::__construct(
            name: 'skill',
            properties: [
                new ToolProperty('name', PropertyType::STRING, 'Name of the skill.', true),
                new ToolProperty('file', PropertyType::STRING, 'Optional path of a file bundled with the skill, relative to the skill folder.', false),
            ],
        );

        $this->own = new SkillRegister();
        $this->own->add($skills);
    }

    public function setWorkflowState(WorkflowState $state): void
    {
        $register = $state->get(SkillRegister::STATE_KEY);

        if (!$register instanceof SkillRegister) {
            $register = new SkillRegister();
            $state->set(SkillRegister::STATE_KEY, $register);
        }

        $register->add($this->own->all());
        $this->bound = $register;
    }

    /**
     * The description is generated from the register, so it lists the skills
     * of every source bound to the same state.
     */
    public function getDescription(): ?string
    {
        $lines = [
            'Load the instructions of a skill, or read a file bundled with it.',
            "Call with the name of the skill to get its instructions. Call again with the name and a file to read a bundled file the instructions refer to.",
            'Available skills:',
        ];

        foreach ($this->register()->all() as $skill) {
            $lines[] = "- {$skill->getName()}: {$skill->getDescription()}";
        }

        return implode("\n", $lines);
    }

    public function __invoke(?string $name, ?string $file = null): string
    {
        $register = $this->register();
        $skill = $name === null || $name === '' ? null : $register->get($name);

        if (!$skill instanceof Skill) {
            return "Error: Unknown skill '{$name}'. Available skills: ".implode(', ', array_keys($register->all())).'.';
        }

        if ($file === null || $file === '') {
            return $skill->body() ?? "Error: Unable to read the instructions of skill '{$name}'.";
        }

        $content = $skill->read($file);

        if ($content === null) {
            return "Error: Unable to read the file '{$file}' of skill '{$name}'. The path must be relative to the skill folder and stay inside it.";
        }

        if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            return "Error: The file '{$file}' of skill '{$name}' is not a text file.";
        }

        if (strlen($content) > self::MAX_FILE_BYTES) {
            return "Error: The file '{$file}' of skill '{$name}' is larger than 1 MB.";
        }

        return $content;
    }

    protected function register(): SkillRegister
    {
        return $this->bound ?? $this->own;
    }
}
