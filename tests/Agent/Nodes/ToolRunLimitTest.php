<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\ToolRunLimitReached;
use NeuronAI\Testing\InteractsWithEventBus;
use NeuronAI\Testing\RecordingObserver;
use NeuronAI\Tests\Agent\Tools\TestCallable;
use NeuronAI\Tests\Agent\Tools\TestKeyedCallableTool;
use NeuronAI\Tests\Agent\Tools\TestParameterizedTool;
use NeuronAI\Tools\HasInterrupt;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\InterruptHandler;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

class ToolRunLimitTest extends TestCase
{
    use InteractsWithEventBus;

    protected function event(ToolInterface ...$tools): ToolCallEvent
    {
        return new ToolCallEvent(
            new ToolCallMessage(null, $tools),
            new AIInferenceEvent(instructions: 'Test', tools: $tools)
        );
    }

    protected function observedState(string $workflowId = 'wf_run_limit_test'): AgentState
    {
        $state = new AgentState();
        $state->set('__workflowId', $workflowId);

        return $state;
    }

    /**
     * Criterion 1: sequential, handler configured, limit exceeded — the
     * refusal becomes the tool result and the workflow continues.
     */
    public function test_sequential_over_limit_becomes_tool_result_with_handler(): void
    {
        $tool = Tool::make('dup_tool', 'A tool')
            ->setCallable(fn (): string => 'ran');
        $tool->setCallId('call_1');
        $tool->setInputs([]);

        $workflowId = 'wf_limit_seq_handler';
        $state = $this->observedState($workflowId);
        $observer = new RecordingObserver();
        EventBus::observe($observer, $workflowId);

        $node = new ToolNode(
            maxRuns: 1,
            errorHandler: fn (Throwable $e, ToolInterface $t): string => 'refused: already ran',
        );

        // First call executes.
        $event = $this->event($tool);
        $node->setWorkflowContext($state, $event);
        foreach ($node($event, $state) as $_) {
            $_ = null;
        }
        $this->assertSame('ran', $tool->getResult());

        // Second call is refused as the tool result; no exception escapes.
        $tool->setCallId('call_2');
        $event2 = $this->event($tool);
        $node->setWorkflowContext($state, $event2);
        foreach ($node($event2, $state) as $_) {
            $_ = null;
        }

        $this->assertSame('refused: already ran', $tool->getResult());

        // Criterion: observability does not depend on catching an exception.
        $limits = $observer->ofClass(\NeuronAI\Observability\Events\ToolRunLimitReached::class);
        $this->assertCount(1, $limits);
        $this->assertSame(2, $limits[0]->runs);
        $this->assertSame(1, $limits[0]->limit);
    }

    /**
     * Criterion 2: sequential, no handler — still throws.
     */
    public function test_sequential_over_limit_throws_without_handler(): void
    {
        $tool = Tool::make('dup_tool', 'A tool')
            ->setCallable(fn (): string => 'ran');
        $tool->setCallId('call_1');
        $tool->setInputs([]);

        $state = new AgentState();
        $node = new ToolNode(maxRuns: 1);

        $event = $this->event($tool);
        $node->setWorkflowContext($state, $event);
        foreach ($node($event, $state) as $_) {
            $_ = null;
        }

        $tool->setCallId('call_2');
        $event2 = $this->event($tool);
        $node->setWorkflowContext($state, $event2);

        $this->expectException(ToolRunsExceededException::class);

        foreach ($node($event2, $state) as $_) {
            $_ = null;
        }
    }

