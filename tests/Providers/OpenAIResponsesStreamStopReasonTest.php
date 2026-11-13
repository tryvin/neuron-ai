<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class OpenAIResponsesStreamStopReasonTest extends TestCase
{
    public function test_stream_reports_completed_status(): void
    {
        $message = $this->stream($this->body('response.completed', 'completed'));

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('completed', $message->stopReason());
        $this->assertSame('Hello', $message->getContent());
        $this->assertSame(8, $message->getUsage()->inputTokens);
        $this->assertSame(3, $message->getUsage()->outputTokens);
    }

    public function test_stream_reports_incomplete_status(): void
    {
        $message = $this->stream($this->body('response.incomplete', 'incomplete'));

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('incomplete', $message->stopReason());
        $this->assertSame('Hello', $message->getContent());
    }

    protected function body(string $event, string $status): string
    {
        return "data: {\"type\":\"response.output_text.delta\",\"item_id\":\"msg_1\",\"delta\":\"Hello\"}\n\n"
            . "data: {\"type\":\"{$event}\",\"response\":{\"status\":\"{$status}\",\"output\":[{\"type\":\"message\",\"content\":[{\"text\":\"Hello\"}]}],\"usage\":{\"input_tokens\":8,\"output_tokens\":3}}}\n\n"
            . "data: [DONE]\n\n";
    }

    protected function stream(string $body): AssistantMessage
    {
        $provider = (new OpenAIResponses('', 'gpt-5'))
            ->setHttpClient(new GuzzleHttpClient(
                handler: HandlerStack::create(new MockHandler([
                    new Response(status: 200, body: $body),
                ]))
            ));

        /** @var Generator<mixed, mixed, mixed, AssistantMessage> $stream */
        $stream = $provider->stream(new UserMessage('Hi'));
        iterator_to_array($stream, false);

        return $stream->getReturn();
    }
}
