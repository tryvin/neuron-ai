<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use function count;

/**
 * The skills available to an agent, by id.
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
     * The paths of the skills ignored because their id was already taken, by id.
     *
     * @var array<string, string>
     */
    protected array $skipped = [];

    /**
     * Add skills. The first skill registered with an id wins: a different skill with the same id is ignored.
     *
     * @param array<array-key, Skill> $skills
     */
    public function add(array $skills): void
    {
        foreach ($skills as $skill) {
            $id = $skill->getId();
            $existing = $this->skills[$id] ?? null;

            // The same skill registered again, e.g. when the tools are bootstrapped once more
            if ($existing === $skill) {
                continue;
            }

            if ($existing !== null) {
                $this->skipped[$id] = $skill->getPath();
                continue;
            }

            $this->skills[$id] = $skill;
        }
    }

    /**
     * A skill by exact id, or by bare name when exactly one skill has it.
     */
    public function get(string $id): ?Skill
    {
        if (isset($this->skills[$id])) {
            return $this->skills[$id];
        }

        $matches = [];

        foreach ($this->skills as $skill) {
            if ($skill->getName() === $id) {
                $matches[] = $skill;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
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
