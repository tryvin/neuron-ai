<?php

declare(strict_types=1);

namespace Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpInputRequiredException;
use NeuronAI\MCP\McpProtocolVersions;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_column;
use function array_filter;
use function array_values;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

class McpProtocolNegotiationTest extends TestCase
{
    /**
     * The first request sent with the given method.
     *
     * @return array<string, mixed>
     */
    protected function sentRequest(FakeMcpTransport $transport, string $method): array
    {
        return array_values(array_filter(
            $transport->getSent(),
            fn (array $data): bool => ($data['method'] ?? null) === $method
        ))[0];
    }

    public function test_client_advertises_no_extensions_by_default(): void
    {
        // Extensions are opt-in: nothing is announced unless configured.
        $modern = (new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        ))->asModern();
        $legacy = new FakeMcpTransport();

        (new McpClient(['transport' => $modern]))->listTools();
        new McpClient(['transport' => $legacy]);

        $this->assertEquals(
            new stdClass(),
            $this->sentRequest($modern, 'tools/list')['params']['_meta']['io.modelcontextprotocol/clientCapabilities']
        );
        $this->assertArrayNotHasKey(
            'extensions',
            (array) $this->sentRequest($legacy, 'initialize')['params']['capabilities']
        );
    }

    public function test_configured_extensions_travel_in_every_modern_request(): void
    {
        $transport = (new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        ))->asModern();

        $client = new McpClient([
            'transport' => $transport,
            'extensions' => [
                'io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']],
                'com.example/flag' => [],
            ],
        ]);
        $client->listTools();

        // The discover probe announces them too: it is how a modern server
        // learns what the client supports. Empty settings stay an object.
        $expected = '{"extensions":{"io.modelcontextprotocol/ui":{"mimeTypes":["text/html;profile=mcp-app"]},"com.example/flag":{}}}';

        foreach (['server/discover', 'tools/list'] as $method) {
            $capabilities = $this->sentRequest($transport, $method)['params']['_meta']['io.modelcontextprotocol/clientCapabilities'];

            $this->assertSame($expected, json_encode($capabilities, JSON_UNESCAPED_SLASHES), $method);
        }
    }

    public function test_configured_extensions_travel_in_the_legacy_initialize_handshake(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]);

        $client = new McpClient([
            'transport' => $transport,
            'extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]],
        ]);
        $client->listTools();

        $capabilities = $this->sentRequest($transport, 'initialize')['params']['capabilities'];

        $this->assertSame(
            '{"io.modelcontextprotocol/ui":{"mimeTypes":["text/html;profile=mcp-app"]}}',
            json_encode($capabilities->extensions, JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_sse_transport_negotiates_extensions_through_initialize_only(): void
    {
        // The SSE transport skips the server/discover probe: it only ever
        // negotiates through the legacy initialize handshake.
        $transport = new class () extends SseHttpTransport {
            /** @var array<string, mixed>[] */
            public array $sent = [];

            public function __construct()
            {
                parent::__construct([]);
            }

            public function connect(): void
            {
            }

            public function send(array $data): void
            {
                $this->sent[] = $data;
            }

            /**
             * @return array<string, mixed>
             */
            public function receive(): array
            {
                return [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'protocolVersion' => '2025-06-18',
                        'capabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => new stdClass()]],
                        'serverInfo' => ['name' => 'sse', 'version' => '1.0.0'],
                    ],
                ];
            }
        };

        $client = new McpClient([
            'transport' => $transport,
            'extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]],
        ]);

        $this->assertSame(['initialize', 'notifications/initialized'], array_column($transport->sent, 'method'));
        $this->assertSame(
            '{"io.modelcontextprotocol/ui":{"mimeTypes":["text/html;profile=mcp-app"]}}',
            json_encode($transport->sent[0]['params']['capabilities']->extensions, JSON_UNESCAPED_SLASHES)
        );
        $this->assertTrue($client->hasExtension('io.modelcontextprotocol/ui'));
    }

    public function test_server_extensions_advertised_on_discovery_are_readable(): void
    {
        $capabilities = [
            'tools' => new stdClass(),
            'extensions' => [
                'io.modelcontextprotocol/ui' => new stdClass(),
                'com.example/unknown' => ['anything' => ['nested' => true]],
            ],
        ];

        $transport = (new FakeMcpTransport())->asModern()->withServerCapabilities($capabilities);

        $client = new McpClient(['transport' => $transport]);

        $this->assertTrue($client->hasExtension('io.modelcontextprotocol/ui'));
        $this->assertSame([], $client->extensionSettings('io.modelcontextprotocol/ui'));

        // Extensions Neuron does not interpret are preserved as advertised.
        $this->assertSame(['anything' => ['nested' => true]], $client->extensionSettings('com.example/unknown'));
        $this->assertSame($capabilities, $client->getCapabilities());

        $this->assertFalse($client->hasExtension('io.modelcontextprotocol/tasks'));
        $this->assertNull($client->extensionSettings('io.modelcontextprotocol/tasks'));
    }

    public function test_server_extensions_advertised_on_initialize_are_readable(): void
    {
        $transport = (new FakeMcpTransport())->withServerCapabilities([
            'extensions' => ['io.modelcontextprotocol/ui' => new stdClass()],
        ]);

        $client = new McpClient(['transport' => $transport]);

        $this->assertFalse($client->isModern());
        $this->assertTrue($client->hasExtension('io.modelcontextprotocol/ui'));
        $this->assertSame([], $client->extensionSettings('io.modelcontextprotocol/ui'));
    }

    public function test_malformed_server_capabilities_are_discarded(): void
    {
        $client = new McpClient([
            'transport' => (new FakeMcpTransport())->answerDiscoverWith([
                'jsonrpc' => '2.0',
                'result' => [
                    'resultType' => 'complete',
                    'supportedVersions' => ['2026-07-28'],
                    'capabilities' => 'not-an-object',
                ],
            ]),
        ]);

        $this->assertTrue($client->isModern());
        $this->assertNull($client->getCapabilities());
        $this->assertFalse($client->hasExtension('io.modelcontextprotocol/ui'));
    }

    public function test_advertised_extension_with_malformed_settings_still_counts_as_supported(): void
    {
        $client = new McpClient([
            'transport' => (new FakeMcpTransport())->withServerCapabilities([
                'extensions' => ['io.modelcontextprotocol/ui' => true],
            ]),
        ]);

        $this->assertTrue($client->hasExtension('io.modelcontextprotocol/ui'));
        $this->assertSame([], $client->extensionSettings('io.modelcontextprotocol/ui'));
    }

    public static function invalidExtensions(): array
    {
        return [
            'not a map' => ['io.modelcontextprotocol/ui'],
            'settings not an object' => [['io.modelcontextprotocol/ui' => 'text/html;profile=mcp-app']],
        ];
    }

    /**
     * @dataProvider invalidExtensions
     */
    public function test_invalid_extensions_configuration_is_rejected(mixed $extensions): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('extensions');

        new McpClient(['transport' => new FakeMcpTransport(), 'extensions' => $extensions]);
    }

    public function test_extensions_are_objects_on_the_wire(): void
    {
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'resultType' => 'complete',
                    'supportedVersions' => ['2026-07-28'],
                    'capabilities' => ['extensions' => ['io.modelcontextprotocol/ui' => new stdClass()]],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}'),
        ]);

        $client = new McpClient([
            'url' => 'https://example.com/mcp',
            'httpClient' => new Client(['handler' => $handler, 'http_errors' => false]),
            'extensions' => [
                'io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']],
                'com.example/flag' => [],
            ],
        ]);
        $client->listTools();

        $this->assertStringContainsString(
            '"io.modelcontextprotocol/clientCapabilities":{"extensions":{"io.modelcontextprotocol/ui":{"mimeTypes":["text/html;profile=mcp-app"]},"com.example/flag":{}}}',
            (string) $handler->getLastRequest()->getBody()
        );
        $this->assertTrue($client->hasExtension('io.modelcontextprotocol/ui'));
    }
    public function test_legacy_server_falls_back_to_initialize_handshake(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        );

        $client = new McpClient(['transport' => $transport]);

        $this->assertFalse($client->isModern());
        $this->assertEquals('2024-11-05', $client->getProtocolVersion());
        $transport->assertInitialized();
    }

    public function test_modern_server_skips_initialize_handshake(): void
    {
        $transport = (new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        ))->asModern();

        $client = new McpClient(['transport' => $transport]);
        $client->listTools();

        $this->assertTrue($client->isModern());
        $this->assertEquals('2026-07-28', $client->getProtocolVersion());
        $transport->assertNotInitialized();
    }

    public function test_modern_requests_carry_meta_metadata(): void
    {
        $transport = (new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        ))->asModern();

        $client = new McpClient(['transport' => $transport]);
        $client->listTools();

        $toolsList = array_values(array_filter(
            $transport->getSent(),
            fn (array $data): bool => ($data['method'] ?? null) === 'tools/list'
        ))[0];

        $meta = $toolsList['params']['_meta'];

        $this->assertSame('2026-07-28', $meta['io.modelcontextprotocol/protocolVersion']);
        $this->assertSame('neuron-ai', $meta['io.modelcontextprotocol/clientInfo']->name);
        $this->assertEquals(new stdClass(), $meta['io.modelcontextprotocol/clientCapabilities']);
    }

    public function test_modern_server_exposes_identity_and_instructions(): void
    {
        $transport = (new FakeMcpTransport())->asModern();

        $client = new McpClient(['transport' => $transport]);

        $this->assertSame('fake-mcp-server', $client->getServerInfo()['name']);
        $this->assertSame('Fake server instructions.', $client->getInstructions());
    }

    public function test_unsupported_protocol_version_error_negotiates_from_supported_list(): void
    {
        $transport = (new FakeMcpTransport())->answerDiscoverWith(
            ['jsonrpc' => '2.0', 'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => ['2025-11-25']],
            ]]
        );

        $client = new McpClient(['transport' => $transport]);

        $this->assertFalse($client->isModern());
        $transport->assertInitialized();
    }

    public function test_no_mutually_supported_version_throws(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No mutually supported MCP protocol version');

        $transport = (new FakeMcpTransport())->answerDiscoverWith(
            ['jsonrpc' => '2.0', 'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => ['supported' => ['1999-01-01']],
            ]]
        );

        new McpClient(['transport' => $transport]);
    }

    public function test_input_required_result_throws_typed_exception(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                'resultType' => 'input_required',
                'inputRequests' => [
                    ['method' => 'elicitation/create', 'params' => ['message' => 'Confirm?']],
                ],
            ]]
        );

        $connector = new McpConnector(['transport' => $transport]);

        try {
            $connector->invokeTool(
                item: ['name' => 'tool', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                arguments: []
            );
            $this->fail('Expected McpInputRequiredException');
        } catch (McpInputRequiredException $exception) {
            $this->assertSame('elicitation/create', $exception->getInputRequests()[0]['method']);
        }
    }

    public function test_missing_result_type_is_treated_as_complete(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                'content' => [['type' => 'text', 'text' => 'ok']],
            ]]
        );

        $connector = new McpConnector(['transport' => $transport]);

        $result = $connector->invokeTool(
            item: ['name' => 'tool', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            arguments: []
        );

        $this->assertSame([['type' => 'text', 'text' => 'ok']], $result);
    }

    public function test_version_constants_stay_consistent(): void
    {
        $this->assertTrue(McpProtocolVersions::isModern('2026-07-28'));
        $this->assertTrue(McpProtocolVersions::isModern('2027-01-01'));
        $this->assertFalse(McpProtocolVersions::isModern('2025-11-25'));

        $this->assertSame(
            '2025-06-18',
            McpProtocolVersions::negotiate(['2025-03-26', '2025-06-18'])
        );
        $this->assertNull(McpProtocolVersions::negotiate(['1999-01-01']));
    }
}
