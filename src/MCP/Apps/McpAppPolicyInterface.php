<?php

declare(strict_types=1);

namespace NeuronAI\MCP\Apps;

interface McpAppPolicyInterface
{
    /** @param array<string, mixed> $arguments */
    public function allowToolCall(string $server, string $tool, array $arguments): bool;

    public function allowResourceRead(string $server, string $uri): bool;

    public function allowOpenLink(string $url): bool;
}
