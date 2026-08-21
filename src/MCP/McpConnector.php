<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\StaticConstructor;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use Exception;
use ReflectionException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function call_user_func;
use function in_array;
use function is_array;
use function is_null;
use function is_string;

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
     * Get the list of available Tools from the server.
     *
     * @return ToolInterface[]
     * @throws Exception
     */
    public function tools(): array
    {
        // Filter by the only and exclude preferences.
        $tools = array_filter(
            $this->client()->listTools(),
            fn (array $tool): bool =>
                !in_array($tool['name'], $this->exclude) &&
                ($this->only === [] || in_array($tool['name'], $this->only)),
        );

        return array_map($this->createTool(...), $tools);
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
        )->setCallable(
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
     * This might look counter-intuitive, but when dealing with interrupts and serialization PHP doesnt allow for MCP connectors serialization
     * @throws McpException
     */
    public function invokeTool(array $item, array $arguments): mixed
    {
        $response = call_user_func(
            $this->client()->callTool(...),
            $item['name'],
            $arguments,
            $this->paramHeadersFor($item, $arguments)
        );

        if (array_key_exists('error', $response)) {
            throw new McpException($response['error']['message'], $response['error']['code'] ?? 0);
        }

        if (isset($response['result']) && is_array($response['result']) && array_key_exists('content', $response['result'])) {
            return $response['result']['content'];
        }

        return '';
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
