<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Testing\FakeHttpClient;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Stream handlers must drain the usage-only tail that providers emit after
 * the tool-call finish reason, or streamed tool-call steps bill as zero tokens.
 */
class StreamUsageDrainTest extends TestCase
{
    protected function withSearchTool(object $provider): object
    {
        return $provider->setTools([Tool::make('search', 'Look it up')]);
    }

    protected function streamedMessage(object $provider): Message
    {
        $stream = $provider->stream(new UserMessage('Hi'));

        foreach ($stream as $chunk) {
            // Nothing content-bearing follows the finish reason; chunks are
            // irrelevant to the billing assertions under test.
        }

        return $stream->getReturn();
    }

    public function test_openai_drains_usage_after_tool_call_finish_reason(): void
    {
        $client = FakeHttpClient::make()->queueSse(
            'data: {"id":"c1","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search","arguments":"{}"}}]}}]}',
            'data: {"id":"c1","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}',
            'data: {"id":"c1","choices":[],"usage":{"prompt_tokens":11,"completion_tokens":7}}',
        );

        $provider = $this->withSearchTool(new OpenAI('test-key', 'gpt-4o'));
        $provider->setHttpClient($client);

        $message = $this->streamedMessage($provider);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertNotNull($message->getUsage());
        $this->assertSame(11, $message->getUsage()->inputTokens);
        $this->assertSame(7, $message->getUsage()->outputTokens);
    }

    public function test_openai_tool_call_stream_without_usage_tail_is_unchanged(): void
    {
        $client = FakeHttpClient::make()->queueSse(
            'data: {"id":"c1","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search","arguments":"{}"}}]}}]}',
            'data: {"id":"c1","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}',
        );

        $provider = $this->withSearchTool(new OpenAI('test-key', 'gpt-4o'));
        $provider->setHttpClient($client);

        $message = $this->streamedMessage($provider);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('search', $message->getTools()[0]->getName());
        $this->assertSame(0, $message->getUsage()->inputTokens);
        $this->assertSame(0, $message->getUsage()->outputTokens);
    }

    public function test_gemini_drains_usage_after_tool_call_finish_reason(): void
    {
        $client = FakeHttpClient::make()->queueSse(
            '{"candidates":[{"content":{"parts":[{"functionCall":{"name":"search","args":{}}}]},"role":"model"}]}',
            '{"candidates":[{"content":{},"finishReason":"STOP","role":"model"}]}',
            '{"usageMetadata":{"promptTokenCount":9,"candidatesTokenCount":5,"cachedContentTokenCount":1,"thoughtsTokenCount":2}}',
        );

        $provider = $this->withSearchTool(new Gemini('test-key', 'gemini-2.0-flash'));
        $provider->setHttpClient($client);

        $message = $this->streamedMessage($provider);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertNotNull($message->getUsage());
        $this->assertSame(9, $message->getUsage()->inputTokens);
        $this->assertSame(5, $message->getUsage()->outputTokens);
    }

    public function test_gemini_tool_call_stream_without_usage_tail_is_unchanged(): void
    {
        $client = FakeHttpClient::make()->queueSse(
            '{"candidates":[{"content":{"parts":[{"functionCall":{"name":"search","args":{}}}]},"role":"model"}]}',
            '{"candidates":[{"content":{},"finishReason":"STOP","role":"model"}]}',
        );

        $provider = $this->withSearchTool(new Gemini('test-key', 'gemini-2.0-flash'));
        $provider->setHttpClient($client);

        $message = $this->streamedMessage($provider);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('search', $message->getTools()[0]->getName());
        $this->assertSame(0, $message->getUsage()->inputTokens);
        $this->assertSame(0, $message->getUsage()->outputTokens);
    }

    public function test_mistral_drains_usage_after_tool_call_finish_reason(): void
    {
        $client = FakeHttpClient::make()->queueSse(
            'data: {"id":"m1","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search","arguments":"{}"}}]}}]}',
            'data: {"id":"m1","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search","arguments":"{}"}}]},"finish_reason":"tool_calls"}]}',
            'data: {"id":"m1","choices":[],"usage":{"prompt_tokens":13,"completion_tokens":4}}',
        );

        $provider = $this->withSearchTool(new Mistral('test-key', 'mistral-large-latest'));
        $provider->setHttpClient($client);

        $message = $this->streamedMessage($provider);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertNotNull($message->getUsage());
        $this->assertSame(13, $message->getUsage()->inputTokens);
        $this->assertSame(4, $message->getUsage()->outputTokens);
    }

    public function test_mistral_tool_call_stream_without_usage_tail_is_unchanged(): void
    {
        $client = FakeHttpClient::make()->queueSse(
            'data: {"id":"m1","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search","arguments":"{}"}}]}}]}',
            'data: {"id":"m1","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search","arguments":"{}"}}]},"finish_reason":"tool_calls"}]}',
        );

        $provider = $this->withSearchTool(new Mistral('test-key', 'mistral-large-latest'));
        $provider->setHttpClient($client);

        $message = $this->streamedMessage($provider);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('search', $message->getTools()[0]->getName());
        $this->assertSame(0, $message->getUsage()->inputTokens);
        $this->assertSame(0, $message->getUsage()->outputTokens);
    }
}
