<?php

declare(strict_types=1);

namespace Tests\MCP;

use NeuronAI\MCP\CallableMcpTool;
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tools\HasMeta;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

use function array_map;
use function array_values;
use function serialize;
use function unserialize;

class McpConnectorTest extends TestCase
{
    public McpConnector $connector;
    public FakeMcpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->connector, $this->transport] = $this->createConnectorWithMockedClient();
    }

    /**
     * Create a connector with a mocked client to prevent HTTP calls
     * @return array<McpConnector | FakeMcpTransport>
     */
    private function createConnectorWithMockedClient(?McpClient $clientMock = null, array $extraResponses = []): array
    {
        // Protocol negotiation (server/discover probe and the legacy
        // initialize handshake) is answered by the fake transport itself.
        $transport = new FakeMcpTransport(
            ...$extraResponses
        );

        $connector = new McpConnector([
            'transport' => $transport,
        ]);


        return [$connector, $transport];
    }

    /**
     * @param array<string, mixed> ...$tools
     * @return array<string, mixed>
     */
    protected function toolsList(array ...$tools): array
    {
        return ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => $tools]];
    }

    /**
     * A tool declaring the given _meta.ui.
     *
     * @param array<string, mixed> $ui
     * @return array<string, mixed>
     */
    protected function uiTool(string $name, array $ui): array
    {
        return [
            'name' => $name,
            'description' => $name,
            'inputSchema' => ['type' => 'object', 'properties' => []],
            '_meta' => ['ui' => $ui],
        ];
    }

    /**
     * A tool declaring only the deprecated, flat ui/resourceUri form.
     *
     * @return array<string, mixed>
     */
    protected function deprecatedUiTool(string $name, string $resourceUri): array
    {
        return [
            'name' => $name,
            'description' => $name,
            'inputSchema' => ['type' => 'object', 'properties' => []],
            '_meta' => ['ui/resourceUri' => $resourceUri],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function metaOf(ToolInterface $tool): array
    {
        if (! $tool instanceof HasMeta) {
            self::fail('The tool carries no metadata.');
        }

        return $tool->getMeta();
    }

    public function testToolsKeepAppOnlyToolsOutOfTheModelList(): void
    {
        $transport = new FakeMcpTransport($this->toolsList(
            $this->uiTool('dashboard', ['resourceUri' => 'ui://sales/dashboard.html', 'visibility' => ['model', 'app']]),
            $this->uiTool('refresh', ['resourceUri' => 'ui://sales/dashboard.html', 'visibility' => ['app']]),
            $this->uiTool('internal', ['visibility' => ['model']]),
            ['name' => 'clock', 'description' => 'clock', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ));

        $tools = (new McpConnector(['transport' => $transport]))->tools();

        // A tool without a declared visibility is for both.
        $this->assertSame(
            ['dashboard', 'internal', 'clock'],
            array_values(array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools))
        );
    }

    public function testRawToolsHoldEveryToolAndAreFetchedOnce(): void
    {
        $transport = new FakeMcpTransport($this->toolsList(
            $this->uiTool('dashboard', ['resourceUri' => 'ui://sales/dashboard.html']),
            $this->uiTool('refresh', ['visibility' => ['app']]),
        ));
        $connector = new McpConnector(['transport' => $transport]);

        // App-only tools included, exactly as the server declared them.
        $this->assertSame(['dashboard', 'refresh'], array_map(fn (array $tool): string => $tool['name'], $connector->rawTools()));
        $this->assertCount(1, $connector->tools());

        $transport->assertToolsListCalled(1);
    }

    public function testToolsCarryTheirServerAndUiMetadata(): void
    {
        $transport = new FakeMcpTransport($this->toolsList(
            $this->uiTool('dashboard', ['resourceUri' => 'ui://sales/dashboard.html', 'visibility' => ['model', 'app']]),
            $this->deprecatedUiTool('orphan', 'ui://orphan/view.html'),
            ['name' => 'clock', 'description' => 'clock', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ));

        [$dashboard, $orphan, $clock] = (new McpConnector(['transport' => $transport, 'name' => 'erp']))->tools();

        $this->assertSame(
            ['mcp' => ['server' => 'erp', 'ui' => ['resourceUri' => 'ui://sales/dashboard.html', 'visibility' => ['model', 'app']]]],
            $this->metaOf($dashboard)
        );
        // The deprecated flat form is read under the current shape.
        $this->assertSame('ui://orphan/view.html', $this->metaOf($orphan)['mcp']['ui']['resourceUri']);
        $this->assertSame(['mcp' => ['server' => 'erp']], $this->metaOf($clock));
    }

    public function testNameLabelsTheServer(): void
    {
        $this->assertSame('erp', (new McpConnector(['url' => 'https://example.com/mcp', 'name' => 'erp']))->name());

        // Without a name: the URL, minus what must never reach history.
        $this->assertSame(
            'https://example.com:8443/mcp',
            (new McpConnector(['url' => 'https://user:secret@example.com:8443/mcp?token=secret#fragment']))->name()
        );
        $this->assertSame('npx', (new McpConnector(['command' => 'npx', 'args' => ['server']]))->name());
    }

    public static function toolResults(): array
    {
        return [
            'content for the model' => [
                ['content' => [['type' => 'text', 'text' => 'Total: 42']], 'structuredContent' => ['total' => 42], '_meta' => ['timestamp' => 'now']],
                '[{"type":"text","text":"Total: 42"}]',
                ['total' => 42],
                ['mcp' => ['isError' => false, 'resultMeta' => ['timestamp' => 'now']]],
            ],
            'empty content with structured data' => [
                ['content' => [], 'structuredContent' => ['total' => 42]],
                '{"total":42}',
                ['total' => 42],
                ['mcp' => ['isError' => false]],
            ],
            'no content with structured data' => [
                ['structuredContent' => ['total' => 42]],
                '{"total":42}',
                ['total' => 42],
                ['mcp' => ['isError' => false]],
            ],
            'empty content and nothing else' => [
                ['content' => []],
                '[]',
                null,
                ['mcp' => ['isError' => false]],
            ],
            'no content at all' => [
                [],
                '',
                null,
                ['mcp' => ['isError' => false]],
            ],
            'tool error' => [
                ['content' => [['type' => 'text', 'text' => 'boom']], 'isError' => true],
                '[{"type":"text","text":"boom"}]',
                null,
                ['mcp' => ['isError' => true]],
            ],
        ];
    }

    /**
     * @dataProvider toolResults
     */
    public function testInvokeToolResultKeepsTheWholeResult(array $result, string $text, ?array $structuredContent, array $meta): void
    {
        [$connector] = $this->createConnectorWithMockedClient(extraResponses: [
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => $result],
        ]);

        $output = $connector->invokeToolResult(
            item: ['name' => 'get_sales', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            arguments: [],
        );

        $this->assertSame($text, $output->getText());
        $this->assertSame($structuredContent, $output->structuredContent);
        $this->assertSame($meta, $output->meta);
    }

    public function testExecutingAnMcpToolKeepsTheWholeResult(): void
    {
        $transport = new FakeMcpTransport(
            $this->toolsList([
                'name' => 'get_sales',
                'description' => 'Sales by region',
                'inputSchema' => ['type' => 'object', 'properties' => ['region' => ['type' => 'string']]],
                '_meta' => ['ui' => ['resourceUri' => 'ui://sales/dashboard.html']],
            ]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                'content' => [['type' => 'text', 'text' => 'Total: 42']],
                'structuredContent' => ['total' => 42],
            ]],
        );

        /** @var Tool $tool */
        $tool = (new McpConnector(['transport' => $transport, 'name' => 'erp']))->tools()[0];

        $tool->setInputs(['region' => 'emea']);
        $tool->execute();

        $transport->assertToolCalled('get_sales');
        $this->assertSame('[{"type":"text","text":"Total: 42"}]', $tool->getResult());
        $this->assertSame(['total' => 42], $tool->getOutput()->structuredContent);
        $this->assertSame(['mcp' => ['isError' => false]], $tool->getOutput()->meta);
        $this->assertSame('ui://sales/dashboard.html', $tool->getMeta()['mcp']['ui']['resourceUri']);
    }

    public function testConnectorExposesServerExtensions(): void
    {
        $capabilities = [
            'tools' => new stdClass(),
            'extensions' => ['io.modelcontextprotocol/ui' => new stdClass()],
        ];

        $transport = (new FakeMcpTransport())->asModern()->withServerCapabilities($capabilities);

        $connector = new McpConnector(['transport' => $transport]);

        $this->assertTrue($connector->hasExtension('io.modelcontextprotocol/ui'));
        $this->assertSame([], $connector->extensionSettings('io.modelcontextprotocol/ui'));
        $this->assertSame($capabilities, $connector->capabilities());
        $this->assertFalse($connector->hasExtension('io.modelcontextprotocol/tasks'));
    }

    public function testExcludeReturnsSelf(): void
    {
        $result = $this->connector->exclude(['tool1', 'tool2']);

        $this->assertSame($this->connector, $result);
    }

    public function testOnlyReturnsSelf(): void
    {
        $result = $this->connector->only(['tool1', 'tool2']);

        $this->assertSame($this->connector, $result);
    }

    public function testCallableMcpToolsIsSerializable(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        $callable = new CallableMcpTool(
            connector: $this->connector,
            item: $item,
        );

        // Test serialization
        $serialized = serialize($callable);

        // Test unserialization
        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(CallableMcpTool::class, $unserialized);
    }

    public function testInvokeToolStaticMethodCreatesNewInstance(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        [$connector,] = $this->createConnectorWithMockedClient(extraResponses: [
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'The result is 42'],
                    ],
                ],
            ],
            ]);


        $reflection = new ReflectionClass($connector);
        $clientProperty = $reflection->getProperty('client');

        $clientMock = $this->createMock(McpClient::class);
        $clientMock->expects($this->once())
            ->method('callTool')
            ->with('test_tool', ['arg1' => 'value1'])
            ->willReturn([
                'result' => [
                    'content' => ['result' => 'success'],
                ],
            ]);

        // Initialize transport so __destruct doesn't fail
        $transportProperty = (new ReflectionClass(McpClient::class))->getProperty('transport');
        $transportProperty->setValue($clientMock, new FakeMcpTransport());

        $clientProperty->setValue($connector, $clientMock);

        $result = $connector->invokeTool(
            item: $item,
            arguments: ['arg1' => 'value1'],
        );

        $this->assertEquals(['result' => 'success'], $result);
    }

    public function testInvokeToolThrowsExceptionOnError(): void
    {
        [$connector,] = $this->createConnectorWithMockedClient(
            extraResponses: [
                [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'error' => [
                        'message' => 'Tool execution failed',
                    ],
                ],
            ]
        );

        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        $this->expectException(\NeuronAI\MCP\McpException::class);
        $this->expectExceptionMessage('Tool execution failed');

        $connector->invokeTool(
            item: $item,
            arguments: []
        );
    }

    public function testInvokeToolReturnsEmptyStringWhenNoContent(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        [$connector,$tranport] = $this->createConnectorWithMockedClient();

        $reflection = new ReflectionClass($connector);
        $clientProperty = $reflection->getProperty('client');

        $clientMock = $this->createMock(McpClient::class);
        $clientMock->expects($this->once())
            ->method('callTool')
            ->willReturn(['result' => []]);

        // Initialize transport so __destruct doesn't fail
        $transportProperty = (new ReflectionClass(McpClient::class))->getProperty('transport');
        $transportProperty->setValue($clientMock, new FakeMcpTransport());

        $clientProperty->setValue($connector, $clientMock);

        $result = $connector->invokeTool(
            item: $item,
            arguments: [],
        );

        $this->assertEquals('', $result);
    }

    public function testFakeTransportInitializesCorrectly(): void
    {
        $transport = new FakeMcpTransport(
            // Response for tools/list request
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        );

        $connector = new McpConnector(['transport' => $transport]);
        $connector->tools();

        // Verify initialization sequence was called
        $transport->assertInitialized();
        $transport->assertToolsListCalled();
    }

    public function testFakeTransportToolsList(): void
    {
        $transport = new FakeMcpTransport(
            // Response for tools/list request with multiple tools
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'tools' => [
                        [
                            'name' => 'calculator',
                            'description' => 'Perform calculations',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'operation' => ['type' => 'string'],
                                    'a' => ['type' => 'number'],
                                    'b' => ['type' => 'number'],
                                ],
                                'required' => ['operation', 'a', 'b'],
                            ],
                        ],
                        [
                            'name' => 'greet',
                            'description' => 'Greet someone',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $connector = new McpConnector(['transport' => $transport]);
        $tools = $connector->tools();

        $this->assertCount(2, $tools);
        $transport->assertToolsListCalled();
    }

    public function testFakeTransportToolCalling(): void
    {
        [$connector,$transport] = $this->createConnectorWithMockedClient(
            extraResponses: [
                [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'The result is 42'],
                    ],
                ],
            ],
            ]
        );

        $result = $connector->invokeTool(
            item: ['name' => 'calculator', 'description' => 'Calculator', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            arguments: ['operation' => 'add', 'a' => 20, 'b' => 22],
        );

        $this->assertEquals([['type' => 'text', 'text' => 'The result is 42']], $result);
        $transport->assertToolCalled('calculator', 1);
    }

    public function testFakeTransportOnlyAndExcludeFilters(): void
    {
        $transport = new FakeMcpTransport(
            // Response for tools/list request
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'tools' => [
                        ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool2', 'description' => 'Tool 2', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool3', 'description' => 'Tool 3', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                    ],
                ],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => [
                    'tools' => [
                        ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool2', 'description' => 'Tool 2', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool3', 'description' => 'Tool 3', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                    ],
                ],
            ]
        );

        $connector = new McpConnector(['transport' => $transport]);

        // Test "only" filter
        $toolsOnly = $connector->only(['tool1', 'tool3'])->tools();
        $this->assertCount(2, $toolsOnly);

        // Test "exclude" filter
        $toolsExclude = $connector->only([])->exclude(['tool2'])->tools();
        $this->assertCount(2, $toolsExclude);
    }
}
