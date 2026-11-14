<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeHttpClient;
use NeuronAI\Testing\FakeStream;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class FakeHttpClientTest extends TestCase
{
    public function test_request_returns_queued_response_and_captures_request(): void
    {
        $client = FakeHttpClient::make()
            ->queueJson(['choices' => [['message' => ['content' => 'ok']]]]);

        $response = $client->request(
            HttpRequest::post('chat/completions', ['model' => 'gpt-4o'])
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('ok', $response->json()['choices'][0]['message']['content']);

        $client->assertSentCount(1);
        $client->assertSent(fn (HttpRequest $r): bool => $r->uri === 'chat/completions');

        $captured = $client->requests()[0];
        $this->assertSame(['model' => 'gpt-4o'], $captured->body);
    }

    public function test_queued_failure_throws_with_status_code(): void
    {
        $client = FakeHttpClient::make()->queueFailure(429, '{"error":{}}');

        try {
            $client->request(HttpRequest::post('chat/completions'));
            $this->fail('Expected the queued failure to be thrown.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->response->statusCode);
        }

        // The attempt is still captured.
        $client->assertSentCount(1);
    }

    public function test_empty_response_queue_fails_loud(): void
    {
        $client = FakeHttpClient::make();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('response queue is empty');

        $client->request(HttpRequest::post('chat/completions'));
    }

    public function test_stream_serves_queued_sse_events(): void
    {
        $client = FakeHttpClient::make()
            ->queueSse('data: {"content":"he"}', 'data: {"content":"llo"}', 'data: [DONE]');

        $stream = $client->stream(HttpRequest::post('chat/completions'));

        $lines = [];
        while (!$stream->eof()) {
            $lines[] = $stream->readLine();
        }

        $this->assertSame('data: {"content":"he"}' . "\n", $lines[0]);
        $this->assertSame("\n", $lines[1]); // SSE blank separator line
        $this->assertSame('data: {"content":"llo"}' . "\n", $lines[2]);
        $this->assertStringContainsString('DONE', $lines[4]);
    }

    public function test_empty_stream_queue_fails_loud(): void
    {
        $client = FakeHttpClient::make();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('stream queue is empty');

        $client->stream(HttpRequest::post('chat/completions'));
    }

    public function test_queue_stream_failure_throws_on_stream(): void
    {
        $client = FakeHttpClient::make()->queueStreamFailure(new HttpException('boom'));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('boom');

        $client->stream(HttpRequest::post('chat/completions'));
    }

    public function test_assert_nothing_sent(): void
    {
        $client = FakeHttpClient::make();

        $client->assertNothingSent();

        $this->expectException(AssertionFailedError::class);

        $client->queueJson([]);
        $client->request(HttpRequest::post('x'));
        $client->assertNothingSent();
    }

    public function test_works_with_real_openai_provider(): void
    {
        $client = FakeHttpClient::make()
            ->queueJson([
                'choices' => [
                    ['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']],
                ],
            ]);

        $provider = new OpenAI('test-key', 'gpt-4o');
        $provider->setHttpClient($client);

        $message = $provider->chat(new UserMessage('Hi'));

        $this->assertSame('ok', $message->getContent());

        $payload = (array) $client->requests()[0]->body;
        $this->assertSame('gpt-4o', $payload['model']);
        $client->assertSent(fn (HttpRequest $r): bool => $r->uri === 'chat/completions');
    }

    public function test_provider_maps_429_to_http_exception(): void
    {
        $client = FakeHttpClient::make()->queueFailure(429, '{"error":{}}');

        $provider = new OpenAI('test-key', 'gpt-4o');
        $provider->setHttpClient($client);

        $this->expectException(HttpException::class);

        $provider->chat(new UserMessage('Hi'));
    }

    public function test_fake_stream_line_reading(): void
    {
        $stream = new FakeStream("a\nb\nc");

        $this->assertSame("a\n", $stream->readLine());
        $this->assertSame("b\n", $stream->readLine());
        $this->assertFalse($stream->eof());
        $this->assertSame('c', $stream->readLine());
        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->readLine());
    }

    public function test_fake_stream_partial_reads(): void
    {
        $stream = new FakeStream('hello');

        $this->assertSame('he', $stream->read(2));
        $this->assertSame('llo', $stream->read(10));
        $this->assertTrue($stream->eof());

        $stream->close();
    }
}
