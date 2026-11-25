<?php

declare(strict_types=1);

namespace NeuronAI\Storage;

use function explode;
use function implode;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * Normalization of root-relative paths.
 */
final class Path
{
    /**
     * Normalize a root-relative path: segments are joined with "/", empty and "." segments are
     * dropped and "" addresses the root.
     *
     * Returns null for absolute paths and for paths containing "\", control characters or a ".." segment.
     */
    public static function normalize(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return null;
            }

            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
