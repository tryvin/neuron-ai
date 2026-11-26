<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Tools\ToolOutput;

class CallableMcpTool
{
    public function __construct(
        protected McpConnector $connector,
        protected array $item
    ) {
    }

    public function __invoke(...$arguments): ToolOutput
    {
        return $this->connector->invokeToolResult(
            item: $this->item,
            arguments: $arguments
        );
    }
}