    /**
     * Criterion 3: parallel, handler configured, one tool over limit — the
     * refusal is that tool's result, the rest still execute.
     */
    public function test_parallel_over_limit_refuses_one_and_executes_the_rest(): void
    {
        $exhausted = TestKeyedCallableTool::make('p_tool', 'A tool')
            ->setCallable(new TestCallable())
            ->setInputs(['id' => 1]);
        $healthy = TestKeyedCallableTool::make('p_tool', 'A tool')
            ->setCallable(new TestCallable())
            ->setInputs(['id' => 2]);

        $workflowId = 'wf_limit_par_handler';
        $state = $this->observedState($workflowId);
        $observer = new RecordingObserver();
        EventBus::observe($observer, $workflowId);

        $node = new ParallelToolNode(
            maxRuns: 1,
            errorHandler: fn (Throwable $e, ToolInterface $t): string => 'refused: already ran',
        );

        // Burn the budget of id=1 alone.
        $exhausted->setCallId('call_0');
        $event = $this->event($exhausted);
        $node->setWorkflowContext($state, $event);
        foreach ($node($event, $state) as $_) {
            $_ = null;
        }

        // Both together: id=1 refuses, id=2 executes.
        $exhausted->setCallId('call_1');
        $healthy->setCallId('call_2');
        $event2 = $this->event($exhausted, $healthy);
        $node->setWorkflowContext($state, $event2);

        $generator = $node($event2, $state);
        foreach ($generator as $_) {
            $_ = null;
        }

        // The refused tool keeps its refusal; the executed one comes back
        // from the child process through the result message.
        $this->assertSame('refused: already ran', $exhausted->getResult());

        $inference = $generator->getReturn();
        $resultMessage = null;
        foreach ($inference->getMessages() as $message) {
            if ($message instanceof ToolResultMessage) {
                $resultMessage = $message;
            }
        }

        $this->assertNotNull($resultMessage);
        $executed = null;
        foreach ($resultMessage->getTools() as $resultTool) {
            if ($resultTool->getInputs() === ['id' => 2]) {
                $executed = $resultTool;
            }
        }

        $this->assertNotNull($executed);
        $this->assertSame('result', $executed->getResult());
        $this->assertCount(1, $observer->ofClass(ToolRunLimitReached::class));
    }

    /**
     * Criterion 4: parallel, no handler — still throws.
     */
    public function test_parallel_over_limit_throws_without_handler(): void
    {
        $toolA = new TestParameterizedTool('p_tool', 'id=1');
        $toolA->setInputs(['id' => 1]);
        $toolB = new TestParameterizedTool('p_tool', 'id=2');
        $toolB->setInputs(['id' => 2]);

        $state = new AgentState();
        $node = new ParallelToolNode(maxRuns: 1);

        // Burn the budget of id=1 alone.
        $toolA->setCallId('call_0');
        $event = $this->event($toolA);
        $node->setWorkflowContext($state, $event);
        foreach ($node($event, $state) as $_) {
            $_ = null;
        }

        $toolA->setCallId('call_1');
        $toolB->setCallId('call_2');
        $event2 = $this->event($toolA, $toolB);
        $node->setWorkflowContext($state, $event2);

        $this->expectException(ToolRunsExceededException::class);

        foreach ($node($event2, $state) as $_) {
            $_ = null;
        }
    }

    /**
     * Criterion 5: HasRunKey tools are limited per key — different arguments
     * have independent budgets.
     */
    public function test_run_key_budgets_are_independent(): void
    {
        $toolA = TestKeyedCallableTool::make('keyed_tool', 'A tool')
            ->setCallable(new TestCallable())
            ->setInputs(['id' => 1]);
        $toolB = TestKeyedCallableTool::make('keyed_tool', 'A tool')
            ->setCallable(new TestCallable())
            ->setInputs(['id' => 2]);

        $node = new ToolNode(
            maxRuns: 1,
            errorHandler: fn (Throwable $e, ToolInterface $t): string => 'refused by budget',
        );
        $state = new AgentState();

        // Both succeed once each: different keys, same limit.
        $toolA->setCallId('call_1');
        $event = $this->event($toolA);
        $node->setWorkflowContext($state, $event);
        foreach ($node($event, $state) as $_) {
            $_ = null;
        }

        $toolB->setCallId('call_2');
        $event2 = $this->event($toolB);
        $node->setWorkflowContext($state, $event2);
        foreach ($node($event2, $state) as $_) {
            $_ = null;
        }

        $this->assertInstanceOf(HasRunKey::class, $toolA);
        $this->assertSame(1, $state->getToolRuns($toolA->getRunKey()));
        $this->assertInstanceOf(HasRunKey::class, $toolB);
        $this->assertSame(1, $state->getToolRuns($toolB->getRunKey()));

        // The same key over its budget is refused via the handler.
        $toolA->setCallId('call_3');
        $event3 = $this->event($toolA);
        $node->setWorkflowContext($state, $event3);
        foreach ($node($event3, $state) as $_) {
            $_ = null;
        }

        $this->assertInstanceOf(HasRunKey::class, $toolA);
        $this->assertSame('refused by budget', $toolA->getResult());
        $this->assertSame(2, $state->getToolRuns($toolA->getRunKey()));
        $this->assertInstanceOf(HasRunKey::class, $toolB);
        $this->assertSame(1, $state->getToolRuns($toolB->getRunKey()));
    }

