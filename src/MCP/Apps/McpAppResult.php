<?php

declare(strict_types=1);

namespace NeuronAI\MCP\Apps;

/**
 * A prepared JSON-RPC response and, when the request asked for one, an action
 * the backend must execute before or after sending the response.
 */
final class McpAppResult
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        public readonly ?array $response = null,
        public readonly ?McpAppAction $action = null,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function error(array $message, int $code, string $text): self
    {
        return new self(response: [
            'jsonrpc' => '2.0',
            'id' => $message['id'] ?? null,
            'error' => ['code' => $code, 'message' => $text],
        ]);
    }
}
