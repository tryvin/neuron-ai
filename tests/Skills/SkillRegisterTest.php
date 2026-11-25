<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Skills;

use NeuronAI\Skills\Skill;
use NeuronAI\Skills\SkillRegister;
use NeuronAI\Tests\Stubs\ArrayStorage;
use PHPUnit\Framework\TestCase;

use function array_keys;

class SkillRegisterTest extends TestCase
{
    protected function skill(string $name, string $path = ''): Skill
    {
        return new Skill($name, "The {$name} skill.", new ArrayStorage([]), $path === '' ? $name : $path);
    }

    public function test_the_first_skill_registered_with_a_name_wins(): void
    {
        $register = new SkillRegister();

        $register->add(['alpha' => $this->skill('alpha', 'first/alpha'), 'beta' => $this->skill('beta')]);
        $register->add(['alpha' => $this->skill('alpha', 'second/alpha')]);

        $this->assertSame('first/alpha', $register->get('alpha')?->getPath());
        $this->assertSame(['alpha', 'beta'], array_keys($register->all()));
        $this->assertSame(['alpha' => 'second/alpha'], $register->skipped());
    }

    public function test_registering_the_same_skill_again_is_not_a_conflict(): void
    {
        $register = new SkillRegister();
        $alpha = $this->skill('alpha');

        $register->add(['alpha' => $alpha]);
        $register->add(['alpha' => $alpha]);

        $this->assertSame(['alpha'], array_keys($register->all()));
        $this->assertSame([], $register->skipped());
    }

    public function test_returns_null_for_an_unknown_skill(): void
    {
        $this->assertNull((new SkillRegister())->get('missing'));
    }
}
