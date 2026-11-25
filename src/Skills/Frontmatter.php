<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Exceptions\SkillException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function array_is_list;
use function is_array;
use function ltrim;
use function preg_match;

/**
 * Splits a SKILL.md document into its YAML frontmatter and its markdown body.
 */
final class Frontmatter
{
    /**
     * The frontmatter opens the document with a "---" line and is closed by another "---" line.
     */
    protected const PATTERN = '/\A(?:\xEF\xBB\xBF)?---[ \t]*\r?\n(?:(.*?)\r?\n)?---[ \t]*(?:\r?\n|\z)(.*)\z/s';

    /**
     * Parse the frontmatter of a markdown document.
     *
     * Returns null when the document has no frontmatter block, or when the frontmatter is not a mapping.
     *
     * @return array{meta: array<string, mixed>, body: string}|null
     *
     * @throws SkillException When the frontmatter is not valid YAML
     */
    public static function parse(string $markdown): ?array
    {
        if (preg_match(self::PATTERN, $markdown, $match) !== 1) {
            return null;
        }

        try {
            $meta = Yaml::parse($match[1]);
        } catch (ParseException $exception) {
            throw new SkillException("Invalid frontmatter: {$exception->getMessage()}", $exception->getCode(), previous: $exception);
        }

        $meta ??= [];

        // The frontmatter must be a mapping, not a scalar or a list
        if (!is_array($meta) || array_is_list($meta)) {
            return null;
        }

        return ['meta' => $meta, 'body' => ltrim($match[2])];
    }
}
