<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Testing\InteractsWithEventBus;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class EventBusTest extends TestCase
{
    use InteractsWithEventBus;

    protected function setUp(): void
    {
        unset($_ENV['INSPECTOR_INGESTION_KEY']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['INSPECTOR_INGESTION_KEY']);
    }

    public function test_emit_does_not_attach_inspector_observer_without_key(): void
    {
        EventBus::emit('workflow-start', $this->workflow(), new WorkflowStart([]), 'workflow_test');

        $this->assertSame([], $this->scopedObservers('workflow_test'));
    }

    public function test_emit_attaches_inspector_observer_when_key_is_configured(): void
    {
        $_ENV['INSPECTOR_INGESTION_KEY'] = 'test-key';

        EventBus::emit('workflow-start', $this->workflow(), new WorkflowStart([]), 'workflow_test');

        $observers = $this->scopedObservers('workflow_test');

        $this->assertCount(1, $observers);
        $this->assertInstanceOf(\Inspector\Neuron\InspectorObserver::class, $observers[0]);
    }

    public function test_explicitly_registered_observers_are_not_replaced(): void
    {
        $observer = new class () implements ObserverInterface {
            public int $calls = 0;

            public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
            {
                $this->calls++;
            }
        };

        EventBus::observe($observer, 'workflow_test');
        $workflow = $this->workflow();
        EventBus::emit('workflow-start', $workflow, new WorkflowStart([]), 'workflow_test');
        EventBus::emit('workflow-end', $workflow, new WorkflowStart([]), 'workflow_test');

        $this->assertSame(2, $observer->calls);
        $this->assertCount(1, $this->scopedObservers('workflow_test'));
    }

    protected function workflow(): Workflow
    {
        return Workflow::make();
    }

    /**
     * @return ObserverInterface[]
     */
    protected function scopedObservers(string $workflowId): array
    {
        $property = new ReflectionProperty(EventBus::class, 'scopedObservers');

        return $property->getValue()[$workflowId] ?? [];
    }
}
