<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use stdClass;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function in_array;
use function is_array;
use function implode;
use function is_null;

class McpClient
{
    protected McpTransportInterface $transport;

    protected int $requestId = 0;

    /**
     * Protocol revision negotiated with the server.
     */
    protected ?string $protocolVersion = null;

    /**
     * True when speaking a modern revision (2026-07-28+): stateless,
     * per-request _meta metadata, no initialize handshake.
     */
    protected bool $modern = false;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $serverInfo = null;

    protected ?string $instructions = null;

    /**
     * JSON-RPC error codes that only modern-era servers emit.
     */
    protected const MODERN_ERROR_CODES = [-32020, -32021, -32022];

    /**
     * Create a new MCP client with the given transport
     *
     * @param  array<string, mixed>  $config
     *
     * @throws McpException
     */
    public function __construct(array $config)
    {
        if (isset($config['transport']) && $config['transport'] instanceof McpTransportInterface) {
            $this->transport = $config['transport'];
        } elseif (isset($config['command'])) {
            $this->transport = new StdioTransport($config);
        } elseif (isset($config['url'])) {
            $isAsync = $config['async'] ?? false;
            $this->transport = $isAsync
                ? new SseHttpTransport($config)
                : new StreamableHttpTransport($config);
        } else {
            throw new McpException('Transport not supported! Provide either "command" for StdioTransport, "url" for StreamableHttpTransport/SseHttpTransport, or a custom "transport" instance.');
        }

        $this->transport->connect();
        $this->negotiateProtocol();
    }

    public function __destruct()
    {
        $this->transport->disconnect();
    }

    /**
     * Negotiate the protocol revision and dialect with the server.
     *
     * The deprecated HTTP+SSE transport predates modern revisions, so it
     * always uses the legacy initialize handshake. Every other transport
     * probes with the modern server/discover RPC first and falls back to
     * the legacy handshake when the server does not speak a modern
     * revision.
     *
     * @throws McpException
     */
    protected function negotiateProtocol(): void
    {
        if ($this->transport instanceof SseHttpTransport) {
            $this->legacyInitialize();

            return;
        }

        try {
            $this->transport->send($this->buildRequest('server/discover', [], forceModernMeta: true));
            $response = $this->transport->receive();
        } catch (McpException $exception) {
            // Authentication and authorization failures are not era
            // signals: the server rejected the request before protocol
            // semantics. Surface them instead of falling back.
            if (in_array($exception->getHttpStatusCode(), [401, 403], true)) {
                throw $exception;
            }

            // The server could not answer a modern probe (transport-level
            // failure or unparseable response): fall back to the legacy
            // initialize handshake.
            $this->legacyInitialize();

            return;
        }

        $this->handleDiscoverResponse($response);
    }

    /**
     * @param  array<string, mixed>  $response
     *
     * @throws McpException
     */
    protected function handleDiscoverResponse(array $response): void
    {
        if (isset($response['result']) && is_array($response['result'])) {
            $result = $response['result'];

            $this->serverInfo = $result['_meta']['io.modelcontextprotocol/serverInfo']
                ?? $result['serverInfo']
                ?? null;
            $this->instructions = $result['instructions'] ?? null;

            $supportedVersions = $result['supportedVersions'] ?? null;

            if (! is_array($supportedVersions) || $supportedVersions === []) {
                // Not a real DiscoverResult: treat the server as legacy.
                $this->legacyInitialize();

                return;
            }

            $version = McpProtocolVersions::negotiate($supportedVersions);

            if ($version === null) {
                throw new McpException(
                    'No mutually supported MCP protocol version. Server supports: '
                    .implode(', ', $supportedVersions)
                );
            }

            if (McpProtocolVersions::isModern($version)) {
                $this->useModernProtocol($version);

                return;
            }

            // Dual-era server advertising only legacy revisions.
            $this->legacyInitialize();

            return;
        }

        if (isset($response['error']) && is_array($response['error'])) {
            $error = $response['error'];

            if (in_array($error['code'], self::MODERN_ERROR_CODES, true)) {
                // A recognized modern error: the server speaks a modern
                // revision, so never fall back — negotiate by its list.
                $supported = $error['data']['supported'] ?? [];
                $version = McpProtocolVersions::negotiate(is_array($supported) ? $supported : []);

                if ($version !== null && McpProtocolVersions::isModern($version)) {
                    $this->useModernProtocol($version);

                    return;
                }

                if ($version !== null) {
                    $this->legacyInitialize();

                    return;
                }

                throw new McpException(
                    'No mutually supported MCP protocol version. Server supports: '
                    .implode(', ', is_array($supported) ? $supported : ['(none advertised)'])
                );
            }

            // Any other error (typically -32601 method not found on
            // legacy servers): fall back to the initialize handshake.
            $this->legacyInitialize();

            return;
        }

        // Unrecognized response shape: assume a legacy server.
        $this->legacyInitialize();
    }

