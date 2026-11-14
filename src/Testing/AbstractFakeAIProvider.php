<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\Assert;
use Throwable;

use function array_filter;
use function array_map;
use function array_shift;
use function array_values;
use function count;
use function implode;
use function is_array;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function uniqid;
use function var_export;

/**
 * Queue/record/assert behaviour shared by the testing provider fakes.
 *
 * Use FakeAIProvider for a fake that also supports tool choice, and
 * FakeAIProvider::withoutToolChoiceSupport() for one that cannot receive it.
 */
abstract class AbstractFakeAIProvider implements AIProviderInterface
{
    use StaticConstructor;
    use HandleWithTools;

    protected ?string $systemPrompt = null;

    /** @var array<Message|Throwable|StreamingResponse> */
    protected array $responseQueue;

    /** @var RequestRecord[] */
    protected array $recorded = [];

    protected int $streamChunkSize = 5;

    protected ?string $streamMessageId = null;

    protected bool $streamFailuresEager = false;

    /**
     * @param Message|Throwable ...$responses Predetermined responses to return sequentially.
     *        A Throwable thrown by chat()/structured() simulates provider failures at call
     *        time; in stream() it is thrown lazily on first iteration, matching real providers.
     */
    public function __construct(Message|Throwable ...$responses)
    {
        $this->responseQueue = $responses;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * @throws ProviderException
     * @throws Throwable The queued exception, if the next queue item is a Throwable
     */
    public function chat(Message ...$messages): Message
    {
        $response = $this->nextResponse();

        $this->recorded[] = new RequestRecord(
            method: 'chat',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
            toolChoice: $this->toolChoiceForRecord(),
        );

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($response instanceof StreamingResponse) {
            return $response->message;
        }

        return $response;
    }

    /**
     * @return Generator<int, TextChunk|ReasoningChunk|ToolCallChunk, mixed, Message>
     * @throws ProviderException
     * @throws Throwable The queued exception, on first iteration (unless failures are eager)
     */
    public function stream(Message ...$messages): Generator
    {
        // Eagerly shift from queue and record before returning the generator,
        // because generator bodies don't execute until iterated.
        $response = $this->nextResponse();

        $this->recorded[] = new RequestRecord(
            method: 'stream',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
            toolChoice: $this->toolChoiceForRecord(),
        );

        if ($this->streamFailuresEager && $response instanceof Throwable) {
            throw $response;
        }

        return $this->streamChunks($response);
    }

    /**
     * @return Generator<int, TextChunk|ReasoningChunk|ToolCallChunk, mixed, Message>
     */
    protected function streamChunks(Message|Throwable|StreamingResponse $response): Generator
    {
        if ($response instanceof StreamingResponse) {
            if ($response->failure === null) {
                return yield from $this->messageChunks($response->message);
            }

            return yield from $this->failAfterChunks($response->failAfterChunks, $response->message, $response->failure);
        }

        if ($response instanceof Throwable) {
            // Lazy failure: real providers throw when iteration starts, not
            // when the stream is opened.
            return yield from $this->failAfterChunks(0, null, $response);
        }

        return yield from $this->messageChunks($response);
    }

    /**
     * Stream the message's chunks, then never return: the failure is thrown.
     *
     * @return Generator<int, TextChunk|ReasoningChunk|ToolCallChunk, mixed, Message>
     */
    protected function failAfterChunks(int $failAfterChunks, ?Message $message, Throwable $failure): Generator
    {
        $yielded = 0;

        if ($message !== null) {
            foreach ($this->messageChunks($message) as $chunk) {
                if ($yielded >= $failAfterChunks) {
                    break;
                }
                yield $chunk;
                $yielded++;
            }
        }

        throw $failure;
    }

    /**
     * @return Generator<int, TextChunk|ReasoningChunk|ToolCallChunk, mixed, Message>
     */
    protected function messageChunks(Message $response): Generator
    {
        $messageId = $this->streamMessageId ?? uniqid('fake_msg_');

        // Reasoning blocks stream before the visible text, as real providers do.
        foreach ($response->getContentBlocks() as $block) {
            if ($block instanceof ReasoningContent) {
                yield new ReasoningChunk($messageId, $block->content);
            }
        }

        $text = $response->getContent() ?? '';
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $chunk = mb_substr($text, $offset, $this->streamChunkSize);
            yield new TextChunk($messageId, $chunk);
            $offset += $this->streamChunkSize;
        }

        // Tool call messages stream one chunk per requested tool, mirroring
        // the shape real providers use mid-stream.
        if ($response instanceof ToolCallMessage) {
            foreach ($response->getTools() as $tool) {
                yield new ToolCallChunk($tool);
            }
        }

        return $response;
    }

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     * @throws ProviderException
     * @throws Throwable The queued exception, if the next queue item is a Throwable
     */
    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $messages = is_array($messages) ? $messages : [$messages];

