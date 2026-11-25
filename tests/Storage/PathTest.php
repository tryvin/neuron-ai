<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Storage;

use NeuronAI\Storage\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    public function test_normalize(): void
    {
        $this->assertSame('', Path::normalize(''));
        $this->assertSame('', Path::normalize('  '));
        $this->assertSame('a/b/c', Path::normalize('a//b/./c'));
        $this->assertSame('a/b', Path::normalize(' a/b/ '));
        $this->assertSame('.hidden/file.json', Path::normalize('.hidden/./file.json'));
    }

    /**
     * @dataProvider invalid_paths_provider
     */
    public function test_normalize_rejects_unsafe_paths(string $path): void
    {
        $this->assertNull(Path::normalize($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid_paths_provider(): array
    {
        return [
            'absolute' => ['/etc/passwd'],
            'parent' => ['../secret'],
            'parent inside' => ['a/../b'],
            'backslash' => ['a\\b'],
            'null byte' => ["a\0b"],
            'newline' => ["a\nb"],
            'control character' => ["a\x1Fb"],
            'delete character' => ["a\x7Fb"],
        ];
    }
}
