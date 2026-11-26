<?php

declare(strict_types=1);

namespace Tests\MCP\Apps;

use NeuronAI\MCP\Apps\McpAppAction;
use NeuronAI\MCP\Apps\McpAppResult;
use PHPUnit\Framework\TestCase;

final class McpAppResultTest extends TestCase
{
    public function testItCarriesAResponseAndAnAction(): void
    {
        $action = new McpAppAction(McpAppAction::OPEN_LINK, ['url' => 'https://example.com']);
        $result = new McpAppResult(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $action);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $result->response);
        $this->assertSame($action, $result->action);
        $this->assertSame(McpAppAction::OPEN_LINK, $result->action->type);
        $this->assertSame(['url' => 'https://example.com'], $result->action->payload);
    }

    public function testItBuildsAnErrorResponseForTheIncomingId(): void
    {
        $result = McpAppResult::error(['jsonrpc' => '2.0', 'id' => 'call_1', 'method' => 'ui/open-link'], -32000, 'Blocked');

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 'call_1',
            'error' => ['code' => -32000, 'message' => 'Blocked'],
        ], $result->response);
        $this->assertNull($result->action);
    }
}
