<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Plugins;

use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeMcpTransport;

/**
 * A transport whose server is unreachable.
 */
class OfflineMcpTransport extends FakeMcpTransport
{
    public function connect(): void
    {
        throw new McpException('Connection refused');
    }
}
