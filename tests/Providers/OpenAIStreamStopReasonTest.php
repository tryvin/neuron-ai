<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class OpenAIStreamStopReasonTest extends TestCase
{
    public function test_stream_reports_length_stop_reason(): void
    {
        $body = "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n";
        $body .= "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\" there\"},\"finish_reason\":null}]}\n\n";
        $body .= "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"length\"}]}\n\n";
        $body .= "data: {\"id\":\"chatcmpl-123\",\"choices\":[],\"usage\":{\"prompt_tokens\":8,\"completion_tokens\":3}}\n\n";
        $body .= "data: [DONE]\n\n";

        $stream = $this->stream($body);
        $chunks = iterator_to_array($stream, false);
        $message = $stream->getReturn();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNotInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('length', $message->stopReason());
        $this->assertSame('Hello there', $message->getContent());
        $this->assertCount(2, $chunks);
        $this->assertContainsOnlyInstancesOf(TextChunk::class, $chunks);
        $this->assertSame(8, $message->getUsage()->inputTokens);
        $this->assertSame(3, $message->getUsage()->outputTokens);
    }

    public function test_stream_reports_tool_calls_stop_reason(): void
    {
        $body = "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call-123\",\"type\":\"function\",\"function\":{\"name\":\"lookup\",\"arguments\":\"{\\\"q\\\":\\\"paris\\\"}\"}}]},\"finish_reason\":null}]}\n\n";
        $body .= "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n";
        $body .= "data: [DONE]\n\n";

        $stream = $this->stream($body, true);
        iterator_to_array($stream, false);
        $message = $stream->getReturn();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('tool_calls', $message->stopReason());
        $this->assertSame('call-123', $message->getTools()[0]->getCallId());
        $this->assertSame(['q' => 'paris'], $message->getTools()[0]->getInputs());
    }

    public function test_stream_stop_reason_is_null_without_finish_reason(): void
    {
        $body = "data: {\"id\":\"chatcmpl-123\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"},\"finish_reason\":null}]}\n\n";
        $body .= "data: {\"id\":\"chatcmpl-123\",\"choices\":[],\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":2}}\n\n";
        $body .= "data: [DONE]\n\n";

        $stream = $this->stream($body);
        iterator_to_array($stream, false);
        $message = $stream->getReturn();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertNull($message->stopReason());
        $this->assertSame('Hello', $message->getContent());
        $this->assertSame(5, $message->getUsage()->inputTokens);
        $this->assertSame(2, $message->getUsage()->outputTokens);
    }

    /**
     * @return Generator<int, StreamChunk, mixed, Message>
     */
    protected function stream(string $body, bool $withTool = false): Generator
    {
        $provider = (new OpenAI('', 'gpt-4o'))
            ->setHttpClient(new GuzzleHttpClient(
                handler: HandlerStack::create(new MockHandler([
                    new Response(status: 200, body: $body),
                ]))
            ));

        if ($withTool) {
            $provider->setTools([Tool::make('lookup', 'Look it up')]);
        }

        return $provider->stream(new UserMessage('Hi'));
    }
}
