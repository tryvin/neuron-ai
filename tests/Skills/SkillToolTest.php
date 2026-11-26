<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Skills;

use NeuronAI\Agent\AgentState;
use NeuronAI\Skills\Skill;
use NeuronAI\Skills\SkillRegister;
use NeuronAI\Skills\SkillTool;
use NeuronAI\Skills\Skills;
use NeuronAI\Tests\Stubs\ArrayStorage;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function serialize;
use function str_repeat;
use function unserialize;

class SkillToolTest extends TestCase
{
    protected const ALPHA = "---\nname: alpha\ndescription: Writes things.\n---\n\n# Alpha\n\nWrite the thing.\n";

    protected const BETA = "---\nname: beta\ndescription: Reads things.\n---\n\n# Beta\n";

    /**
     * A skill tool created from the skills of an in-memory storage.
     *
     * @param array<string, string> $files
     */
    protected function tool(array $files): SkillTool
    {
        return new SkillTool((new Skills(new ArrayStorage($files)))->skills());
    }

    protected function alphaTool(): SkillTool
    {
        return $this->tool([
            'alpha/SKILL.md' => self::ALPHA,
            'alpha/reference/guide.md' => '# Guide',
            'alpha/image.bin' => "\x89PNG\r\n\x1A\n",
            'alpha/null.txt' => "a\0b",
            'alpha/big.txt' => str_repeat('a', SkillTool::MAX_FILE_BYTES + 1),
            'alpha/exact.txt' => str_repeat('a', SkillTool::MAX_FILE_BYTES),
        ]);
    }

    public function test_is_named_skill_and_takes_a_name_and_a_file(): void
    {
        $tool = $this->alphaTool();

        $this->assertSame('skill', $tool->getName());
        $this->assertSame(['name', 'file'], array_map(static fn (ToolPropertyInterface $property): string => $property->getName(), $tool->getProperties()));
        $this->assertSame(['name'], $tool->getRequiredProperties());
    }

    public function test_describes_the_available_skills(): void
    {
        $description = $this->tool(['alpha/SKILL.md' => self::ALPHA, 'beta/SKILL.md' => self::BETA])->getDescription();

        $this->assertNotNull($description);
        $this->assertStringContainsString('Available skills:', $description);
        $this->assertStringContainsString('- alpha: Writes things.', $description);
        $this->assertStringContainsString('- beta: Reads things.', $description);
    }

    public function test_lists_namespaced_ids_and_accepts_a_unique_bare_name(): void
    {
        $storage = new ArrayStorage(['alpha/SKILL.md' => self::ALPHA]);
        $tool = new SkillTool(Skills::from([
            'plug/alpha' => new Skill('alpha', 'Writes things.', $storage, 'alpha', 'plug', 'plug/alpha'),
        ])->skills());

        $this->assertStringContainsString('- plug/alpha: Writes things.', (string) $tool->getDescription());
        $this->assertSame("# Alpha\n\nWrite the thing.\n", ($tool)('plug/alpha'));
        $this->assertSame("# Alpha\n\nWrite the thing.\n", ($tool)('alpha'));
    }

    public function test_refuses_an_ambiguous_bare_name(): void
    {
        $one = new ArrayStorage(['alpha/SKILL.md' => self::ALPHA]);
        $two = new ArrayStorage(['beta/SKILL.md' => self::BETA]);
        $tool = new SkillTool(Skills::from([
            'one/dup' => new Skill('dup', 'One.', $one, 'alpha', 'one', 'one/dup'),
            'two/dup' => new Skill('dup', 'Two.', $two, 'beta', 'two', 'two/dup'),
        ])->skills());

        $this->assertSame("Error: Unknown skill 'dup'. Available skills: one/dup, two/dup.", ($tool)('dup'));
        $this->assertSame("# Alpha\n\nWrite the thing.\n", ($tool)('one/dup'));
        $this->assertSame("# Beta\n", ($tool)('two/dup'));
    }

    public function test_loads_the_instructions_without_the_frontmatter(): void
    {
        $tool = $this->alphaTool();

        $this->assertSame("# Alpha\n\nWrite the thing.\n", ($tool)('alpha'));
    }

