<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Assert;

/**
 * Outcome of a WorkflowTester run: either a final state or an interrupt.
 */
class RunResult
{
    protected function __construct(
        public readonly ?WorkflowState $state,
        public readonly ?WorkflowInterrupt $interrupt,
    ) {
    }

    public static function completed(WorkflowState $state): self
    {
        return new self($state, null);
    }

    public static function interrupted(WorkflowInterrupt $interrupt): self
    {
        return new self(null, $interrupt);
    }

    public function assertCompleted(): void
    {
        Assert::assertNotNull(
            $this->state,
            'Expected the workflow to complete, but it was interrupted: ' . $this->interrupt?->getMessage()
        );
    }

    public function assertInterrupted(): void
    {
        Assert::assertNotNull(
            $this->interrupt,
            'Expected the workflow to be interrupted, but it completed.'
        );
    }

    /**
     * The interrupt request that stopped the workflow.
     *
     * @throws WorkflowInterrupt
     */
    public function interruptRequest(): InterruptRequest
    {
        $this->assertInterrupted();

        return $this->interrupt->getRequest();
    }
}
