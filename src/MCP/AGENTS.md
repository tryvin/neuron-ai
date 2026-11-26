# MCP Module

Model Context Protocol connector for external tool integration. MCP is an open standard by Anthropic to connect agents to external services.

**Dependencies**: `src/HttpClient/AGENTS.md`

## Core

| File | Purpose |
|------|---------|
| `McpConnector.php` | Establishes connection, discovers tools |
| `McpClient.php` | Calls tools on MCP server |
| `McpTransportInterface.php` | Transport contract |
| `McpException.php` | Module exception |

## Transports

| Class | Protocol | Use Case |
|-------|----------|----------|
| `StdioTransport` | Standard I/O | Local MCP processes |
| `SseHttpTransport` | Server-Sent Events | Remote servers with SSE |
| `StreamableHttpTransport` | HTTP streaming | Standard HTTP MCP |

## Usage with Agent Extension Pattern

Connect MCP tools in your custom agent class:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class MyAgent extends Agent
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
        return 'You are a helpful assistant with access to external tools.';
    }

    protected function tools(): array
    {
        return [
            // Local MCP server via stdio
            ...McpConnector::make([
                'command' => 'php',
                'args' => ['/path/to/mcp_server.php'],
            ])->tools(),
        ];
    }
}
```

### Remote MCP Server

```php
use NeuronAI\MCP\McpConnector;

protected function tools(): array
{
    return [
        // HTTP transport
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
            'token' => env('MCP_BEARER_TOKEN'),
            'timeout' => 30,
        ])->tools(),
    ];
}
```

### SSE Transport (Async)

```php
protected function tools(): array
{
    return [
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
            'token' => env('MCP_BEARER_TOKEN'),
            'async' => true, // Enables SSE transport
        ])->tools(),
    ];
}
```

## Filtering Tools

Control which tools are exposed from the MCP server:

```php
protected function tools(): array
{
    return [
        // EXCLUDE: discard certain tools
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
        ])->exclude([
            'dangerous_tool',
            'admin_tool',
        ])->tools(),

        // ONLY: select specific tools
        ...McpConnector::make([
            'url' => 'https://mcp.example.com',
        ])->only([
            'search_tool',
            'read_tool',
        ])->tools(),
    };
}
```

## Extensions

MCP extensions are opt-in: nothing is advertised unless the connector is
configured with it. `extensions` maps an extension identifier to its settings.

```php
$connector = McpConnector::make([
    'url' => 'https://mcp.example.com',
    'token' => env('MCP_BEARER_TOKEN'),
    'extensions' => [
        'io.modelcontextprotocol/ui' => [
            'mimeTypes' => ['text/html;profile=mcp-app'],
        ],
    ],
]);
```

The client announces them in every request on modern revisions
(`_meta["io.modelcontextprotocol/clientCapabilities"].extensions`, the
`server/discover` probe included) and in the `initialize` handshake on legacy
revisions, HTTP+SSE included. Settings must be arrays; an empty array is sent
as `{}`.

What the server advertised in return is readable from the connector:

```php
$connector->hasExtension('io.modelcontextprotocol/ui');       // bool
$connector->extensionSettings('io.modelcontextprotocol/ui');  // array, or null when not advertised
$connector->capabilities();                                   // full server capabilities, or null
```

An extension Neuron does not interpret is preserved as received. A server that
omits an extension does not support it: the application decides how to degrade.

## Tool Visibility and Results

### App-only tools

A server built for [MCP Apps](https://modelcontextprotocol.io/extensions/apps/overview)
can mark a tool with `_meta.ui.visibility: ["app"]`: the tool exists for a UI,
not for the agent. `tools()` leaves such tools out, as the specification
requires. A tool without a declared visibility is for both.

`only()` and `exclude()` are further filters for the model. `rawTools()` returns
every tool as the server declared it, `_meta` included, and is fetched once per
connector:

```php
$connector->rawTools(); // App-only tools included
```

### Tool metadata

Each tool carries where it comes from, under the `mcp` key of `Tool::getMeta()`:

```php
$tool->getMeta();
// ['mcp' => ['server' => 'erp', 'ui' => ['resourceUri' => 'ui://sales/dashboard.html']]]
```

- `server` is the connector label, `name()`: the `name` entry of the
  configuration or, without one, the URL without credentials, query and
  fragment, or the command.
- `ui` is the `_meta.ui` of the tool. The deprecated flat
  `_meta["ui/resourceUri"]` is read under the same shape.

### Whole results

An MCP tool returns a `ToolOutput` with the whole result:

| Property | Holds |
|----------|-------|
| `text` | What the model reads |
| `structuredContent` | The `structuredContent` of the result, for a UI |
| `meta['mcp']['isError']` | Whether the server reported `isError` |
| `meta['mcp']['resultMeta']` | The `_meta` the server put on the result |

The text is the `text` of every text content block, joined with a newline:
that is what the MCP specification means by tool result text. Non-text blocks
are left out when a text block exists. Without any text block, the model reads
`structuredContent` as JSON; without that either, the content blocks are JSON,
as they were before.

```php
$output = $connector->invokeToolResult(item: $rawTool, arguments: ['region' => 'emea']);
```

`invokeTool()` keeps returning the raw content blocks. The output does not
repeat the server label or the tool name: the `Tool` beside it has them.

## Multiple MCP Servers

```php
protected function tools(): array
{
    return [
        // Database tools
        ...McpConnector::make([
            'command' => 'mcp-server-postgres',
            'args' => ['postgres://localhost/mydb'],
        ])->tools(),

        // File system tools
        ...McpConnector::make([
            'command' => 'mcp-server-filesystem',
            'args' => ['/home/user/documents'],
        ])->tools(),
    ];
}
```

## MCP Server Directories

Find pre-built MCP servers:
- [MCP Official GitHub](https://github.com/modelcontextprotocol/servers)
- [MCP-GET Registry](https://mcp-get.com/)

## How It Works

1. `McpConnector` establishes connection using configured transport
2. Neuron discovers tools exposed by the MCP server
3. Tools are converted to `ProviderTool` instances
4. When agent calls a tool, Neuron sends request to MCP server
5. Result is returned to the LLM to continue the task

## Dependencies

- `HttpClient` for HTTP transports
