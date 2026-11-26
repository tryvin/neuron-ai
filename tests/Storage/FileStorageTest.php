<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Storage;

use NeuronAI\Exceptions\StorageException;
use NeuronAI\Storage\FileStorage;
use NeuronAI\Tests\Traits\TempDirectory;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;
use function serialize;
use function stream_get_contents;
use function symlink;
use function unserialize;

class FileStorageTest extends TestCase
{
    use TempDirectory;

    protected string $root;

    protected FileStorage $storage;

    protected function setUp(): void
    {
        $this->root = $this->createTempDirectory();
        mkdir($this->root.'/skills/alpha', 0o700, true);
        file_put_contents($this->root.'/README.md', '# Hello');
        file_put_contents($this->root.'/skills/alpha/SKILL.md', 'instructions');

        $this->storage = new FileStorage($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirectories();
    }

    public function test_reads_files(): void
    {
        $this->assertSame('# Hello', $this->storage->read('README.md'));
        $this->assertSame('# Hello', $this->storage->read('./README.md'));
        $this->assertSame('instructions', $this->storage->read('skills/alpha/SKILL.md'));
    }

    public function test_returns_null_for_missing_files_and_directories(): void
    {
        $this->assertNull($this->storage->read('missing.md'));
        $this->assertNull($this->storage->read('skills'));
        $this->assertNull($this->storage->read(''));
    }

    public function test_refuses_paths_outside_the_root(): void
    {
        $this->assertNull($this->storage->read('../README.md'));
        $this->assertNull($this->storage->read('/etc/passwd'));
        $this->assertNull($this->storage->read('skills/../../README.md'));
        $this->assertSame([], $this->storage->children('../'));
    }

    public function test_opens_a_stream_on_a_file(): void
    {
        $stream = $this->storage->open('skills/alpha/SKILL.md');

        $this->assertIsResource($stream);
        $this->assertSame('instructions', stream_get_contents($stream));
    }

    public function test_streams_binary_files_untouched(): void
    {
        file_put_contents($this->root.'/image.bin', "\x89PNG\r\n\x1A\n\x00\xFF");

        $stream = $this->storage->open('image.bin');

        $this->assertIsResource($stream);
        $this->assertSame("\x89PNG\r\n\x1A\n\x00\xFF", stream_get_contents($stream));
    }

    public function test_returns_no_stream_for_unreadable_paths(): void
    {
        $this->assertNull($this->storage->open('missing.md'));
        $this->assertNull($this->storage->open('skills'));
        $this->assertNull($this->storage->open('../README.md'));
        $this->assertNull($this->storage->open('/etc/passwd'));
    }

    public function test_lists_the_children(): void
    {
        $this->assertSame(['README.md', 'skills'], $this->storage->children(''));
        $this->assertSame(['alpha'], $this->storage->children('skills'));
        $this->assertSame(['alpha'], $this->storage->children('skills/'));
    }

    public function test_returns_no_children_for_missing_paths_and_files(): void
    {
        $this->assertSame([], $this->storage->children('missing'));
        $this->assertSame([], $this->storage->children('README.md'));
    }

    public function test_reports_stats_of_files_and_directories(): void
    {
        $this->assertTrue($this->storage->exists('README.md'));
        $this->assertTrue($this->storage->exists('skills/alpha'));
        $this->assertFalse($this->storage->exists('missing.md'));
        $this->assertFalse($this->storage->exists('../README.md'));

        $this->assertTrue($this->storage->isDirectory('skills'));
        $this->assertFalse($this->storage->isDirectory('README.md'));
        $this->assertFalse($this->storage->isDirectory('missing'));

        $this->assertSame(7, $this->storage->size('README.md'));
        $this->assertNull($this->storage->size('skills'));
        $this->assertNull($this->storage->size('missing.md'));

        $this->assertIsInt($this->storage->modifiedAt('README.md'));
        $this->assertIsInt($this->storage->modifiedAt('skills'));
        $this->assertNull($this->storage->modifiedAt('missing.md'));
        $this->assertNull($this->storage->modifiedAt('../README.md'));
    }

    public function test_throws_when_the_root_is_not_a_directory(): void
    {
        $this->expectException(StorageException::class);

        new FileStorage($this->root.'/missing');
    }

    public function test_refuses_symlinks_resolving_outside_the_root(): void
    {
        $outside = $this->createTempDirectory();
        file_put_contents($outside.'/secret.txt', 'secret');

        if (@symlink($outside, $this->root.'/outside') === false) {
            $this->markTestSkipped('Symlinks are not available on this system.');
        }

        $this->assertNull($this->storage->read('outside/secret.txt'));
        $this->assertNull($this->storage->open('outside/secret.txt'));
        $this->assertSame([], $this->storage->children('outside'));
    }

    public function test_follows_symlinks_resolving_inside_the_root(): void
    {
        if (@symlink($this->root.'/skills', $this->root.'/link') === false) {
            $this->markTestSkipped('Symlinks are not available on this system.');
        }

        $this->assertSame('instructions', $this->storage->read('link/alpha/SKILL.md'));
        $this->assertSame(['alpha'], $this->storage->children('link'));
    }

    public function test_survives_serialization(): void
    {
        $restored = unserialize(serialize($this->storage));

        $this->assertInstanceOf(FileStorage::class, $restored);
        $this->assertSame('# Hello', $restored->read('README.md'));
    }
}
