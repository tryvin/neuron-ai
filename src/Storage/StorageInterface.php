<?php

declare(strict_types=1);

namespace NeuronAI\Storage;

/**
 * Read-only access to the files of a directory tree, such as a plugin or a skills folder.
 *
 * Paths are relative to the root and use "/" as separator; an empty string addresses the root itself.
 *
 * Custom implementations are serialized with the tools (persisted interrupts) and deep-cloned with
 * the workflow state (parallel branches): hold identifiers only and connect lazily, like McpConnector does.
 */
interface StorageInterface
{
    /**
     * Contents of the regular file at the root-relative path, or null when the file is missing,
     * is not a regular file, or resolves outside the root.
     */
    public function read(string $path): ?string;

    /**
     * A stream open for reading on the file at the root-relative path, or null when the file
     * is missing, is not a regular file, or resolves outside the root.
     *
     * For the files not loaded into memory, such as the assets of a plugin. PHP has no native
     * resource type, hence the mixed declaration: the stream is a resource.
     *
     * @return resource|null
     */
    public function open(string $path): mixed;

    /**
     * Names of the immediate children (files and directories) of a directory.
     * Empty when the directory is missing or is not a directory.
     *
     * @return string[]
     */
    public function children(string $path): array;
}
