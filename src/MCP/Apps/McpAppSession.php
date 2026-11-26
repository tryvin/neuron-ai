<?php

declare(strict_types=1);

namespace NeuronAI\MCP\Apps;

use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\Tools\ToolOutput;
use stdClass;

use function array_is_list;
use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function strlen;
use function array_filter;

/**
 * The host side of one app view. It translates JSON-RPC from the view into
 * calls on the connector that produced the view's tool, and returns prepared
 * results and actions for the backend to carry out.
 */
final class McpAppSession
{
    protected const PROTOCOL_VERSION = '2026-01-26';
    protected const MAX_CONTEXT_BYTES = 100000;

    protected bool $initialized = false;

    /** @var array<string, mixed> */
    protected array $hostCapabilities = [];

    /** @var array<string, mixed> */
    protected array $context;

    /** @var array<string, mixed> */
    protected array $appCapabilities = [];

    /** @var array<string, mixed> */
    protected array $appInfo = [];

    /** @var array<string, mixed>|null */
    protected ?array $modelContext = null;

    protected McpAppPolicyInterface $policy;

    protected string $viewId;

    protected string $toolName;

    protected int|string|null $callId;

    /** @var array<string, mixed> */
    protected array $hostInfo;

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws McpAppException
     */
    public function __construct(
        protected McpConnector $connector,
        array $config,
    ) {
        $viewId = $config['view'] ?? null;
        $toolName = $config['tool'] ?? null;

        if (!is_string($viewId) || $viewId === '' || !is_string($toolName) || $toolName === '') {
            throw new McpAppException('An app session needs a view id and a tool name.');
        }

        $this->viewId = $viewId;
        $this->toolName = $toolName;
        $this->callId = is_int($config['callId'] ?? null) || is_string($config['callId'] ?? null)
            ? $config['callId']
            : null;
        $this->hostInfo = is_array($config['host'] ?? null) ? $config['host'] : [
            'name' => 'neuron',
            'version' => '0.0.0',
        ];
        $this->context = is_array($config['context'] ?? null) ? $config['context'] : [];
        $this->policy = $config['policy'] ?? new DefaultMcpAppPolicy();
        $this->hostCapabilities = $this->capabilities(is_array($config['capabilities'] ?? null) ? $config['capabilities'] : []);
    }

