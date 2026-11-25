<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Traits;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

trait TempDirectory
{
    /**
     * @var string[]
     */
    protected array $tempDirectories = [];

    /**
     * Create an empty directory, removed by removeTempDirectories().
     */
    protected function createTempDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'neuron_test_'.uniqid();
        mkdir($directory, 0o700, true);

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    /**
     * Remove the directories created by createTempDirectory(), with their content.
     */
    protected function removeTempDirectories(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }

            rmdir($directory);
        }

        $this->tempDirectories = [];
    }
}
