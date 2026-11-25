<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs;

use NeuronAI\Storage\Path;
use NeuronAI\Storage\StorageInterface;

use function array_keys;
use function array_unique;
use function array_values;
use function explode;
use function fopen;
use function fwrite;
use function rewind;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * In-memory storage holding the contents of the files, by path.
 */
class ArrayStorage implements StorageInterface
{
    /**
     * @param array<string, string> $files
     */
    public function __construct(protected array $files)
    {
    }

    public function read(string $path): ?string
    {
        $path = Path::normalize($path);

        return $path === null ? null : ($this->files[$path] ?? null);
    }

    public function children(string $path): array
    {
        $path = Path::normalize($path);

        if ($path === null) {
            return [];
        }

        $prefix = $path === '' ? '' : $path.'/';
        $children = [];

        foreach (array_keys($this->files) as $file) {
            if (strlen($file) > strlen($prefix) && str_starts_with($file, $prefix)) {
                $children[] = explode('/', substr($file, strlen($prefix)))[0];
            }
        }

        return array_values(array_unique($children));
    }

    /**
     * A storage with no files on disk wraps the contents in a temporary stream.
     *
     * @return resource|null
     */
    public function open(string $path): mixed
    {
        $path = Path::normalize($path);
        $content = $path === null ? null : ($this->files[$path] ?? null);

        if ($content === null) {
            return null;
        }

        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
