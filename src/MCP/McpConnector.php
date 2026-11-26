<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\MCP\Apps\McpAppException;
use NeuronAI\MCP\Apps\McpAppResource;
use NeuronAI\MCP\Apps\McpAppSession;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use Exception;
use ReflectionException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_values;
use function base64_decode;
use function implode;
use function in_array;
use function is_array;
use function is_null;
use function is_string;
use function json_encode;
use function parse_url;

/**
 * @method static static make(array<string, mixed> $config)
 */
class McpConnector
{
    use StaticConstructor;

    protected McpClient $client;

    /**
     * @var string[]
     */
    protected array $exclude = [];

    /**
     * @var string[]
     */
    protected array $only = [];

    /**
     * Raw tool definitions from the server, as returned by tools/list.
     * Fetched once per connector instance, not kept through serialization.
     *
     * @var list<array<string, mixed>>|null
     */
    protected ?array $rawTools = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config)
    {
    }

    /**
     * @throws McpException
     */
    protected function client(): McpClient
    {
        return $this->client ??= new McpClient($this->config);
    }

    /**
     * Label of this server, for audit, policies and view binding. It is the
     * "name" entry of the configuration. Without one: the URL without what
     * must never reach history (credentials, query, fragment), or the command.
     */
    public function name(): string
    {
        $name = $this->config['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (is_string($this->config['url'] ?? null) && is_array($parts = parse_url($this->config['url']))) {
            return ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '')
                . (isset($parts['port']) ? ":{$parts['port']}" : '')
                . ($parts['path'] ?? '');
        }

        return (string) ($this->config['command'] ?? '');
    }

    public function __serialize(): array
    {
        return [
            'config' => $this->config,
            'only' => $this->only,
            'exclude' => $this->exclude,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->config = $data['config'];
        $this->only = $data['only'];
        $this->exclude = $data['exclude'];
    }

    /**
     * @param  string[]  $tools
     */
    public function exclude(array $tools): McpConnector
    {
        $this->exclude = $tools;
        return $this;
    }

    /**
     * @param  string[]  $tools
     */
    public function only(array $tools): McpConnector
    {
        $this->only = $tools;
        return $this;
    }

    /**
     * Capabilities advertised by the server, including its extensions.
     *
     * @return array<string, mixed>|null
     *
     * @throws McpException
     */
    public function capabilities(): ?array
    {
        return $this->client()->getCapabilities();
    }

    /**
     * Whether the server advertised support for the given extension.
     *
     * @throws McpException
     */
    public function hasExtension(string $id): bool
    {
        return $this->client()->hasExtension($id);
    }

    /**
     * Settings the server advertised for an extension, or null when it did
     * not advertise the extension.
     *
     * @return array<string, mixed>|null
     *
     * @throws McpException
     */
    public function extensionSettings(string $id): ?array
    {
        return $this->client()->extensionSettings($id);
    }

    /**
     * Get the list of available Tools from the server.
     *
     * Tools built for an app only (_meta.ui.visibility without "model") stay
     * out of it: the agent must neither see nor call them.
     *
     * @return ToolInterface[]
     * @throws Exception
     */
    public function tools(): array
    {
        // Filter by the only and exclude preferences.
        $tools = array_filter(
            $this->rawTools(),
            fn (array $tool): bool =>
                $this->hasVisibility($tool, 'model') &&
                !in_array($tool['name'], $this->exclude) &&
                ($this->only === [] || in_array($tool['name'], $this->only)),
        );

        return array_map($this->createTool(...), $tools);
    }

    /**
     * Every tool the server declared, exactly as returned by tools/list:
     * app-only tools and _meta included.
     *
     * @return list<array<string, mixed>>
     *
     * @throws McpException
     */
    public function rawTools(): array
    {
        return $this->rawTools ??= $this->client()->listTools();
    }

    /**
     * Raw tool definitions an app may list and call. Unlike tools(), this
     * ignores only() and exclude(): those filter the model's tool list.
     *
     * @return list<array<string, mixed>>
     *
     * @throws McpException
     */
    public function appTools(): array
    {
        return array_values(array_filter(
            $this->rawTools(),
            fn (array $tool): bool => $this->hasVisibility($tool, 'app'),
        ));
    }

    /**
     * The _meta a raw tool was declared with, or [] when it has none.
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function toolMeta(string $name): array
    {
        foreach ($this->rawTools() as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
            }
        }

        return [];
    }

    /**
     * Call a tool by name for an app. The tool must already have been checked
     * against appTools(): this method does not apply the model's filters.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function callTool(string $name, array $arguments): array
    {
        return $this->callToolResult(['name' => $name], $arguments);
    }

    /**
     * The raw resources/read result, contents included.
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function readResource(string $uri): array
    {
        return $this->client()->readResource($uri);
    }

    /**
     * Fetch an HTML resource and merge what the server declared in
     * resources/list with what it sent in resources/read. On a conflict the
     * read metadata wins: it describes the bytes actually returned.
     *
     * @throws McpException
     * @throws McpAppException
     */
    public function resource(string $uri): McpAppResource
    {
        $listing = [];

        foreach ($this->client()->listResources() as $item) {
            if (is_array($item) && ($item['uri'] ?? null) === $uri) {
                $listing = $item;
                break;
            }
        }

        $contents = $this->readResource($uri)['contents'] ?? [];
        $content = null;

        if (is_array($contents)) {
            foreach ($contents as $item) {
                if (is_array($item) && ($item['uri'] ?? null) === $uri) {
                    $content = $item;
                    break;
                }
            }

            if ($content === null) {
                foreach ($contents as $item) {
                    if (is_array($item) && ($item['mimeType'] ?? null) === 'text/html;profile=mcp-app') {
                        $content = $item;
                        break;
                    }
                }
            }
        }

        if (!is_array($content)) {
            throw new McpAppException("The server did not return the app resource {$uri}.");
        }

        if (is_string($content['text'] ?? null)) {
            $html = $content['text'];
        } elseif (is_string($content['blob'] ?? null)) {
            $html = base64_decode($content['blob'], true);

            if ($html === false) {
                throw new McpAppException("The app resource {$uri} is not valid base64.");
            }
        } else {
            throw new McpAppException("The app resource {$uri} has no content.");
        }

        $mimeType = $content['mimeType'] ?? $listing['mimeType'] ?? '';
        $listingUi = is_array($listing['_meta']['ui'] ?? null) ? $listing['_meta']['ui'] : [];
        $contentUi = is_array($content['_meta']['ui'] ?? null) ? $content['_meta']['ui'] : [];

        return new McpAppResource($uri, (string) $mimeType, $html, ['ui' => array_merge($listingUi, $contentUi)]);
    }

    /**
     * The UI resource declared by a tool, or null when the tool has none.
     *
     * @throws McpException
     * @throws McpAppException
     */
    public function uiResourceFor(string $toolName): ?McpAppResource
    {
        $meta = $this->toolMeta($toolName);
        $uri = $meta['ui']['resourceUri'] ?? $meta['ui/resourceUri'] ?? null;

        return is_string($uri) ? $this->resource($uri) : null;
    }

    /**
     * A host-side proxy for one view. The connector is the only server the
     * session can call, which is how cross-server tool calls stay blocked.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws McpException
     */
    public function appSession(array $config): McpAppSession
    {
        return new McpAppSession($this, $config);
    }

    /**
     * Whether a tool is built for the given audience, "model" or "app".
     *
     * _meta.ui.visibility defaults to both when the server does not
     * declare it.
     *
     * @param array<string, mixed> $tool
     */
    protected function hasVisibility(array $tool, string $audience): bool
    {
        $visibility = $tool['_meta']['ui']['visibility'] ?? null;

        return ! is_array($visibility) || in_array($audience, $visibility, true);
    }

    /**
     * Convert the list of tools from the MCP server to Neuron compatible entities.
     *
     * @param array<string, mixed> $item
     * @throws ArrayPropertyException
     * @throws ReflectionException
     * @throws ToolException
     */
    protected function createTool(array $item): ToolInterface
    {
        $tool = Tool::make(
            name: $item['name'],
            description: $item['description'] ?? null,
            annotations: $item['annotations'] ?? [],
        )->setMeta($this->toolMetadata($item))->setCallable(
            new CallableMcpTool(
                connector: $this,
                item: $item,
            ) // This allows us to serialize MCP tools when dealing with interrupts
        );

        // If the tool has no properties, return early
        if (!isset($item['inputSchema']['properties']) || !is_array($item['inputSchema']['properties'])) {
            return $tool;
        }

        foreach ($item['inputSchema']['properties'] as $name => $prop) {
            $required = in_array($name, $item['inputSchema']['required'] ?? []);

            $type = PropertyType::fromSchema($prop['type'] ?? PropertyType::STRING->value);

            $property = match ($type) {
                PropertyType::ARRAY => $this->createArrayProperty($name, $required, $prop),
                PropertyType::OBJECT => $this->createObjectProperty($name, $required, $prop),
                default => $this->createToolProperty($name, $type, $required, $prop),
            };

            $tool->addProperty($property);
        }

        return $tool;
    }

    /**
     * @param array<string, mixed> $prop
     */
    protected function createToolProperty(string $name, PropertyType $type, bool $required, array $prop): ToolProperty
    {
        return new ToolProperty(
            name: $name,
            type: $type,
            description: $prop['description'] ?? null,
            required: $required,
            enum: $prop['items']['enum'] ?? $prop['enum'] ?? []
        );
    }

    /**
     * @param array<string, mixed> $prop
     * @throws ArrayPropertyException
     */
    protected function createArrayProperty(string $name, bool $required, array $prop): ArrayProperty
    {
        return new ArrayProperty(
            name: $name,
            description: $prop['description'] ?? null,
            required: $required,
            items: new ToolProperty(
                name: 'type',
                type: PropertyType::from($prop['items']['type'] ?? 'string'),
            )
        );
    }

    /**
     * @param array<string, mixed> $prop
     * @throws ArrayPropertyException
     * @throws ToolException
     * @throws ReflectionException
     */
    protected function createObjectProperty(string $name, bool $required, array $prop): ObjectProperty
    {
        return new ObjectProperty(
            name: $name,
            description: $prop['description'] ?? null,
            required: $required,
        );
    }

    /**
     * What a tool is, that the model never sees: the server it comes from
     * and, when it declares one, the UI resource an app renders it with.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function toolMetadata(array $item): array
    {
        $meta = ['mcp' => ['server' => $this->name()]];

        $ui = $item['_meta']['ui'] ?? null;

        if (is_array($ui)) {
            $meta['mcp']['ui'] = $ui;
        } elseif (is_string($item['_meta']['ui/resourceUri'] ?? null)) {
            // Deprecated flat form: read it under the current shape, never write it.
            $meta['mcp']['ui'] = ['resourceUri' => $item['_meta']['ui/resourceUri']];
        }

        return $meta;
    }

    /**
     * This might look counter-intuitive, but when dealing with interrupts and serialization PHP doesnt allow for MCP connectors serialization
     *
     * Returns the raw content blocks of the result, or an empty string.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $arguments
     *
     * @throws McpException
     */
    public function invokeTool(array $item, array $arguments): mixed
    {
        $result = $this->callToolResult($item, $arguments);

        return array_key_exists('content', $result) ? $result['content'] : '';
    }

    /**
     * Call a tool and return the whole result: the text the model reads, the
     * structured content an app reads and what the server said about it.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $arguments
     *
     * @throws McpException
     */
    public function invokeToolResult(array $item, array $arguments): ToolOutput
    {
        $result = $this->callToolResult($item, $arguments);

        $mcp = ['isError' => ($result['isError'] ?? null) === true];

        if (is_array($result['_meta'] ?? null)) {
            $mcp['resultMeta'] = $result['_meta'];
        }

        return new ToolOutput(
            text: $this->resultText($result),
            structuredContent: is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : null,
            meta: ['mcp' => $mcp],
        );
    }

    /**
     * Send tools/call for a raw tool definition and return the server's result.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    protected function callToolResult(array $item, array $arguments): array
    {
        $response = $this->client()->callTool(
            $item['name'],
            $arguments,
            $this->paramHeadersFor($item, $arguments)
        );

        if (array_key_exists('error', $response)) {
            throw new McpException($response['error']['message'], $response['error']['code'] ?? 0);
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * The text the model reads: the text blocks of the result, joined with a
     * newline. A result without text blocks is built for an app, so the model
     * reads its structuredContent as JSON instead. Without either, the content
     * blocks are JSON, as they were before, and an absent content is ''.
     *
     * @param array<string, mixed> $result
     */
    protected function resultText(array $result): string
    {
        $content = $result['content'] ?? null;
        $texts = [];

        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $texts[] = $block['text'];
                }
            }
        }

        if ($texts !== []) {
            return implode("\n", $texts);
        }

        if (isset($result['structuredContent'])) {
            return (string) json_encode($result['structuredContent']);
        }

        return is_array($content) ? (string) json_encode($content) : (string) $content;
    }

    /**
     * Extract Mcp-Param header values from the call arguments for tool
     * parameters annotated with x-mcp-header in their inputSchema. The
     * transport mirrors them into HTTP headers; the value lives only in
     * the body for other transports. Headers with no value present in
     * the arguments are omitted per the specification.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $arguments
     * @return array<string, string|int|bool>
     */
    protected function paramHeadersFor(array $item, array $arguments): array
    {
        $properties = $item['inputSchema']['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        $headers = [];

        foreach ($properties as $propertyName => $property) {
            if (! is_array($property)) {
                continue;
            }
            if (! isset($property['x-mcp-header'])) {
                continue;
            }
            $headerName = $property['x-mcp-header'];
            if (! is_string($headerName)) {
                continue;
            }
            if ($headerName === '') {
                continue;
            }

            if (array_key_exists($propertyName, $arguments) && ! is_null($arguments[$propertyName])) {
                $headers[$headerName] = $arguments[$propertyName];
            }
        }

        return $headers;
    }
}
