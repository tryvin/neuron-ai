# Agent Module

AI agent built on Workflow. Provides chat, streaming, and structured output modes.

**Dependencies**: `src/Workflow/AGENTS.md`, `src/Chat/AGENTS.md`, `src/Providers/AGENTS.md`, `src/Tools/AGENTS.md`

## Extension Pattern (Recommended)

Create a custom agent class extending `Agent`:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\SystemPrompt;

class YouTubeAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new Anthropic(
            key: env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-6',
        );
    }

    public function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are an AI agent specialized in writing YouTube video summaries.'],
            steps: [
                'Get the URL of a YouTube video, or ask the user to provide one.',
                'Use the tools you have available to retrieve the transcription of the video.',
                'Write the summary.',
            ],
            output: [
                'Write a summary in a paragraph without using lists.',
                'After the summary add a list of three sentences as the most important takeaways.',
            ]
        );
    }

    protected function tools(): array
    {
        return [
            GetTranscriptionTool::make(env('SUPADATA_API_KEY')),
        ];
    }
}

// Usage
use NeuronAI\Chat\Messages\UserMessage;

$response = YouTubeAgent::make()->chat(
    new UserMessage('Summarize this: https://youtube.com/watch?v=...')
);
```

## Fluent Definition (Alternative)

For quick prototyping or simple use cases:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\Anthropic\Anthropic;

$agent = Agent::make()
    ->setAiProvider(new Anthropic(key: '...', model: '...'))
    ->setInstructions('You are a helpful assistant.')
    ->addTool($tool);

$response = $agent->chat(new UserMessage('Hello'));
```

## Execution Modes

`Agent.php` composes a Workflow internally with specialized nodes:

| Method | Node | Description |
|--------|------|-------------|
| `chat()` | `ChatNode` | Standard inference, returns full response |
| `stream()` | `StreamingNode` | Yields chunks via generator |
| `structured()` | `StructuredOutputNode` | Extracts typed output via JSON schema |

```php
// Streaming
foreach (YouTubeAgent::make()->stream($message) as $chunk) {
    echo $chunk;
}

// Structured output
$report = MyAgent::make()->structured($message, ReportSchema::class);
```

## Key Files

| File | Purpose |
|------|---------|
| `Agent.php` | Main class, builds workflow per mode |
| `AgentState.php` | Extends `WorkflowState` with message history |
| `SystemPrompt.php` | System prompt builder (background, steps, output) |
| `ResolveProvider.php` | Trait for provider injection |

## Nodes (`Nodes/`)

| Node | Purpose |
|------|---------|
| `InferenceNode` | Abstract base for the inference nodes below; attach middleware here to target every mode |
| `ChatNode` | Standard inference |
| `StreamingNode` | Streaming inference |
| `StructuredOutputNode` | JSON schema extraction |
| `ToolNode` | Executes tool calls |
| `ParallelToolNode` | Executes multiple tools concurrently |

## Tools Bootstrap

`bootstrapTools()` expands the toolkits, appends their guidelines to the instructions and collects the tools in registration order: the tools added with `addTool()` come before the ones returned by `tools()`.

- **First in, first out.** Two tools with the same name cannot be both exposed to the model: the first one registered wins and the later ones are dropped. This also settles two MCP servers exposing the same tool name. As a consequence, a tool added with `addTool()` wins over a same-name tool returned by `tools()`.
- **Workflow state.** A tool implementing `NeuronAI\Tools\HasWorkflowState` receives the state before the de-duplication, so also a dropped tool contributes its part. The `skill` tool of the `Skills` and `Plugins` modules uses it to share one register of skills.
- **Memoized.** The bootstrap runs once; it runs again only after `addTool()`.

## Middleware (`Middleware/`)

Register via `$workflow->middleware(NodeClass::class, $middleware)`. Matching is
subclass-aware (instanceof), so attaching middleware to `InferenceNode::class`
runs it in **every** mode — `chat()`, `stream()`, and `structured()` — without
having to register against each concrete node:

| Middleware | Purpose |
|------------|---------|
| `ToolApproval` | Human-in-the-loop for tool execution |
| `TodoPlanning` | Injects todo planning capabilities |
| `Summarization` | Adds conversation summarization |

## Events (`Events/`)

| Event | Triggers |
|-------|----------|
| `AIInferenceEvent` | AI call starts |
| `AIResponseEvent` | AI response received |

## Workflow Flow

```
StartEvent → PrepareInferenceNode → AIInferenceEvent
  → ChatNode → AIResponseEvent
  → RouterNode → ToolCallEvent (if tools) or StopEvent
  → ToolNode → StartEvent (loop)
  [Repeat until no tool calls]
```