    /**
     * Handle one JSON-RPC message from the view. Notifications have no id and
     * therefore no response; requests get a prepared response and, when the
     * request asked for a side effect, an action for the backend.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws McpException
     */
    public function handle(array $message): McpAppResult
    {
        $hasId = array_key_exists('id', $message);
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';

        if (($message['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return $hasId ? McpAppResult::error($message, -32600, 'Invalid Request') : new McpAppResult();
        }

        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if ($method === 'ui/initialize') {
            return $this->initialize($message, $params);
        }

        if ($method === 'ui/notifications/initialized') {
            $this->initialized = true;

            return new McpAppResult();
        }

        if (!$this->initialized) {
            return $hasId ? McpAppResult::error($message, -32000, 'App is not initialized') : new McpAppResult();
        }

        return match ($method) {
            'ping' => $this->result($message, new stdClass()),
            'tools/list' => $this->listTools($message),
            'tools/call' => $this->callTool($message, $params),
            'resources/read' => $this->readResource($message, $params),
            'ui/open-link' => $this->openLink($message, $params),
            'ui/message' => $this->message($message, $params),
            'ui/update-model-context' => $this->updateModelContext($message, $params),
            'ui/request-display-mode' => $this->requestDisplayMode($message, $params),
            'notifications/message' => new McpAppResult(action: new McpAppAction(McpAppAction::LOG, $params)),
            'ui/notifications/size-changed' => new McpAppResult(),
            default => McpAppResult::error($message, -32601, 'Method not found'),
        };
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function viewId(): string
    {
        return $this->viewId;
    }

    public function serverName(): string
    {
        return $this->connector->name();
    }

    public function toolName(): string
    {
        return $this->toolName;
    }

    public function callId(): int|string|null
    {
        return $this->callId;
    }

    /** @return array<string, mixed>|null */
    public function modelContext(): ?array
    {
        return $this->modelContext;
    }

    /** @return array<string, mixed>|null */
    public function takeModelContext(): ?array
    {
        $context = $this->modelContext;
        $this->modelContext = null;

        return $context;
    }

    public function clearModelContext(): void
    {
        $this->modelContext = null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function toolInput(array $arguments): array
    {
        return $this->notification('ui/notifications/tool-input', ['arguments' => (object) $arguments]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function toolInputPartial(array $arguments): array
    {
        return $this->notification('ui/notifications/tool-input-partial', ['arguments' => (object) $arguments]);
    }

    /**
     * Build the CallToolResult the view expects. ToolOutput holds the text the
     * model read, so the view gets one text block with that same text.
     *
     * @return array<string, mixed>
     */
    public function toolResult(ToolOutput|array $result): array
    {
        if (is_array($result)) {
            return $this->notification('ui/notifications/tool-result', $result);
        }

        $params = ['content' => [['type' => 'text', 'text' => $result->text ?? '']]];

        if ($result->structuredContent !== null) {
            $params['structuredContent'] = $result->structuredContent;
        }

        $meta = $result->meta['mcp']['resultMeta'] ?? null;

        if (is_array($meta)) {
            $params['_meta'] = $meta;
        }

        if (($result->meta['mcp']['isError'] ?? false) === true) {
            $params['isError'] = true;
        }

        return $this->notification('ui/notifications/tool-result', $params);
    }

    /** @return array<string, mixed> */
    public function toolCancelled(string $reason = ''): array
    {
        return $this->notification('ui/notifications/tool-cancelled', ['reason' => $reason]);
    }

    /** @return array<string, mixed> */
    public function resourceTeardown(int $id, string $reason): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'ui/resource-teardown',
            'params' => ['reason' => $reason],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function hostContextChanged(array $context): array
    {
        return $this->notification('ui/notifications/host-context-changed', $context === [] ? new stdClass() : $context);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $params
     */
    protected function initialize(array $message, array $params): McpAppResult
    {
        $this->appInfo = is_array($params['appInfo'] ?? null)
            ? $params['appInfo']
            : (is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : []);
        $this->appCapabilities = is_array($params['appCapabilities'] ?? null) ? $params['appCapabilities'] : [];

        $tool = ['name' => $this->toolName];

        foreach ($this->connector->appTools() as $candidate) {
            if (($candidate['name'] ?? null) === $this->toolName) {
                $tool = $candidate;
                break;
            }
        }

        $context = $this->context;
        $context['toolInfo'] = ['id' => $this->callId, 'tool' => $tool];

        return $this->result($message, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'hostCapabilities' => $this->hostCapabilities === [] ? new stdClass() : $this->hostCapabilities,
            'hostInfo' => $this->hostInfo,
            'hostContext' => $this->contextWithObjects($context),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function capabilities(array $config): array
    {
        $capabilities = [];

        if (($config['experimental'] ?? null) !== null && is_array($config['experimental'])) {
            $capabilities['experimental'] = (object) $config['experimental'];
        }

        if (($config['openLinks'] ?? false) === true) {
            $capabilities['openLinks'] = new stdClass();
        }

        foreach (['serverTools', 'serverResources'] as $name) {
            if (($config[$name]['listChanged'] ?? false) === true) {
                $capabilities[$name] = ['listChanged' => true];
            }
        }

        if (($config['logging'] ?? false) === true) {
            $capabilities['logging'] = new stdClass();
        }

        if (is_array($config['sandbox'] ?? null)) {
            $capabilities['sandbox'] = $config['sandbox'];
        }

        return $capabilities;
    }

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws McpException
     */
    protected function listTools(array $message): McpAppResult
    {
        $tools = array_values(array_filter(
            $this->connector->appTools(),
            fn (array $tool): bool => $this->policy->allowToolCall(
                $this->connector->name(),
                (string) ($tool['name'] ?? ''),
                []
            ),
        ));

        return $this->result($message, ['tools' => $tools]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $params
     *
     * @throws McpException
     */
    protected function callTool(array $message, array $params): McpAppResult
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (
            !is_string($name)
            || !is_array($arguments)
            || ($arguments !== [] && array_is_list($arguments))
        ) {
            return McpAppResult::error($message, -32000, 'Invalid tool call');
        }

        $known = false;

        foreach ($this->connector->appTools() as $tool) {
            if (($tool['name'] ?? null) === $name) {
                $known = true;
                break;
            }
        }

        if (!$known || !$this->policy->allowToolCall($this->connector->name(), $name, $arguments)) {
            return McpAppResult::error($message, -32000, 'Tool call not allowed');
        }

        try {
            $result = $this->connector->callTool($name, $arguments);
        } catch (McpException $exception) {
            return McpAppResult::error($message, -32000, $exception->getMessage());
        }

        return $this->result($message, $result);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $params
     *
     * @throws McpException
     */
    protected function readResource(array $message, array $params): McpAppResult
    {
        $uri = $params['uri'] ?? null;

        if (!is_string($uri) || $uri === '') {
            return McpAppResult::error($message, -32000, 'Invalid resource URI');
        }

        if (!$this->policy->allowResourceRead($this->connector->name(), $uri)) {
            return McpAppResult::error($message, -32000, 'Resource read not allowed');
        }

        try {
            $result = $this->connector->readResource($uri);
        } catch (McpException $exception) {
            return McpAppResult::error($message, -32000, $exception->getMessage());
        }

        return $this->result($message, $result);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $params
     */
    protected function openLink(array $message, array $params): McpAppResult
    {
        $url = $params['url'] ?? null;

        if (!is_string($url) || !$this->policy->allowOpenLink($url)) {
            return McpAppResult::error($message, -32000, 'Link not allowed');
        }

        return new McpAppResult(
            response: $this->response($message, new stdClass()),
            action: new McpAppAction(McpAppAction::OPEN_LINK, ['url' => $url]),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $params
     */
    protected function message(array $message, array $params): McpAppResult
    {
        $role = $params['role'] ?? 'user';
        $content = $params['content'] ?? null;
        $text = is_array($content) ? ($content['text'] ?? null) : null;

        if (
            $role !== 'user'
            || !is_array($content)
            || ($content['type'] ?? null) !== 'text'
            || !is_string($text)
            || $text === ''
            || strlen($text) > self::MAX_CONTEXT_BYTES
        ) {
            return McpAppResult::error($message, -32000, 'Invalid message');
        }

        return new McpAppResult(
            response: $this->response($message, new stdClass()),
            action: new McpAppAction(McpAppAction::MESSAGE, ['role' => 'user', 'text' => $text]),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $params
     */
    protected function updateModelContext(array $message, array $params): McpAppResult
    {
        $context = [];

        foreach (['content', 'structuredContent'] as $key) {
            if (array_key_exists($key, $params) && is_array($params[$key])) {
                $context[$key] = $params[$key];
            }
        }

        $encoded = json_encode($context);

        if ($context === [] || $encoded === false || strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            return McpAppResult::error($message, -32000, 'Invalid model context');
        }

        // Each request replaces the previous context; it is not appended.
        $this->modelContext = $context;

        return new McpAppResult(
            response: $this->response($message, new stdClass()),
            action: new McpAppAction(McpAppAction::UPDATE_MODEL_CONTEXT, $context),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $params
     */
    protected function requestDisplayMode(array $message, array $params): McpAppResult
    {
        $requested = $params['mode'] ?? null;

        if (!is_string($requested) || !in_array($requested, ['inline', 'fullscreen', 'pip'], true)) {
            return McpAppResult::error($message, -32000, 'Invalid display mode');
        }

        $available = $this->appCapabilities['availableDisplayModes'] ?? null;
        $current = is_string($this->context['displayMode'] ?? null) ? $this->context['displayMode'] : 'inline';

        if (!is_array($available) || in_array($requested, $available, true)) {
            $current = $requested;
            $this->context['displayMode'] = $requested;
        }

        return new McpAppResult(
            response: $this->response($message, ['mode' => $current]),
            action: new McpAppAction(McpAppAction::DISPLAY_MODE, ['mode' => $current]),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|object  $result
     */
    protected function result(array $message, array|object $result): McpAppResult
    {
        return new McpAppResult(response: $this->response($message, $result));
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|object  $result
     * @return array<string, mixed>
     */
    protected function response(array $message, array|object $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result];
    }

    /** @return array<string, mixed> */
    protected function notification(string $method, array|object $params): array
    {
        return ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function contextWithObjects(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value === []) {
                $context[$key] = new stdClass();
            }
        }

        return $context;
    }
}
