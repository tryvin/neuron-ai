# Testing Module

Test fakes and utilities for testing Neuron applications.

## Fakes

| Class | Purpose |
|-------|---------|
| `FakeAIProvider` | Mock AI responses, record calls (supports tool choice) |
| `PlainFakeAIProvider` | Same, without the tool choice capability |
| `FakeHttpClient` | Mock HTTP transport, capture requests, queue responses/failures |
| `FakeStream` | String-backed stream for faking SSE responses (can die mid-stream) |
| `FakeEmbeddingsProvider` | Mock embeddings |
| `FakeVectorStore` | Mock vector store |
| `FakeMiddleware` | Track middleware execution |
| `FakeMcpTransport` | Mock MCP transport, record send/receive |
| `FakeMessageMapper` | Mock message mapping |
| `FakeToolMapper` | Mock tool mapping |
| `RecordingObserver` | Record EventBus emissions with assertions |
| `WorkflowTester` | Run/resume workflows through interrupts |

## FakeAIProvider Usage

```php
$provider = new FakeAIProvider();
$provider->addResponses(new AssistantMessage('Hello!'));

$agent = Agent::make()->withProvider($provider);
$response = $agent->chat(new UserMessage('Hi'));

// Verify calls
$provider->assertCallCount(1);
$provider->assertMethodCallCount('chat', 1);
```

The provider implements `SupportsToolChoice`, so a `toolChoice` set on
`AIInferenceEvent` is recorded per call and can be asserted:

```php
$provider->assertToolChoice('none');
```

Responses can also simulate failures and tool calls:

```php
// Throws at call time (retry/error-path testing); the attempt is still recorded.
$provider->addResponses(new ProviderException('rate limited'), new AssistantMessage('OK'));

// Queues a ToolCallMessage asking for the given tool.
$provider->addToolCallResponse('search', ['city' => 'Rome']);

// Queues a JSON-encoded message for structured output; the real
// deserializer and validator still run, so provide valid data.
$provider->addStructuredResponse(['firstName' => 'Ada']);
```

Streaming fidelity: reasoning blocks stream as `ReasoningChunk` before the
text, and a queued `ToolCallMessage` streams one `ToolCallChunk` per tool
before the generator returns the message. Use `setStreamMessageId()` for
deterministic chunk IDs in snapshot tests.

**Stream failures are lazy**: a queued `Throwable` in the stream queue is
thrown on first iteration, not at `stream()` call time — matching real
providers, where recovery paths often only trigger after a chunk arrived.
Opt back into eager throwing with `setStreamFailuresEager()`. Failures
queued for `chat()`/`structured()` still throw at call time.

Mid-stream failures that yield chunks first:

```php
// Yields 1 chunk of the message, then throws (HttpException 502).
$provider->addStreamingResponse(
    FakeLLM::assistant('Partial answer. '),
    failure: new HttpException('HTTP 502', response: new HttpResponse(502, 'Bad Gateway')),
    failAfterChunks: 1,
);

// failAfterChunks: 0 dies before the first chunk; null failure streams fully.
```

Queue strictness and filtering:

```php
$provider->assertAllResponsesConsumed(); // no over-provisioned queue
$provider->getRecorded('chat');          // records for one method only
```

Tool choice assertions, exact-sequence and per-index:

```php
$provider->assertToolChoices(null, 'none'); // record 0: no choice, record 1: none
$provider->assertNoToolChoice(0);           // single record must carry no choice
$provider->toolChoiceValue();               // current choice without an inference
```

A fake that cannot receive tool choices (for executors emulating providers
without the capability — Bedrock Converse / Ollama style):

```php
$plain = FakeAIProvider::withoutToolChoiceSupport();
$plain->addResponses(FakeLLM::assistant('Answered.'));
// implements AIProviderInterface only: nodes skip toolChoice forwarding,
// queue/record/assert behaviour is identical. Recording always shows null.
```

**PHP Generator Gotcha**: `FakeAIProvider::stream()` uses separate `streamChunks()` method to ensure side effects execute. See project memory for details.

## Record Types

| Class | Purpose |
|-------|---------|
| `RequestRecord` | Captured request details |
| `MiddlewareRecord` | Captured middleware execution |
| `RunResult` | Outcome of a `WorkflowTester` run (state or interrupt) |

## RecordingObserver Usage

Records every event emitted through the EventBus. Node events are scoped to
the workflow ID, so attach the observer to the workflow before running it —
a globally registered observer records nothing:

```php
$observer = RecordingObserver::for($workflow);
$workflow->init()->run();

$observer->assertEmitted('workflow-start');
$observer->assertNotEmitted('workflow-interrupt');
$observer->assertEmittedInOrder('workflow-start', 'workflow-end');
$observer->ofClass(InferenceStart::class); // event DTOs by class
$observer->names();                        // ordered event names
```

