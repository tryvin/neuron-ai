<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Aws\Api\Parser\EventParsingIterator;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\FulfilledPromise;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Testing\FakeHttpClient;
use PHPUnit\Framework\TestCase;

use function implode;
use function iterator_to_array;

/**
 * Verifies that each provider surfaces the cache-write token count from its
 * usage payload onto `Usage::$cacheWriteTokens`, without changing how
 * inputTokens / cachedInputTokens / outputTokens are reported.
 */
class CacheWriteTokensTest extends TestCase
{
    private function client(string $body): GuzzleHttpClient
    {
        return new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([
                new Response(status: 200, body: $body),
            ]))
        );
    }

    public function test_anthropic_chat_sums_all_cache_creation_buckets(): void
    {
        $body = '{"model":"claude-3-7-sonnet-latest","role":"assistant",'
            .'"stop_reason":"end_turn","content":[{"type":"text","text":"hi"}],'
            .'"usage":{"input_tokens":100,"output_tokens":20,'
            .'"cache_read_input_tokens":30,"cache_creation_input_tokens":10,'
            .'"cache_creation":{"ephemeral_5m_input_tokens":30,"ephemeral_1h_input_tokens":20}}}';

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        // 10 + 30 + 20
        $this->assertSame(60, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(30, $usage->cachedInputTokens);
        $this->assertSame(20, $usage->outputTokens);
    }

    public function test_anthropic_chat_defaults_to_zero_without_cache_fields(): void
    {
        $body = '{"model":"claude-3-7-sonnet-latest","role":"assistant",'
            .'"stop_reason":"end_turn","content":[{"type":"text","text":"hi"}],'
            .'"usage":{"input_tokens":100,"output_tokens":20}}';

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(20, $usage->outputTokens);
    }

    public function test_anthropic_stream_mirrors_cache_writes_into_usage(): void
    {
        $streamBody = implode("\n", [
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_123","type":"message","role":"assistant","content":[],"usage":{"input_tokens":100,"output_tokens":0,"cache_creation":{"ephemeral_5m_input_tokens":50},"cache_read_input_tokens":30}}}',
            '',
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":5}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ]);

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))->setHttpClient($this->client($streamBody));

        /** @var Generator<mixed, mixed, mixed, AssistantMessage> $stream */
        $stream = $provider->stream(new UserMessage('Test'));
        iterator_to_array($stream, false);
        $message = $stream->getReturn();
        $usage = $message->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(50, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(30, $usage->cachedInputTokens);
        $this->assertSame(5, $usage->outputTokens);
        // Metadata mirror kept for existing consumers.
        $this->assertSame('50', $message->getMetadata('cacheWriteTokens'));
    }

    public function test_bedrock_chat_reports_cache_write_input_tokens(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $result = new Result([
            'usage' => [
                'inputTokens' => 10,
                'outputTokens' => 5,
                'cacheReadInputTokens' => 4,
                'cacheWriteInputTokens' => 6,
            ],
            'output' => ['message' => ['content' => [['text' => 'Hello']]]],
            'stopReason' => 'end_turn',
        ]);

        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->willReturn(new FulfilledPromise($result));

        $provider = new BedrockRuntime($bedrockClient, 'model-x');
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(6, $usage->cacheWriteTokens);
        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(4, $usage->cachedInputTokens);
        $this->assertSame(5, $usage->outputTokens);
    }

    public function test_bedrock_chat_defaults_to_zero_without_cache_fields(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $result = new Result([
            'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
            'output' => ['message' => ['content' => [['text' => 'Hello']]]],
            'stopReason' => 'end_turn',
        ]);

        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->willReturn(new FulfilledPromise($result));

        $provider = new BedrockRuntime($bedrockClient, 'model-x');
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->cacheWriteTokens);
        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(5, $usage->outputTokens);
    }

    public function test_bedrock_stream_accumulates_cache_write_input_tokens(): void
    {
        $events = [
            [
                'contentBlockDelta' => [
                    'contentBlockIndex' => 0,
                    'delta' => ['text' => 'Hello'],
                ],
            ],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => [
                'inputTokens' => 10,
                'outputTokens' => 8,
                'cacheReadInputTokens' => 4,
                'cacheWriteInputTokens' => 6,
            ]]],
        ];

        $stream = $this->bedrockStreamProvider($events)->stream(new UserMessage('Hi'));
        iterator_to_array($stream, false);
        $message = $stream->getReturn();
        $usage = $message->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(6, $usage->cacheWriteTokens);
        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(4, $usage->cachedInputTokens);
        $this->assertSame(8, $usage->outputTokens);
    }

    public function test_openai_chat_completions_reports_cache_write_tokens(): void
    {
        $body = '{"model":"gpt-5","choices":[{"index":0,"finish_reason":"stop",'
            .'"message":{"role":"assistant","content":"hi"}}],'
            .'"usage":{"prompt_tokens":100,"completion_tokens":20,"total_tokens":120,'
            .'"prompt_tokens_details":{"cached_tokens":40,"cache_write_tokens":25},'
            .'"completion_tokens_details":{"reasoning_tokens":12}}}';

        $provider = (new OpenAI('', 'gpt-5'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(25, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(20, $usage->outputTokens);
    }

    public function test_openai_chat_completions_defaults_to_zero_without_details(): void
    {
        $body = '{"model":"gpt-4o","choices":[{"index":0,"finish_reason":"stop",'
            .'"message":{"role":"assistant","content":"hi"}}],'
            .'"usage":{"prompt_tokens":19,"completion_tokens":10,"total_tokens":29}}';

        $provider = (new OpenAI('', 'gpt-4o'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->cacheWriteTokens);
        $this->assertSame(19, $usage->inputTokens);
        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(10, $usage->outputTokens);
    }

    public function test_openai_stream_reports_cache_write_tokens(): void
    {
        $client = FakeHttpClient::make()->queueSse(
            'data: {"id":"c1","choices":[{"index":0,"delta":{"role":"assistant","content":"hi"}}]}',
            'data: {"id":"c1","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}',
            'data: {"id":"c1","choices":[],"usage":{"prompt_tokens":100,"completion_tokens":20,'
                .'"prompt_tokens_details":{"cached_tokens":40,"cache_write_tokens":25},'
                .'"completion_tokens_details":{"reasoning_tokens":12}}}',
        );

        $provider = (new OpenAI('test-key', 'gpt-5'))->setHttpClient($client);

        /** @var Generator<mixed, mixed, mixed, AssistantMessage> $stream */
        $stream = $provider->stream(new UserMessage('Hi'));
        iterator_to_array($stream, false);
        $usage = $stream->getReturn()->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(25, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(20, $usage->outputTokens);
    }

    public function test_openai_responses_reports_cache_write_tokens(): void
    {
        $body = '{"status":"completed","output":[{"type":"message",'
            .'"content":[{"type":"output_text","text":"hi"}]}],'
            .'"usage":{"input_tokens":100,"output_tokens":20,'
            .'"input_tokens_details":{"cached_tokens":40,"cache_write_tokens":25},'
            .'"output_tokens_details":{"reasoning_tokens":12}}}';

        $provider = (new OpenAIResponses('', 'gpt-5'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(25, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(20, $usage->outputTokens);
    }

    public function test_openai_responses_stream_reports_cache_write_tokens(): void
    {
        $body = "data: {\"type\":\"response.output_text.delta\",\"item_id\":\"msg_1\",\"delta\":\"hi\"}\n\n"
            . "data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\","
            . "\"output\":[{\"type\":\"message\",\"content\":[{\"type\":\"output_text\",\"text\":\"hi\"}]}],"
            . '"usage":{"input_tokens":100,"output_tokens":20,'
            .'"input_tokens_details":{"cached_tokens":40,"cache_write_tokens":25},'
            .'"output_tokens_details":{"reasoning_tokens":12}}}}' . "\n\n"
            . "data: [DONE]\n\n";

        $provider = (new OpenAIResponses('', 'gpt-5'))->setHttpClient($this->client($body));

        /** @var Generator<mixed, mixed, mixed, AssistantMessage> $stream */
        $stream = $provider->stream(new UserMessage('Hi'));
        iterator_to_array($stream, false);
        $usage = $stream->getReturn()->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(25, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(20, $usage->outputTokens);
    }

    public function test_openai_responses_defaults_to_zero_without_details(): void
    {
        $body = '{"status":"completed","output":[{"type":"message",'
            .'"content":[{"type":"output_text","text":"hi"}]}],'
            .'"usage":{"input_tokens":100,"output_tokens":20}}';

        $provider = (new OpenAIResponses('', 'gpt-5'))->setHttpClient($this->client($body));
        $usage = $provider->chat(new UserMessage('Hi'))->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->cacheWriteTokens);
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(20, $usage->outputTokens);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function bedrockStreamProvider(array $events): BedrockRuntime
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseStream'])
            ->getMock();

        $bedrockClient->expects($this->once())
            ->method('converseStream')
            ->willReturn(new Result(['stream' => $this->eventIterator($events)]));

        return new BedrockRuntime($bedrockClient, 'model-stream');
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function eventIterator(array $events): EventParsingIterator
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
