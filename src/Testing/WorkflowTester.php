<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Workflow;

/**
 * Drives a workflow to completion or to its next interrupt.
 *
 * Absorbs the boilerplate of iterating the run generator, catching the
 * WorkflowInterrupt, and re-initialising for resume:
 *
 *     $result = WorkflowTester::run($workflow);
 *     $result->assertInterrupted();
 *
 *     $result = WorkflowTester::resume($workflow, new ApprovalRequest('ok', []));
 *     $result->assertCompleted();
 *
 * Streamed events are consumed silently. When a test needs the streamed
 * chunks themselves, iterate the workflow generator directly instead.
 */
class WorkflowTester
{
    /**
     * @throws WorkflowInterrupt Never bubbles: absorbed into the RunResult.
     */
    public static function run(Workflow $workflow): RunResult
    {
        try {
            return RunResult::completed($workflow->init()->run());
        } catch (WorkflowInterrupt $interrupt) {
            return RunResult::interrupted($interrupt);
        }
    }

    /**
     * @throws WorkflowInterrupt Never bubbles: absorbed into the RunResult.
     */
    public static function resume(Workflow $workflow, InterruptRequest $request): RunResult
    {
        try {
            return RunResult::completed($workflow->init($request)->run());
        } catch (WorkflowInterrupt $interrupt) {
            return RunResult::interrupted($interrupt);
        }
    }
}
