<?php

declare(strict_types=1);

namespace NeuronAI\Storage;

/**
 * Optional read-only metadata a storage can expose on top of StorageInterface.
 *
 * Skills use it to list, size and date their files without loading them. A
 * storage that does not implement it keeps working: the skill falls back to
 * read(), children() and open().
 */
interface StorageStatInterface
{
    /**
     * Whether a file or directory exists at the path.
     */
    public function exists(string $path): bool;

    /**
     * Whether the path addresses a directory.
     */
    public function isDirectory(string $path): bool;

    /**
     * Size of a regular file in bytes, or null when missing or not a regular file.
     */
    public function size(string $path): ?int;

    /**
     * Last modification time of a file or directory, as a Unix timestamp.
     */
    public function modifiedAt(string $path): ?int;
}
