<?php

declare(strict_types=1);

namespace Tests\MCP\Apps;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\MCP\Apps\DefaultMcpAppPolicy;
use NeuronAI\MCP\Apps\McpAppAction;
use NeuronAI\MCP\Apps\McpAppPolicyInterface;
use NeuronAI\MCP\Apps\McpAppSession;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_column;
use function array_merge;
use function json_encode;

final class McpAppSessionTest extends TestCase
{
    /** @param array<string, mixed> $result */
    private function response(array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => 2, 'result' => $result];
    }

    /** @param array<int, array<string, mixed>> $responses */
    private function session(array $responses = [], array $config = [], ?McpAppPolicyInterface $policy = null): McpAppSession
    {
        $transport = new FakeMcpTransport(...$responses);
        $connector = new McpConnector(['transport' => $transport, 'name' => 'erp']);

        return $connector->appSession(array_merge([
            'view' => 'view_1',
            'tool' => 'get_sales',
            'callId' => 7,
            'host' => ['name' => 'acme-chat', 'version' => '1.0.0'],
            'capabilities' => ['openLinks' => true, 'logging' => true],
            'context' => ['theme' => 'dark'],
            'policy' => $policy ?? new DefaultMcpAppPolicy(),
        ], $config));
    }

    private function initialize(McpAppSession $session): void
    {
        $session->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ui/initialize',
            'params' => [
                'appInfo' => ['name' => 'sales-app', 'version' => '1.0.0'],
                'appCapabilities' => ['availableDisplayModes' => ['inline', 'pip']],
                'protocolVersion' => '2026-01-26',
            ],
        ]);
        $session->handle(['jsonrpc' => '2.0', 'method' => 'ui/notifications/initialized']);
    }

    /** @return array<string, mixed> */
    private function request(string $method, array $params = [], int|string $id = 3): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    public function testInitializeNegotiatesTheStableVersionAndGatesTheView(): void
    {
        $session = $this->session([
            $this->response(['tools' => [['name' => 'get_sales', 'inputSchema' => ['type' => 'object']]]]),
        ]);

        $result = $session->handle($this->request('ui/initialize', [
            'appInfo' => ['name' => 'sales-app', 'version' => '1.0.0'],
            'appCapabilities' => ['availableDisplayModes' => ['inline', 'pip']],
            'protocolVersion' => '2025-06-18',
        ], 1));

        $response = $result->response['result'];
        $this->assertSame('2026-01-26', $response['protocolVersion']);
        $this->assertSame('acme-chat', $response['hostInfo']['name']);
        $this->assertEquals([
            'openLinks' => new stdClass(),
            'logging' => new stdClass(),
        ], $response['hostCapabilities']);
        $this->assertSame('dark', $response['hostContext']['theme']);
        $this->assertSame(7, $response['hostContext']['toolInfo']['id']);
        $this->assertSame('get_sales', $response['hostContext']['toolInfo']['tool']['name']);
        $this->assertFalse($session->isInitialized());
        $this->assertNull($result->action);

        $tooEarly = $session->handle($this->request('ping'));
        $this->assertSame(-32000, $tooEarly->response['error']['code']);

        $ready = $session->handle(['jsonrpc' => '2.0', 'method' => 'ui/notifications/initialized']);
        $this->assertNull($ready->response);
        $this->assertTrue($session->isInitialized());
        $this->assertEquals(new stdClass(), $session->handle($this->request('ping'))->response['result']);
    }

    public function testHostCapabilitiesAdvertiseServerToolsAndResources(): void
    {
        $hostCapabilities = function (array $capabilities): array {
            $session = $this->session([
                $this->response(['tools' => [['name' => 'get_sales', 'inputSchema' => ['type' => 'object']]]]),
            ], ['capabilities' => $capabilities]);

            return $session->handle($this->request('ui/initialize', [
                'appInfo' => ['name' => 'sales-app', 'version' => '1.0.0'],
                'appCapabilities' => [],
            ], 1))->response['result']['hostCapabilities'];
        };

        $quiet = $hostCapabilities([
            'serverTools' => ['listChanged' => false],
            'serverResources' => ['listChanged' => false],
        ]);
        $this->assertSame('{"listChanged":false}', json_encode($quiet['serverTools']));
        $this->assertSame('{"listChanged":false}', json_encode($quiet['serverResources']));

        $empty = $hostCapabilities(['serverTools' => []]);
        $this->assertSame('{"listChanged":false}', json_encode($empty['serverTools']));

        $loud = $hostCapabilities([
            'serverTools' => ['listChanged' => true],
            'serverResources' => true,
        ]);
        $this->assertSame('{"listChanged":true}', json_encode($loud['serverTools']));
        $this->assertSame('{"listChanged":false}', json_encode($loud['serverResources']));

        $absent = $hostCapabilities(['openLinks' => true]);
        $this->assertArrayNotHasKey('serverTools', $absent);
        $this->assertArrayNotHasKey('serverResources', $absent);
    }

    public function testDisplayModesAreClampedToTheHostContext(): void
    {
        $session = $this->session([
            $this->response(['tools' => [['name' => 'get_sales', 'inputSchema' => ['type' => 'object']]]]),
        ], [
            'capabilities' => ['openLinks' => true],
            'context' => [
                'availableDisplayModes' => ['inline', 'fullscreen'],
                'displayMode' => 'inline',
            ],
        ]);
        $session->handle($this->request('ui/initialize', [
            'appInfo' => ['name' => 'sales-app', 'version' => '1.0.0'],
            'appCapabilities' => ['availableDisplayModes' => ['inline', 'pip', 'fullscreen']],
        ], 1));
        $session->handle(['jsonrpc' => '2.0', 'method' => 'ui/notifications/initialized']);

        $pip = $session->handle($this->request('ui/request-display-mode', ['mode' => 'pip']));
        $this->assertSame(['mode' => 'inline'], $pip->response['result']);
        $this->assertSame(McpAppAction::DISPLAY_MODE, $pip->action->type);

        $fullscreen = $session->handle($this->request('ui/request-display-mode', ['mode' => 'fullscreen']));
        $this->assertSame(['mode' => 'fullscreen'], $fullscreen->response['result']);
    }

    public function testToolsListAndCallStayInsideTheBoundConnector(): void
    {
        $tools = ['tools' => [
            ['name' => 'get_sales', 'inputSchema' => ['type' => 'object']],
            ['name' => 'refresh_sales', 'inputSchema' => ['type' => 'object'], '_meta' => ['ui' => ['visibility' => ['app']]]],
            ['name' => 'secret', 'inputSchema' => ['type' => 'object'], '_meta' => ['ui' => ['visibility' => ['model']]]],
        ]];

        $session = $this->session([
            $this->response($tools),
            $this->response(['content' => [['type' => 'text', 'text' => 'refreshed']]]),
        ]);
        $this->initialize($session);

        $listed = $session->handle($this->request('tools/list'))->response;
        $this->assertSame(['get_sales', 'refresh_sales'], array_column($listed['result']['tools'], 'name'));

        $called = $session->handle($this->request('tools/call', [
            'name' => 'refresh_sales',
            'arguments' => ['region' => 'emea'],
        ]))->response;
        $this->assertSame('refreshed', $called['result']['content'][0]['text']);

        $modelOnly = $session->handle($this->request('tools/call', ['name' => 'secret', 'arguments' => []]));
        $this->assertSame(-32000, $modelOnly->response['error']['code']);
        $foreign = $session->handle($this->request('tools/call', ['name' => 'other_server_tool', 'arguments' => []]));
        $this->assertSame(-32000, $foreign->response['error']['code']);
        $notAnObject = $session->handle($this->request('tools/call', ['name' => 'get_sales', 'arguments' => ['a', 'b']]));
        $this->assertSame(-32000, $notAnObject->response['error']['code']);
    }

    public function testPolicyCanDenyToolsLinksAndResources(): void
    {
        $policy = new class () implements McpAppPolicyInterface {
            public function allowToolCall(string $server, string $tool, array $arguments): bool
            {
                return $tool !== 'get_sales';
            }

            public function allowResourceRead(string $server, string $uri): bool
            {
                return $uri !== 'ui://blocked.html';
            }

            public function allowOpenLink(string $url): bool
            {
                return $url !== 'https://blocked.example';
            }
        };

        $session = $this->session([
            $this->response(['tools' => [['name' => 'get_sales', 'inputSchema' => ['type' => 'object']]]]),
            $this->response(['contents' => [['uri' => 'ui://allowed.html', 'text' => '<html></html>']]]),
        ], [], $policy);
        $this->initialize($session);

        $deniedTool = $session->handle($this->request('tools/call', ['name' => 'get_sales', 'arguments' => []]));
        $this->assertSame(-32000, $deniedTool->response['error']['code']);

        $blockedResource = $session->handle($this->request('resources/read', ['uri' => 'ui://blocked.html']));
        $this->assertSame(-32000, $blockedResource->response['error']['code']);

        $resource = $session->handle($this->request('resources/read', ['uri' => 'ui://allowed.html']));
        $this->assertSame('<html></html>', $resource->response['result']['contents'][0]['text']);

        $blockedLink = $session->handle($this->request('ui/open-link', ['url' => 'https://blocked.example']));
        $this->assertSame(-32000, $blockedLink->response['error']['code']);
        $this->assertNull($blockedLink->action);
    }

    public function testActionsAndStagedContext(): void
    {
        $session = $this->session([
            $this->response(['tools' => [['name' => 'get_sales', 'inputSchema' => ['type' => 'object']]]]),
        ]);
        $this->initialize($session);

        $link = $session->handle($this->request('ui/open-link', ['url' => 'https://example.com/page']));
        $this->assertSame(McpAppAction::OPEN_LINK, $link->action->type);
        $this->assertSame('https://example.com/page', $link->action->payload['url']);
        $this->assertEquals(new stdClass(), $link->response['result']);

        $unsafe = $session->handle($this->request('ui/open-link', ['url' => 'javascript:alert(1)']));
        $this->assertSame(-32000, $unsafe->response['error']['code']);
        $this->assertNull($unsafe->action);

        $message = $session->handle($this->request('ui/message', [
            'role' => 'user',
            'content' => ['type' => 'text', 'text' => 'Show EMEA'],
        ]));
        $this->assertSame(McpAppAction::MESSAGE, $message->action->type);
        $this->assertSame('Show EMEA', $message->action->payload['text']);

        $badRole = $session->handle($this->request('ui/message', [
            'role' => 'assistant',
            'content' => ['type' => 'text', 'text' => 'Nope'],
        ]));
        $this->assertSame(-32000, $badRole->response['error']['code']);

        $context = $session->handle($this->request('ui/update-model-context', [
            'content' => [['type' => 'text', 'text' => 'Filter: EMEA']],
        ]));
        $this->assertSame(McpAppAction::UPDATE_MODEL_CONTEXT, $context->action->type);
        $this->assertSame(['content' => [['type' => 'text', 'text' => 'Filter: EMEA']]], $session->modelContext());

        $replaced = $session->handle($this->request('ui/update-model-context', [
            'structuredContent' => ['region' => 'emea'],
        ]));
        $this->assertSame(['structuredContent' => ['region' => 'emea']], $session->takeModelContext());
        $this->assertNull($session->modelContext());
        $this->assertSame(McpAppAction::UPDATE_MODEL_CONTEXT, $replaced->action->type);
    }

    public function testDisplayModeLoggingPingAndUnknownMethods(): void
    {
        $session = $this->session([
            $this->response(['tools' => [['name' => 'get_sales', 'inputSchema' => ['type' => 'object']]]]),
        ]);
        $this->initialize($session);

        $mode = $session->handle($this->request('ui/request-display-mode', ['mode' => 'pip']));
        $this->assertSame(['mode' => 'pip'], $mode->response['result']);
        $this->assertSame(McpAppAction::DISPLAY_MODE, $mode->action->type);

        $undeclared = $session->handle($this->request('ui/request-display-mode', ['mode' => 'fullscreen']));
        $this->assertSame(['mode' => 'pip'], $undeclared->response['result']);

        $log = $session->handle(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => [
            'level' => 'info', 'data' => 'loaded',
        ]]);
        $this->assertNull($log->response);
        $this->assertSame(McpAppAction::LOG, $log->action->type);
        $this->assertSame('loaded', $log->action->payload['data']);

        $unknown = $session->handle($this->request('ui/no-such-method'));
        $this->assertSame(-32601, $unknown->response['error']['code']);
        $this->assertEquals(new stdClass(), $session->handle($this->request('ping'))->response['result']);
    }

    public function testToolResultBuildsACallToolResult(): void
    {
        $session = $this->session();
        $output = new ToolOutput(
            text: 'Total: 42',
            structuredContent: ['total' => 42],
            meta: ['mcp' => ['isError' => true, 'resultMeta' => ['timestamp' => 'now']]],
        );

        $this->assertSame([
            'jsonrpc' => '2.0',
            'method' => 'ui/notifications/tool-result',
            'params' => [
                'content' => [['type' => 'text', 'text' => 'Total: 42']],
                'structuredContent' => ['total' => 42],
                '_meta' => ['timestamp' => 'now'],
                'isError' => true,
            ],
        ], $session->toolResult($output));
    }

    public function testToolResultReadsTextFromContentBlocks(): void
    {
        $session = $this->session();
        $output = new ToolOutput(blocks: [new TextContent('From a text block')]);

        $this->assertSame([
            'jsonrpc' => '2.0',
            'method' => 'ui/notifications/tool-result',
            'params' => [
                'content' => [['type' => 'text', 'text' => 'From a text block']],
            ],
        ], $session->toolResult($output));
    }

    public function testNotificationBuilders(): void
    {
        $session = $this->session();

        $this->assertEquals([
            'jsonrpc' => '2.0', 'method' => 'ui/notifications/tool-input', 'params' => ['arguments' => new stdClass()],
        ], $session->toolInput([]));
        $this->assertSame('ui/notifications/tool-input-partial', $session->toolInputPartial(['region' => 'emea'])['method']);
        $this->assertSame([
            'jsonrpc' => '2.0', 'method' => 'ui/notifications/tool-cancelled', 'params' => ['reason' => 'user'],
        ], $session->toolCancelled('user'));
        $this->assertSame([
            'jsonrpc' => '2.0', 'id' => 9, 'method' => 'ui/resource-teardown', 'params' => ['reason' => 'closed'],
        ], $session->resourceTeardown(9, 'closed'));
        $this->assertSame('ui/notifications/host-context-changed', $session->hostContextChanged(['theme' => 'light'])['method']);
    }
}
