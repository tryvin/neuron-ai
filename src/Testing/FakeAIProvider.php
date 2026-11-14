<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Providers\HasToolChoice;
use NeuronAI\Providers\SupportsToolChoice;

/**
 * Testing provider fake: queue responses, record calls, assert on them.
 *
 * Implements SupportsToolChoice, so a toolChoice forwarded by the inference
 * nodes is recorded per call. Use withoutToolChoiceSupport() when you need a
 * provider that cannot receive a tool choice at all (e.g. to test the
 * emulation path of executors supporting providers without the capability).
 */
class FakeAIProvider extends AbstractFakeAIProvider implements SupportsToolChoice
{
    use HasToolChoice;

    /**
     * A fake sharing the queue/record/assert behaviour but implementing only
     * AIProviderInterface — inference nodes will not forward tool choices to it.
     */
    public static function withoutToolChoiceSupport(): PlainFakeAIProvider
    {
        return new PlainFakeAIProvider();
    }

    /**
     * @return string|array{type: string, name?: string}|null
     */
    protected function toolChoiceForRecord(): string|array|null
    {
        return $this->toolChoice;
    }
}
