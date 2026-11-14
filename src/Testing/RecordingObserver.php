<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\Assert;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Observer that records every emitted event for assertions in tests.
 *
 * Node events are scoped to the workflow ID, so attach the observer to a
 * workflow (before running it) rather than registering it globally:
 *
 *     $observer = RecordingObserver::for($workflow);
 *     $workflow->init()->run();
 *     $observer->assertEmitted('workflow-start');
 *
 * Remove it afterwards with detach(), or use the InteractsWithEventBus trait
 * to clear the bus automatically after each test.
 */
class RecordingObserver implements ObserverInterface
{
    /**
     * @var array<int, array{event: string, source: object, data: mixed, branchId: string|null}>
     */
    public array $events = [];

    protected ?string $workflowId = null;

    /**
     * Attach a new observer scoped to the given workflow's events.
     */
    public static function for(Workflow $workflow): static
    {
        /** @phpstan-ignore new.static */
        $observer = new static();
        $observer->workflowId = $workflow->getWorkflowId();
        EventBus::observe($observer, $observer->workflowId);

        return $observer;
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        $this->events[] = [
            'event' => $event,
            'source' => $source,
            'data' => $data,
            'branchId' => $branchId,
        ];
    }

    /**
     * Remove the observers registered for this recorder's scope.
     *
     * Note: EventBus::clear() is scope-wide, so it also removes other
     * observers attached to the same workflow.
     */
    public function detach(): void
    {
        EventBus::clear($this->workflowId);
    }

    /**
     * Ordered list of the emitted event names.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_column($this->events, 'event');
    }

    public function emitted(string $event): bool
    {
        foreach ($this->events as $record) {
            if ($record['event'] === $event) {
                return true;
            }
        }

        return false;
    }

    /**
     * Source components that emitted the given event, in order.
     *
     * These are the emitting nodes or the workflow itself, not the event DTOs.
     *
     * @return object[]
     */
    public function sources(string $event): array
    {
        return array_values(array_map(
            static fn (array $record): object => $record['source'],
            array_filter($this->events, static fn (array $record): bool => $record['event'] === $event)
        ));
    }

    /**
     * Event DTOs of the given class across all events, in order. Event DTOs
     * (e.g. InferenceStart, ToolCalled) are carried as the event data payload.
     *
     * @param class-string $class
     * @return object[]
     */
    public function ofClass(string $class): array
    {
        return array_values(array_map(
            static fn (array $record): object => $record['data'],
            array_filter($this->events, static fn (array $record): bool => $record['data'] instanceof $class)
        ));
    }

    /**
     * Assert an event was emitted the given number of times.
     */
    public function assertEmitted(string $event, int $times = 1): void
    {
        $count = count($this->sources($event));

        Assert::assertSame(
            $times,
            $count,
            sprintf("Expected event '%s' to be emitted %d time(s), got %d.", $event, $times, $count)
        );
    }

    /**
     * Assert an event was never emitted.
     */
    public function assertNotEmitted(string $event): void
    {
        Assert::assertFalse(
            $this->emitted($event),
            sprintf("Expected event '%s' not to be emitted. Emitted: %s", $event, implode(', ', $this->names()) ?: '(nothing)')
        );
    }

    /**
     * Assert the given events were emitted in the given relative order,
     * possibly with other events in between.
     */
    public function assertEmittedInOrder(string ...$events): void
    {
        $position = 0;
        $matched = 0;

        foreach ($events as $event) {
            $found = null;

            foreach ($this->names() as $index => $name) {
                if ($index >= $position && $name === $event) {
                    $found = $index;
                    break;
                }
            }

            if ($found === null) {
                Assert::fail(
                    sprintf(
                        "Expected event '%s' after position %d. Emitted: %s",
                        $event,
                        $position,
                        implode(', ', $this->names()) ?: '(nothing)'
                    )
                );
            }

            $position = $found + 1;
            $matched++;
        }

        Assert::assertSame(
            count($events),
            $matched,
            sprintf('Expected all %d events to be matched in order.', count($events))
        );
    }
}
