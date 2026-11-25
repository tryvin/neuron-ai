---
name: neuron-plugin-loader
description: Add Agent Plugins (plugin manifests, skills and HTTP MCP servers) and standalone skills to Neuron AI agents. Use this skill whenever the user mentions plugins, loading a Notion/Figma/Canva/Zoom plugin, the openai/plugins repository, a plugin manifest, mcp.json, SKILL.md, Agent Skills, a plugin marketplace or a plugin listing. Also trigger for tasks involving NeuronAI\Plugins\Plugin, NeuronAI\Skills\Skills, the skill tool, or passing credentials to a third-party MCP server.
---

# Neuron AI Plugin Loader

Load Agent Plugins and skills into Neuron AI agents. Neuron loads these formats; it does not create, install or publish plugins.

Two related components:

| Component | What it loads |
|-----------|---------------|
| `NeuronAI\Plugins\Plugin` | An Agent Plugin: manifest, skills, MCP servers |
| `NeuronAI\Skills\Skills` | Skills alone, from any folder |

Both are toolkits: register them in `tools()` or with `addTool()`. Registering one is the opt-in: an agent without it loads no skill and contacts no server.

## What a Plugin Is

```
my-plugin/
├── plugin.json              manifest: name, description, ...
├── skills/<name>/SKILL.md   skills: instructions for the model
├── mcp.json                 MCP servers: tools
└── assets/                  logos and screenshots, for a listing
```

| Layout | Manifest | Skills | MCP configuration |
|--------|----------|--------|-------------------|
| Portable | `plugin.json` | `skills/` | `mcp.json` |
| Legacy Codex | `.codex-plugin/plugin.json` | `skills/`, or the `skills` path of the manifest | `.mcp.json`, or the `mcpServers` path of the manifest |
| Legacy Claude Code | `.claude-plugin/plugin.json` | `skills/`, or the `skills` path of the manifest | `.mcp.json`, or the `mcpServers` path of the manifest |

`plugin.json` wins: when it exists the legacy files are ignored. The plugins in the `openai/plugins` GitHub repository still use the legacy Codex layout.

## Add Plugin Support to an Agent

