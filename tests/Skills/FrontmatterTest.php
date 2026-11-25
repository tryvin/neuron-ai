<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Skills;

use NeuronAI\Exceptions\SkillException;
use NeuronAI\Skills\Frontmatter;
use PHPUnit\Framework\TestCase;

class FrontmatterTest extends TestCase
{
    public function test_parses_the_frontmatter_and_strips_it_from_the_body(): void
    {
        $parsed = Frontmatter::parse(
            "---\nname: alpha\ndescription: 'Use when: needed'\nmetadata:\n  author: someone\nsteps: >-\n  one\n  two\n---\n\n# Title\n\nBody\n"
        );

        $this->assertSame(
            [
                'name' => 'alpha',
                'description' => 'Use when: needed',
                'metadata' => ['author' => 'someone'],
                'steps' => 'one two',
            ],
            $parsed['meta']
        );
        $this->assertSame("# Title\n\nBody\n", $parsed['body']);
    }

    public function test_accepts_windows_line_endings_and_a_byte_order_mark(): void
    {
        $parsed = Frontmatter::parse("\xEF\xBB\xBF---\r\nname: alpha\r\n---\r\n# Title\r\n");

        $this->assertSame(['name' => 'alpha'], $parsed['meta']);
        $this->assertSame("# Title\r\n", $parsed['body']);
    }

    /**
     * @dataProvider documents_without_a_frontmatter_mapping
     */
    public function test_returns_null_without_a_frontmatter_mapping(string $document): void
    {
        $this->assertNull(Frontmatter::parse($document));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documents_without_a_frontmatter_mapping(): array
    {
        return [
            'empty' => [''],
            'no frontmatter' => ["# Title\n"],
            'unterminated' => ["---\nname: alpha\n"],
            'four dashes' => ["----\nname: alpha\n----\n"],
            'text after the delimiter' => ["---\nname: alpha\n--- and more\n"],
            'empty block' => ["---\n---\n# Title\n"],
            'scalar' => ["---\njust text\n---\n"],
            'list' => ["---\n- a\n- b\n---\n"],
        ];
    }

    public function test_throws_when_the_frontmatter_is_not_valid_yaml(): void
    {
        $this->expectException(SkillException::class);
        $this->expectExceptionMessage('Duplicate key "name"');

        Frontmatter::parse("---\nname: a\nname: b\n---\n");
    }
}