    /**
     * Switch to a modern stateless revision.
     */
    protected function useModernProtocol(string $version): void
    {
        $this->protocolVersion = $version;
        $this->modern = true;
        $this->applyTransportProtocolVersion();
    }

    /**
     * Legacy initialize handshake (2025-11-25 and earlier).
     *
     * @throws McpException
     */
    protected function legacyInitialize(): void
    {
        // The transport header must announce the legacy revision during
        // the handshake, otherwise a dual-era server rejects the modern
        // header paired with a legacy initialize body (-32020).
        $this->protocolVersion = McpProtocolVersions::LEGACY_DEFAULT;
        $this->applyTransportProtocolVersion();

        $request = [
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => McpProtocolVersions::LEGACY_DEFAULT,
                'capabilities' => (object) [
                    'sampling' => new stdClass(),
                ],
                'clientInfo' => (object) [
                    'name' => 'neuron-ai',
                    'version' => '1.0.0',
                ],
            ],
        ];
        $this->transport->send($request);
        $response = $this->transport->receive();

        if ($response['id'] !== $this->requestId) {
            throw new McpException('Invalid response ID');
        }

        $this->protocolVersion = $response['result']['protocolVersion']
            ?? McpProtocolVersions::LEGACY_DEFAULT;
        $this->modern = false;

        $this->serverInfo = $response['result']['serverInfo'] ?? null;
        $this->instructions = $response['result']['instructions'] ?? null;

