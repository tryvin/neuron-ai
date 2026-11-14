<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Testing\RunResult;
use NeuronAI\Testing\WorkflowTester;
use NeuronAI\Tests\Workflow\Stubs\InterruptableNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class WorkflowTesterTest extends TestCase
{
    public function test_run_returns_completed_state(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $result = WorkflowTester::run($workflow);

        $result->assertCompleted();

        $this->assertTrue($result->state->get('node_one_executed'));
        $this->assertTrue($result->state->get('node_two_executed'));
        $this->assertTrue($result->state->get('node_three_executed'));
    }

    public function test_run_absorbs_interrupt(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);

        $result = WorkflowTester::run($workflow);

        $result->assertInterrupted();
        $this->assertNull($result->state);
        $this->assertInstanceOf(ApprovalRequest::class, $result->interruptRequest());
    }

    public function test_resume_drives_workflow_to_completion(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new InterruptableNode(), new NodeThree()]);

        $interrupted = WorkflowTester::run($workflow);
        $interrupted->assertInterrupted();

        $resumed = WorkflowTester::resume($workflow, $interrupted->interruptRequest());
        $resumed->assertCompleted();

        $this->assertTrue($resumed->state->get('interruptable_node_executed'));
        $this->assertSame('completed', $resumed->state->get('received_feedback'));
    }

    public function test_interrupt_request_fails_when_completed(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $result = WorkflowTester::run($workflow);
        $result->assertCompleted();

        $this->expectException(AssertionFailedError::class);

        $result->interruptRequest();
    }

    public function test_assert_interrupted_fails_when_completed(): void
    {
        $workflow = Workflow::make()->addNodes([new NodeOne(), new NodeTwo(), new NodeThree()]);

        $result = WorkflowTester::run($workflow);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('completed');

        $result->assertInterrupted();
    }

    public function test_run_result_can_be_constructed_directly(): void
    {
        $state = new WorkflowState();
        $result = RunResult::completed($state);

        $this->assertSame($state, $result->state);
        $this->assertNull($result->interrupt);
    }
}
