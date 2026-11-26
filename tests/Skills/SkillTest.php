<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Skills;

use NeuronAI\Skills\Skill;
use NeuronAI\Storage\FileStorage;
use NeuronAI\Tests\Stubs\ArrayStorage;
use NeuronAI\Tests\Traits\TempDirectory;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;
use function sort;

use const DIRECTORY_SEPARATOR;

class SkillTest extends TestCase
{
    use TempDirectory;

    /**
     * @param array<string, string> $files
     */
    protected function skill(array $files, string $id = '', string $namespace = ''): Skill
    {
        return new Skill('alpha', 'Does things.', new ArrayStorage($files), 'alpha', $namespace, $id === '' ? null : $id);
    }

    /**
     * @return array<string, string>
     */
    protected function files(): array
    {
        return [
            'alpha/SKILL.md' => "---\nname: alpha\ndescription: Does things.\nlicense: MIT\n---\n\n# Alpha\n",
            'alpha/reference/guide.md' => '# Guide',
            'alpha/empty.txt' => '',
        ];
    }

    public function test_exposes_a_namespace_and_an_id(): void
    {
        $default = $this->skill($this->files());

        $this->assertSame('alpha', $default->getId());
        $this->assertSame('', $default->getNamespace());

        $namespaced = $this->skill($this->files(), 'plug/alpha', 'plug');

        $this->assertSame('plug/alpha', $namespaced->getId());
        $this->assertSame('plug', $namespaced->getNamespace());
    }

    public function test_lists_and_inspects_the_files_of_the_skill_folder(): void
    {
        $skill = $this->skill($this->files());

        $this->assertTrue($skill->exists('SKILL.md'));
        $this->assertTrue($skill->exists('reference/guide.md'));
        $this->assertTrue($skill->exists('reference'));
        $this->assertFalse($skill->exists('missing.md'));
        $this->assertFalse($skill->exists('../alpha/SKILL.md'));
        $this->assertFalse($skill->exists('/etc/passwd'));

        $this->assertTrue($skill->isDirectory('reference'));
        $this->assertFalse($skill->isDirectory('SKILL.md'));
        $this->assertFalse($skill->isDirectory('missing'));

        $children = $skill->children();
        sort($children);
        $this->assertSame(['SKILL.md', 'empty.txt', 'reference'], $children);
        $this->assertSame(['guide.md'], $skill->children('reference'));
        $this->assertSame([], $skill->children('missing'));
        $this->assertSame([], $skill->children('../'));

        $this->assertSame(0, $skill->size('empty.txt'));
        $this->assertSame(7, $skill->size('reference/guide.md'));
        $this->assertNull($skill->size('missing.md'));
        $this->assertNull($skill->size('../alpha/SKILL.md'));

        $this->assertNull($skill->modifiedAt());
    }

    public function test_reads_the_metadata_and_the_extra_frontmatter_fields(): void
    {
        $metadata = $this->skill($this->files(), 'plug/alpha', 'plug')->metadata();

        $this->assertSame('plug/alpha', $metadata['id']);
        $this->assertSame('plug', $metadata['namespace']);
        $this->assertSame('alpha', $metadata['name']);
        $this->assertSame('Does things.', $metadata['description']);
        $this->assertSame(ArrayStorage::class, $metadata['source']);
        $this->assertSame('alpha', $metadata['path']);
        $this->assertSame(['license' => 'MIT'], $metadata['frontmatter']);

        $this->assertArrayNotHasKey('frontmatter', $this->skill(['alpha/SKILL.md' => "# No frontmatter\n"])->metadata());
    }

    public function test_reads_the_stats_of_a_skill_on_the_filesystem(): void
    {
        $root = $this->createTempDirectory();
        mkdir($root.DIRECTORY_SEPARATOR.'alpha', 0o700, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'alpha/SKILL.md', "# Alpha\n");

        $skill = new Skill('alpha', 'Does things.', new FileStorage($root), 'alpha');

        $this->assertTrue($skill->exists('SKILL.md'));
        $this->assertTrue($skill->isDirectory(''));
        $this->assertSame(8, $skill->size('SKILL.md'));
        $this->assertIsInt($skill->modifiedAt());

        $this->removeTempDirectories();
    }
}
