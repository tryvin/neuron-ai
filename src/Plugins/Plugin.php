<?php

declare(strict_types=1);

namespace NeuronAI\Plugins;

use NeuronAI\Exceptions\PluginException;
use NeuronAI\MCP\Apps\McpAppSession;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Skills\Skill;
use NeuronAI\Skills\Skills;
use NeuronAI\Storage\FileStorage;
use NeuronAI\Storage\Path;
use NeuronAI\Storage\StorageInterface;
use NeuronAI\Tools\HasMeta;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use JsonException;
use stdClass;
use Throwable;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function get_object_vars;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_match;
use function sort;
use function strcasecmp;
use function strlen;
use function strtolower;
use function array_values;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Loads an Agent Plugin (https://agent-plugins.org): a directory with a plugin.json manifest,
 * an optional skills folder and an optional mcp.json file.
 *
 * The legacy layout of Codex and Claude Code plugins (.codex-plugin/plugin.json or
 * .claude-plugin/plugin.json with a .mcp.json file) is loaded when there is no plugin.json.
 *
 * Only HTTP MCP servers are supported: stdio, ws and sdk servers are skipped and reported.
 *
 * @method static static make(string|StorageInterface $source)
 */
class Plugin extends AbstractToolkit
{
    public const MANIFEST_SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json';

    public const MCP_SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json';

    /**
     * A plugin name: lowercase letters and digits, with single dots and hyphens, not starting
     * nor ending with a dot or hyphen.
     */
    protected const NAME_PATTERN = '/^(?!.*(?:--|\.\.))[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D';

    protected StorageInterface $storage;

    /**
     * Prepended to the name of every MCP tool of the plugin.
     */
    protected string $prefix = '';

    protected Skills $skills;

    /**
     * The manifest of the plugin, as read from its file.
     */
    protected string $manifestJson = '';

    protected string $name = '';

    protected ?string $description = null;

    /**
     * The connector configuration of the supported MCP servers, by server name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $servers = [];

    /**
     * The connector configuration overridden by the developer, by server name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $overrides = [];

    /**
     * @var array<string>
     */
    protected array $errors = [];

    /**
     * The tools provided by the plugin. Memoized, so the MCP servers are contacted only once.
     *
     * @var ToolInterface[]|null
     */
    protected ?array $provided = null;

    /**
     * One connector per MCP server, by server name. A failed build is kept too,
     * so a server is never built twice.
     *
     * @var array<string, McpConnector|Throwable>
     */
    protected array $connectors = [];

    public function __construct(/**
     * The source the plugin was built from: a directory path or a storage object.
     */
        protected string|StorageInterface $source
    ) {
        $this->load();
    }

    /**
     * Serialize only what is needed to rebuild the plugin: the source, the
     * server overrides, the prefix and the toolkit filters. Connectors, tools
     * and the skills are rebuilt on first use after unserialization.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'source' => $this->source,
            'overrides' => $this->overrides,
            'prefix' => $this->prefix,
            'exclude' => $this->exclude,
            'only' => $this->only,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->source = $data['source'];
        $this->overrides = $data['overrides'];
        $this->prefix = $data['prefix'];
        $this->exclude = $data['exclude'];
        $this->only = $data['only'];
        $this->provided = null;
        $this->connectors = [];
        $this->errors = [];

        $this->load();
    }

    /**
     * Prefix the MCP tool names of the plugin, e.g. to keep the tools of two
     * plugins apart. The skill tool is left untouched.
     */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Read the manifest, the skills and the MCP configuration of the source.
     */
    protected function load(): void
    {
        $this->storage = is_string($this->source) ? new FileStorage($this->source) : $this->source;

        $manifest = $this->storage->read('plugin.json');
        $locations = ['skills' => 'skills', 'mcp' => 'mcp.json'];

        if ($manifest !== null) {
            $this->manifestJson = $manifest;
            $this->validateManifest($this->decode($manifest, 'plugin.json'));
        } else {
            $locations = $this->legacyManifest();
        }

        $this->skills = new Skills($this->storage, $locations['skills'], null, $this->name);
        $this->loadMcp($locations['mcp'], $manifest !== null);
    }

    /**
     * Override the connector configuration of an MCP server, e.g. to add the credentials.
     *
     * The name is the one servers() lists. The configuration is merged over the one mapped from
     * the plugin, and the headers are merged one by one.
     *
     * Call it before getting the tools.
     *
     * @param array<string, mixed> $config
     */
    public function server(string $name, array $config): static
    {
        $this->overrides[$name] = $config;
        return $this;
    }

    /**
     * The name declared in the manifest.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The description declared in the manifest, when there is one.
     */
    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * The skills of the plugin, by the name declared in their frontmatter.
     *
     * @return array<string, Skill>
     */
    public function skills(): array
    {
        return $this->skills->skills();
    }

    /**
     * The supported MCP servers of the plugin, by server name, with their connector configuration.
     *
     * The name is the one server() takes: use it to pass credentials to plugins you did not write.
     *
     * @return array<string, array<string, mixed>>
     */
    public function servers(): array
    {
        return $this->servers;
    }

    /**
     * The manifest of the plugin, decoded to an array.
     *
     * It carries what the loader does not interpret, such as the interface block of legacy
     * Codex manifests: display name, icon, logo, colors, screenshots.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $manifest = json_decode($this->manifestJson, true);

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * A stream open for reading on a file of the plugin, such as the logo its manifest refers to.
     *
     * Branding files are declared by the interface block of legacy Codex manifests, with paths
     * relative to the plugin. The portable manifest declares none.
     *
     * @return resource|null Null when the path is invalid or the file is missing
     */
    public function openAsset(string $path): mixed
    {
        $path = Path::normalize($path);

        return $path === null ? null : $this->storage->open($path);
    }

    public function provide(): array
    {
        if ($this->provided !== null) {
            return $this->provided;
        }

        $tools = [];

        foreach (array_keys($this->servers) as $name) {
            try {
                foreach ($this->connectorFor($name)->tools() as $tool) {
                    $tools[] = $this->identify($tool);
                }
            } catch (Throwable $exception) {
                // A server that fails to connect must not break the other components of the plugin
                $this->errors[] = "MCP server '{$name}': {$exception->getMessage()}.";
            }
        }

        // The MCP tools come first, so a tool named "skill" wins over the skill tool
        return $this->provided = array_merge($tools, $this->skills->tools());
    }

    /**
     * Apply the prefix to an MCP tool and record the plugin it comes from in
     * its metadata, beside the server declared by the connector.
     */
    protected function identify(ToolInterface $tool): ToolInterface
    {
        if ($this->prefix !== '') {
            $tool->setName($this->prefix.$tool->getName());
        }

        if ($tool instanceof HasMeta) {
            $meta = $tool->getMeta();
            $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
            $mcp['plugin'] = $this->name;
            $meta['mcp'] = $mcp;
            $tool->setMeta($meta);
        }

        return $tool;
    }

    /**
     * The connector of one MCP server, built once per plugin instance. The
     * tools given to the agent and app sessions share it, so a server is not
     * contacted twice and its tool cache survives.
     *
     * @throws PluginException When the server is not supported by this plugin
     * @throws Throwable       When the connector could not be built
     */
    public function connectorFor(string $name): McpConnector
    {
        if (array_key_exists($name, $this->connectors)) {
            $connector = $this->connectors[$name];

            if ($connector instanceof Throwable) {
                throw $connector;
            }

            return $connector;
        }

        if (!array_key_exists($name, $this->servers)) {
            throw new PluginException("MCP server '{$name}' is not supported by this plugin.");
        }

        try {
            return $this->connectors[$name] = $this->connector($this->configuration($name));
        } catch (Throwable $exception) {
            $this->connectors[$name] = $exception;

            throw $exception;
        }
    }

    /**
     * A host-side app session bound to one server of the plugin. The view can
     * only reach tools of that server, with the credentials and connector the
     * agent's tools came from.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws PluginException
     * @throws Throwable
     */
    public function appSession(string $name, array $config): McpAppSession
    {
        return $this->connectorFor($name)->appSession($config);
    }

    /**
     * The connector configuration of a server: the one mapped from the plugin, with the
     * override of server() merged over it. An overridden header replaces the header of the
     * plugin with the same name, case-insensitively.
     *
     * @return array<string, mixed>
     */
    protected function configuration(string $name): array
    {
        $config = $this->servers[$name];
        $override = $this->overrides[$name] ?? [];
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        $extra = is_array($override['headers'] ?? null) ? $override['headers'] : [];

        foreach ($extra as $header => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp((string) $existing, (string) $header) === 0) {
                    unset($headers[$existing]);
                }
            }

            $headers[(string) $header] = $value;
        }

        return array_merge($config, $override, ['headers' => $headers]);
    }

    /**
     * The problems found while loading the plugin: invalid skills and MCP entries, servers that failed to connect.
     *
     * @return array<string>
     */
    public function errors(): array
    {
        return array_merge($this->errors, $this->skills->errors());
    }

    /**
     * The connector of an MCP server. Extend to replace the transport.
     *
     * @param array<string, mixed> $config
     */
    protected function connector(array $config): McpConnector
    {
        return McpConnector::make($config);
    }

    /**
     * @return mixed The decoded value: a JSON object becomes a stdClass, a JSON array a PHP array
     *
     * @throws PluginException When the content is not valid JSON
     */
    protected function decode(string $json, string $file): mixed
    {
        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PluginException("Plugin file '{$file}' is not valid JSON: {$exception->getMessage()}", $exception->getCode(), previous: $exception);
        }
    }

    /**
     * Validate a portable plugin.json manifest.
     *
     * Unknown fields are reported and ignored. Any other violation of the schema rejects the plugin.
     *
     * @throws PluginException
     */
    protected function validateManifest(mixed $manifest): void
    {
        $fields = $manifest instanceof stdClass ? get_object_vars($manifest) : null;

        if ($fields === null) {
            throw new PluginException("Plugin manifest 'plugin.json' must be a JSON object.");
        }

        $schema = $fields['$schema'] ?? null;

        if ($schema !== self::MANIFEST_SCHEMA) {
            throw new PluginException("Plugin manifest 'plugin.json': unsupported \$schema '".(is_string($schema) ? $schema : '')."', expected '".self::MANIFEST_SCHEMA."'.");
        }

        $name = $fields['name'] ?? null;

        if (!is_string($name) || strlen($name) > 64 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new PluginException("Plugin manifest 'plugin.json': 'name' is missing or invalid.");
        }

        $known = ['$schema', 'name', 'version', 'description', 'author', 'homepage', 'repository', 'license', 'keywords', 'extensions'];

        foreach (array_diff(array_keys($fields), $known) as $field) {
            $this->errors[] = "Plugin manifest 'plugin.json': the field '{$field}' is unknown and has been ignored.";
        }

        foreach (['version', 'description', 'homepage', 'repository', 'license'] as $field) {
            if (array_key_exists($field, $fields) && !is_string($fields[$field])) {
                throw new PluginException("Plugin manifest 'plugin.json': the field '{$field}' must be a string.");
            }
        }

        $keywords = $fields['keywords'] ?? null;

        if (array_key_exists('keywords', $fields) && (!is_array($keywords) || array_filter($keywords, is_string(...)) !== $keywords)) {
            throw new PluginException("Plugin manifest 'plugin.json': the field 'keywords' must be an array of strings.");
        }

        if (array_key_exists('author', $fields) && !$this->isAuthor($fields['author'])) {
            throw new PluginException("Plugin manifest 'plugin.json': the field 'author' must be an object whose name, email and url are strings.");
        }

        if (array_key_exists('extensions', $fields)) {
            $extensions = $fields['extensions'];

            if (!$extensions instanceof stdClass) {
                $this->errors[] = "Plugin manifest 'plugin.json': the field 'extensions' is not an object and has been ignored.";
            } else {
                foreach (get_object_vars($extensions) as $namespace => $value) {
                    if (!$value instanceof stdClass) {
                        throw new PluginException("Plugin manifest 'plugin.json': every namespace of the field 'extensions' must be an object, '{$namespace}' is not.");
                    }
                }
            }
        }

        $this->name = $name;
        $this->description = is_string($fields['description'] ?? null) ? $fields['description'] : null;
    }

    protected function isAuthor(mixed $author): bool
    {
        if (!$author instanceof stdClass) {
            return false;
        }

        foreach (get_object_vars($author) as $field => $value) {
            if (!in_array($field, ['name', 'email', 'url'], true) || !is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load a legacy Codex or Claude Code manifest: .codex-plugin/plugin.json or .claude-plugin/plugin.json.
     *
     * Only name is required. skills and mcpServers are the paths of the skills folder and of the MCP configuration.
     *
     * @return array{skills: string, mcp: string}
     *
     * @throws PluginException When there is no manifest at all, or the first readable one is invalid
     */
    protected function legacyManifest(): array
    {
        $manifest = null;

        foreach (['.codex-plugin/plugin.json', '.claude-plugin/plugin.json'] as $file) {
            $json = $this->storage->read($file);

            if ($json !== null) {
                $manifest = $this->decode($json, $file);
                break;
            }
        }

        if ($manifest === null) {
            throw new PluginException("No plugin manifest found: expected 'plugin.json', '.codex-plugin/plugin.json' or '.claude-plugin/plugin.json'.");
        }

        $fields = $manifest instanceof stdClass ? get_object_vars($manifest) : [];
        $name = $fields['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw new PluginException("Plugin manifest '{$file}': 'name' is missing or empty.");
        }

        $this->manifestJson = $json;
        $this->name = $name;
        $this->description = is_string($fields['description'] ?? null) ? $fields['description'] : null;

        return [
            'skills' => $this->location($fields, 'skills', 'skills', $file),
            'mcp' => $this->location($fields, 'mcpServers', '.mcp.json', $file),
        ];
    }

    /**
     * A path declared by a legacy manifest: the default when the field is absent,
     * and also when it is not a valid relative path, which is reported.
     *
     * @param array<string, mixed> $fields
     */
    protected function location(array $fields, string $field, string $default, string $file): string
    {
        if (!array_key_exists($field, $fields)) {
            return $default;
        }

        $path = is_string($fields[$field]) ? Path::normalize($fields[$field]) : null;

        if ($path === null) {
            $this->errors[] = "Plugin manifest '{$file}': the field '{$field}' must be a relative path, '{$default}' used instead.";
            return $default;
        }

        return $path;
    }

    /**
     * Load the MCP configuration.
     *
     * The portable mcp.json file is validated strictly, the legacy .mcp.json file ignores unknown fields.
     * An invalid configuration or entry is reported and skipped: the other components of the plugin keep loading.
     *
     * @throws PluginException When the file is not valid JSON
     */
    protected function loadMcp(string $file, bool $portable): void
    {
        $json = $this->storage->read($file);

        // A missing configuration is not an error
        if ($json === null) {
            return;
        }

        $config = $this->decode($json, $file);
        $fields = $config instanceof stdClass ? get_object_vars($config) : [];
        $keys = array_keys($fields);

        sort($keys);

        if ($portable && ($keys !== ['$schema', 'mcpServers'] || $fields['$schema'] !== self::MCP_SCHEMA)) {
            $this->errors[] = "MCP configuration '{$file}': the fields must be exactly \$schema ('".self::MCP_SCHEMA."') and mcpServers. The MCP servers have not been loaded.";
            return;
        }

        $servers = $fields['mcpServers'] ?? null;

        if (!$servers instanceof stdClass) {
            $this->errors[] = "MCP configuration '{$file}': 'mcpServers' must be an object. The MCP servers have not been loaded.";
            return;
        }

        foreach (get_object_vars($servers) as $name => $entry) {
            $server = $this->parseServer($entry, $portable);

            if (is_string($server)) {
                $this->errors[] = "MCP server '{$name}' in '{$file}' skipped: {$server}.";
                continue;
            }

            $this->servers[$name] = $server;
        }
    }

    /**
     * Map an MCP server entry to its connector configuration.
     *
     * @return array<string, mixed>|string The configuration, or the reason why the entry was skipped
     */
    protected function parseServer(mixed $entry, bool $portable): array|string
    {
        if (!$entry instanceof stdClass) {
            return 'the entry must be an object';
        }

        $fields = get_object_vars($entry);
        $type = $fields['type'] ?? null;

        // A legacy entry with a command and no type is a stdio server
        if (!$portable && $type === null && array_key_exists('command', $fields)) {
            $type = 'stdio';
        }

        // The legacy "http" type is an alias of streamable-http
        $types = $portable ? ['stdio', 'streamable-http', 'sse'] : ['stdio', 'streamable-http', 'http', 'sse', 'ws', 'sdk'];

        if (!is_string($type) || !in_array($type, $types, true)) {
            return 'the transport type is unknown';
        }

        $remote = in_array($type, ['streamable-http', 'http', 'sse'], true);

        if ($portable) {
            // The portable configuration accepts only the fields of the transport variant
            $unknown = array_values(array_diff(array_keys($fields), $remote ? ['type', 'url', 'headers'] : ['type', 'command', 'args', 'env']));

            if ($unknown !== []) {
                return "the field '{$unknown[0]}' is not allowed for a {$type} server";
            }
        }

        if (!$remote) {
            return "{$type} servers are not supported";
        }

        $url = $this->url($fields['url'] ?? null);

        if ($url === null) {
            return 'the url is missing or invalid';
        }

        $headers = $this->headers($fields['headers'] ?? new stdClass());

        if ($headers === null) {
            return 'the headers are invalid';
        }

        // The SSE transport is the asynchronous HTTP transport of the connector
        return ['url' => $url, 'headers' => $headers, ...($type === 'sse' ? ['async' => true] : [])];
    }

    /**
     * An http or https URL without user info and fragment. Plain http is allowed only on loopback hosts.
     */
    protected function url(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';

        if ($parts === false || !isset($parts['host']) || !in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }

        if ($scheme === 'https') {
            return $url;
        }

        // Plain http is allowed only for localhost, 127.0.0.0/8 and ::1
        $loopback = in_array(strtolower($parts['host']), ['localhost', '[::1]', '::1'], true)
            || preg_match('/^127(?:\.\d{1,3}){3}$/', $parts['host']) === 1;

        return $loopback ? $url : null;
    }

    /**
     * A map of header names to values. Names are unique, case-insensitively.
     *
     * @return array<string, string>|null
     */
    protected function headers(mixed $headers): ?array
    {
        if (!$headers instanceof stdClass) {
            return null;
        }

        $result = [];
        $seen = [];

        foreach (get_object_vars($headers) as $name => $value) {
            $name = (string) $name;

            if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1 || !is_string($value) || preg_match('/^[\x09\x20-\x7E]*$/D', $value) !== 1 || isset($seen[strtolower($name)])) {
                return null;
            }

            $seen[strtolower($name)] = true;
            $result[$name] = $value;
        }

        return $result;
    }
}
