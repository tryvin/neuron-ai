<?php

declare(strict_types=1);

namespace Tests\MCP\Apps;

use NeuronAI\MCP\Apps\McpAppException;
use NeuronAI\MCP\Apps\McpAppResource;
use PHPUnit\Framework\TestCase;

final class McpAppResourceTest extends TestCase
{
    private function resource(array $ui = []): McpAppResource
    {
        return new McpAppResource(
            uri: 'ui://sales/dashboard.html',
            mimeType: 'text/html;profile=mcp-app',
            html: '<html><body>Sales</body></html>',
            meta: ['ui' => $ui],
        );
    }

    public function testItRejectsNonUiUris(): void
    {
        $this->expectException(McpAppException::class);

        new McpAppResource('https://example.com/view.html', 'text/html;profile=mcp-app', '<html></html>');
    }

    public function testItRejectsOtherMimeTypes(): void
    {
        $this->expectException(McpAppException::class);

        new McpAppResource('ui://sales/view.html', 'text/html', '<html></html>');
    }

    public function testItBuildsRestrictiveHeaders(): void
    {
        $headers = $this->resource()->headers('http://127.0.0.1:8080');

        $this->assertSame('text/html;profile=mcp-app', $headers['Content-Type']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame(
            "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
            ."font-src 'self' data:; img-src 'self' data:; media-src 'self' data:; object-src 'none'; connect-src 'none'; "
            ."frame-src 'none'; base-uri 'self'; frame-ancestors http://127.0.0.1:8080",
            $headers['Content-Security-Policy']
        );
    }

    public function testItIntersectsDeclaredDomainsWithTheHostAllowlist(): void
    {
        $resource = $this->resource(['csp' => [
            'connectDomains' => ['https://api.example.com', 'https://evil.example'],
            'resourceDomains' => ['https://cdn.example.com'],
            'frameDomains' => ['https://frames.example.com'],
            'baseUriDomains' => ['https://base.example.com'],
        ]]);

        $headers = $resource->headers('http://127.0.0.1:8080', [
            'https://api.example.com',
            'https://cdn.example.com',
            'https://base.example.com',
        ]);

        $this->assertStringContainsString("connect-src 'self' https://api.example.com;", $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('evil.example', $headers['Content-Security-Policy']);
        $this->assertStringContainsString('img-src \'self\' data: https://cdn.example.com;', $headers['Content-Security-Policy']);
        $this->assertStringContainsString('font-src \'self\' data: https://cdn.example.com;', $headers['Content-Security-Policy']);
        $this->assertStringContainsString("frame-src 'none';", $headers['Content-Security-Policy']);
        $this->assertStringContainsString("base-uri 'self' https://base.example.com;", $headers['Content-Security-Policy']);
    }

    public function testItBuildsTheAllowAttributeFromPermissions(): void
    {
        $resource = $this->resource(['permissions' => [
            'camera' => [],
            'microphone' => [],
            'geolocation' => [],
            'clipboardWrite' => [],
            'unknown' => [],
        ]]);

        $this->assertSame('camera; microphone; geolocation; clipboard-write', $resource->allowAttribute());
        $this->assertSame('', $this->resource()->allowAttribute());
    }

    public function testItExposesDomainAndBorderPreference(): void
    {
        $resource = $this->resource(['domain' => 'sales.example.com', 'prefersBorder' => true]);

        $this->assertSame('sales.example.com', $resource->domain);
        $this->assertTrue($resource->prefersBorder);
        $this->assertSame([], $this->resource()->csp);
    }
}
