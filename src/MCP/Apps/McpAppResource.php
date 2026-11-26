<?php

declare(strict_types=1);

namespace NeuronAI\MCP\Apps;

use function implode;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function str_starts_with;

/**
 * The HTML an app renders, with the host's view of its declared sandbox needs.
 */
final class McpAppResource
{
    /** @var array<string, mixed> */
    public readonly array $csp;

    /** @var array<string, mixed> */
    public readonly array $permissions;

    public readonly ?string $domain;

    public readonly bool $prefersBorder;

    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $uri,
        public readonly string $mimeType,
        public readonly string $html,
        public readonly array $meta = [],
    ) {
        if (!str_starts_with($uri, 'ui://')) {
            throw new McpAppException('An app resource URI must start with ui://.');
        }

        if ($mimeType !== 'text/html;profile=mcp-app') {
            throw new McpAppException('An app resource must use text/html;profile=mcp-app.');
        }

        $ui = is_array($meta['ui'] ?? null) ? $meta['ui'] : [];
        $this->csp = is_array($ui['csp'] ?? null) ? $ui['csp'] : [];
        $this->permissions = is_array($ui['permissions'] ?? null) ? $ui['permissions'] : [];
        $this->domain = is_string($ui['domain'] ?? null) ? $ui['domain'] : null;
        $this->prefersBorder = ($ui['prefersBorder'] ?? false) === true;
    }

    /**
     * @param  array<int, string>  $allowedDomains
     * @return array<string, string>
     */
    public function headers(string $hostOrigin, array $allowedDomains = []): array
    {
        $parts = parse_url($hostOrigin);

        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || preg_match('/[\x00-\x1F\x7F]/', $hostOrigin) === 1
        ) {
            throw new McpAppException('The host origin must be an http(s) origin.');
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        $connect = $this->domains('connectDomains', $allowedDomains);
        $resources = $this->domains('resourceDomains', $allowedDomains);
        $frames = $this->domains('frameDomains', $allowedDomains);
        $baseUris = $this->domains('baseUriDomains', $allowedDomains);

        $scriptSources = "'self' 'unsafe-inline'".$this->sources($resources);
        $resourceSources = "'self' data:".$this->sources($resources);

        $csp = "default-src 'none'; "
            ."script-src {$scriptSources}; "
            ."style-src {$scriptSources}; "
            ."img-src {$resourceSources}; "
            ."media-src {$resourceSources}; "
            ."object-src 'none'; "
            .'connect-src '.(($connect !== []) ? "'self'".$this->sources($connect) : "'none'").'; '
            .'frame-src '.(($frames !== []) ? "'self'".$this->sources($frames) : "'none'").'; '
            ."base-uri 'self'".$this->sources($baseUris).'; '
            ."frame-ancestors {$origin}";

        return [
            'Content-Type' => $this->mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => $csp,
        ];
    }

    public function allowAttribute(): string
    {
        $map = [
            'camera' => 'camera',
            'microphone' => 'microphone',
            'geolocation' => 'geolocation',
            'clipboardWrite' => 'clipboard-write',
        ];

        $allowed = [];

        foreach ($map as $key => $attribute) {
            if (($this->permissions[$key] ?? null) === []) {
                $allowed[] = $attribute;
            }
        }

        return implode('; ', $allowed);
    }

    /**
     * @param  array<int, string>  $allowedDomains
     * @return array<int, string>
     */
    protected function domains(string $key, array $allowedDomains): array
    {
        $declared = $this->csp[$key] ?? [];
        $domains = [];

        if (!is_array($declared)) {
            return $domains;
        }

        foreach ($declared as $domain) {
            if (is_string($domain) && in_array($domain, $allowedDomains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * @param  array<int, string>  $domains
     */
    protected function sources(array $domains): string
    {
        return $domains === [] ? '' : ' '.implode(' ', $domains);
    }
}
