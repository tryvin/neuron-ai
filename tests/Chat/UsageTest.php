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

    public function test_get_total_covers_input_and_output_only(): void
    {
        $usage = new Usage(100, 20, 40, 12);

        $this->assertSame(120, $usage->getTotal());
    }

    public function test_json_serialize_exposes_all_four_fields(): void
    {
        $usage = new Usage(100, 20, 40, 12);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cached_input_tokens' => 40,
            'reasoning_tokens' => 12,
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
        $usage = new Usage(100, 20, 40, 12, '0.10', '0.05', '0.15', 'USD');

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 20,
            'cached_input_tokens' => 40,
            'reasoning_tokens' => 12,
            'input_cost' => '0.10',
            'output_cost' => '0.05',
            'total_cost' => '0.15',
            'currency' => 'USD',
        ], $usage->jsonSerialize());
    }
}
