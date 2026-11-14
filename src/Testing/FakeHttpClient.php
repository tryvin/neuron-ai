<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\StaticConstructor;
use PHPUnit\Framework\Assert;
use Throwable;

use function array_shift;
use function count;
use function implode;
use function json_encode;

/**
 * HTTP client fake for testing providers at the transport boundary.
 *
 * Queue responses (or exceptions) for request(), stream payloads for
 * stream(), then assert on the captured requests:
 *
 *     $client = FakeHttpClient::make()
 *         ->queueResponse(new HttpResponse(429, '{"error":{}}'))
 *         ->queueJson(['choices' => []]);
 *
 *     $provider = (new OpenAI($key, 'gpt-4o'))->setHttpClient($client);
 *
 * Queuing a Throwable makes the next call throw it, so retry and
 * fallback logic can be exercised without a real wire.
 */
class FakeHttpClient implements HttpClientInterface
{
    use StaticConstructor;

    /** @var array<int, HttpResponse|Throwable> */
    protected array $responses = [];

    /** @var array<int, string|Throwable|FakeStream> */
    protected array $streams = [];

    /** @var HttpRequest[] */
    protected array $requests = [];

    /**
     * Queue the response returned by the next request() call.
     */
    public function queueResponse(HttpResponse|Throwable $response): self
    {
        $this->responses[] = $response;
        return $this;
    }

    /**
     * Queue a JSON response with the given status code.
     *
     * @param array<string, mixed> $body
     */
    public function queueJson(array $body, int $statusCode = 200): self
    {
        return $this->queueResponse(
            new HttpResponse($statusCode, (string) json_encode($body))
        );
    }

    /**
     * Queue an HTTP failure for the next request() call. Shorthand for
     * queueResponse() with an HttpException carrying the status code.
     */
    public function queueFailure(int $statusCode, string $body = ''): self
    {
        return $this->queueResponse(
            new HttpException("HTTP {$statusCode}", response: new HttpResponse($statusCode, $body))
        );
    }

    /**
     * Queue the raw payload served by the next stream() call. Each argument
     * is one SSE event (e.g. "data: {...}"); events are joined by blank
     * lines as the SSE protocol separates them. Call with no arguments to
     * serve an immediately-ending stream.
     */
    public function queueSse(string ...$events): self
    {
        $this->streams[] = implode("\n\n", $events);
        return $this;
    }

    /**
     * Queue a Throwable to be thrown by the next stream() call.
     */
    public function queueStreamFailure(Throwable $exception): self
    {
        $this->streams[] = $exception;
        return $this;
    }

    /**
     * Queue a stream that serves the given number of SSE events and then
     * dies mid-stream: the failure is thrown from the stream's read operations
     * once the events are exhausted, not at stream() call time.
     *
     * The served events are OpenAI-compatible no-op chunks ("data:
     * {"choices":[]}"), enough to start the stream before the failure hits.
     * Use queueSse() when the event payloads themselves matter.
     */
    public function queueSseFailure(Throwable $exception, int $afterEvents = 1): self
    {
        $events = [];

        for ($i = 0; $i < $afterEvents; $i++) {
            $events[] = 'data: {"choices":[]}';
        }

        $this->streams[] = new FakeStream(implode("\n", $events), $exception);

        return $this;
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        if ($this->responses === []) {
            throw new ProviderException(
                'FakeHttpClient response queue is empty. Queue responses with queueResponse(), queueJson(), or queueFailure().'
            );
        }

        $next = array_shift($this->responses);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $this->requests[] = $request;

        if ($this->streams === []) {
            throw new ProviderException(
                'FakeHttpClient stream queue is empty. Queue streams with queueSse() or queueStreamFailure().'
            );
        }

        $next = array_shift($this->streams);

        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next instanceof FakeStream) {
            return $next;
        }

        return new FakeStream($next);
    }

    public function withBaseUri(string $baseUri): HttpClientInterface
    {
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): HttpClientInterface
    {
        return $this;
    }

    public function withTimeout(float $timeout): HttpClientInterface
    {
        return $this;
    }

    /**
     * @return HttpRequest[]
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function assertNothingSent(): void
    {
        Assert::assertEmpty(
            $this->requests,
            'Expected no HTTP requests, but ' . count($this->requests) . ' were sent.'
        );
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->requests,
            "Expected {$expected} HTTP request(s), got " . count($this->requests) . '.'
        );
    }

    /**
     * Assert at least one captured request matches the given callback.
     *
     * @param callable(HttpRequest): bool $callback
     */
    public function assertSent(callable $callback): void
    {
        $matched = false;

        foreach ($this->requests as $request) {
            if ($callback($request)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'No captured HTTP request matched the given assertion callback.');
    }
}