        $response = $this->nextResponse();

        $this->recorded[] = new RequestRecord(
            method: 'structured',
            messages: $messages,
            systemPrompt: $this->systemPrompt,
            tools: $this->tools,
            structuredClass: $class,
            structuredSchema: $response_schema,
            toolChoice: $this->toolChoiceForRecord(),
        );

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($response instanceof StreamingResponse) {
            return $response->message;
        }

        return $response;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new FakeMessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new FakeToolMapper();
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    /**
     * Add more responses to the queue.
     *
     * @param Message|Throwable ...$responses A Throwable thrown by chat()/structured()
     *        at call time and by stream() on first iteration.
     */
    public function addResponses(Message|Throwable ...$responses): self
    {
        foreach ($responses as $response) {
            $this->responseQueue[] = $response;
        }
        return $this;
    }

    /**
     * Queue a stream response that yields chunks of the message and then fails.
     *
     * failAfterChunks: 0 with a failure dies before the first chunk; with a null
     * failure the message streams to completion like addResponses().
     *
     * The failure is thrown on iteration, not at stream() call time, so recovery
     * paths that only trigger after a chunk has been yielded can be tested.
     */
    public function addStreamingResponse(Message $message, ?Throwable $failure = null, int $failAfterChunks = 0): self
    {
        $this->responseQueue[] = new StreamingResponse($message, $failure, $failAfterChunks);
        return $this;
    }

    /**
     * Queue a tool call response, as if the model asked to call a tool.
     *
     * @param array<string, mixed> $inputs The arguments the model passes to the tool
     */
    public function addToolCallResponse(string $toolName, array $inputs = []): self
    {
        return $this->addResponses(
            new ToolCallMessage(null, [Tool::make($toolName)->setInputs($inputs)])
        );
    }

    /**
     * Queue a structured output response by encoding the given data as JSON.
     *
     * The encoded message still goes through the real deserializer and validator,
     * so use values that satisfy the target class.
     *
     * @param object|array<string, mixed> $data
     */
    public function addStructuredResponse(object|array $data): self
    {
        return $this->addResponses(
            new AssistantMessage((string) json_encode($data))
        );
    }

    /**
     * Set the chunk size for stream simulation.
     */
    public function setStreamChunkSize(int $size): self
    {
        $this->streamChunkSize = $size;
        return $this;
    }

    /**
     * Set a deterministic message ID for stream chunks. Use it when tests
     * assert chunk IDs or snapshot streamed output; IDs are random otherwise.
     */
    public function setStreamMessageId(string $messageId): self
    {
        $this->streamMessageId = $messageId;
        return $this;
    }

    /**
     * Opt in to throwing queued stream failures at stream() call time instead
     * of on first iteration. Lazy is the default because real providers fail
     * once the response body starts being read.
     */
    public function setStreamFailuresEager(bool $eager = true): self
    {
        $this->streamFailuresEager = $eager;
        return $this;
    }

    /**
     * @param string|null $method Filter records by method: 'chat', 'stream', or 'structured'
     * @return RequestRecord[]
     */
    public function getRecorded(?string $method = null): array
    {
        if ($method === null) {
            return $this->recorded;
        }

        return array_values(array_filter(
            $this->recorded,
            static fn (RequestRecord $record): bool => $record->method === $method
        ));
    }

