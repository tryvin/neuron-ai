<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Workflow\WorkflowState;

/**
 * Tools implement this interface to receive the workflow state when the tools
 * are bootstrapped (Agent::bootstrapTools()), before tool de-duplication.
 *
 * @see HasRunKey For the similar opt-in pattern used for run tracking
 */
interface HasWorkflowState
{
    public function setWorkflowState(WorkflowState $state): void;
}
