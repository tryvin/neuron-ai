<?php

declare(strict_types=1);

namespace Tests\MCP;

use NeuronAI\MCP\Apps\McpAppResource;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

use function array_map;
use function base64_encode;

final class McpAppsConnectorTest extends TestCase
{
    /** @param array<string, mixed> $result */
    private function response(array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => 2, 'result' => $result];
    }

    public function testAppToolsIncludeToolsDeclaredForTheApp(): void
    {
        $transport = (new FakeMcpTransport($this->response(['tools' => [
            ['name' => 'for_model', 'inputSchema' => ['type' => 'object'], '_meta' => ['ui' => ['visibility' => ['model']]]],
            ['name' => 'for_app', 'inputSchema' => ['type' => 'object'], '_meta' => ['ui' => ['visibility' => ['app']]]],
            ['name' => 'for_both', 'inputSchema' => ['type' => 'object'], '_meta' => ['ui' => ['visibility' => ['model', 'app']]]],
            ['name' => 'undeclared', 'inputSchema' => ['type' => 'object']],
        ]])))->asModern();

        $connector = new McpConnector(['transport' => $transport, 'name' => 'erp']);

        $this->assertSame(
            ['for_app', 'for_both', 'undeclared'],
            array_map(static fn (array $tool): string => $tool['name'], $connector->appTools())
        );
        $this->assertSame(['ui' => ['visibility' => ['app']]], $connector->toolMeta('for_app'));
        $this->assertSame([], $connector->toolMeta('missing'));
    }

    public function testItCallsAToolAndReadsAResource(): void
    {
        $transport = (new FakeMcpTransport(
            $this->response(['content' => [['type' => 'text', 'text' => 'ok']]]),
            $this->response(['contents' => [['uri' => 'ui://sales/view.html', 'text' => '<html></html>']]])
        ))->asModern();

        $connector = new McpConnector(['transport' => $transport, 'name' => 'erp']);

        $this->assertSame(
            ['content' => [['type' => 'text', 'text' => 'ok']]],
            $connector->callTool('get_sales', ['region' => 'emea'])
        );
        $this->assertSame(
            ['contents' => [['uri' => 'ui://sales/view.html', 'text' => '<html></html>']]],
            $connector->readResource('ui://sales/view.html')
        );
        $transport->assertMethodSent('tools/call');
        $transport->assertMethodSent('resources/read');
    }

    public function testItBuildsAResourceFromListingAndRead(): void
    {
        $transport = (new FakeMcpTransport(
            $this->response(['resources' => [[
                'uri' => 'ui://sales/dashboard.html',
                'mimeType' => 'text/html;profile=mcp-app',
                '_meta' => ['ui' => [
                    'csp' => ['connectDomains' => ['https://listed.example']],
                    'permissions' => ['camera' => []],
                ]],
            ]]]),
            $this->response(['contents' => [[
                'uri' => 'ui://sales/dashboard.html',
                'mimeType' => 'text/html;profile=mcp-app',
                'text' => '<html>Sales</html>',
                '_meta' => ['ui' => ['csp' => ['connectDomains' => ['https://read.example']]]],
            ]]])
        ))->asModern();

        $connector = new McpConnector(['transport' => $transport, 'name' => 'erp']);
        $resource = $connector->resource('ui://sales/dashboard.html');

        $this->assertInstanceOf(McpAppResource::class, $resource);
        $this->assertSame('<html>Sales</html>', $resource->html);
        $this->assertSame(['connectDomains' => ['https://read.example']], $resource->csp);
        $this->assertSame(['camera' => []], $resource->permissions);
    }

    public function testItFindsTheUiResourceDeclaredByATool(): void
    {
        $transport = (new FakeMcpTransport(
            $this->response(['tools' => [[
                'name' => 'get_sales',
                'inputSchema' => ['type' => 'object'],
                '_meta' => ['ui' => ['resourceUri' => 'ui://sales/dashboard.html']],
            ]]]),
            $this->response(['resources' => []]),
            $this->response(['contents' => [[
                'uri' => 'ui://sales/dashboard.html',
                'mimeType' => 'text/html;profile=mcp-app',
                'blob' => base64_encode('<html>Blob</html>'),
            ]]])
        ))->asModern();

        $connector = new McpConnector(['transport' => $transport, 'name' => 'erp']);

        $this->assertNull($connector->uiResourceFor('missing'));
        $resource = $connector->uiResourceFor('get_sales');
        $this->assertInstanceOf(McpAppResource::class, $resource);
        $this->assertSame('<html>Blob</html>', $resource->html);
    }

    public function testToolErrorsBecomeMcpExceptions(): void
    {
        $transport = (new FakeMcpTransport([
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => ['code' => -32000, 'message' => 'nope'],
        ]))->asModern();

        $connector = new McpConnector(['transport' => $transport, 'name' => 'erp']);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('nope');

        $connector->callTool('get_sales', []);
    }
}
