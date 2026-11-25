<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

/**
 * The skills available to an agent, by name.
 *
 * The register is shared by every skill tool through the workflow state, so one tool can serve
 * the skills of all the registered sources.
 */
final class SkillRegister
{
    /**
     * The key holding the register in the workflow state.
     */
    public const STATE_KEY = '__skills';

    /**
     * @var array<string, Skill>
     */
    protected array $skills = [];

    /**
     * The paths of the skills ignored because their name was already taken, by name.
     *
     * @var array<string, string>
     */
    protected array $skipped = [];

    /**
     * Add skills. The first skill registered with a name wins: a different skill with the same name is ignored.
     *
     * @param array<string, Skill> $skills
     */
    public function add(array $skills): void
    {
        foreach ($skills as $skill) {
            $existing = $this->skills[$skill->getName()] ?? null;

            // The same skill registered again, e.g. when the tools are bootstrapped once more
            if ($existing === $skill) {
                continue;
            }

            if ($existing !== null) {
                $this->skipped[$skill->getName()] = $skill->getPath();
                continue;
            }

            $this->skills[$skill->getName()] = $skill;
        }
    }

    public function get(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /**
     * @return array<string, Skill>
     */
    public function all(): array
    {
        return $this->skills;
    }

    /**
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
