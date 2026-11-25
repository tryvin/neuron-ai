<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use NeuronAI\Tools\HasWorkflowState;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\WorkflowState;

/**
 * A tool that records the workflow state it receives when the tools are bootstrapped.
 */
class StatefulTool extends Tool implements HasWorkflowState
{
    public ?WorkflowState $state = null;

    public function __construct(string $name = 'stateful')
    {
        parent::__construct($name);
    }

    public function setWorkflowState(WorkflowState $state): void
    {
        $this->state = $state;
    }
}