        $request = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ];

        $this->transport->send($request);
        $this->applyTransportProtocolVersion();
    }

    /**
     * Keep transports that mirror the protocol version into transport
     * headers in sync with the negotiated revision.
     */
    protected function applyTransportProtocolVersion(): void
    {
        if ($this->transport instanceof McpProtocolVersionAwareInterface) {
            $this->transport->setProtocolVersion($this->protocolVersion);
        }
    }

    /**
     * Build a JSON-RPC request carrying the modern per-request _meta
     * metadata when speaking a modern revision. The negotiation probe
     * always carries the modern envelope: announcing modern metadata is
     * how a modern server is detected in the first place.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function buildRequest(string $method, array $params, bool $forceModernMeta = false): array
    {
        if ($this->modern || $forceModernMeta) {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => $this->protocolVersion ?? McpProtocolVersions::MODERN_LATEST,
                'io.modelcontextprotocol/clientInfo' => (object) [
                    'name' => 'neuron-ai',
                    'version' => '1.0.0',
                ],
                // Roots and sampling are deprecated in modern revisions:
                // advertise no client capabilities.
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ];
        }

        if ($params === []) {
            // Some servers (e.g. Sanity) reject an empty array and expect
            // params to be a JSON object when present. Serialize as `{}`.
            $params = new stdClass();
        }

        return [
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * List all available tools from the MCP server
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function listTools(): array
    {
        return $this->paginatedList('tools/list', 'tools');
    }

    /**
     * Call a tool on the MCP server
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $paramHeaders  Raw values for x-mcp-header
     *                                               tool parameters, keyed by
     *                                               header name (without the
     *                                               Mcp-Param- prefix). Consumed
     *                                               by HTTP transports.
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function callTool(string $toolName, array $arguments = [], array $paramHeaders = []): array
    {
        $arguments = array_filter($arguments, fn (mixed $value): bool => ! is_null($value));

        $request = $this->buildRequest('tools/call', [
            'name' => $toolName,
            ...($arguments !== [] ? ['arguments' => $arguments] : ['arguments' => new stdClass()]),
        ]);

        if ($paramHeaders !== []) {
            $request[StreamableHttpTransport::PARAM_HEADERS_KEY] = $paramHeaders;
        }

        return $this->sendAndReceive($request);
    }

    /**
     * List all available prompts from the MCP server.
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function listPrompts(): array
    {
        return $this->paginatedList('prompts/list', 'prompts');
    }

    /**
     * Fetch a specific prompt, resolved with the given arguments.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> The prompt result (description + messages).
     *
     * @throws McpException
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        $request = $this->buildRequest('prompts/get', [
            'name' => $name,
            ...($arguments !== [] ? ['arguments' => $arguments] : []),
        ]);

        return $this->sendAndReceive($request)['result'];
    }

    /**
     * List all available resources from the MCP server.
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function listResources(): array
    {
        return $this->paginatedList('resources/list', 'resources');
    }

    /**
     * List parameterized resource templates (RFC 6570 URI templates).
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function listResourceTemplates(): array
    {
        return $this->paginatedList('resources/templates/list', 'resourceTemplates');
    }

    /**
     * Read the contents of a resource by URI. Servers MAY return multiple
     * content blocks (e.g. a directory resource expands to several files).
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    public function readResource(string $uri): array
    {
        $request = $this->buildRequest('resources/read', ['uri' => $uri]);

        return $this->sendAndReceive($request)['result'];
    }

    /**
     * Send a JSON-RPC request, validate the response and return it.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    protected function sendAndReceive(array $request): array
    {
        $this->transport->send($request);
        $response = $this->transport->receive();

        if ($response['id'] !== $this->requestId) {
            throw new McpException('Invalid response ID');
        }

        $this->assertNoProtocolError($response);

        return $response;
    }

    /**
     * Drive a list-style RPC through its cursor pagination, accumulating
     * the contents of one result key across pages.
     *
     * @return array<string, mixed>
     *
     * @throws McpException
     */
    protected function paginatedList(string $method, string $resultKey): array
    {
        $items = [];
        $cursor = null;

        do {
            $params = $cursor !== null ? ['cursor' => $cursor] : [];

            $response = $this->sendAndReceive($this->buildRequest($method, $params));

            $items = array_merge($items, $response['result'][$resultKey] ?? []);

            $cursor = $response['result']['nextCursor'] ?? null;
        } while ($cursor !== null);

        return $items;
    }

    /**
     * Translate JSON-RPC error responses and modern interim results into
     * typed exceptions. A missing resultType is treated as "complete"
     * (results from earlier-protocol servers).
     *
     * @param  array<string, mixed>  $response
     *
     * @throws McpException
     */
    protected function assertNoProtocolError(array $response): void
    {
        if (array_key_exists('error', $response)) {
            throw new McpException($response['error']['message'], $response['error']['code'] ?? 0);
        }

        $resultType = $response['result']['resultType'] ?? 'complete';

        if ($resultType === 'input_required') {
            throw new McpInputRequiredException(
                'The MCP server requested additional input before completing the request.',
                $response['result']['inputRequests'] ?? []
            );
        }
    }

    /**
     * The protocol revision negotiated with the server.
     */
    public function getProtocolVersion(): ?string
    {
        return $this->protocolVersion;
    }

    /**
     * Whether the client speaks a modern stateless revision.
     */
    public function isModern(): bool
    {
        return $this->modern;
    }

    /**
     * Self-reported server identity, when the server disclosed it.
     *
     * @return array<string, mixed>|null
     */
    public function getServerInfo(): ?array
    {
        return $this->serverInfo;
    }

    /**
     * Optional natural-language server instructions for LLMs.
     */
    public function getInstructions(): ?string
    {
        return $this->instructions;
    }
}
