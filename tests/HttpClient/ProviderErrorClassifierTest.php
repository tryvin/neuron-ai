<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\ContextWindowExceededException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\ProviderErrorClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ProviderErrorClassifierTest extends TestCase
{
    #[DataProvider('overflowResponses')]
    public function test_classifies_provider_overflow_responses(
        int $statusCode,
        string $body,
        ?int $promptTokens,
        ?int $maxContextTokens,
        ?string $providerCode,
    ): void {
        $request = HttpRequest::post('chat/completions');
        $response = new HttpResponse($statusCode, $body);

        $exception = ProviderErrorClassifier::classify($request, $response);

        $this->assertInstanceOf(ContextWindowExceededException::class, $exception);
        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertSame($promptTokens, $exception->promptTokens);
        $this->assertSame($maxContextTokens, $exception->maxContextTokens);
        $this->assertSame($providerCode, $exception->providerCode);
        $this->assertSame(
            "HTTP {$statusCode} error during POST chat/completions: {$body}",
            $exception->getMessage()
        );
        $this->assertSame($request, $exception->request);
        $this->assertSame($response, $exception->response);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: ?int, 3: ?int, 4: ?string}>
     */
    public static function overflowResponses(): array
    {
        return [
            'openai context length' => [
                400,
                (string) json_encode([
                    'error' => [
                        'message' => "This model's maximum context length is 128000 tokens. However, your messages resulted in 130000 tokens.",
                        'type' => 'invalid_request_error',
                        'code' => 'context_length_exceeded',
                    ],
                ]),
                130000,
                128000,
                'context_length_exceeded',
            ],
            'openai responses context length' => [
                400,
                (string) json_encode([
                    'error' => [
                        'code' => 'context_length_exceeded',
                        'message' => 'Input exceeds the configured context window.',
                    ],
                ]),
                null,
                null,
                'context_length_exceeded',
            ],
            'anthropic prompt too long' => [
                400,
                (string) json_encode([
                    'type' => 'error',
                    'error' => [
                        'type' => 'invalid_request_error',
                        'message' => 'prompt is too long: 2134 tokens > 2000 maximum',
                    ],
                ]),
                2134,
                2000,
                'invalid_request_error',
            ],
            'bedrock input too long' => [
                400,
                (string) json_encode([
                    'message' => 'Input is too long for requested model',
                ]),
                null,
                null,
                null,
            ],
            'bedrock too many input tokens' => [
                400,
                (string) json_encode([
                    'message' => 'Too many input tokens',
                ]),
                null,
                null,
                null,
            ],
            'gemini invalid argument' => [
                400,
                (string) json_encode([
                    'error' => [
                        'code' => 400,
                        'message' => 'The input token count (1048577) exceeds the maximum number of tokens allowed (1048576).',
                        'status' => 'INVALID_ARGUMENT',
                    ],
                ]),
                1048577,
                1048576,
                'INVALID_ARGUMENT',
            ],
            'mistral input too large' => [
                400,
                (string) json_encode([
                    'message' => 'Input too large for model',
                ]),
                null,
                null,
                null,
            ],
        ];
    }

    #[DataProvider('inStreamOverflowEvents')]
    public function test_classifies_in_stream_overflow_events(
        string $body,
        ?int $promptTokens,
        ?int $maxContextTokens,
        ?string $providerCode,
    ): void {
        $request = HttpRequest::post('chat/completions');

        $exception = ProviderErrorClassifier::classifyStreamError($request, $body);

        $this->assertInstanceOf(ContextWindowExceededException::class, $exception);
        $this->assertSame($promptTokens, $exception->promptTokens);
        $this->assertSame($maxContextTokens, $exception->maxContextTokens);
        $this->assertSame($providerCode, $exception->providerCode);
        $this->assertSame($request, $exception->request);
        $this->assertSame(200, $exception->response->statusCode);
        $this->assertSame($body, $exception->response->body);
    }

    /**
     * @return array<string, array{0: string, 1: ?int, 2: ?int, 3: ?string}>
     */
    public static function inStreamOverflowEvents(): array
    {
        return [
            'openai sse error' => [
                (string) json_encode([
                    'error' => [
                        'message' => "This model's maximum context length is 8192 tokens. However, your messages resulted in 9000 tokens.",
                        'code' => 'context_length_exceeded',
                    ],
                ]),
                9000,
                8192,
                'context_length_exceeded',
            ],
            'anthropic error event' => [
                (string) json_encode([
                    'type' => 'error',
                    'error' => [
                        'type' => 'invalid_request_error',
                        'message' => 'prompt is too long: 2134 tokens > 2000 maximum',
                    ],
                ]),
                2134,
                2000,
                'invalid_request_error',
            ],
            'gemini error event' => [
                (string) json_encode([
                    'error' => [
                        'code' => 400,
                        'message' => 'The input token count (1048577) exceeds the maximum number of tokens allowed (1048576).',
                        'status' => 'INVALID_ARGUMENT',
                    ],
                ]),
                1048577,
                1048576,
                'INVALID_ARGUMENT',
            ],
            'bedrock exception event' => [
                (string) json_encode([
                    'validationException' => [
                        'message' => 'Input is too long for requested model',
                    ],
                ]),
                null,
                null,
                null,
            ],
        ];
    }

    #[DataProvider('nonOverflowResponses')]
    public function test_does_not_classify_non_overflow_errors(int $statusCode, string $body): void
    {
        $request = HttpRequest::post('chat/completions');
        $response = new HttpResponse($statusCode, $body);

        $this->assertNull(ProviderErrorClassifier::classify($request, $response));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function nonOverflowResponses(): array
    {
        return [
            'rate limit' => [
                429,
                (string) json_encode([
                    'error' => [
                        'message' => 'Rate limit reached. Please try again in 20ms. Limit: 200000 tokens per minute.',
                        'code' => 'rate_limit_exceeded',
                    ],
                ]),
            ],
            'quota exceeded on validation status' => [
                400,
                (string) json_encode([
                    'error' => [
                        'message' => 'You exceeded your current quota.',
                        'code' => 'insufficient_quota',
                    ],
                ]),
            ],
            'throttling' => [
                400,
                (string) json_encode([
                    'message' => 'ThrottlingException: Too many requests',
                ]),
            ],
            'generic validation error' => [
                400,
                (string) json_encode([
                    'error' => [
                        'message' => 'messages.0.content must be a string',
                        'type' => 'invalid_request_error',
                    ],
                ]),
            ],
            'server error with overflow wording' => [
                503,
                (string) json_encode([
                    'message' => 'Input is too long for requested model',
                ]),
            ],
            'string above max length' => [
                400,
                (string) json_encode([
                    'error' => [
                        'code' => 'string_above_max_length',
                        'message' => 'The string exceeds the maximum length.',
                    ],
                ]),
            ],
        ];
    }

    public function test_does_not_classify_non_overflow_stream_errors(): void
    {
        $request = HttpRequest::post('chat/completions');
        $bodies = [
            (string) json_encode([
                'error' => [
                    'message' => 'Rate limit reached: 200000 tokens per minute.',
                    'code' => 'rate_limit_exceeded',
                ],
            ]),
            (string) json_encode([
                'error' => [
                    'message' => 'You exceeded your current quota.',
                    'code' => 'insufficient_quota',
                ],
            ]),
            (string) json_encode([
                'message' => 'ThrottlingException: Too many requests',
            ]),
            (string) json_encode([
                'error' => [
                    'code' => 'string_above_max_length',
                    'message' => 'The string exceeds the maximum length.',
                ],
            ]),
        ];

        foreach ($bodies as $body) {
            $this->assertNull(ProviderErrorClassifier::classifyStreamError($request, $body));
        }
    }
}