    /**
     * Criterion 6: a callable policy receives the tool and the current count,
     * and its return value is honoured.
     */
    public function test_callable_policy_receives_tool_and_count(): void
    {
        $tool = Tool::make('policy_tool', 'A tool')
            ->setCallable(fn (): string => 'ran');
        $tool->setCallId('call_1');
        $tool->setInputs([]);

        $received = [];
        $node = new ToolNode(
            maxRuns: function (ToolInterface $t, int $runsSoFar) use (&$received): int {
                $received[] = [$t->getName(), $runsSoFar];

                return 1;
            },
            errorHandler: fn (Throwable $e, ToolInterface $t): string => 'refused by policy',
        );

        $state = new AgentState();

        // attempt 1: runsSoFar 0 -> limit 1, executes.
        $event = $this->event($tool);
        $node->setWorkflowContext($state, $event);
        foreach ($node($event, $state) as $_) {
            $_ = null;
        }

        // attempt 2: runsSoFar 1 -> limit 1, refused.
        $tool->setCallId('call_2');
        $event2 = $this->event($tool);
        $node->setWorkflowContext($state, $event2);
        foreach ($node($event2, $state) as $_) {
            $_ = null;
        }

        $this->assertSame('refused by policy', $tool->getResult());
        $this->assertSame([['policy_tool', 1], ['policy_tool', 2]], $received);
    }

    public function test_callable_policy_works_in_parallel(): void
    {
        $toolA = new TestParameterizedTool('policy_tool', 'id=1');
        $toolA->setInputs(['id' => 1]);
        $toolB = new TestParameterizedTool('policy_tool', 'id=2');
        $toolB->setInputs(['id' => 2]);

        $node = new ParallelToolNode(
            maxRuns: fn (ToolInterface $t, int $runsSoFar): int => 2,
            errorHandler: fn (Throwable $e, ToolInterface $t): string => 'refused by policy',
        );

        $state = new AgentState();

        $toolA->setCallId('call_1');
        $event = $this->event($toolA, $toolB);
        $node->setWorkflowContext($state, $event);
        foreach ($node($event, $state) as $_) {
            $_ = null;
        }

        $this->assertSame('executed', $toolA->getResult());
        $this->assertSame('executed', $toolB->getResult());
    }

    /**
     * Criterion 6: the deprecated alias behaves like toolMaxRuns.
     */
    public function test_tool_max_tries_alias_stores_the_policy(): void
    {
        $agent = Agent::make()->toolMaxTries(3);
        $property = new ReflectionProperty(Agent::class, 'toolMaxRuns');

        $this->assertSame(3, $property->getValue($agent));

        $policy = fn (): int => 5;
        $agent->toolMaxTries($policy);
        $this->assertSame($policy, $property->getValue($agent));

        $agent->toolMaxRuns(7);
        $this->assertSame(7, $property->getValue($agent));
    }

    /**
     * Criterion 7: WorkflowInterrupt propagates untouched, sequentially.
     */
    public function test_interrupting_tool_propagates_sequentially(): void
    {
        $interrupting = $this->interruptingTool('interrupt_tool');

        $node = new ToolNode(maxRuns: 10);
        $state = new AgentState();
        $event = $this->event($interrupting);
        $node->setWorkflowContext($state, $event);

        $this->expectException(WorkflowInterrupt::class);

        foreach ($node($event, $state) as $_) {
            $_ = null;
        }
    }

    /**
     * Criterion 7: WorkflowInterrupt propagates untouched through the
     * parallel node's sequential path.
     */
    public function test_interrupting_tool_propagates_in_parallel(): void
    {
        $interrupting = $this->interruptingTool('interrupt_tool');

        $node = new ParallelToolNode(maxRuns: 10);
        $state = new AgentState();
        $event = $this->event($interrupting);
        $node->setWorkflowContext($state, $event);

        $this->expectException(WorkflowInterrupt::class);

        foreach ($node($event, $state) as $_) {
            $_ = null;
        }
    }

    protected function interruptingTool(string $name): ToolInterface
    {
        return new class ($name) extends Tool implements HasInterrupt {
            use InterruptHandler;

            public function __invoke(mixed ...$params): string
            {
                $this->setInterruptRequest(new ApprovalRequest('human input needed'));

                return '';
            }
        };
    }
}
