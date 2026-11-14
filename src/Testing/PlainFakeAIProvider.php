<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

/**
 * Provider fake without tool choice support: implements AIProviderInterface
 * only, so inference nodes skip forwarding a tool choice to it. Shares the
 * queue/record/assert behaviour of FakeAIProvider.
 *
 * @see FakeAIProvider::withoutToolChoiceSupport()
 */
class PlainFakeAIProvider extends AbstractFakeAIProvider
{
}
