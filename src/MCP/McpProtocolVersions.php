<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

/**
 * MCP protocol revision constants and negotiation helpers.
 *
 * Revisions are identified by ISO date strings, so lexicographic
 * comparison matches chronological order.
 *
 * Modern revisions (2026-07-28 and later) are stateless: version and
 * capabilities travel per-request in _meta. Legacy revisions
 * (2025-11-25 and earlier) use the initialize handshake.
 */
final class McpProtocolVersions
{
    public const LEGACY_DEFAULT = '2024-11-05';

    public const MODERN_LATEST = '2026-07-28';

    /**
     * Protocol versions this client can speak, newest first.
     *
     * @var string[]
     */
    public const SUPPORTED = [
        self::MODERN_LATEST,
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        self::LEGACY_DEFAULT,
    ];

    /**
     * Modern revisions carry protocol metadata per-request (2026-07-28+).
     */
    public static function isModern(string $version): bool
    {
        return $version >= self::MODERN_LATEST;
    }

    /**
     * Pick the newest mutually supported version, preferring modern ones.
     *
     * @param string[] $serverVersions
     */
    public static function negotiate(array $serverVersions): ?string
    {
        return array_values(array_intersect(self::SUPPORTED, $serverVersions))[0] ?? null;
    }
}
