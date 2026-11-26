<?php

declare(strict_types=1);

namespace Tests\MCP\Apps;

use NeuronAI\MCP\Apps\DefaultMcpAppPolicy;
use PHPUnit\Framework\TestCase;

use function chr;

final class DefaultMcpAppPolicyTest extends TestCase
{
    public function testItAllowsToolCallsAndResourceReads(): void
    {
        $policy = new DefaultMcpAppPolicy();

        $this->assertTrue($policy->allowToolCall('erp', 'get_sales', []));
        $this->assertTrue($policy->allowResourceRead('erp', 'ui://sales/dashboard.html'));
    }

    public static function allowedLinks(): array
    {
        return [
            ['https://example.com/path?a=1'],
            ['http://example.com'],
        ];
    }

    /**
     * @dataProvider allowedLinks
     */
    public function testItAllowsHttpLinks(string $url): void
    {
        $this->assertTrue((new DefaultMcpAppPolicy())->allowOpenLink($url));
    }

    public static function blockedLinks(): array
    {
        return [
            ['javascript:alert(1)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['file:///etc/passwd'],
            ['https://user:secret@example.com'],
            ["https://example.com/\nSet-Cookie: x"],
            ['https://example.com/' . chr(0)],
            ['not a url'],
            [''],
        ];
    }

    /**
     * @dataProvider blockedLinks
     */
    public function testItBlocksUnsafeLinks(string $url): void
    {
        $this->assertFalse((new DefaultMcpAppPolicy())->allowOpenLink($url));
    }
}
