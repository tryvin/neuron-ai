# MCP Apps chat example

A small chat UI that shows the whole MCP Apps chain:

```
iframe (view) --postMessage--> host page bridge --HTTP--> PHP backend
    --> Neuron MCP client and app session --> MCP fixture server
```

Neuron owns the MCP client and the app proxy. This example owns HTTP, the
browser bridge, the iframe, the conversation store and the actions. No HTTP
code lives in `src/`.

## Run

```bash
cd examples/mcp-apps
cp .env.example .env   # optional
chmod +x bin/run.sh
./bin/run.sh
```

Open <http://127.0.0.1:8080>. The first request installs the Node fixture
dependencies if needed. Three loopback processes are started and stopped with
the script:

| Port | Role |
|------|------|
| `8080` | Chat page and `/api/*` |
| `8081` | Separate origin that serves the app view HTML with CSP |
| `3001` | MCP Apps fixture server |

Offline mode needs no model key. `DemoProvider` calls `get_sales` when the
message mentions "sales" and `get_time` when it mentions "time", so the agent
loop, tool execution and app session all run without network calls to a model
provider. Set `DEMO_PROVIDER=openai` or `anthropic` plus the matching key in
`.env` to let a real model choose the tools.

## What to try

- Ask about **sales**. The agent calls `get_sales`, and a tool card appears
  with the dashboard view. The dashboard is rendered by HTML from the MCP
  `ui://` resource, not by a chat message.
- Click a region button. The view calls `get_sales` through the host. The table
  updates, but `GET /api/conversation/demo` shows no new messages.
- Click **Refresh**. The view calls `refresh_sales`, an app-only tool. It does
  not appear in the model's tool list and never enters the agent loop.
- Click **Stage context**, then **Send summary to chat**. The staged context is
  folded into the next `UserMessage`, not appended as a second user turn.
- Click **Blocked link**. The policy rejects `javascript:` and the view shows
  the error.
- Ask about **time**. `get_time` returns text only, so no iframe is mounted.
- Reload the page. The transcript and the view cards are restored from JSON
  records under `var/`.

Useful checks:

```bash
curl http://127.0.0.1:8080/api/conversation/demo | python3 -m json.tool
curl -sSI http://127.0.0.1:8081/app/view/view_demo_1 | grep -i content-security-policy
```

## Files

| File | Purpose |
|------|---------|
| `router.php` | Host routes and the separate view origin (`MCP_APPS_ROLE`) |
| `AppAgent.php` | Agent subclass, connector configuration, provider selection |
| `DemoProvider.php` | Deterministic tool-calling provider for offline runs |
| `Store.php` | JSON chat history, view records and staged context |
| `public/` | Chat page, transcript rendering and the small host bridge |
| `fixture/` | MCP server, app resource and plain-`postMessage` view |

`var/` is runtime state and is ignored by Git.

## Bridge contract

The view posts JSON-RPC to the host. The bridge accepts messages only from the
view origin and the exact iframe `contentWindow`; host-to-view messages use
that origin as the `postMessage` target. `ui/notifications/sandbox-*` messages
would stay in the bridge: this example's simple view does not need the sandbox
proxy handshake, but a production host should follow the spec and use the
official bridge.

A request becomes `POST /api/view/{viewId}/rpc`. The backend rebuilds a
`McpAppSession` bound to that view's connector, tool and call id, calls
`handle()`, executes the returned `McpAppAction` and returns the prepared
JSON-RPC response plus any actions. After `ui/notifications/initialized`, the
bridge replays `tool-input` and `tool-result` from the view record. The HTML
never enters chat history.

## Security

Local demo only: no auth, no user data. The view is served from `8081`, the
host from `8080`, and the CSP is default-deny with `frame-ancestors` fixed to
the host origin. `MCP_APP_ALLOWED_DOMAINS` can list domains an app declares in
its CSP metadata; the host intersects that list with the server's request and
never loosens it. Links are checked by `DefaultMcpAppPolicy`. Staged context is
size-capped by `McpAppSession` and labelled when it is folded into a user
message.

For production use the official `@modelcontextprotocol/ext-apps/app-bridge`
(or MCP-UI), a dedicated view domain, real authentication and per-user
conversation storage.
