<?php

declare(strict_types=1);

namespace NeuronAI\Storage;

use NeuronAI\Exceptions\StorageException;

use function array_diff;
use function array_values;
use function file_get_contents;
use function fopen;
use function is_dir;
use function is_file;
use function is_readable;
use function realpath;
use function scandir;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * Read-only access to a directory of the local filesystem.
 *
 * Paths resolving outside the root, also through symlinks, are refused.
 */
class FileStorage implements StorageInterface
{
    public function __construct(protected string $root)
    {
        $resolved = realpath($root);

        if ($resolved === false || !is_dir($resolved)) {
            throw new StorageException("The directory '{$root}' does not exist or is not readable.");
        }

        $this->root = $resolved;
    }

    public function read(string $path): ?string
    {
        $resolved = $this->resolve($path);

        if ($resolved === null || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        $content = file_get_contents($resolved);

        return $content === false ? null : $content;
    }

    /**
     * @return resource|null
     */
    public function open(string $path): mixed
    {
        $resolved = $this->resolve($path);

        if ($resolved === null || !is_file($resolved) || !is_readable($resolved)) {
            return null;
        }

        $stream = fopen($resolved, 'rb');

        return $stream === false ? null : $stream;
    }

    /**
     * @return string[]
     */
    public function children(string $path): array
    {
        $resolved = $this->resolve($path);

        if ($resolved === null || !is_dir($resolved)) {
            return [];
        }

        $entries = scandir($resolved);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    /**
     * The real path of a file or directory inside the root, or null when the path is invalid,
     * is missing, or resolves outside the root.
     */
    protected function resolve(string $path): ?string
    {
        $path = Path::normalize($path);

        if ($path === null) {
            return null;
        }

        $resolved = realpath($this->root.($path === '' ? '' : DIRECTORY_SEPARATOR.$path));
        $prefix = $this->root === DIRECTORY_SEPARATOR ? $this->root : $this->root.DIRECTORY_SEPARATOR;

        return $resolved !== false && ($resolved === $this->root || str_starts_with($resolved, $prefix)) ? $resolved : null;
    }
}
