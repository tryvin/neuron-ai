<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat;

use NeuronAI\Chat\Messages\Usage;
use PHPUnit\Framework\TestCase;

class UsageTest extends TestCase
{
    public function test_cached_and_reasoning_default_to_zero(): void
    {
        $usage = new Usage(100, 20);

        $this->assertSame(0, $usage->cachedInputTokens);
        $this->assertSame(0, $usage->reasoningTokens);
    }

    public function test_cached_and_reasoning_are_stored(): void
    {
        $usage = new Usage(100, 20, 40, 12);

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(40, $usage->cachedInputTokens);
        $this->assertSame(12, $usage->reasoningTokens);
    }

    public function test_positional_construction_keeps_cache_write_tokens_zero(): void
    {
        // Fifth positional slot must stay open with a default: existing
        // four-argument calls must keep their meaning.
        $usage = new Usage(10, 20, 5, 3);

        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(5, $usage->cachedInputTokens);
        $this->assertSame(3, $usage->reasoningTokens);
        $this->assertSame(0, $usage->cacheWriteTokens);
    }

    public function test_cache_write_tokens_are_stored(): void
    {
        $usage = new Usage(100, 20, 40, 12, 25);

        $this->assertSame(25, $usage->cacheWriteTokens);
    }

    public function test_get_total_covers_input_and_output_only(): void
    {
        // Cache writes are parallel to or inside input depending on the
        // provider; adding them to the total would double count.
        $usage = new Usage(100, 20, 40, 12, 25);

        $this->assertSame(120, $usage->getTotal());
    }

    public function test_json_serialize_exposes_token_fields(): void
    {
        $usage = new Usage(100, 20, 40, 12, 25);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cached_input_tokens' => 40,
            'reasoning_tokens' => 12,
            'cache_write_tokens' => 25,
        ], $usage->jsonSerialize());
    }

    public function test_cost_and_currency_are_null_by_default(): void
    {
        $usage = new Usage(100, 20);

        $this->assertNull($usage->inputCost);
        $this->assertNull($usage->outputCost);
        $this->assertNull($usage->totalCost);
        $this->assertNull($usage->currency);
    }

    public function test_json_serialize_omits_null_cost_fields(): void
    {
        $usage = new Usage(100, 20, 40, 12);

        $this->assertArrayNotHasKey('input_cost', $usage->jsonSerialize());
        $this->assertArrayNotHasKey('output_cost', $usage->jsonSerialize());
        $this->assertArrayNotHasKey('total_cost', $usage->jsonSerialize());
        $this->assertArrayNotHasKey('currency', $usage->jsonSerialize());
    }

    public function test_json_serialize_includes_set_cost_fields(): void
    {
        $usage = new Usage(100, 20, 40, 12, 0, '0.10', '0.05', '0.15', 'USD');

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cached_input_tokens' => 40,
            'reasoning_tokens' => 12,
            'cache_write_tokens' => 0,
            'input_cost' => '0.10',
            'output_cost' => '0.05',
            'total_cost' => '0.15',
            'currency' => 'USD',
        ], $usage->jsonSerialize());
    }
}
