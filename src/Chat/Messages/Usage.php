<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

use JsonSerializable;

use function array_filter;
use function is_null;

class Usage implements JsonSerializable
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        /**
         * Input tokens served from the provider prompt cache, billed at a
         * reduced rate. Whether this count is already part of `inputTokens`
         * is provider-specific: OpenAI includes cached tokens in
         * `input_tokens`, while Anthropic reports `cache_read_input_tokens`
         * separately from `input_tokens`. Stays `0` for providers without a
         * prompt cache or when no cache hit occurred.
         */
        public int $cachedInputTokens = 0,
        /**
         * Output tokens spent on internal reasoning by reasoning-capable
         * models (e.g. OpenAI o-series / GPT-5, Gemini thinking). Whether
         * this count is already part of `outputTokens` is provider-specific:
         * OpenAI includes reasoning tokens in `output_tokens`, while Gemini
         * reports `thoughtsTokenCount` separately from `candidatesTokenCount`
         * (so for Gemini `getTotal()` does not account for them). Stays `0`
         * for non-reasoning models.
         */
        public int $reasoningTokens = 0,
        /**
         * Cost of the input tokens, expressed as a string to preserve
         * decimal precision. Stays `null` when the provider does not expose
         * cost (most providers). Enriched by providers that do (e.g. OpenRouter,
         * Ollama Cloud) via their `buildUsage` hook.
         */
        public ?string $inputCost = null,
        /**
         * Cost of the output tokens. See `inputCost`.
         */
        public ?string $outputCost = null,
        /**
         * Total cost of the interaction. See `inputCost`.
         */
        public ?string $totalCost = null,
        /**
         * Currency the cost is expressed in (e.g. "USD", "credit"). Stays
         * `null` when no cost is available.
         */
        public ?string $currency = null,
    ) {
    }

    public function getTotal(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * @return array<string, int|string>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'input_cost' => $this->inputCost,
            'output_cost' => $this->outputCost,
            'total_cost' => $this->totalCost,
            'currency' => $this->currency,
        ], fn (int|string|null $value): bool => !is_null($value));
    }
}
