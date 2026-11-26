<?php

declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/AppAgent.php';
require_once __DIR__.'/DemoProvider.php';
require_once __DIR__.'/Store.php';

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\MCP\Apps\McpAppAction;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\Apps\McpAppResource;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\HasMeta;
use NeuronAI\Tools\HasOutput;

$role = \getenv('MCP_APPS_ROLE') ?: 'host';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = \parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';
$store = new Store();

if ($role === 'view') {
    mcp_apps_handle_view($store, $path);

    return true;
}

if ($method === 'GET' && $path === '/api/config') {
    mcp_apps_respond([
        'hostOrigin' => mcp_apps_host_origin(),
        'viewOrigin' => mcp_apps_view_origin(),
    ]);

    return true;
}

if ($method === 'GET' && \preg_match('#^/api/conversation/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
    mcp_apps_respond($store->conversation($matches[1]));

    return true;
}

if ($method === 'POST' && $path === '/api/chat') {
    mcp_apps_handle_chat($store, mcp_apps_body());

    return true;
}

if ($method === 'POST' && \preg_match('#^/api/view/([A-Za-z0-9_-]+)/rpc$#', $path, $matches) === 1) {
    mcp_apps_handle_rpc($store, $matches[1], mcp_apps_body());

    return true;
}

return false;

function mcp_apps_handle_chat(Store $store, array $body): void
{
    $conversationId = mcp_apps_conversation_id($body);
    $message = $body['message'] ?? null;

    if (!\is_string($message) || \trim($message) === '' || \strlen($message) > 4000) {
        mcp_apps_respond(['error' => 'A message of 1 to 4000 characters is required.'], 400);

        return;
    }

    $metadata = $store->metadata($conversationId);
    $staged = \is_array($metadata['stagedContext'] ?? null) ? $metadata['stagedContext'] : null;
    $metadata['stagedContext'] = null;
    $store->saveMetadata($conversationId, $metadata);

    $userText = $message;

    if ($staged !== null && $staged !== []) {
        $userText .= "\n\n[Context shared by the app view for this message]\n"
            .\json_encode($staged, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    try {
        $connector = AppAgent::makeConnector();
        $agent = new AppAgent($connector, mcp_apps_provider());
        $history = $store->history($conversationId);
        $agent->setChatHistory($history);

        $beforeIds = \array_map(
            static fn (Message $item): mixed => $item->getMetadata('__id'),
            $history->getMessages()
        );

        $agent->chat(new UserMessage($userText))->getMessage();

        $newMessages = \array_values(\array_filter(
            $history->getMessages(),
            static fn (Message $item): bool => !\in_array($item->getMetadata('__id'), $beforeIds, true)
        ));

        $views = [];

        foreach ($newMessages as $item) {
            if (!$item instanceof ToolResultMessage) {
                continue;
            }

            foreach ($item->getTools() as $tool) {
                $view = mcp_apps_view_for_tool($store, $connector, $conversationId, $tool);

                if ($view !== null) {
                    $views[] = $view;
                }
            }
        }

        mcp_apps_respond([
            'conversationId' => $conversationId,
            'messages' => \array_map(
                static fn (Message $item): array => $item->jsonSerialize(),
                $newMessages
            ),
            'views' => $views,
            'stagedContext' => null,
            'appsSupported' => $connector->hasExtension('io.modelcontextprotocol/ui'),
        ]);
    } catch (Throwable $exception) {
        \error_log('MCP Apps example chat failed: '.$exception->getMessage());
        mcp_apps_respond(['error' => 'The chat request failed. Check the fixture server and var/host.log.'], 500);
    }
}

/**
 * @param array<string, mixed> $view
 */
function mcp_apps_handle_rpc(Store $store, string $viewId, array $message): void
{
    $view = $store->findView($viewId);

    if ($view === null) {
        mcp_apps_respond(['error' => 'Unknown view.'], 404);

        return;
    }

    $uri = $view['resourceUri'] ?? null;

    if (!\is_string($uri) || $uri === '') {
        mcp_apps_respond(['error' => 'This tool has no app view.'], 400);

        return;
    }

    $id = $message['id'] ?? null;
    $method = \is_string($message['method'] ?? null) ? $message['method'] : '';

    try {
        $connector = AppAgent::makeConnector();
        $resource = mcp_apps_resource_from_view($connector, $view);
        $session = $connector->appSession(mcp_apps_session_config($connector, $view, $resource));

        // A session is rebuilt per HTTP request. The bridge has already seen
        // the view's initialized notification, so prime this fresh session.
        if (($view['initialized'] ?? false) === true && $method !== 'ui/notifications/initialized') {
            $session->handle(['jsonrpc' => '2.0', 'method' => 'ui/notifications/initialized']);
        }

        $result = $session->handle($message);
        $actions = [];

        if ($result->action instanceof \NeuronAI\MCP\Apps\McpAppAction) {
            $actions[] = mcp_apps_execute_action($store, $view, $result->action);
        }

        if ($method === 'ui/notifications/initialized') {
            $view['initialized'] = true;
            $view['status'] = 'ready';
            $store->saveView($view);
        }

        $context = $session->modelContext();

        if ($context !== null) {
            mcp_apps_stage_context($store, $view, $context);
        }

        mcp_apps_respond([
            'response' => $result->response,
            'actions' => $actions,
        ]);
    } catch (Throwable $exception) {
        \error_log('MCP Apps example RPC failed: '.$exception->getMessage());
        mcp_apps_respond([
            'response' => $id === null ? null : [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32603, 'message' => 'Backend error'],
            ],
            'actions' => [['type' => 'error', 'message' => 'The app request failed.']],
        ]);
    }
}

function mcp_apps_handle_view(Store $store, string $path): void
{
    if (\preg_match('#^/app/view/([A-Za-z0-9_-]+)$#', $path, $matches) !== 1) {
        mcp_apps_respond(['error' => 'Not found.'], 404);

        return;
    }

    $view = $store->findView($matches[1]);

    if ($view === null) {
        mcp_apps_respond(['error' => 'Unknown view.'], 404);

        return;
    }

    $uri = $view['resourceUri'] ?? null;

    if (!\is_string($uri) || $uri === '') {
        mcp_apps_respond(['error' => 'This tool has no app view.'], 404);

        return;
    }

    try {
        $resource = AppAgent::makeConnector()->resource($uri);

        foreach ($resource->headers(mcp_apps_host_origin(), mcp_apps_allowed_domains()) as $name => $value) {
            \header($name.': '.$value);
        }

        echo $resource->html;
    } catch (Throwable $exception) {
        \error_log('MCP Apps example view failed: '.$exception->getMessage());
        mcp_apps_respond(['error' => 'The app resource could not be loaded.'], 500);
    }
}

/**
 * @return array<string, mixed>|null
 */
function mcp_apps_view_for_tool(Store $store, McpConnector $connector, string $conversationId, object $tool): ?array
{
    if (!$tool instanceof HasMeta || !$tool instanceof HasOutput) {
        return null;
    }

    $ui = $tool->getMeta()['mcp']['ui'] ?? null;
    $uri = \is_array($ui) ? ($ui['resourceUri'] ?? null) : null;

    if (!\is_string($uri) || $uri === '') {
        return null;
    }

    $output = $tool->getOutput();

    $view = [
        'id' => $store->nextViewId($conversationId),
        'conversationId' => $conversationId,
        'server' => $tool->getMeta()['mcp']['server'] ?? $connector->name(),
        'tool' => $tool->getName(),
        'callId' => $tool->getCallId(),
        'input' => $tool->getInputs(),
        'result' => $output->jsonSerialize(),
        'resourceUri' => $uri,
        'initialized' => false,
        'status' => 'preparing',
        'stagedContext' => null,
        'displayMode' => 'inline',
    ];

    try {
        $resource = $connector->resource($uri);
        $view['resource'] = [
            'mimeType' => $resource->mimeType,
            'meta' => $resource->meta,
            'allow' => $resource->allowAttribute(),
        ];

        $session = $connector->appSession(mcp_apps_session_config($connector, $view, $resource));
        $view['wire'] = [
            'toolInput' => $session->toolInput($tool->getInputs()),
            'toolResult' => $session->toolResult($output),
        ];
    } catch (Throwable $exception) {
        \error_log('MCP Apps example view record failed: '.$exception->getMessage());
        $view['status'] = 'error';
        $view['error'] = 'The app resource could not be loaded.';
    }

    $store->addView($conversationId, $view);

    return $view;
}

/**
 * @param array<string, mixed> $view
 */
function mcp_apps_resource_from_view(McpConnector $connector, array $view): McpAppResource
{
    $uri = (string) $view['resourceUri'];
    $resourceMeta = \is_array($view['resource']['meta'] ?? null) ? $view['resource']['meta'] : [];
    $mimeType = \is_string($view['resource']['mimeType'] ?? null)
        ? $view['resource']['mimeType']
        : 'text/html;profile=mcp-app';

    try {
        return $connector->resource($uri);
    } catch (Throwable) {
        return new McpAppResource($uri, $mimeType, '', $resourceMeta);
    }
}

/**
 * @param array<string, mixed> $view
 * @return array<string, mixed>
 */
function mcp_apps_session_config(McpConnector $connector, array $view, McpAppResource $resource): array
{
    return [
        'view' => (string) $view['id'],
        'tool' => (string) $view['tool'],
        'callId' => $view['callId'] ?? null,
        'host' => ['name' => 'neuron-mcp-apps-example', 'version' => '1.0.0'],
        'capabilities' => [
            'openLinks' => true,
            'logging' => true,
            'sandbox' => [
                'permissions' => $resource->permissions,
                'csp' => $resource->csp,
            ],
        ],
        'context' => [
            'theme' => 'light',
            'displayMode' => \is_string($view['displayMode'] ?? null) ? $view['displayMode'] : 'inline',
            'availableDisplayModes' => ['inline', 'fullscreen'],
            'locale' => 'en-US',
            'timeZone' => 'UTC',
            'platform' => 'web',
        ],
    ];
}

/**
 * @param array<string, mixed> $view
 * @return array<string, mixed>
 */
function mcp_apps_execute_action(Store $store, array $view, McpAppAction $action): array
{
    $payload = $action->payload;

    switch ($action->type) {
        case McpAppAction::MESSAGE:
            return [
                'type' => 'message',
                'text' => \is_string($payload['text'] ?? null) ? $payload['text'] : '',
            ];

        case McpAppAction::UPDATE_MODEL_CONTEXT:
            return [
                'type' => 'update_model_context',
                'staged' => true,
            ];

        case McpAppAction::OPEN_LINK:
            return [
                'type' => 'open_link',
                'url' => \is_string($payload['url'] ?? null) ? $payload['url'] : '',
            ];

        case McpAppAction::DISPLAY_MODE:
            $mode = \is_string($payload['mode'] ?? null) ? $payload['mode'] : 'inline';
            $view['displayMode'] = $mode;
            $store->saveView($view);

            return ['type' => 'display_mode', 'mode' => $mode];

        case McpAppAction::LOG:
            return [
                'type' => 'log',
                'level' => \is_string($payload['level'] ?? null) ? $payload['level'] : 'info',
                'data' => $payload,
            ];

        default:
            return ['type' => 'unknown'];
    }
}

/**
 * @param array<string, mixed> $view
 * @param array<string, mixed> $context
 */
function mcp_apps_stage_context(Store $store, array $view, array $context): void
{
    $conversationId = (string) ($view['conversationId'] ?? 'demo');
    $metadata = $store->metadata($conversationId);
    $metadata['stagedContext'] = $context;
    $store->saveMetadata($conversationId, $metadata);

    $view['stagedContext'] = $context;
    $view['status'] = 'ready';
    $store->saveView($view);
}

function mcp_apps_provider(): AIProviderInterface
{
    $mode = \strtolower(mcp_apps_env('DEMO_PROVIDER', ''));

    if ($mode === '') {
        $openaiKey = mcp_apps_env('OPENAI_API_KEY', '');
        $anthropicKey = mcp_apps_env('ANTHROPIC_API_KEY', '');
        $mode = $openaiKey !== '' ? 'openai' : ($anthropicKey !== '' ? 'anthropic' : 'fake');
    }

    if ($mode === 'fake') {
        return new DemoProvider();
    }

    if ($mode === 'openai') {
        return new OpenAI(
            key: mcp_apps_env('OPENAI_API_KEY', ''),
            model: mcp_apps_env('OPENAI_MODEL', 'gpt-4o-mini'),
        );
    }

    if ($mode === 'anthropic') {
        return new Anthropic(
            key: mcp_apps_env('ANTHROPIC_API_KEY', ''),
            model: mcp_apps_env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        );
    }

    throw new RuntimeException("Unknown DEMO_PROVIDER: {$mode}");
}

/**
 * @return array<string, mixed>
 */
function mcp_apps_body(): array
{
    $raw = \file_get_contents('php://input');
    $data = \is_string($raw) ? \json_decode($raw, true) : null;

    return \is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $body
 */
function mcp_apps_conversation_id(array $body): string
{
    $id = $body['conversationId'] ?? 'demo';

    return \is_string($id) && \preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : 'demo';
}

/**
 * @return array<int, string>
 */
function mcp_apps_allowed_domains(): array
{
    $raw = mcp_apps_env('MCP_APP_ALLOWED_DOMAINS', '');

    return \array_values(\array_filter(\array_map(trim(...), \explode(',', $raw))));
}

function mcp_apps_host_origin(): string
{
    return \rtrim(mcp_apps_env('HOST_ORIGIN', 'http://127.0.0.1:8080'), '/');
}

function mcp_apps_view_origin(): string
{
    return \rtrim(mcp_apps_env('VIEW_ORIGIN', 'http://127.0.0.1:8081'), '/');
}

function mcp_apps_env(string $name, string $default): string
{
    $value = \getenv($name);

    return \is_string($value) && $value !== '' ? $value : $default;
}

function mcp_apps_respond(mixed $data, int $status = 200): void
{
    \http_response_code($status);
    \header('Content-Type: application/json; charset=utf-8');
    echo \json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
}