Cleanup: either call `$observer->detach()` yourself, or use the
`InteractsWithEventBus` trait, which clears the bus after every test so
observers never leak between tests. Both approaches are equivalent — pick one.

## WorkflowTester Usage

Drives a workflow (or agent) to completion or to its next interrupt without
the catch-inspect-resume boilerplate. Streamed events are consumed silently:

```php
$result = WorkflowTester::run($workflow);
$result->assertInterrupted();
$request = $result->interruptRequest(); // e.g. ApprovalRequest

$result = WorkflowTester::resume($workflow, $request);
$result->assertCompleted();
$result->state->get('received_feedback');
```

When a test needs the streamed chunks themselves, iterate the workflow
generator directly instead of using the tester.

## FakeHttpClient Usage

Tests providers at the transport boundary. Queue responses, exceptions, or
SSE payloads, then assert on captured requests — no Guzzle test internals:

```php
$client = FakeHttpClient::make()
    ->queueJson(['choices' => [['message' => ['content' => 'ok']]]]);

$provider = (new OpenAI($key, 'gpt-4o'))->setHttpClient($client);
$provider->chat(new UserMessage('Hi'));

$client->assertSent(fn (HttpRequest $r) => $r->uri === 'chat/completions');
$client->requests()[0]->body; // the decoded request payload
```

Failures (retry/fallback logic):

```php
$client->queueFailure(429, '{"error":{}}');         // HttpException w/ status
$client->queueResponse(new HttpException('boom'));  // any Throwable
$client->queueStreamFailure(new HttpException('boom')); // stream() path
```

Streaming:

```php
$client->queueSse('data: {"choices":[]}', 'data: [DONE]'); // one stream
$client->queueSse();                                        // empty stream
```

Mid-stream transport failures (real adapters' error translation):

```php
// Serves 1 SSE event, then the stream dies — HttpException surfaces from
// the adapter's stream iteration, not from stream() call time.
$client->queueSseFailure(
    new HttpException('upstream reset', response: new HttpResponse(502, '')),
    afterEvents: 1,
);
```

## Fallback / Multi-Provider Testing

Wrappers that try one provider and fall back to the next (e.g. on HTTP 429)
are testable with **one fake per slot** — fakes are per-instance, never
shared. Build the wrapper by hand with distinct fakes, then assert routing
through each instance's own records:

```php
$primary = new FakeAIProvider();
$primary->addResponses(new HttpException(
    'rate limited',
    response: new HttpResponse(429, ''),
));

$secondary = new FakeAIProvider(new AssistantMessage('ok from #2'));

$executor = new FallbackExecutor($primary, $secondary); // your wrapper
$agent = Agent::make()->withProvider($executor);
$agent->chat(new UserMessage('Hi'));

$primary->assertMethodCallCount('chat', 1);   // tried, failed
$secondary->assertMethodCallCount('chat', 1); // picked up
$primary->assertAllResponsesConsumed();
```

A queued `HttpException` with a 429 `HttpResponse` exercises the
catch-and-inspect-status path such wrappers use. The `HttpResponse` argument
is optional — plain exceptions work when the wrapper does not read the status.

To also cover the real adapters' HTTP error translation, fake the transport
instead of the provider: real provider classes + `FakeHttpClient` per slot.

```php
$primary = new OpenAI($key, 'gpt-4o');
$primary->setHttpClient(FakeHttpClient::make()->queueFailure(429, '{"error":{}}'));

$secondary = new OpenAI($key, 'gpt-4o');
$secondary->setHttpClient(
    FakeHttpClient::make()->queueJson(['choices' => [['message' => ['content' => 'ok']]]])
);

$executor = new FallbackExecutor($primary, $secondary);
```

Provider-level fakes test the wrapper's routing logic; HTTP-level fakes test
routing plus the real provider mapping. Use both layers.

## FakeMcpTransport Usage

Test double for `McpTransportInterface`. Queue predetermined responses, then assert what was sent/received.

```php
$transport = new FakeMcpTransport(
    ['result' => ['tools' => []]],
    ['result' => ['content' => 'Hello']],
);

$transport->connect();
$transport->send(['method' => 'initialize', 'params' => []]);
$response = $transport->receive(); // first queued response

$transport->assertConnected();
$transport->assertMethodSent('initialize');
$transport->assertInitialized(); // checks initialize + notifications/initialized
$transport->assertToolsListCalled();
$transport->assertToolCalled('search');
```

## Dependencies

- `Providers` module (implements interfaces)
- `MCP` module (`FakeMcpTransport` implements `McpTransportInterface`)
- `Workflow` module (`WorkflowTester` drives `Workflow::init()->run()`)
- `Observability` module (`RecordingObserver` implements `ObserverInterface`)
- `HttpClient` module (`FakeHttpClient` implements `HttpClientInterface`, `FakeStream` implements `StreamInterface`)
