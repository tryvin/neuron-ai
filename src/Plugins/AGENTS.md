# Plugins Module

Loads Agent Plugins (<https://agent-plugins.org>): a directory bundling MCP servers, skills (instructions for the model) and a manifest. Neuron loads plugins. It does not create, install or publish them.

```
my-plugin/
├── plugin.json              manifest
├── skills/<name>/SKILL.md   skills, in the Agent Skills format
└── mcp.json                 MCP servers
```

Related guides: `src/Skills/AGENTS.md` for skills alone, `src/MCP/AGENTS.md` for the connector, `src/Storage/AGENTS.md` to read plugins from somewhere else than the filesystem.

## Add Plugin Support to an Agent

1. **Get the plugin directory** into the project, for example under `plugins/`: clone it, download it, or write it (see [Write a Plugin](#write-a-plugin)). The loader never fetches anything from the network. OpenAI keeps reference plugins for Notion, Figma, Zoom and others in the `openai/plugins` repository on GitHub.

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

   Registering `Plugin` is the opt-in. An agent without it loads no skill and contacts no server.

4. **Pass the credentials.** Most MCP servers want a bearer token or an API key header, and a plugin that relies on OAuth is no exception: Neuron runs no OAuth flow, so the token comes from the project. `server()` takes a server name and the configuration to merge over the one of the plugin:

   | Key | Effect |
   |-----|--------|
   | `token` | Sent as `Authorization: Bearer <token>` |
   | `headers` | Merged with the headers of the plugin. A header with the same name replaces it |
   | `timeout` | Seconds before a request gives up, 30 by default |

   Call it before the tools are used. Never write credentials into the files of a plugin.

   The name is the key of an entry in `mcpServers`. When the plugin is not yours, take it from `servers()`, which lists every supported server with its URL and headers:

   ```php
   $plugin = Plugin::make($dir);

   foreach ($plugin->servers() as $name => $config) {
       $plugin->server($name, ['token' => $credentials->for($config['url'])]);
   }
   ```

   A name that `servers()` does not list is ignored. Skipped servers (stdio, unknown types) are not listed: they are in `errors()`.

5. **Test without the network** (see [Testing Without the Network](#testing-without-the-network)).

## What the Agent Gets

- **The MCP tools of the plugin**, with their original names.
- **One `skill` tool** listing the skills of the plugin with their descriptions. When a task matches a skill, the model calls `skill(name)` and gets its instructions. It can then read the files bundled with the skill with `skill(name, file)`. Nothing enters the context before that call. Details in `src/Skills/AGENTS.md`.

With several plugins, or plugins and `Skills` together, all the skills appear in the same single `skill` tool. Two tools with the same name (two plugins, or a plugin and a tool of the project): the first registered wins and the other is dropped.

To use only the MCP servers of a plugin, opt its skills out: `->exclude([\NeuronAI\Skills\SkillTool::class])`.

## Build a Listing

A marketplace shows plugins before any agent uses them. A loaded `Plugin` tells what it declares, without contacting anything:

```php
use NeuronAI\Plugins\Plugin;

$plugin = Plugin::make($dir);

$plugin->name();         // 'canva', the name of the manifest
$plugin->description();  // ?string, from the manifest
$plugin->skills();       // Skill[], by name, each with getDescription()
$plugin->servers();      // connector configuration, by server name
$plugin->errors();
```

**Branding** is not part of the portable manifest. The plugins in `openai/plugins` declare it in the `interface` block of their legacy Codex manifest, with plugin-relative paths:

| Field | Content |
|-------|---------|
| `displayName`, `shortDescription`, `longDescription` | Text for the listing |
| `developerName`, `category`, `websiteURL` | Who, what, where |
| `composerIcon`, `logo`, `logoDark` | Paths such as `./assets/logo.png` |
| `brandColor`, `brandColorDark` | Hex colors |
| `screenshots` | Array of paths |

Read it from `manifest()`, which returns the decoded manifest as an array, and stream the files with `openAsset()`:

```php
$interface = $plugin->manifest()['interface'] ?? [];
$logo = $interface['logo'] ?? null;

if (is_string($logo) && ($stream = $plugin->openAsset($logo)) !== null) {
    header('Content-Type: image/png'); // from the extension of the path
    fpassthru($stream);
    fclose($stream);
}
```

- `openAsset()` returns a stream, a resource, or null when the path is invalid or the file is missing. The file is never loaded into memory and the path cannot escape the plugin.
- A portable plugin has no `interface` block, and a legacy one may declare no logo: design the listing with a fallback.
- Finding the plugin folders is up to the application. The loader reads one directory at a time and knows no marketplace index.

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

Each server gets its own connector, so queue one `tools/list` response per call. The skills of the plugin are still read from the directory: they need no fake.

## Layouts

| Layout | Manifest | Skills | MCP configuration |
|--------|----------|--------|-------------------|
| Portable | `plugin.json` | `skills/` | `mcp.json` |
| Legacy (Codex, Claude Code) | `.codex-plugin/plugin.json`, then `.claude-plugin/plugin.json` | `skills/`, or the `skills` path of the manifest | `.mcp.json`, or the `mcpServers` path of the manifest |

`plugin.json` wins: when it exists the legacy files are ignored. No manifest at all throws `PluginException`.

- **Portable**: validated against the schema. A wrong `$schema` or `name`, or a known field of the wrong type, throws `PluginException`. Unknown fields are reported and ignored.
- **Legacy**: only `name` is required. Unknown fields are ignored. The plugins in `openai/plugins` still ship this layout.

## MCP Servers

Only HTTP servers are supported.

| Entry | Connector |
|-------|-----------|
| `streamable-http`, legacy `http` | Streamable HTTP |
| `sse` | SSE transport |
| `stdio`, `ws`, `sdk`, unknown types | Skipped and reported |

- **Portable `mcp.json`** is strict: the fields must be exactly `$schema` and `mcpServers`, and an entry only the fields of its transport. A wrong `$schema` or top-level shape disables the MCP servers of the plugin. An invalid entry only skips itself.
- **Legacy `.mcp.json`** ignores unknown fields. An entry with a `command` and no `type` is a stdio server.
- **URLs** must be absolute `http` or `https`, without user info and fragment. Plain `http` is allowed only on loopback hosts.
- **Headers** must be valid names with printable values, unique case-insensitively.
- **Failure isolation.** A server that fails to connect is reported, and the other servers and the skills keep loading.

The servers are contacted when `tools()` is called, once per plugin instance. `errors()` grows as the plugin is used: connection failures appear there after that call.

## Troubleshooting

| Message | Cause |
|---------|-------|
| `StorageException: The directory '...' does not exist or is not readable.` | The path is wrong, or the plugin was not downloaded |
| `PluginException: No plugin manifest found` | The directory has neither `plugin.json` nor a legacy manifest: it is not a plugin |
| `PluginException: ... unsupported $schema` | The manifest declares a schema version this loader does not implement |
| `PluginException: ... is not valid JSON` | A manifest or MCP configuration file is broken |
| `MCP configuration 'mcp.json': ... The MCP servers have not been loaded.` | The portable MCP configuration does not follow the schema |
| `MCP server 'x' skipped: stdio servers are not supported` | The server is a local process. Only HTTP servers are supported |
| `MCP server 'x' skipped: ...` | The entry breaks a rule above (unknown type, field, URL or headers) |
| `MCP server 'x': ...` | The server failed to connect or refused the credentials. Check `server()` |
| `server()` has no effect | The name is not in `servers()`: it is ignored |
| `Skill 'x': the name is missing` | `SKILL.md` has no frontmatter `name` |
| `Skill 'x': ... Loaded anyway.` | Not an error: the skill breaks a rule of the format and is usable |

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

The `name` goes up to 64 lowercase letters and digits, with single dots and hyphens, and cannot start or end with one.

`skills/acme-search/SKILL.md`: how to write one in `src/Skills/AGENTS.md`.

```markdown
---
name: acme-search
description: Search the Acme documentation for a topic. Use when the user asks how Acme works.
---

# Acme search

1. Call `search` with the topic the user asks about.
2. Read the page of the best result before answering.
```

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

Write instructions for the model, not for a human: say when to use a skill, and which tool to call. Keep credentials out of the files.

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

## Key Files

| File | Purpose |
|------|---------|
| `Plugin.php` | Toolkit: manifest, MCP configuration and skills |

## Dependencies

`Skills`, `MCP` (connector), `Storage`, `Tools`.
