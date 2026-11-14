<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\SupportsToolChoice;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\PlainFakeAIProvider;
use NeuronAI\Tests\Stubs\StructuredOutput\Person;
use NeuronAI\Testing\FakeMessageMapper;
use NeuronAI\Testing\FakeToolMapper;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class FakeAIProviderTest extends TestCase
{
    public function test_chat_returns_queued_response(): void
    {
        $expected = new AssistantMessage('Hello!');

        $provider = new FakeAIProvider($expected);
        $response = $provider->chat(new UserMessage('Hi'));

        $this->assertSame($expected, $response);
    }

    public function test_chat_returns_responses_sequentially(): void
    {
        $first = new AssistantMessage('First');
        $second = new AssistantMessage('Second');

        $provider = new FakeAIProvider($first, $second);

        $this->assertSame($first, $provider->chat(new UserMessage('1')));
        $this->assertSame($second, $provider->chat(new UserMessage('2')));
    }

    public function test_empty_queue_throws_exception(): void
    {
        $provider = new FakeAIProvider();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('response queue is empty');

        $provider->chat(new UserMessage('Hi'));
    }

    public function test_add_responses_extends_queue(): void
    {
        $provider = new FakeAIProvider();
        $message = new AssistantMessage('Added later');

        $provider->addResponses($message);
        $response = $provider->chat(new UserMessage('Hi'));

        $this->assertSame($message, $response);
    }

    public function test_system_prompt_is_stored(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $returned = $provider->systemPrompt('You are helpful.');

        $this->assertSame($provider, $returned);

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame('You are helpful.', $provider->getRecorded()[0]->systemPrompt);
    }

    public function test_tools_are_stored(): void
    {
        $tool = Tool::make('search', 'Search the web')
            ->addProperty(new ToolProperty('query', PropertyType::STRING, 'Search query', true));

        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->setTools([$tool]);
        $provider->chat(new UserMessage('Hi'));

        $this->assertCount(1, $provider->getRecorded()[0]->tools);
        $this->assertSame('search', $provider->getRecorded()[0]->tools[0]->getName());
    }

    public function test_chat_records_request(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('Be helpful');
        $provider->chat(new UserMessage('Hello'));

        $records = $provider->getRecorded();
        $this->assertCount(1, $records);
        $this->assertSame('chat', $records[0]->method);
        $this->assertSame('Be helpful', $records[0]->systemPrompt);
        $this->assertCount(1, $records[0]->messages);
        $this->assertNull($records[0]->structuredClass);
    }

    public function test_stream_yields_text_chunks(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello world'));
        $provider->setStreamChunkSize(5);

        $generator = $provider->stream(new UserMessage('Hi'));

        $chunks = [];
        foreach ($generator as $chunk) {
            $this->assertInstanceOf(TextChunk::class, $chunk);
            $chunks[] = $chunk->content;
        }

        $this->assertSame(['Hello', ' worl', 'd'], $chunks);

        $finalMessage = $generator->getReturn();
        $this->assertSame('Hello world', $finalMessage->getContent());
    }

    public function test_stream_records_request(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Streamed'));
        $provider->stream(new UserMessage('Hi'));

        $this->assertSame('stream', $provider->getRecorded()[0]->method);
    }

    public function test_stream_with_empty_content(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage());

        $generator = $provider->stream(new UserMessage('Hi'));

        $chunks = [];
        /** @var TextChunk $chunk */
        foreach ($generator as $chunk) {
            $chunks[] = $chunk->content;
        }

        $this->assertEmpty($chunks);

        $finalMessage = $generator->getReturn();
        $this->assertInstanceOf(AssistantMessage::class, $finalMessage);
    }

    public function test_structured_records_class_and_schema(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name":"Alice"}'));

        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $provider->structured(new UserMessage('Generate'), 'App\\User', $schema);

        $record = $provider->getRecorded()[0];
        $this->assertSame('structured', $record->method);
        $this->assertSame('App\\User', $record->structuredClass);
        $this->assertSame($schema, $record->structuredSchema);
    }

    public function test_message_mapper_returns_fake(): void
    {
        $provider = new FakeAIProvider();
        $this->assertInstanceOf(FakeMessageMapper::class, $provider->messageMapper());
    }

    public function test_tool_payload_mapper_returns_fake(): void
    {
        $provider = new FakeAIProvider();
        $this->assertInstanceOf(FakeToolMapper::class, $provider->toolPayloadMapper());
    }

    public function test_get_call_count(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('1'),
            new AssistantMessage('2'),
        );

        $this->assertSame(0, $provider->getCallCount());

        $provider->chat(new UserMessage('a'));
        $this->assertSame(1, $provider->getCallCount());

        $provider->chat(new UserMessage('b'));
        $this->assertSame(2, $provider->getCallCount());
    }

    // ---------------------------------------------------------------
    // Assertion tests
    // ---------------------------------------------------------------

    public function test_assert_call_count_passes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $provider->assertCallCount(1);
        $this->addToAssertionCount(1);
    }

    public function test_assert_call_count_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertCallCount(2);
    }

    public function test_assert_sent_passes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('Be helpful');
        $provider->chat(new UserMessage('Hello world'));

        $provider->assertSent(fn (RequestRecord $record): bool => $record->method === 'chat'
            && $record->systemPrompt === 'Be helpful');
    }

    public function test_assert_sent_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertSent(fn (RequestRecord $r): bool => $r->method === 'stream');
    }

    public function test_assert_nothing_sent_passes(): void
    {
        $provider = new FakeAIProvider();
        $provider->assertNothingSent();
        // If we reach here, the assertion passed
        $this->addToAssertionCount(1);
    }

    public function test_assert_nothing_sent_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertNothingSent();
    }

    public function test_assert_method_call_count(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('1'),
            new AssistantMessage('2'),
            new AssistantMessage('3'),
        );

        $provider->chat(new UserMessage('a'));
        $provider->chat(new UserMessage('b'));
        $provider->stream(new UserMessage('c'));

        $provider->assertMethodCallCount('chat', 2);
        $provider->assertMethodCallCount('stream', 1);
    }

    public function test_assert_system_prompt_passes(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('You are a weather assistant.');
        $provider->chat(new UserMessage('Hi'));

        $provider->assertSystemPrompt('You are a weather assistant.');
    }

    public function test_assert_system_prompt_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->systemPrompt('Something else');
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertSystemPrompt('You are a weather assistant.');
    }

    public function test_assert_tools_configured_passes(): void
    {
        $tool = Tool::make('search', 'Search the web');

        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->setTools([$tool]);
        $provider->chat(new UserMessage('Hi'));

        $provider->assertToolsConfigured(['search']);
    }

    public function test_assert_tools_configured_fails(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $provider->assertToolsConfigured(['nonexistent']);
    }

    public function test_static_make_constructor(): void
    {
        $provider = FakeAIProvider::make(new AssistantMessage('OK'));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertSame('OK', $response->getContent());
    }

    public function test_set_http_client_is_noop(): void
    {
        $provider = new FakeAIProvider();
        $result = $provider->setHttpClient($this->createMock(HttpClientInterface::class));

        $this->assertSame($provider, $result);
    }

    public function test_single_message_is_normalized_to_array(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $record = $provider->getRecorded()[0];
        $this->assertIsArray($record->messages);
        $this->assertCount(1, $record->messages);
    }

    public function test_usage_on_fake_response(): void
    {
        $response = (new AssistantMessage('Hello'))->setUsage(new Usage(10, 20));
        $provider = new FakeAIProvider($response);

        $message = $provider->chat(new UserMessage('Hi'));

        $this->assertSame(10, $message->getUsage()->inputTokens);
        $this->assertSame(20, $message->getUsage()->outputTokens);
    }

    public function test_fake_supports_tool_choice_capability(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));

        $this->assertInstanceOf(SupportsToolChoice::class, $provider);
        $this->assertSame($provider, $provider->toolChoice('none'));
    }

    public function test_tool_choice_is_recorded_per_call(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('a'), new AssistantMessage('b'));
        $provider->toolChoice('none');
        $provider->chat(new UserMessage('1'));

        $provider->toolChoice(['type' => 'function', 'name' => 'search']);
        $provider->chat(new UserMessage('2'));

        $this->assertSame('none', $provider->getRecorded()[0]->toolChoice);
        $this->assertSame(['type' => 'function', 'name' => 'search'], $provider->getRecorded()[1]->toolChoice);
    }

    public function test_tool_choice_defaults_to_null_when_not_set(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->assertNull($provider->getRecorded()[0]->toolChoice);
    }

    public function test_invalid_tool_choice_is_rejected(): void
    {
        $provider = new FakeAIProvider();

        $this->expectException(ProviderException::class);
        $provider->toolChoice('sometimes');
    }

    public function test_assert_tool_choice_passes_when_recorded(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->toolChoice('none');
        $provider->chat(new UserMessage('Hi'));

        $provider->assertToolChoice('none');

        $this->addToAssertionCount(1);
    }

    public function test_assert_tool_choice_fails_when_not_recorded(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);

        $provider->assertToolChoice('none');
    }

    public function test_queued_exception_is_thrown_and_attempt_recorded(): void
    {
        $provider = new FakeAIProvider(new ProviderException('boom'), new AssistantMessage('recovered'));

        try {
            $provider->chat(new UserMessage('1'));
            $this->fail('Expected the queued exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame('recovered', $provider->chat(new UserMessage('2'))->getContent());
        $this->assertCount(2, $provider->getRecorded());
    }

    public function test_queued_exception_is_thrown_by_stream_lazily(): void
    {
        $provider = new FakeAIProvider(new ProviderException('stream boom'));

        // stream() itself must not throw — real providers fail on first iteration.
        $stream = $provider->stream(new UserMessage('Hi'));

        $this->assertSame(1, $provider->getCallCount()); // attempt recorded eagerly

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('stream boom');

        iterator_to_array($stream, false);
    }

    public function test_stream_failures_can_be_made_eager(): void
    {
        $provider = new FakeAIProvider(new ProviderException('stream boom'));
        $provider->setStreamFailuresEager();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('stream boom');

        $provider->stream(new UserMessage('Hi'));
    }

    public function test_add_streaming_response_streams_message_to_completion_without_failure(): void
    {
        $provider = new FakeAIProvider();
        $provider->addStreamingResponse(new AssistantMessage('Hello!'));

        $chunks = [];
        foreach ($provider->stream(new UserMessage('Hi')) as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertNotSame([], $chunks);
        $provider->assertAllResponsesConsumed();
    }

    public function test_add_streaming_response_yields_n_chunks_then_throws(): void
    {
        $provider = new FakeAIProvider();
        $provider->addStreamingResponse(
            new AssistantMessage('Partial answer. '),
            failure: new ProviderException('HTTP 502'),
            failAfterChunks: 1,
        );

        $stream = $provider->stream(new UserMessage('Hi'));

        /** @var array<int, \NeuronAI\Chat\Messages\Stream\Chunks\TextChunk> $chunks */
        $chunks = [];
        try {
            foreach ($stream as $chunk) {
                $chunks[] = $chunk;
            }
            $this->fail('Expected the mid-stream failure to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertSame('HTTP 502', $exception->getMessage());
        }

        $this->assertCount(1, $chunks);
        $this->assertSame('Parti', $chunks[0]->content);
        $provider->assertAllResponsesConsumed();
    }

    public function test_add_streaming_response_with_fail_after_zero_dies_before_first_chunk(): void
    {
        $provider = new FakeAIProvider();
        $provider->addStreamingResponse(
            new AssistantMessage('Never seen'),
            failure: new ProviderException('HTTP 503'),
            failAfterChunks: 0,
        );

        $stream = $provider->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('HTTP 503');

        iterator_to_array($stream, false);
    }

    public function test_mid_stream_failure_recovery_flow(): void
    {
        $provider = new FakeAIProvider();
        $provider->addStreamingResponse(
            new AssistantMessage('Partial answer. '),
            failure: new ProviderException('HTTP 502'),
            failAfterChunks: 1,
        );
        $provider->addResponses(new AssistantMessage('Recovered fully.'));

        $first = $provider->stream(new UserMessage('Hello'));
        try {
            foreach ($first as $chunk) {
                $chunk = $chunk;
            }
            $this->fail('Expected the first stream to die mid-flight.');
        } catch (ProviderException) {
            // recovery path: restart on the next provider call
        }

        $second = $provider->stream(new UserMessage('Hello'));
        $content = '';
        foreach ($second as $chunk) {
            $content .= $chunk->content;
        }

        $this->assertSame('Recovered fully.', $content);
        $provider->assertMethodCallCount('stream', 2);
        $provider->assertAllResponsesConsumed();
    }

    public function test_streaming_response_is_unwrapped_by_chat(): void
    {
        $message = new AssistantMessage('from chat');
        $provider = new FakeAIProvider();
        $provider->addStreamingResponse($message);

        $this->assertSame($message, $provider->chat(new UserMessage('Hi')));
    }

    public function test_assert_tool_choices_exact_sequence(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('a'), new AssistantMessage('b'));

        $provider->toolChoice('none');
        $provider->chat(new UserMessage('1'));

        $provider->toolChoice('auto');
        $provider->chat(new UserMessage('2'));

        $provider->assertToolChoices('none', 'auto');
    }

    public function test_assert_tool_choices_supports_null_entries(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('a'), new AssistantMessage('b'));

        $provider->chat(new UserMessage('1')); // no choice
        $provider->toolChoice('none');
        $provider->chat(new UserMessage('2'));

        $provider->assertToolChoices(null, 'none');
        $provider->assertNoToolChoice(0);
    }

    public function test_assert_tool_choices_fails_on_wrong_sequence(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('a'), new AssistantMessage('b'));

        $provider->chat(new UserMessage('1'));
        $provider->toolChoice('none');
        $provider->chat(new UserMessage('2'));

        $this->expectException(AssertionFailedError::class);

        $provider->assertToolChoices('none', null);
    }

    public function test_tool_choice_value_getter(): void
    {
        $provider = new FakeAIProvider();

        $this->assertNull($provider->toolChoiceValue());

        $provider->toolChoice('none');
        $this->assertSame('none', $provider->toolChoiceValue());
    }

    public function test_without_tool_choice_support_returns_capability_free_fake(): void
    {
        $plain = FakeAIProvider::withoutToolChoiceSupport();
        $plain->addResponses(new AssistantMessage('Answered.'));

        $this->assertInstanceOf(PlainFakeAIProvider::class, $plain);
        $this->assertNotInstanceOf(SupportsToolChoice::class, $plain);

        // Streams and records like the standard fake.
        $content = '';
        foreach ($plain->stream(new UserMessage('Hello')) as $chunk) {
            $content .= $chunk->content;
        }

        $this->assertSame('Answered.', $content);
        $plain->assertMethodCallCount('stream', 1);
        $plain->assertAllResponsesConsumed();

        // No tool choice is ever recorded.
        $plain->assertToolChoices(null);
        $this->assertObjectNotHasProperty('toolChoice', $plain);
    }

    public function test_queued_exception_is_thrown_by_structured_and_recorded(): void
    {
        $provider = new FakeAIProvider(new ProviderException('structured boom'));

        try {
            $provider->structured(new UserMessage('Hi'), 'TestClass', []);
            $this->fail('Expected the queued exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertSame('structured boom', $exception->getMessage());
        }

        $this->assertSame('structured', $provider->getRecorded()[0]->method);
    }

    public function test_add_tool_call_response_queues_tool_call_message(): void
    {
        $provider = new FakeAIProvider();
        $provider->addToolCallResponse('search', ['city' => 'Rome']);

        $response = $provider->chat(new UserMessage('Weather in Rome?'));

        $this->assertInstanceOf(ToolCallMessage::class, $response);
        $tools = $response->getTools();
        $this->assertSame('search', $tools[0]->getName());
        $this->assertSame(['city' => 'Rome'], $tools[0]->getInputs());
    }

    public function test_add_structured_response_encodes_data_as_json(): void
    {
        $provider = new FakeAIProvider();
        $provider->addStructuredResponse(['firstName' => 'Ada', 'lastName' => 'Lovelace']);

        $response = $provider->structured(new UserMessage('Who?'), Person::class, []);

        $this->assertSame('{"firstName":"Ada","lastName":"Lovelace"}', $response->getContent());
    }

    public function test_stream_emits_tool_call_chunks_for_tool_call_message(): void
    {
        $provider = new FakeAIProvider();
        $provider->addToolCallResponse('search', ['city' => 'Rome']);

        $stream = $provider->stream(new UserMessage('Weather?'));

        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(ToolCallChunk::class, $chunks[0]);
        $this->assertSame('search', $chunks[0]->tool->getName());
        $this->assertInstanceOf(ToolCallMessage::class, $stream->getReturn());
    }

    public function test_stream_emits_reasoning_chunks_before_text(): void
    {
        $response = new AssistantMessage([
            new ReasoningContent('thinking'),
            new TextContent('Hello!'),
        ]);

        $provider = new FakeAIProvider($response);
        $provider->setStreamChunkSize(5);

        $chunks = [];
        foreach ($provider->stream(new UserMessage('Hi')) as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertInstanceOf(ReasoningChunk::class, $chunks[0]);
        $this->assertSame('thinking', $chunks[0]->content);
        $this->assertInstanceOf(TextChunk::class, $chunks[1]);
        $this->assertSame('Hello', $chunks[1]->content);
        $this->assertSame('!', $chunks[2]->content);
    }

    public function test_stream_message_id_is_injectable(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello!'));
        $provider->setStreamMessageId('msg_fixed');

        foreach ($provider->stream(new UserMessage('Hi')) as $chunk) {
            $this->assertSame('msg_fixed', $chunk->messageId);
        }
    }

    public function test_get_recorded_filters_by_method(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('a'), new AssistantMessage('b'));
        $provider->chat(new UserMessage('1'));
        $provider->stream(new UserMessage('2'));

        $this->assertCount(2, $provider->getRecorded());
        $this->assertCount(1, $provider->getRecorded('chat'));
        $this->assertSame('chat', $provider->getRecorded('chat')[0]->method);
        $this->assertSame('stream', $provider->getRecorded('stream')[0]->method);
    }

    public function test_assert_all_responses_consumed_passes_when_queue_is_empty(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('OK'));
        $provider->chat(new UserMessage('Hi'));

        $provider->assertAllResponsesConsumed();

        $this->addToAssertionCount(1);
    }

    public function test_assert_all_responses_consumed_fails_when_queue_has_items(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('a'), new AssistantMessage('b'));
        $provider->chat(new UserMessage('Hi'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 were left');

        $provider->assertAllResponsesConsumed();
    }
}
