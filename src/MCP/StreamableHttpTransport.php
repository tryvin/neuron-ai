<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use JsonException;

use function array_key_exists;
use function array_merge;
use function base64_encode;
use function explode;
use function filter_var;
use function implode;
use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function trim;

use const FILTER_VALIDATE_URL;
use const JSON_THROW_ON_ERROR;

class StreamableHttpTransport implements McpTransportInterface, McpProtocolVersionAwareInterface
{
    /**
     * Reserved request key carrying header-name => raw-value pairs to
     * mirror into HTTP headers (x-mcp-header tool parameters). The key is
     * stripped from the JSON body before transmission.
     */
    public const PARAM_HEADERS_KEY = '_neuron_http_headers';

    protected readonly Client $httpClient;

    protected ?string $sessionId = null;

    protected ?ResponseInterface $lastResponse = null;

    protected ?int $lastHttpStatusCode = null;

    /**
     * Protocol revision announced in the MCP-Protocol-Version header.
     * Defaults to the latest modern revision until negotiation completes.
     */
    protected ?string $protocolVersion = null;

    /**
     * Create a new StreamableHttpTransport with the given configuration
     *
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config)
    {
        $this->httpClient = ($config['httpClient'] ?? null) instanceof Client
            ? $config['httpClient']
            : new Client([
                'timeout' => $config['timeout'] ?? 30,
                'http_errors' => false,
                'headers' => [
                    'Accept' => 'application/json, text/event-stream',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'neuron-ai/1.0.0',
                ],
            ]);
    }

    public function setProtocolVersion(?string $version): void
    {
        $this->protocolVersion = $version;
    }

    /**
     * Connect to the MCP HTTP server
     *
     * @throws McpException
     */
    public function connect(): void
    {
        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for HTTP transport');
        }

        // Validate URL format
        if (!filter_var($this->config['url'], FILTER_VALIDATE_URL)) {
            throw new McpException('Invalid URL format');
        }

