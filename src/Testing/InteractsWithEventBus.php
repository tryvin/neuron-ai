<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Observability\EventBus;
use PHPUnit\Framework\Attributes\After;

/**
 * Clears the EventBus after every test so observers registered in one test
 * do not leak into the next one.
 *
 * Alternatively, detach manually with RecordingObserver::detach() or
 * EventBus::clear() in your own teardown.
 */
trait InteractsWithEventBus
{
    #[After]
    protected function clearEventBusAfterTest(): void
    {
        EventBus::clear();
    }
}
