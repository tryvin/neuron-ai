<?php

declare(strict_types=1);

namespace Tests\MCP;

use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpInputRequiredException;
use NeuronAI\MCP\McpProtocolVersions;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

class McpProtocolNegotiationTest extends TestCase
{
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
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);
        $client->listTools();

        $this->assertTrue($client->isModern());
        $this->assertEquals('2026-07-28', $client->getProtocolVersion());
        $transport->assertNotInitialized();
    }

    public function test_modern_requests_carry_meta_metadata(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);
        $client->listTools();

        $toolsList = array_values(array_filter(
            $transport->getSent(),
            fn (array $data): bool => ($data['method'] ?? null) === 'tools/list'
        ))[0];

        $meta = $toolsList['params']['_meta'];

        $this->assertSame('2026-07-28', $meta['io.modelcontextprotocol/protocolVersion']);
        $this->assertSame('neuron-ai', $meta['io.modelcontextprotocol/clientInfo']->name);
        $this->assertEquals(new \stdClass(), $meta['io.modelcontextprotocol/clientCapabilities']);
    }

    public function test_modern_server_exposes_identity_and_instructions(): void
    {
        $transport = new FakeMcpTransport()->asModern();

        $client = new McpClient(['transport' => $transport]);

        $this->assertSame('fake-mcp-server', $client->getServerInfo()['name']);
        $this->assertSame('Fake server instructions.', $client->getInstructions());
    }

    public function test_unsupported_protocol_version_error_negotiates_from_supported_list(): void
    {
        $transport = new FakeMcpTransport()->answerDiscoverWith(
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

        $transport = new FakeMcpTransport()->answerDiscoverWith(
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
