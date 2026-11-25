<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Plugins;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Plugins\Plugin;
use NeuronAI\Testing\FakeMcpTransport;

use function str_contains;

/**
 * A plugin whose MCP servers are faked: the connector configuration mapped for
 * each server is recorded instead of being used to contact the server.
 */
class FakePlugin extends Plugin
{
    /**
     * The tools listed by every fake server.
     */
    protected const TOOLS = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'tools' => [
                ['name' => 'server_tool', 'description' => 'A tool of the server.', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            ],
        ],
    ];

    /**
     * The connector configuration mapped for each supported server, in order.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $configs = [];

    /**
     * The servers whose URL contains this value are unreachable.
     */
    public string $offline = '';

    /**
     * @param array<string, mixed> $config
     */
    protected function connector(array $config): McpConnector
    {
        $this->configs[] = $config;

        $offline = $this->offline !== '' && str_contains((string) ($config['url'] ?? ''), $this->offline);

        return new McpConnector([
            'transport' => $offline ? new OfflineMcpTransport() : new FakeMcpTransport(self::TOOLS),
        ]);
    }
}
