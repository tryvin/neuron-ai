<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Exceptions\SkillException;
use NeuronAI\Storage\Path;
use NeuronAI\Storage\StorageInterface;
use NeuronAI\Storage\StorageStatInterface;

use function fclose;
use function fstat;
use function is_array;
use function is_int;
use function is_resource;

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
        protected string $namespace = '',
        protected ?string $id = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The identity of the skill in a registry. Skills discovered from a folder
     * use their frontmatter name; a caller can supply a namespaced id.
     */
    public function getId(): string
    {
        return $this->id ?? $this->name;
    }

    /**
     * The namespace the skill belongs to, empty when it has none.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * A copy of the skill under another identity, for registries that namespace
     * the skills they load.
     */
    public function withIdentity(string $namespace, string $id): self
    {
        return new self(
            $this->name,
            $this->description,
            $this->storage,
            $this->path,
            $namespace,
            $id,
        );
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
        $file = $this->resolve($file);

        return $file === null ? null : $this->storage->read($file);
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

    /**
     * Whether a file or directory exists inside the skill folder.
     */
    public function exists(string $file): bool
    {
        $path = $this->resolve($file);

        if ($path === null) {
            return false;
        }

        if ($this->storage instanceof StorageStatInterface) {
            return $this->storage->exists($path);
        }
        if ($this->storage->read($path) !== null) {
            return true;
        }
        return $this->storage->children($path) !== [];
    }

    /**
     * Whether a path inside the skill folder addresses a directory.
     */
    public function isDirectory(string $file): bool
    {
        $path = $this->resolve($file);

        if ($path === null) {
            return false;
        }

        if ($this->storage instanceof StorageStatInterface) {
            return $this->storage->isDirectory($path);
        }

        return $this->storage->read($path) === null && $this->storage->children($path) !== [];
    }

    /**
     * The names of the immediate children of a directory in the skill folder.
     *
     * @return array<int, string>
     */
    public function children(string $dir = ''): array
    {
        $path = $this->resolve($dir);

        return $path === null ? [] : $this->storage->children($path);
    }

    /**
     * Size of a file bundled with the skill in bytes, or null when it is missing.
     */
    public function size(string $file): ?int
    {
        $path = $this->resolve($file);

        if ($path === null) {
            return null;
        }

        if ($this->storage instanceof StorageStatInterface) {
            return $this->storage->size($path);
        }

        $stream = $this->storage->open($path);

        if (!is_resource($stream)) {
            return null;
        }

        $stat = fstat($stream);
        fclose($stream);

        return is_array($stat) && is_int($stat['size'] ?? null) ? $stat['size'] : null;
    }

    /**
     * Last modification time of the skill folder, as a Unix timestamp, or null
     * when the storage cannot report it.
     */
    public function modifiedAt(): ?int
    {
        if (!$this->storage instanceof StorageStatInterface) {
            return null;
        }

        return $this->storage->modifiedAt($this->path);
    }

    /**
     * What the application knows about the skill: its identity, where it comes
     * from, and any extra frontmatter fields.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $metadata = [
            'namespace' => $this->namespace,
            'id' => $this->getId(),
            'name' => $this->name,
            'description' => $this->description,
            'source' => $this->storage::class,
            'path' => $this->path,
        ];

        $extras = $this->frontmatterExtras();

        if ($extras !== []) {
            $metadata['frontmatter'] = $extras;
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    protected function frontmatterExtras(): array
    {
        $content = $this->read('SKILL.md');

        if ($content === null) {
            return [];
        }

        try {
            $parsed = Frontmatter::parse($content);
        } catch (SkillException) {
            return [];
        }

        $meta = $parsed['meta'] ?? [];
        unset($meta['name'], $meta['description']);

        return $meta;
    }

    /**
     * The path of a bundled file, relative to the root of the storage, or null
     * when it is unsafe or escapes the skill folder.
     */
    protected function resolve(string $file): ?string
    {
        $file = Path::normalize($file);

        if ($file === null) {
            return null;
        }

        if ($this->path === '') {
            return $file;
        }

        return $file === '' ? $this->path : $this->path.'/'.$file;
    }
}
