<?php

declare(strict_types=1);

namespace NeuronAI\MCP\Apps;

use function in_array;
use function parse_url;
use function preg_match;
use function is_array;

final class DefaultMcpAppPolicy implements McpAppPolicyInterface
{
    public function allowToolCall(string $server, string $tool, array $arguments): bool
    {
        return true;
    }

    public function allowResourceRead(string $server, string $uri): bool
    {
        return true;
    }

    public function allowOpenLink(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return false;
        }

        return !isset($parts['user']) && !isset($parts['pass']);
    }
}
