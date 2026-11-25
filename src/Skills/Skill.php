<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Exceptions\SkillException;
use NeuronAI\Storage\Path;
use NeuronAI\Storage\StorageInterface;

/**
 * A skill of the Agent Skills format: a folder holding a SKILL.md file and the files it refers to.
 */
final class Skill
{
    public function __construct(
        protected string $name,
        protected string $description,
        protected StorageInterface $storage,
        protected string $path,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * The folder of the skill, relative to the root of its storage.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Contents of a file bundled with the skill, or null when the path is invalid, the file is
     * missing, or the file resolves outside the root of the storage.
     */
    public function read(string $file): ?string
    {
        $file = Path::normalize($file);

        if ($file === null) {
            return null;
        }

        return $this->storage->read($this->path.'/'.$file);
    }

    /**
     * The instructions of the skill: the SKILL.md body, without the frontmatter.
     */
    public function body(): ?string
    {
        $content = $this->read('SKILL.md');

        if ($content === null) {
            return null;
        }

        try {
            return Frontmatter::parse($content)['body'] ?? null;
        } catch (SkillException) {
            return null;
        }
    }
}
