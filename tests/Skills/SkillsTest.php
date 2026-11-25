<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Skills;

use NeuronAI\Exceptions\SkillException;
use NeuronAI\Skills\SkillTool;
use NeuronAI\Skills\Skills;
use NeuronAI\Tests\Stubs\ArrayStorage;
use NeuronAI\Tests\Traits\TempDirectory;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function dirname;
use function file_put_contents;
use function implode;
use function str_repeat;
use function mkdir;

use const DIRECTORY_SEPARATOR;

class SkillsTest extends TestCase
{
    use TempDirectory;

    protected string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTempDirectory();
        $this->write('skills/alpha/SKILL.md', $this->skill('alpha', 'Summarizes things.'));
        $this->write('skills/beta/SKILL.md', "---\nname: beta\ndescription: >-\n  Searches\n  things.\n---\n\n# Beta\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempDirectories();
    }

    public function test_discovers_the_immediate_skill_folders_only(): void
    {
        $this->write('skills/notes.txt', 'not a skill');
        $this->write('skills/empty/README.md', 'no skill file');
        $this->write('skills/nested/deep/SKILL.md', $this->skill('deep'));
        $this->write('outside/SKILL.md', $this->skill('outside'));

        $skills = Skills::make($this->root, 'skills');

        $this->assertSame(['alpha', 'beta'], array_keys($skills->skills()));
        $this->assertSame('Searches things.', $skills->skills()['beta']->getDescription());
        $this->assertSame([], $skills->errors());
    }

    public function test_discovers_the_root_without_a_directory(): void
    {
        $this->write('gamma/SKILL.md', $this->skill('gamma'));

        $skills = Skills::make($this->root);

        // skills/ has no SKILL.md and is not scanned further
        $this->assertSame(['gamma'], array_keys($skills->skills()));
        $this->assertSame([], $skills->errors());
    }

    public function test_skips_the_skills_without_a_name_or_a_description(): void
    {
        $this->write('skills/no-name/SKILL.md', "---\ndescription: Missing name.\n---\n");
        $this->write('skills/numeric-name/SKILL.md', "---\nname: 123\ndescription: Numeric name.\n---\n");
        $this->write('skills/no-description/SKILL.md', "---\nname: no-description\ndescription:   \n---\n");
        $this->write('skills/no-frontmatter/SKILL.md', "# Not a skill\n");
        $this->write('skills/list/SKILL.md', "---\n- a\n- b\n---\n");

        $skills = Skills::make($this->root, 'skills');

        $this->assertSame(['alpha', 'beta'], array_keys($skills->skills()));
        $this->assertSame([
            "Skill 'list': SKILL.md has no frontmatter block, or it is not a mapping.",
            "Skill 'no-description': the description is missing.",
            "Skill 'no-frontmatter': SKILL.md has no frontmatter block, or it is not a mapping.",
            "Skill 'no-name': the name is missing.",
            "Skill 'numeric-name': the name is missing.",
        ], $skills->errors());
    }

    public function test_reports_invalid_yaml(): void
    {
        $this->write('skills/broken/SKILL.md', "---\nname: broken\nmetadata:\n\tauthor: someone\n---\n");

        $this->assertStringStartsWith(
            "Skill 'broken': Invalid frontmatter: A YAML file cannot contain tabs",
            implode("\n", Skills::make($this->root, 'skills')->errors())
        );
    }

    public function test_loads_the_skills_breaking_the_format_rules_and_reports_them(): void
    {
        // Real world plugins break the rules of the format: their skills are loaded anyway
        $this->write('skills/Bad_Name/SKILL.md', $this->skill('Bad_Name'));
        $this->write('skills/mismatch/SKILL.md', $this->skill('other'));
        $this->write('skills/long-description/SKILL.md', $this->skill('long-description', str_repeat('a', 1025)));
        $this->write('skills/skill a/SKILL.md', $this->skill('skill a'));
        $this->write('skills/zotero/SKILL.md', $this->skill('Zotero'));

        $skills = Skills::make($this->root, 'skills');
        $errors = $skills->errors();

        // Loaded under the name of their frontmatter
        $this->assertSame(['Bad_Name', 'alpha', 'beta', 'long-description', 'other', 'skill a', 'Zotero'], array_keys($skills->skills()));
        $this->assertSame("Skill 'Bad_Name': the name is not made of lowercase letters, digits and single hyphens (64 characters at most). Loaded anyway.", $errors[0]);
        $this->assertSame("Skill 'long-description': the description is longer than 1024 characters. Loaded anyway.", $errors[1]);
        $this->assertSame("Skill 'mismatch': the name 'other' differs from the folder name 'mismatch'. Loaded anyway.", $errors[2]);
        $this->assertSame("Skill 'skill a': the name is not made of lowercase letters, digits and single hyphens (64 characters at most). Loaded anyway.", $errors[3]);
        $this->assertSame("Skill 'zotero': the name 'Zotero' differs from the folder name 'zotero'; the name is not made of lowercase letters, digits and single hyphens (64 characters at most). Loaded anyway.", $errors[4]);
    }

    public function test_keeps_the_first_skill_of_a_directory_using_a_name(): void
    {
        $this->write('skills/a/SKILL.md', $this->skill('dup'));
        $this->write('skills/dup/SKILL.md', $this->skill('dup'));

        $skills = Skills::make($this->root, 'skills');

        $this->assertSame('skills/a', $skills->skills()['dup']->getPath());
        $this->assertSame([
            "Skill 'a': the name 'dup' differs from the folder name 'a'. Loaded anyway.",
            "Skill 'dup': the name 'dup' is already used by another skill of the directory.",
        ], $skills->errors());
    }

    public function test_provides_one_skill_tool(): void
    {
        $tools = Skills::make($this->root, 'skills')->tools();

        $this->assertSame([SkillTool::class], array_map(static fn (ToolInterface $tool): string => $tool::class, $tools));
        $this->assertSame('skill', $tools[0]->getName());
    }

    public function test_provides_nothing_without_valid_skills(): void
    {
        $skills = Skills::make($this->root, 'missing');

        $this->assertSame([], $skills->tools());
        $this->assertSame([], $skills->errors());
    }

    public function test_can_exclude_the_skill_tool(): void
    {
        $this->assertSame([], Skills::make($this->root, 'skills')->exclude([SkillTool::class])->tools());
    }

    public function test_reads_from_a_custom_storage(): void
    {
        $skills = new Skills(new ArrayStorage(['custom/SKILL.md' => $this->skill('custom')]));

        $this->assertSame(['custom'], array_keys($skills->skills()));
    }

    public function test_rejects_an_invalid_directory(): void
    {
        $this->expectException(SkillException::class);

        Skills::make($this->root, '../skills');
    }

    protected function write(string $path, string $content): void
    {
        $file = $this->root.DIRECTORY_SEPARATOR.$path;

        @mkdir(dirname($file), 0o700, true);
        file_put_contents($file, $content);
    }

    protected function skill(string $name, string $description = 'Does things.'): string
    {
        return "---\nname: {$name}\ndescription: {$description}\n---\n\n# Instructions\n";
    }
}
