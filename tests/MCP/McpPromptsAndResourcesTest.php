<?php

declare(strict_types=1);

namespace Tests\MCP;

use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

class McpPromptsAndResourcesTest extends TestCase
{
    public function test_list_prompts_accumulates_pages(): void
    {
        $transport = new FakeMcpTransport(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'resultType' => 'complete',
                    'prompts' => [['name' => 'code_review']],
                    'nextCursor' => 'page-2',
                ],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'resultType' => 'complete',
                    'prompts' => [['name' => 'explain']],
                ],
            ]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);

        $prompts = $client->listPrompts();

        $this->assertSame(['code_review', 'explain'], array_column($prompts, 'name'));

        $secondPage = $this->lastRequestForMethod($transport, 'prompts/list');

        $this->assertSame('page-2', $secondPage['params']['cursor']);
    }

    public function test_get_prompt_sends_name_and_arguments(): void
    {
        $transport = new FakeMcpTransport(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'resultType' => 'complete',
                    'description' => 'Code review prompt',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => ['type' => 'text', 'text' => 'Review this code'],
                        ],
                    ],
                ],
            ]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);

        $result = $client->getPrompt('code_review', ['code' => 'fn main(){}']);

        $this->assertSame('Code review prompt', $result['description']);
        $this->assertSame('user', $result['messages'][0]['role']);

        $request = $this->lastRequestForMethod($transport, 'prompts/get');

        $this->assertSame('code_review', $request['params']['name']);
        $this->assertSame('fn main(){}', $request['params']['arguments']['code']);
    }

    public function test_get_prompt_without_arguments_omits_arguments_field(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['resultType' => 'complete', 'messages' => []]]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);

        $client->getPrompt('simple');

        $request = $this->lastRequestForMethod($transport, 'prompts/get');

        $this->assertArrayNotHasKey('arguments', $request['params']);
        $this->assertSame('simple', $request['params']['name']);
    }

    public function test_list_resources(): void
    {
        $transport = new FakeMcpTransport(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'resultType' => 'complete',
                    'resources' => [
                        [
                            'uri' => 'file:///project/src/main.rs',
                            'name' => 'main.rs',
                            'mimeType' => 'text/x-rust',
                        ],
                    ],
                ],
            ]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);

        $resources = $client->listResources();

        $this->assertSame('file:///project/src/main.rs', $resources[0]['uri']);
    }

    public function test_list_resource_templates(): void
    {
        $transport = new FakeMcpTransport(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'resultType' => 'complete',
                    'resourceTemplates' => [
                        ['uriTemplate' => 'file:///{path}', 'name' => 'Project Files'],
                    ],
                ],
            ]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);

        $templates = $client->listResourceTemplates();

        $this->assertSame('file:///{path}', $templates[0]['uriTemplate']);
    }

    public function test_read_resource_sends_uri(): void
    {
        $transport = new FakeMcpTransport(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'resultType' => 'complete',
                    'contents' => [
                        [
                            'uri' => 'file:///project/src/main.rs',
                            'mimeType' => 'text/x-rust',
                            'text' => 'fn main() { println!("Hello world!"); }',
                        ],
                    ],
                ],
            ]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);

        $result = $client->readResource('file:///project/src/main.rs');

        $request = $this->lastRequestForMethod($transport, 'resources/read');

        $this->assertSame('resources/read', $request['method']);
        $this->assertSame('file:///project/src/main.rs', $request['params']['uri']);
        $this->assertSame('text/x-rust', $result['contents'][0]['mimeType']);
    }

    public function test_read_resource_surfaced_error_is_typed(): void
    {
        $transport = new FakeMcpTransport(
            [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32602,
                    'message' => 'Resource not found',
                    'data' => ['uri' => 'file:///missing'],
                ],
            ]
        )->asModern();

        $client = new McpClient(['transport' => $transport]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Resource not found');

        $client->readResource('file:///missing');
    }

    public function test_legacy_server_without_prompt_capability_surfaces_error(): void
    {
        // A legacy server that does not implement prompts returns -32601
        // method not found: surfaced as a normal McpException.
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'error' => ['code' => -32601, 'message' => 'Method not found']]
        );

        $client = new McpClient(['transport' => $transport]);

        try {
            $client->listPrompts();
            $this->fail('Expected McpException');
        } catch (McpException $exception) {
            $this->assertSame(-32601, $exception->getCode());
        }
    }

    /**
     * The server/discover negotiation probe is sent first; assert the
     * actual business request by filtering on its JSON-RPC method.
     *
     * @return array<string, mixed>
     */
    private function lastRequestForMethod(FakeMcpTransport $transport, string $method): array
    {
        $matches = array_values(array_filter(
            $transport->getSent(),
            fn (array $data): bool => ($data['method'] ?? null) === $method
        ));

        $this->assertNotEmpty($matches, "No sent request with method '{$method}'.");

        return $matches[count($matches) - 1];
    }
}
