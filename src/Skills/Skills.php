<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Exceptions\SkillException;
use NeuronAI\Storage\FileStorage;
use NeuronAI\Storage\Path;
use NeuronAI\Storage\StorageInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function is_string;
use function mb_strlen;
use function preg_match;
use function strlen;
use function trim;
use function implode;

/**
 * Discovers the skills of a directory: every immediate child folder holding a SKILL.md file.
 *
 * Discovery is not recursive. A skill needs a name and a description in its frontmatter:
 * the others are skipped and reported. Skills breaking the other rules of the format are
 * loaded and reported through errors().
 *
 * @method static static make(string|StorageInterface $source, string $directory = '')
 */
class Skills extends AbstractToolkit
{
    /**
     * A skill name: lowercase letters and digits separated by single hyphens.
     */
    protected const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    protected ?StorageInterface $storage = null;

    protected string $directory = '';

    /**
     * The namespace given to the discovered skills: the directory they were
     * found in, unless the caller supplied another one.
     */
    protected string $namespace = '';

    /**
     * @var array<string, Skill>
     */
    protected array $skills = [];

    /**
     * @var array<string>
     */
    protected array $errors = [];

    /**
     * @param array<string, Skill>|null $skills Already-built skills, keyed by id. When given, no discovery happens.
     */
    public function __construct(
        string|StorageInterface|null $source = null,
        string $directory = '',
        ?array $skills = null,
        string $namespace = '',
    ) {
        if ($skills !== null) {
            $this->skills = $skills;
            return;
        }

        if ($source === null) {
            throw new SkillException('A source is needed to discover skills.');
        }

        $this->storage = is_string($source) ? new FileStorage($source) : $source;
        $this->directory = Path::normalize($directory)
            ?? throw new SkillException("The skills directory '{$directory}' is not a valid relative path.");
        $this->namespace = $namespace !== '' ? $namespace : $this->directory;

        $this->discover();
    }

    /**
     * Build a source from already-built skills, keyed by id. They are served by
     * the same tool and register as the discovered ones.
     *
     * @param array<string, Skill> $skills
     */
    public static function from(array $skills): self
    {
        $indexed = [];

        foreach ($skills as $skill) {
            $indexed[$skill->getId()] = $skill;
        }

        return new self(skills: $indexed);
    }

    public function provide(): array
    {
        return $this->skills === [] ? [] : [new SkillTool($this->skills)];
    }

    /**
     * The skills found in the directory, by the name declared in their frontmatter.
     *
     * @return array<string, Skill>
     */
    public function skills(): array
    {
        return $this->skills;
    }

    /**
     * Why the other folders of the directory are not valid skills, and the rules of the format broken by the loaded skills.
     *
     * @return array<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    protected function discover(): void
    {
        if (!$this->storage instanceof StorageInterface) {
            return;
        }

        foreach ($this->storage->children($this->directory) as $name) {
            // A skill is a folder holding a SKILL.md file
            $path = Path::normalize($this->directory === '' ? $name : $this->directory.'/'.$name);
            if ($path === null) {
                continue;
            }
            if (($content = $this->storage->read($path.'/SKILL.md')) === null) {
                continue;
            }

            $this->load($name, $path, $content);
        }
    }

    protected function load(string $folder, string $path, string $content): void
    {
        $storage = $this->storage;

        if (!$storage instanceof StorageInterface) {
            return;
        }

        try {
            $parsed = Frontmatter::parse($content);
        } catch (SkillException $exception) {
            $this->errors[] = "Skill '{$folder}': {$exception->getMessage()}.";
            return;
        }

        if ($parsed === null) {
            $this->errors[] = "Skill '{$folder}': SKILL.md has no frontmatter block, or it is not a mapping.";
            return;
        }

        $name = $parsed['meta']['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            $this->errors[] = "Skill '{$folder}': the name is missing.";
            return;
        }

        $description = $parsed['meta']['description'] ?? null;

        if (!is_string($description) || trim($description) === '') {
            $this->errors[] = "Skill '{$folder}': the description is missing.";
            return;
        }

        $name = trim($name);

        if (isset($this->skills[$name])) {
            $this->errors[] = "Skill '{$folder}': the name '{$name}' is already used by another skill of the directory.";
            return;
        }

        // The rules of the format are not enforced: real world plugins break them,
        // and a skill with a name and a description is usable anyway.
        $notices = [];

        if ($name !== $folder) {
            $notices[] = "the name '{$name}' differs from the folder name '{$folder}'";
        }

        if (strlen($name) > 64 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $notices[] = 'the name is not made of lowercase letters, digits and single hyphens (64 characters at most)';
        }

        if (mb_strlen(trim($description)) > 1024) {
            $notices[] = 'the description is longer than 1024 characters';
        }

        if ($notices !== []) {
            $this->errors[] = "Skill '{$folder}': ".implode('; ', $notices).'. Loaded anyway.';
        }

        $this->skills[$name] = new Skill(
            $name,
            trim($description),
            $storage,
            $path,
            $this->namespace,
        );
    }
}