    public function test_reads_the_bundled_files(): void
    {
        $tool = $this->alphaTool();

        $this->assertSame('# Guide', ($tool)('alpha', 'reference/guide.md'));
        $this->assertSame('# Guide', ($tool)('alpha', './reference/guide.md'));
        $this->assertSame(str_repeat('a', SkillTool::MAX_FILE_BYTES), ($tool)('alpha', 'exact.txt'));
    }

    public function test_executes_through_the_tool_interface(): void
    {
        $tool = $this->alphaTool();
        $tool->setInputs(['name' => 'alpha', 'file' => 'reference/guide.md']);
        $tool->execute();

        $this->assertSame('# Guide', $tool->getResult());
    }

    public function test_reports_unknown_skills_and_missing_files(): void
    {
        $tool = $this->alphaTool();

        $this->assertSame("Error: Unknown skill 'nope'. Available skills: alpha.", ($tool)('nope'));
        $this->assertSame("Error: Unknown skill ''. Available skills: alpha.", ($tool)(''));
        $this->assertSame("Error: Unknown skill ''. Available skills: alpha.", ($tool)(null));
        $this->assertSame("Error: Unable to read the file 'missing.md' of skill 'alpha'. The path must be relative to the skill folder and stay inside it.", ($tool)('alpha', 'missing.md'));
    }

    public function test_refuses_paths_outside_the_skill_folder(): void
    {
        $tool = $this->alphaTool();

        $this->assertStringStartsWith("Error: Unable to read the file '../beta/SKILL.md' of skill 'alpha'.", ($tool)('alpha', '../beta/SKILL.md'));
        $this->assertStringStartsWith("Error: Unable to read the file '/etc/passwd' of skill 'alpha'.", ($tool)('alpha', '/etc/passwd'));
    }

    public function test_refuses_binary_files(): void
    {
        $tool = $this->alphaTool();

        $this->assertSame("Error: The file 'image.bin' of skill 'alpha' is not a text file.", ($tool)('alpha', 'image.bin'));
        $this->assertSame("Error: The file 'null.txt' of skill 'alpha' is not a text file.", ($tool)('alpha', 'null.txt'));
    }

    public function test_refuses_files_larger_than_one_megabyte(): void
    {
        $this->assertSame("Error: The file 'big.txt' of skill 'alpha' is larger than 1 MB.", ($this->alphaTool())('alpha', 'big.txt'));
    }

    public function test_serves_its_own_skills_until_it_is_bound_to_a_state(): void
    {
        $first = $this->tool(['alpha/SKILL.md' => self::ALPHA]);
        $second = $this->tool(['beta/SKILL.md' => self::BETA]);

        // Unbound, every tool serves the skills of its own source
        $this->assertSame("Error: Unknown skill 'beta'. Available skills: alpha.", ($first)('beta'));
        $this->assertStringNotContainsString('- alpha:', (string) $second->getDescription());

        $state = new AgentState();
        $first->setWorkflowState($state);
        $second->setWorkflowState($state);

        $register = $state->get(SkillRegister::STATE_KEY);

        $this->assertInstanceOf(SkillRegister::class, $register);
        $this->assertSame(['alpha', 'beta'], array_keys($register->all()));

        // Bound, every tool serves and describes all the skills of the state
        $this->assertSame("# Beta\n", ($first)('beta'));
        $this->assertStringContainsString('- alpha:', (string) $second->getDescription());
    }

    public function test_tracks_the_runs_by_inputs(): void
    {
        $tool = $this->alphaTool();

        $tool->setInputs(['name' => 'alpha']);
        $key = $tool->getRunKey();

        $tool->setInputs(['name' => 'alpha', 'file' => 'reference/guide.md']);
        $this->assertNotSame($key, $tool->getRunKey());

        $tool->setInputs(['name' => 'alpha']);
        $this->assertSame($key, $tool->getRunKey());
    }

    public function test_survives_serialization(): void
    {
        $tool = unserialize(serialize($this->alphaTool()));

        $this->assertInstanceOf(SkillTool::class, $tool);
        $this->assertSame("# Alpha\n\nWrite the thing.\n", ($tool)('alpha'));
    }
}
