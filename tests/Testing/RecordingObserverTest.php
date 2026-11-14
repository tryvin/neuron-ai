<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Testing\InteractsWithEventBus;
use NeuronAI\Testing\RecordingObserver;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class RecordingObserverTest extends TestCase
{
    use InteractsWithEventBus;

    public function test_records_scoped_workflow_events(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $observer = RecordingObserver::for($workflow);
        $workflow->init()->run();

        $observer->assertEmitted('workflow-start');
        $observer->assertEmitted('workflow-end');
        $observer->assertNotEmitted('workflow-interrupt');

        $this->assertContains('workflow-start', $observer->names());
        $this->assertContains('workflow-end', $observer->names());
    }

    public function test_events_are_recorded_in_order(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $observer = RecordingObserver::for($workflow);
        $workflow->init()->run();

        $observer->assertEmittedInOrder('workflow-start', 'workflow-end');
    }

    public function test_of_class_filters_by_source_dto(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $observer = RecordingObserver::for($workflow);
        $workflow->init()->run();

        $starts = $observer->ofClass(WorkflowStart::class);
        $ends = $observer->ofClass(WorkflowEnd::class);

        $this->assertCount(1, $starts);
        $this->assertCount(1, $ends);
        $this->assertInstanceOf(WorkflowStart::class, $starts[0]);
        $this->assertInstanceOf(WorkflowEnd::class, $ends[0]);
    }

    public function test_assert_emitted_fails_for_unseen_event(): void
    {
        $observer = RecordingObserver::for(
            Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()])
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('workflow-interrupt');

        $observer->assertEmitted('workflow-interrupt');
    }

    public function test_assert_emitted_in_order_fails_on_wrong_order(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $observer = RecordingObserver::for($workflow);
        $workflow->init()->run();

        $this->expectException(AssertionFailedError::class);

        $observer->assertEmittedInOrder('workflow-end', 'workflow-start');
    }

    public function test_detach_stops_recording(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $observer = RecordingObserver::for($workflow);
        $observer->detach();

        $workflow->init()->run();

        $observer->assertNotEmitted('workflow-start');
        $this->assertSame([], $observer->names());
    }

    public function test_trait_clear_method_clears_scoped_observers(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);
        $stale = RecordingObserver::for($workflow);

        // Simulate what the trait does after each test.
        $this->clearEventBusAfterTest();

        $fresh = RecordingObserver::for($workflow);
        EventBus::emit('workflow-start', $workflow, new WorkflowStart([]), $workflow->getWorkflowId());

        $this->assertSame([], $stale->names());
        $fresh->assertEmitted('workflow-start');
    }
}
