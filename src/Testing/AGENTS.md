# Testing Module

Test fakes and utilities for testing Neuron applications.

## Fakes

| Class | Purpose |
|-------|---------|
| `FakeAIProvider` | Mock AI responses, record calls |
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

Queue strictness and filtering:

```php
$provider->assertAllResponsesConsumed(); // no over-provisioned queue
$provider->getRecorded('chat');          // records for one method only
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
