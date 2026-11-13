<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Aws\Api\Parser\EventParsingIterator;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Exception\EventStreamDataException;
use Aws\Result;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Response as PsrResponse;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ContextWindowExceededException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_encode;

class ContextWindowStreamTest extends TestCase
{
    public function test_openai_stream_raises_typed_exception_for_overflow_event(): void
    {
        $body = 'data: ' . json_encode([
            'error' => [
                'message' => "This model's maximum context length is 8192 tokens. However, your messages resulted in 9000 tokens.",
                'code' => 'context_length_exceeded',
            ],
        ]) . "\n\n";

        $generator = $this->provider(new OpenAI('', 'gpt-4o'), $body)
            ->stream(new UserMessage('Hi'));

        $this->expectException(ContextWindowExceededException::class);

        iterator_to_array($generator, false);
    }

    public function test_openai_responses_stream_raises_typed_exception_for_failed_event(): void
    {
        $body = 'data: ' . json_encode([
            'type' => 'response.failed',
            'response' => [
                'error' => [
                    'code' => 'context_length_exceeded',
                    'message' => 'Input exceeds the configured context window.',
                ],
            ],
        ]) . "\n\ndata: [DONE]\n\n";

        $generator = $this->provider(new OpenAIResponses('', 'gpt-5'), $body)
            ->stream(new UserMessage('Hi'));

        $this->expectException(ContextWindowExceededException::class);

        iterator_to_array($generator, false);
    }

    public function test_anthropic_stream_raises_typed_exception_for_error_event(): void
    {
        $body = 'data: ' . json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'prompt is too long: 2134 tokens > 2000 maximum',
            ],
        ]) . "\n\n";

        $generator = $this->provider(new Anthropic('', 'claude-sonnet-4-5'), $body)
            ->stream(new UserMessage('Hi'));

        $this->expectException(ContextWindowExceededException::class);

        iterator_to_array($generator, false);
    }

    public function test_gemini_stream_raises_typed_exception_for_error_event(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 400,
                'message' => 'The input token count (1048577) exceeds the maximum number of tokens allowed (1048576).',
                'status' => 'INVALID_ARGUMENT',
            ],
        ]);

        $generator = $this->provider(new Gemini('', 'gemini-2.0-flash'), (string) $body)
            ->stream(new UserMessage('Hi'));

        $this->expectException(ContextWindowExceededException::class);

        iterator_to_array($generator, false);
    }

    public function test_bedrock_stream_raises_typed_exception_for_exception_event(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseStream'])
            ->getMock();

        $bedrockClient->expects($this->once())
            ->method('converseStream')
            ->willReturn(new Result(['stream' => $this->throwingEventIterator()]));

        $stream = (new BedrockRuntime($bedrockClient, 'model-stream'))
            ->stream(new UserMessage('Hi'));

        $this->expectException(ContextWindowExceededException::class);

        $stream->current();
    }

    public function test_bedrock_chat_raises_typed_exception_for_validation_exception(): void
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $bedrockClient->expects($this->once())
            ->method('converseAsync')
            ->willReturn(new RejectedPromise(new AwsException(
                'Input is too long for requested model',
                $this->createMock(CommandInterface::class),
                [
                    'code' => 'ValidationException',
                    'message' => 'Input is too long for requested model',
                    'response' => new PsrResponse(400),
                ]
            )));

        $provider = new BedrockRuntime($bedrockClient, 'model-stream');

        $this->expectException(ContextWindowExceededException::class);

        $provider->chat(new UserMessage('Hi'));
    }

    protected function provider(AIProviderInterface $provider, string $body): AIProviderInterface
    {
        return $provider->setHttpClient(new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([
                new Response(status: 200, body: $body),
            ]))
        ));
    }

    protected function throwingEventIterator(): EventParsingIterator
    {
        return new class () extends EventParsingIterator {
            public function __construct()
            {
            }

            public function current(): mixed
            {
                throw new EventStreamDataException(
                    'ValidationException',
                    'Input is too long for requested model'
                );
            }

            public function key(): int
            {
                return 0;
            }

            public function next(): void
            {
            }

            public function rewind(): void
            {
            }

            public function valid(): bool
            {
                return true;
            }
        };
    }
}