1. **Put the plugin directory** in the project, for example under `plugins/`. Clone it, download it, or write it (see [Write a Plugin](#write-a-plugin)). The loader never fetches anything.

2. **Check that it loads** before wiring it:

   ```php
   use NeuronAI\Plugins\Plugin;

   $plugin = Plugin::make(__DIR__.'/../plugins/notion');

   $plugin->tools(); // Contacts the MCP servers. Omit it to check the files only.

   foreach ($plugin->errors() as $error) {
       // Decide whether a skipped server or a broken skill matters for the project
   }
   ```

   `Plugin::make()` throws only when the directory does not exist, or when a manifest or an MCP configuration file is unusable: missing, broken JSON, off-schema. Everything else is reported through `errors()`.

3. **Register it** in the agent:

   ```php
   use NeuronAI\Agent\Agent;
   use NeuronAI\Plugins\Plugin;
   use NeuronAI\Providers\AIProviderInterface;

   class MyAgent extends Agent
   {
       protected function provider(): AIProviderInterface
       {
           // ...
       }

       protected function tools(): array
       {
           return [
               Plugin::make(__DIR__.'/../plugins/notion')
                   ->server('notion', ['token' => env('NOTION_MCP_TOKEN')]),
           ];
       }
   }
   ```

4. **Pass the credentials** (see below).

5. **Test without the network** (see [Testing Without the Network](#testing-without-the-network)).

### Skills Alone

```php
use NeuronAI\Skills\Skills;

protected function tools(): array
{
    return [
        Skills::make(__DIR__.'/skills'),                  // a folder, uses the filesystem
        Skills::make($storage, 'more/skills'),            // or a StorageInterface
    ];
}
```

Discovery is not recursive: one `Skills::make()` call per folder. It takes every immediate child folder holding a `SKILL.md`. With several sources, the model still sees one `skill` tool.

## Passing Credentials

Most MCP servers want a bearer token or an API key header. A plugin that relies on OAuth is no exception: Neuron runs no OAuth flow, so the token comes from the project. Never write credentials into the files of a plugin.

`server()` takes a server name and the configuration to merge over the one of the plugin:

| Key | Effect |
|-----|--------|
| `token` | Sent as `Authorization: Bearer <token>` |
| `headers` | Merged with the headers of the plugin. A header with the same name replaces it |
| `timeout` | Seconds before a request gives up, 30 by default |

The name is the key of an entry in `mcpServers`. When the plugin is not yours, take it from `servers()`, which lists every supported server with its URL and headers:

```php
$plugin = Plugin::make($dir);

foreach ($plugin->servers() as $name => $config) {
    $plugin->server($name, ['token' => $credentials->for($config['url'])]);
}
```

A name that `servers()` does not list is ignored. Skipped servers (stdio, unknown types) are not listed: they are in `errors()`. Call `server()` before the tools are used.

## What the Agent Gets

- **The MCP tools of the plugin**, with their original names.
- **One `skill` tool** listing the skills of every source with their descriptions. When a task matches a skill, the model calls `skill(name)` and gets its instructions. It can then read the files bundled with the skill with `skill(name, file)`, by path relative to the skill folder. Nothing enters the context before that call.

Two tools with the same name (two plugins, or a plugin and a tool of the project): the first registered wins and the other is dropped. `addTool()` tools come before the ones returned by `tools()`.

To use only the MCP servers of a plugin, opt its skills out: `->exclude([\NeuronAI\Skills\SkillTool::class])`.

## Write a Skill

A skill is a folder with a `SKILL.md`. The frontmatter needs a `name` and a `description`: the description is what the model reads to decide whether to load the skill.

```markdown
---
name: acme-search
description: Search the Acme documentation for a topic. Use when the user asks how Acme works.
---

# Acme search

1. Call `search` with the topic the user asks about.
2. Read the page of the best result before answering.

The query syntax is described in reference/query-syntax.md.
```

- Give the skill the name of its folder, in lowercase with hyphens.
- Say when to use the skill, in one or two sentences. Every description travels in the tool description on each request.
- Bundled files stay next to `SKILL.md` and are read by relative path. Neuron never executes them.
- A skill without a name or a description is skipped and reported. A skill breaking the other rules of the format is loaded and reported: real world plugins break them.

## Write a Plugin

A plugin is three kinds of files. Only the manifest is required.

`plugin.json`:

```json
{
    "$schema": "https://agent-plugins.org/schemas/1.0.0/plugin.schema.json",
    "name": "acme-docs",
    "description": "Search the Acme documentation.",
    "version": "1.0.0"
}
```

The `name` goes up to 64 lowercase letters and digits, with single dots and hyphens, and cannot start or end with one. It is the name a listing shows.

`mcp.json`:

```json
{
    "$schema": "https://agent-plugins.org/schemas/1.0.0/mcp.schema.json",
    "mcpServers": {
        "acme": {
            "type": "streamable-http",
            "url": "https://mcp.acme.example/mcp"
        }
    }
}
```

Only HTTP servers are supported: `streamable-http` (legacy `http`) and `sse`. `stdio`, `ws`, `sdk` and unknown types are skipped and reported. URLs must be absolute `http` or `https`, without user info and fragment; plain `http` only on loopback hosts. Headers must be valid names with printable values, unique case-insensitively. A wrong `$schema` or top-level shape disables the servers of the plugin; an invalid entry only skips itself.

Write the skills for the model, not for a human: say when to use them and which tool to call.

## Testing Without the Network

`tools()` contacts the MCP servers. In tests, extend `Plugin` and replace the connector:

```php
use NeuronAI\MCP\McpConnector;
use NeuronAI\Plugins\Plugin;
use NeuronAI\Testing\FakeMcpTransport;

class FakeNotionPlugin extends Plugin
{
    protected function connector(array $config): McpConnector
    {
        return new McpConnector([
            'transport' => new FakeMcpTransport([
                // The tools the server lists. The handshake is answered by the fake itself.
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['tools' => [[
                    'name' => 'search',
                    'description' => 'Search Notion.',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                ]]],
            ]),
        ]);
    }
}

$plugin = new FakeNotionPlugin(__DIR__.'/plugins/notion');
```

Each server gets its own connector, so queue one `tools/list` response per call. The skills of the plugin are still read from the directory: they need no fake. To test the model side, use `NeuronAI\Testing\FakeAIProvider`.

## Build a Listing (Marketplace)

A loaded plugin tells what it declares, without contacting anything:

```php
$plugin = Plugin::make($dir);

$plugin->name();         // 'canva', the name of the manifest
$plugin->description();  // ?string, from the manifest
$plugin->skills();       // Skill[], by name, each with getDescription()
$plugin->servers();      // connector configuration, by server name
$plugin->errors();
```

Branding is not part of the portable manifest. The plugins in `openai/plugins` declare it in the `interface` block of their legacy manifest, with plugin-relative paths: `displayName`, `shortDescription`, `longDescription`, `developerName`, `category`, `composerIcon`, `logo`, `logoDark`, `brandColor`, `brandColorDark`, `screenshots`, `websiteURL`, `privacyPolicyURL`, `termsOfServiceURL`.

```php
$interface = $plugin->manifest()['interface'] ?? [];
$logo = $interface['logo'] ?? null;

if (is_string($logo) && ($stream = $plugin->openAsset($logo)) !== null) {
    header('Content-Type: image/png'); // from the extension of the path
    fpassthru($stream);
    fclose($stream);
}
```

- `manifest()` returns the decoded manifest as an array.
- `openAsset()` returns a stream, a resource, or null when the path is invalid or the file is missing. The file is never loaded into memory and the path cannot escape the plugin.
- A portable plugin has no `interface` block, and a legacy one may declare no logo: design the listing with a fallback.
- Finding the plugin folders is up to the application: the loader reads one directory at a time and knows no marketplace index.

## Troubleshooting

| Message | Cause |
|---------|-------|
| `StorageException: The directory '...' does not exist or is not readable.` | The path is wrong, or the plugin was not downloaded |
| `PluginException: No plugin manifest found` | The directory has neither `plugin.json` nor a legacy manifest |
| `PluginException: ... unsupported $schema` | The manifest declares a schema version this loader does not implement |
| `PluginException: ... is not valid JSON` | A manifest or MCP configuration file is broken |
| `MCP configuration 'mcp.json': ... The MCP servers have not been loaded.` | The portable MCP configuration does not follow the schema |
| `MCP server 'x' skipped: stdio servers are not supported` | The server is a local process. Only HTTP servers are supported |
| `MCP server 'x' skipped: ...` | Unknown type, field, URL or headers: the entry breaks a rule |
| `MCP server 'x': ...` | The server failed to connect or refused the credentials. Check `server()` |
| `server()` has no effect | The name is not in `servers()`: it is ignored |
| `Skill 'x': the name is missing` | `SKILL.md` has no frontmatter `name` |
| `Skill 'x': ... Loaded anyway.` | Not an error: the skill breaks a rule of the format and is usable |

## API

| Method | Returns |
|--------|---------|
| `Plugin::make($source)` | The plugin. `$source` is a directory or a `StorageInterface`. Throws when the manifest is unusable |
| `->server($name, $config)` | The plugin, with the credentials of one server |
| `->tools()` | The MCP tools, then the skill tool. Contacts the servers once |
| `->errors()` | Every problem met while loading |
| `->name()`, `->description()` | From the manifest |
| `->skills()` | The skills, by name |
| `->servers()` | The connector configuration, by server name |
| `->manifest()` | The decoded manifest, as an array |
| `->openAsset($path)` | A stream on a file of the plugin, or null |
| `Skills::make($source, $directory = '')` | A skills source: a toolkit with one `skill` tool |

## Rules

- **Trust.** Loading a plugin means trusting its instructions and its MCP server. Pointing at a directory is the trust decision. Skill scripts are never executed and paths are contained.
- **Credentials** come from the project, never from the plugin files.
- **HTTP only.** stdio and `ws` servers are skipped and reported.
- **No marketplace tooling.** The loader reads one directory at a time. Scanning, indexing, installing and updating are the application's job.
- **Custom storage.** `StorageInterface` is read-only: `read()`, `open()` for a stream, `children()`. Expose the plugin through a path string or pass your own implementation; a database-backed one should hold identifiers and connect lazily, because the storage ends up in the agent state and inside serialized tools.
