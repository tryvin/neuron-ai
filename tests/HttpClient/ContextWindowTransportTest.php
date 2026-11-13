<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\Client\Response as AmpResponse;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\ContextWindowExceededException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ContextWindowTransportTest extends TestCase
{
    protected string $overflowBody;

    protected string $validationBody;

    protected function setUp(): void
    {
        $this->overflowBody = (string) json_encode([
            'error' => [
                'message' => "This model's maximum context length is 8192 tokens. However, your messages resulted in 9000 tokens.",
                'code' => 'context_length_exceeded',
            ],
        ]);

        $this->validationBody = (string) json_encode([
            'error' => [
                'message' => 'messages.0.content must be a string',
                'type' => 'invalid_request_error',
            ],
        ]);
    }

    public function test_guzzle_request_raises_typed_exception_on_overflow(): void
    {
        $client = $this->guzzleClient(400, $this->overflowBody);

        try {
            $client->request(HttpRequest::post('chat/completions'));
            $this->fail('Expected ContextWindowExceededException');
        } catch (ContextWindowExceededException $e) {
            $this->assertSame(9000, $e->promptTokens);
            $this->assertSame(8192, $e->maxContextTokens);
            $this->assertSame('context_length_exceeded', $e->providerCode);
            $this->assertSame(400, $e->response?->statusCode);
            $this->assertStringStartsWith('HTTP 400 error during POST chat/completions:', $e->getMessage());
        }
    }

    public function test_guzzle_stream_raises_typed_exception_on_overflow(): void
    {
        $client = $this->guzzleClient(400, $this->overflowBody);

        $this->expectException(ContextWindowExceededException::class);

        $client->stream(HttpRequest::post('chat/completions'));
    }

    public function test_guzzle_ordinary_validation_error_stays_http_exception(): void
    {
        $client = $this->guzzleClient(400, $this->validationBody);

        try {
            $client->request(HttpRequest::post('chat/completions'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertNotInstanceOf(ContextWindowExceededException::class, $e);
            $this->assertStringContainsString('HTTP 400 error during POST chat/completions:', $e->getMessage());
        }
    }

    public function test_guzzle_rate_limit_with_tokens_wording_stays_http_exception(): void
    {
        $body = (string) json_encode([
            'error' => [
                'message' => 'Rate limit reached: 200000 tokens per minute. This model maximum context length is 8192 tokens.',
                'code' => 'rate_limit_exceeded',
            ],
        ]);
        $client = $this->guzzleClient(429, $body);

        try {
            $client->request(HttpRequest::post('chat/completions'));
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertNotInstanceOf(ContextWindowExceededException::class, $e);
        }
    }

    public function test_amp_request_raises_typed_exception_on_overflow(): void
    {
        $client = $this->ampClient(400, $this->overflowBody);

        try {
            $client->request(HttpRequest::post('chat/completions'));
            $this->fail('Expected ContextWindowExceededException');
        } catch (ContextWindowExceededException $e) {
            $this->assertSame(9000, $e->promptTokens);
            $this->assertSame(8192, $e->maxContextTokens);
            $this->assertSame('context_length_exceeded', $e->providerCode);
            $this->assertSame(400, $e->response?->statusCode);
        }
    }

    public function test_amp_stream_raises_typed_exception_on_overflow(): void
    {
        $client = $this->ampClient(400, $this->overflowBody);

        $this->expectException(ContextWindowExceededException::class);

        $client->stream(HttpRequest::post('chat/completions'));
    }

    public function test_amp_ordinary_validation_error_keeps_existing_response_behaviour(): void
    {
        $client = $this->ampClient(400, $this->validationBody);

        $response = $client->request(HttpRequest::post('chat/completions'));

        $this->assertSame(400, $response->statusCode);
        $this->assertSame($this->validationBody, $response->body);
    }

    public function test_amp_ordinary_validation_error_stream_keeps_body(): void
    {
        $client = $this->ampClient(400, $this->validationBody);

        $stream = $client->stream(HttpRequest::post('chat/completions'));

        $this->assertSame($this->validationBody, $this->readStream($stream));
    }

    protected function guzzleClient(int $statusCode, string $body): GuzzleHttpClient
    {
        return new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([
                new Response(status: $statusCode, body: $body),
            ]))
        );
    }

    protected function ampClient(int $statusCode, string $body): AmpHttpClient
    {
        $response = new AmpResponse(
            '1.1',
            $statusCode,
            'Test response',
            [],
            $body,
            new AmpRequest('https://api.example.com/chat/completions', 'POST'),
        );

        return new class ($response) extends AmpHttpClient {
            public function __construct(
                private readonly AmpResponse $response,
            ) {
            }

            protected function execute(HttpRequest $request): AmpResponse
            {
                return $this->response;
            }
        };
    }

    protected function readStream(StreamInterface $stream): string
    {
        $body = '';

        while (!$stream->eof()) {
            $body .= $stream->read(8192);
        }

        return $body;
    }
}
