<?php

declare(strict_types=1);

namespace Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\StreamableHttpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class StreamableHttpTransportHeadersTest extends TestCase
{
    private StreamableHttpTransport $transport;

    private MockHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new MockHandler();

        $this->transport = new StreamableHttpTransport([
            'url' => 'https://example.com/mcp',
            'token' => 'secret-token',
            'httpClient' => new Client(['handler' => $this->handler, 'http_errors' => false]),
        ]);
    }

    private function queueResponse(string $body = '{"jsonrpc":"2.0","id":1,"result":{}}'): void
    {
        $this->handler->append(new Response(200, ['Content-Type' => 'application/json'], $body));
    }

    public function test_send_includes_required_request_metadata_headers(): void
    {
        $this->queueResponse();

        $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'get_weather'],
        ]);

        $request = $this->handler->getLastRequest();

        $this->assertSame('2026-07-28', $request->getHeaderLine('MCP-Protocol-Version'));
        $this->assertSame('tools/call', $request->getHeaderLine('Mcp-Method'));
        $this->assertSame('get_weather', $request->getHeaderLine('Mcp-Name'));
        $this->assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
    }

    public function test_negotiated_version_is_mirrored_into_header(): void
    {
        $this->transport->setProtocolVersion('2025-06-18');
        $this->queueResponse();

        $this->transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);

        $this->assertSame('2025-06-18', $this->handler->getLastRequest()->getHeaderLine('MCP-Protocol-Version'));
    }

    public function test_param_headers_are_mirrored_and_stripped_from_body(): void
    {
        $this->queueResponse();

        $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'execute_sql'],
            StreamableHttpTransport::PARAM_HEADERS_KEY => ['Region' => 'us-west1'],
        ]);

        $request = $this->handler->getLastRequest();

        $this->assertSame('us-west1', $request->getHeaderLine('Mcp-Param-Region'));
        $this->assertStringNotContainsString('_neuron_http_headers', (string) $request->getBody());
    }

    public function test_boolean_and_integer_param_values_are_stringified(): void
    {
        $this->queueResponse();

        $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'x'],
            StreamableHttpTransport::PARAM_HEADERS_KEY => [
                'Flag' => true,
                'Count' => 42,
            ],
        ]);

        $request = $this->handler->getLastRequest();

        $this->assertSame('true', $request->getHeaderLine('Mcp-Param-Flag'));
        $this->assertSame('42', $request->getHeaderLine('Mcp-Param-Count'));
    }

    public function test_non_ascii_values_use_base64_sentinel_encoding(): void
    {
        $this->queueResponse();

        $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['uri' => 'file:///pröjekt/config.json'],
        ]);

        $expected = '=?base64?'.base64_encode('file:///pröjekt/config.json').'?=';

        $this->assertSame($expected, $this->handler->getLastRequest()->getHeaderLine('Mcp-Name'));
    }

    public function test_values_matching_the_sentinel_pattern_are_encoded(): void
    {
        $reflection = new ReflectionClass(StreamableHttpTransport::class);
        $method = $reflection->getMethod('encodeHeaderValue');

        $sentinel = '=?base64?literal?=';

        $encoded = $method->invoke($this->transport, $sentinel);

        $this->assertNotSame($sentinel, $encoded);
        $this->assertSame(
            '=?base64?'.base64_encode($sentinel).'?=',
            $encoded
        );
    }

    public function test_ascii_values_with_interior_space_pass_through(): void
    {
        $reflection = new ReflectionClass(StreamableHttpTransport::class);
        $method = $reflection->getMethod('encodeHeaderValue');

        $this->assertSame('Seattle, WA', $method->invoke($this->transport, 'Seattle, WA'));
        $this->assertSame('simple-value', $method->invoke($this->transport, 'simple-value'));
    }

    public function test_401_and_403_carry_http_status_code(): void
    {
        $this->handler->append(new Response(401, [], ''));

        try {
            $this->transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
            $this->fail('Expected McpException');
        } catch (McpException $exception) {
            $this->assertSame(401, $exception->getHttpStatusCode());
        }

        $this->handler->append(new Response(403, [], ''));

        try {
            $this->transport->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
            $this->fail('Expected McpException');
        } catch (McpException $exception) {
            $this->assertSame(403, $exception->getHttpStatusCode());
        }
    }

    public function test_error_status_bodies_reach_the_client(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32022, 'message' => 'Unsupported protocol version', 'data' => ['supported' => ['2026-07-28']]],
        ]);

        $this->handler->append(new Response(400, ['Content-Type' => 'application/json'], $body));

        $this->transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => []]);

        $response = $this->transport->receive();

        $this->assertSame(-32022, $response['error']['code']);
        $this->assertSame(400, $this->transport->getLastHttpStatusCode());
    }

    public function test_sse_with_multiple_data_lines_and_notifications(): void
    {
        $sse = ""
            ."event: message\n"
            ."data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\",\n"
            ."data: \"params\":{\"progress\":50}}\n"
            ."\n"
            ."event: message\n"
            ."data: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"resultType\":\"complete\"}}\n"
            ."\n";

        $this->handler->append(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $this->transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);

        $response = $this->transport->receive();

        $this->assertSame(1, $response['id']);
        $this->assertSame('complete', $response['result']['resultType']);
    }

    public function test_sse_comment_lines_are_ignored(): void
    {
        $sse = ": keep-alive\n\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n";

        $this->handler->append(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));

        $this->transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);

        $response = $this->transport->receive();

        $this->assertSame(1, $response['id']);
    }

    public function test_session_id_is_captured_and_echoed_on_subsequent_requests(): void
    {
        // The initialize handshake returns a session id the server assigns.
        $this->handler->append(new Response(
            200,
            ['Mcp-Session-Id' => 'abc-123'],
            '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2024-11-05"}}'
        ));

        $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ]);

        $sessionProperty = (new ReflectionClass($this->transport))->getProperty('sessionId');
        $this->assertSame('abc-123', $sessionProperty->getValue($this->transport));

        // Subsequent requests must echo the captured session id.
        $this->queueResponse('{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}');

        $this->transport->send([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $this->assertSame('abc-123', $this->handler->getLastRequest()->getHeaderLine('Mcp-Session-Id'));
    }
}
