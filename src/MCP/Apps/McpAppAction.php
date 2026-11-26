<?php

declare(strict_types=1);

namespace NeuronAI\MCP\Apps;

/**
 * A side effect the app requested. The host executes it; Neuron only describes it.
 */
final class McpAppAction
{
    public const OPEN_LINK = 'open_link';
    public const MESSAGE = 'message';
    public const UPDATE_MODEL_CONTEXT = 'update_model_context';
    public const DISPLAY_MODE = 'display_mode';
    public const LOG = 'log';

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
    ) {
    }
}