    public function getCallCount(): int
    {
        return count($this->recorded);
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    public function assertCallCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->recorded,
            "Expected {$expected} provider calls, got " . count($this->recorded) . '.'
        );
    }

    /**
     * Assert that at least one recorded call matches the given callback.
     *
     * @param callable(RequestRecord): bool $callback
     */
    public function assertSent(callable $callback): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            if ($callback($record)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request matched the given assertion callback.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no provider calls, but ' . count($this->recorded) . ' were recorded.'
        );
    }

    /**
     * Assert every queued response was consumed. Catches tests that queue more
     * responses than the agent actually needed.
     */
    public function assertAllResponsesConsumed(): void
    {
        $remaining = count($this->responseQueue);

        Assert::assertSame(
            0,
            $remaining,
            "Expected all queued responses to be consumed, but {$remaining} were left."
        );
    }

    /**
     * Assert a specific method was called a given number of times.
     */
    public function assertMethodCallCount(string $method, int $expected): void
    {
        $count = 0;
        foreach ($this->recorded as $record) {
            if ($record->method === $method) {
                $count++;
            }
        }

        Assert::assertSame(
            $expected,
            $count,
            "Expected {$expected} '{$method}' calls, got {$count}."
        );
    }

    /**
     * Assert the system prompt was set to the expected value on at least one call.
     */
    public function assertSystemPrompt(string $expected): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            if ($record->systemPrompt === $expected) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request had the expected system prompt.');
    }

    /**
     * Assert that tools were configured by name on at least one call.
     *
     * @param string[] $toolNames
     */
    public function assertToolsConfigured(array $toolNames): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            $recordToolNames = array_values(array_map(
                static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(),
                $record->tools
            ));

            if (array_values($toolNames) === $recordToolNames) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request had the expected tools configured: ' . implode(', ', $toolNames));
    }

    /**
     * Assert a tool choice was set on at least one call.
     *
     * @param string|array{type: string, name?: string} $expected
     */
    public function assertToolChoice(string|array $expected): void
    {
        $matched = false;

        foreach ($this->recorded as $record) {
            if ($record->toolChoice === $expected) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No recorded request had the expected tool choice.');
    }

    /**
     * Assert the exact tool choice sequence across all recorded calls, in order.
     * Pass null for calls that must carry no choice:
     *
     *     $provider->assertToolChoices(null, 'none');
     *
     * @param string|array{type: string, name?: string}|null ...$expected
     */
    public function assertToolChoices(string|array|null ...$expected): void
    {
        $actual = array_map(
            static fn (RequestRecord $record): string|array|null => $record->toolChoice,
            $this->recorded
        );

        Assert::assertSame(
            $expected,
            $actual,
            'Expected the recorded tool choices to match the exact sequence.'
        );
    }

    /**
     * Assert the record at the given index carries no tool choice.
     */
    public function assertNoToolChoice(int $index = 0): void
    {
        Assert::assertArrayHasKey(
            $index,
            $this->recorded,
            "Expected at least " . ($index + 1) . " recorded call(s)."
        );

        Assert::assertNull(
            $this->recorded[$index]->toolChoice,
            "Expected record {$index} to carry no tool choice, got " . var_export($this->recorded[$index]->toolChoice, true) . '.'
        );
    }

    /**
     * @return Message|Throwable|StreamingResponse
     */
    protected function nextResponse(): Message|Throwable|StreamingResponse
    {
        if ($this->responseQueue === []) {
            throw new ProviderException(
                'FakeAIProvider response queue is empty. Add more responses with addResponses() or pass them to the constructor.'
            );
        }

        return array_shift($this->responseQueue);
    }

    /**
     * The tool choice to record for each call. Overridden by fakes that
     * support the capability.
     *
     * @return string|array{type: string, name?: string}|null
     */
    protected function toolChoiceForRecord(): string|array|null
    {
        return null;
    }
}