        // For HTTP transport, no explicit connection test is needed
        // The connection will be validated during the first request
    }

    /**
     * Send a JSON-RPC request to the MCP HTTP server
     *
     * @param array<string, mixed> $data
     * @throws McpException
     */
    public function send(array $data): void
    {
        if (!isset($this->config['url'])) {
            throw new McpException('URL is required for HTTP transport');
        }

        try {
            $headers = array_merge($this->getAuthHeaders(), $this->getRequestMetadataHeaders($data));

            // Add session ID if available (ignored by modern servers)
            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }

            unset($data[self::PARAM_HEADERS_KEY]);

            $jsonData = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $request = new Request('POST', $this->config['url'], $headers, $jsonData);
            $response = $this->httpClient->send($request);

            $this->lastHttpStatusCode = $response->getStatusCode();
            $this->lastResponse = $response;

            // Capture the session id the server assigns (legacy/stateful
            // servers return it on initialize and expect it echoed back on
            // every subsequent request). Modern stateless servers ignore it.
            if ($response->hasHeader('Mcp-Session-Id')) {
                $this->sessionId = $response->getHeaderLine('Mcp-Session-Id');
            }

            if ($response->getStatusCode() === 401) {
                throw new McpException('Authentication failed: Invalid or expired token', 0, null, 401);
            }

            if ($response->getStatusCode() === 403) {
                throw new McpException('Authorization failed: Insufficient permissions', 0, null, 403);
            }
        } catch (GuzzleException $e) {
            throw new McpException('HTTP request failed: ' . $e->getMessage(), $e->getCode(), $e);
        } catch (JsonException $e) {
            throw new McpException('Failed to encode JSON: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Receive a response from the MCP HTTP server
     *
     * @return array<string, mixed>
     * @throws McpException
     */
    public function receive(): array
    {
        if (!$this->lastResponse instanceof ResponseInterface) {
            throw new McpException('No response available. Call send() first.');
        }

        try {
            $response = (string) $this->lastResponse->getBody();
            $this->lastResponse = null; // Clear the stored response

            if ($response === '') {
                throw new McpException('Empty response body');
            }

            try {
                return json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // If the response from the server is not a valid JSON
                // try to parse the SSE format to extract JSON data
                return $this->receiveSseResponse($response);
            }
        } catch (JsonException $e) {
            throw new McpException('Invalid JSON response: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * HTTP status code of the most recent response, when it has not been
     * consumed yet. Lets callers distinguish modern 4xx JSON-RPC errors
     * (400 with a recognized error body) from legacy endpoints.
     */
    public function getLastHttpStatusCode(): ?int
    {
        return $this->lastHttpStatusCode;
    }

    /**
     * Disconnect from the HTTP server
     */
    public function disconnect(): void
    {
        // HTTP connections are stateless, no explicit disconnect needed
        $this->sessionId = null;
        $this->lastResponse = null;
        $this->lastHttpStatusCode = null;
    }

    /**
     * Get authentication headers based on configuration
     *
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        $headers = $this->config['headers'] ?? [];

        // Add Bearer token if provided
        if (isset($this->config['token'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        return $headers;
    }

    /**
     * Build the Streamable HTTP request metadata headers
     * (MCP-Protocol-Version, Mcp-Method, Mcp-Name, Mcp-Param-*) required
     * by modern revisions and ignored by legacy servers.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    protected function getRequestMetadataHeaders(array $data): array
    {
        $headers = [
            'MCP-Protocol-Version' => $this->protocolVersion ?? McpProtocolVersions::MODERN_LATEST,
        ];

        if (isset($data['method']) && is_scalar($data['method'])) {
            $headers['Mcp-Method'] = (string) $data['method'];
        }

        $name = $data['params']['name'] ?? $data['params']['uri'] ?? null;

        if (is_scalar($name) && (string) $name !== '') {
            $headers['Mcp-Name'] = $this->encodeHeaderValue((string) $name);
        }

        $paramHeaders = $data[self::PARAM_HEADERS_KEY] ?? [];

        if (is_array($paramHeaders)) {
            foreach ($paramHeaders as $headerName => $value) {
                if (is_scalar($value)) {
                    $headers['Mcp-Param-'.$headerName] = $this->encodeHeaderValue($this->stringifyParameterValue($value));
                }
            }
        }

        return $headers;
    }

    /**
     * Type conversion for x-mcp-header parameter values: string as-is,
     * integer as decimal string, boolean as lowercase true/false.
     */
    protected function stringifyParameterValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Encode a header value per the Streamable HTTP spec: plain visible
     * ASCII (plus interior space and tab, without leading/trailing
     * whitespace) passes through, anything else is carried in the Base64
     * sentinel format. Values already matching the sentinel pattern are
     * always encoded to stay unambiguous.
     */
    protected function encodeHeaderValue(string $value): string
    {
        if ($this->isBase64Sentinel($value)) {
            return '=?base64?'.base64_encode($value).'?=';
        }

        $isAsciiSafe = $value !== ''
            && preg_match('/^[\x20-\x7E\x09]+$/', $value) === 1
            && trim($value) === $value;

        if ($isAsciiSafe) {
            return $value;
        }

        return '=?base64?'.base64_encode($value).'?=';
    }

    /**
     * Values that collide with the Base64 sentinel pattern must also be
     * encoded to stay unambiguous.
     */
    protected function isBase64Sentinel(string $value): bool
    {
        return $value !== ''
            && str_starts_with($value, '=?base64?')
            && str_ends_with($value, '?=');
    }

    /**
     * Extract the first JSON-RPC message carrying an id from an SSE
     * response body. Multiple data lines of one event are joined with
     * newlines; comment lines and notifications (no id) are skipped.
     *
     * @return array<string, mixed>
     * @throws McpException
     */
    protected function receiveSseResponse(string $sseResponse): array
    {
        foreach (explode("\n\n", $sseResponse) as $eventBlock) {
            $data = $this->parseSSEResponse($eventBlock);

            if ($data === null) {
                continue;
            }

            try {
                $message = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($message) && array_key_exists('id', $message)) {
                return $message;
            }
        }

        throw new McpException('No JSON data found in SSE response');
    }

    /**
     * Parse a single SSE event to extract its JSON data.
     *
     * @throws McpException
     */
    protected function parseSSEResponse(string $sseResponse): ?string
    {
        $lines = explode("\n", $sseResponse);

        $dataLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            // Extract data from SSE format
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = substr($line, 5);
            }
        }

        if ($dataLines === []) {
            return null;
        }

        return trim(implode("\n", $dataLines));
    }
}
