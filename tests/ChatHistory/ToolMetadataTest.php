<?php

declare(strict_types=1);

namespace NeuronAI\Tests\ChatHistory;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\HasMeta;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function str_repeat;
use function unlink;

use const DIRECTORY_SEPARATOR;

class ToolMetadataTest extends TestCase
{
    private string $key = 'tool_metadata_test';

    protected function tearDown(): void
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'neuron_' . $this->key . '.chat';
        if (file_exists($file)) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function test_tool_meta_and_whole_result_survive_file_chat_history(): void
    {
        $meta = ['mcp' => ['server' => 'erp', 'ui' => ['resourceUri' => 'ui://sales/dashboard.html']]];
        $resultMeta = ['mcp' => ['isError' => false, 'resultMeta' => ['timestamp' => 'now']]];

        $tool = Tool::make('get_sales', 'Sales by region', meta: $meta)
            ->setCallId('call_1')
            ->setResult(new ToolOutput(
                text: '[{"type":"text","text":"Total: 42"}]',
                structuredContent: ['total' => 42],
                meta: $resultMeta,
            ));

        $persist = new FileChatHistory(__DIR__, $this->key);
        $persist->addMessage(new ToolCallMessage(tools: [$tool]));
        $persist->addMessage(new ToolResultMessage([$tool]));

        [$called, $returned] = $this->reloadedTools();

        $this->assertInstanceOf(HasMeta::class, $called);
        $this->assertInstanceOf(HasMeta::class, $returned);
        $this->assertSame($meta, $called->getMeta());
        $this->assertSame($meta, $returned->getMeta());
        $this->assertSame('[{"type":"text","text":"Total: 42"}]', $returned->getResult());
        $this->assertSame(['total' => 42], $returned->getOutput()->structuredContent);
        $this->assertSame($resultMeta, $returned->getOutput()->meta);
    }

    public function test_structured_content_and_meta_are_not_counted_as_tokens(): void
    {
        $bulky = new ToolOutput(
            text: 'four',
            structuredContent: ['blob' => str_repeat('x', 4000)],
            meta: ['mcp' => ['isError' => false, 'resultMeta' => ['blob' => str_repeat('y', 4000)]]],
        );

        $counter = new TokenCounter();

        $this->assertSame(
            $counter->count(new ToolResultMessage([Tool::make('t', 'd')->setCallId('c')->setResult('four')])),
            $counter->count(new ToolResultMessage([Tool::make('t', 'd')->setCallId('c')->setResult($bulky)]))
        );
    }

    /**
     * The tool of a persisted tool call and the tool of its result, reloaded.
     *
     * @return array{Tool, Tool}
     */
    protected function reloadedTools(): array
    {
        $messages = (new FileChatHistory(__DIR__, $this->key))->getMessages();

        if (! $messages[0] instanceof ToolCallMessage || ! $messages[1] instanceof ToolResultMessage) {
            self::fail('Expected a tool call followed by its result.');
        }

        /** @var Tool $called */
        $called = $messages[0]->getTools()[0];
        /** @var Tool $returned */
        $returned = $messages[1]->getTools()[0];

        return [$called, $returned];
    }
}
