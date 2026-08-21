<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

/**
 * Transports that mirror the negotiated protocol revision into
 * transport-level metadata (e.g. the MCP-Protocol-Version HTTP header)
 * implement this contract so the client can keep them in sync.
 */
interface McpProtocolVersionAwareInterface
{
    public function setProtocolVersion(?string $version): void;
}
