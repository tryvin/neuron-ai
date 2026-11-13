<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS;

use Aws\Api\Parser\EventParsingIterator;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class BedrockStreamStopReasonTest extends TestCase
{
    public function test_stream_reports_max_tokens_stop_reason(): void
    {
        $events = [
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['text' => 'Hello'],
                ],
            ],
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['text' => ' world'],
                ],
            ],
            ['messageStop' => ['stopReason' => 'max_tokens']],
            ['metadata' => ['usage' => ['inputTokens' => 10, 'outputTokens' => 8]]],
        ];

        $stream = $this->provider($events, false)->stream(new UserMessage('Hi'));
        $chunks = iterator_to_array($stream, false);
        $message = $stream->getReturn();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('max_tokens', $message->stopReason());
        $this->assertCount(2, $chunks);
        $this->assertSame('Hello world', $message->getContent());
        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(8, $message->getUsage()->outputTokens);
    }

    public function test_stream_reports_tool_use_stop_reason(): void
    {
        $events = [
            [
                'contentBlockStart' => [
                    'contentBlockIndex' => 0,
                    'start' => [
                        'toolUse' => [
                            'toolUseId' => 'call-1',
                            'name' => 'lookup',
                        ],
                    ],
                ],
            ],
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['toolUse' => ['input' => '{"q":"paris"}']],
                ],
            ],
            ['contentBlockStop' => ['contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'tool_use']],
        ];

        $stream = $this->provider($events, true)->stream(new UserMessage('Hi'));
        iterator_to_array($stream);
        $message = $stream->getReturn();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('tool_use', $message->stopReason());
        $this->assertSame('call-1', $message->getTools()[0]->getCallId());
        $this->assertSame(['q' => 'paris'], $message->getTools()[0]->getInputs());
    }

    public function test_stream_stop_reason_is_null_without_message_stop(): void
    {
        $events = [
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['text' => 'Cut off'],
                ],
            ],
            ['metadata' => ['usage' => ['inputTokens' => 3, 'outputTokens' => 2]]],
        ];

        $stream = $this->provider($events, false)->stream(new UserMessage('Hi'));
        iterator_to_array($stream);
        $message = $stream->getReturn();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNull($message->stopReason());
        $this->assertSame('Cut off', $message->getContent());
        $this->assertSame(3, $message->getUsage()->inputTokens);
        $this->assertSame(2, $message->getUsage()->outputTokens);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected function provider(array $events, bool $withTool): BedrockRuntime
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseStream'])
            ->getMock();

        $bedrockClient->expects($this->once())
            ->method('converseStream')
            ->willReturn(new Result(['stream' => $this->eventIterator($events)]));

        $provider = new BedrockRuntime($bedrockClient, 'model-stream');

        if ($withTool) {
            $provider->setTools([Tool::make('lookup', 'Look it up')]);
        }

        return $provider;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected function eventIterator(array $events): EventParsingIterator
    {
        return new class ($events) extends EventParsingIterator {
            protected int $position = 0;

            /**
             * @param array<int, array<string, mixed>> $events
             */
            public function __construct(
                protected array $events,
            ) {
            }

            public function current(): mixed
            {
                return $this->events[$this->position];
            }

            public function key(): int
            {
                return $this->position;
            }

            public function next(): void
            {
                $this->position++;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function valid(): bool
            {
                return isset($this->events[$this->position]);
            }
        };
    }
}
