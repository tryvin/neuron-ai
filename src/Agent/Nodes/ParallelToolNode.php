<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Nodes;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Tools\HasInterrupt;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\ToolsInterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use Spatie\Fork\Fork;
use Closure;
use Throwable;

use function array_map;
use function class_exists;
use function count;
use function extension_loaded;
use function is_array;
use function is_subclass_of;
use function serialize;
use function unserialize;
use function array_values;
use function ksort;

class ParallelToolNode extends ToolNode
{
    protected ?Closure $beforeChild;

    protected ?Closure $afterChild;

    public function __construct(
        int|callable $maxRuns = 10,
        ?callable $errorHandler = null,
        ?callable $beforeChild = null,
        ?callable $afterChild = null,
    ) {
        parent::__construct($maxRuns, $errorHandler);

        $this->beforeChild = $beforeChild !== null
            ? Closure::fromCallable($beforeChild)
            : null;
        $this->afterChild = $afterChild !== null
            ? Closure::fromCallable($afterChild)
            : null;
    }

    /**
     * @throws ToolException
     * @throws ToolRunsExceededException
     * @throws Throwable
     */
    protected function executeTools(ToolCallMessage $toolCallMessage, AgentState $state): Generator
    {
        // Fallback to sequential execution if pcntl is not available (e.g., Windows)
        if (!extension_loaded('pcntl')) {
            return yield from parent::executeTools($toolCallMessage, $state);
        }

        // Fallback to sequential execution if spatie/fork is not installed
        if (!class_exists(Fork::class)) {
            return yield from parent::executeTools($toolCallMessage, $state);
        }

        $tools = $toolCallMessage->getTools();

        // If there's only one tool, no need for concurrency
        if (count($tools) === 1) {
            return yield from parent::executeTools($toolCallMessage, $state);
        }

        // Enforce the run limit per tool before forking. With a configured
        // error handler an over-limit tool is skipped and its refusal becomes
        // its result; without one the exception escapes, aborting the batch.
        $skipped = [];
        $batch = [];
        $batchIndexes = [];
        foreach ($tools as $index => $tool) {
            try {
                $this->enforceRunLimit($tool, $state);
            } catch (ToolRunsExceededException $exception) {
                $this->handleError($exception, $tool);
                $skipped[$index] = $tool;
                continue;
            }

            $this->emit('tool-calling', new ToolCalling($tool, true));

            yield new ToolCallChunk($tool);
            $batchIndexes[] = $index;
            $batch[] = $tool;
        }

        // Execute tools concurrently and collect serialized tool states
        $beforeChild = $this->beforeChild;
        $afterChild = $this->afterChild;
        $executedTools = $skipped;

        if ($batch !== []) {
            $serializedTools = Fork::new()->run(
                ...array_map(
                    fn (ToolInterface $tool): Closure => function () use ($tool, $beforeChild, $afterChild): string {
                        try {
                            if ($beforeChild instanceof Closure) {
                                $beforeChild();
                            }

                            try {
                                // Execute the tool - this mutates the tool's internal state
                                $tool->execute();
                            } finally {
                                if ($afterChild instanceof Closure) {
                                    $afterChild();
                                }
                            }

                            // Serialize the entire tool object with its new state
                            return serialize($tool);
                        } catch (Throwable $exception) {
                            // Wrap the exception info with the tool for proper error handling
                            return serialize([
                                'error' => true,
                                'exception_class' => $exception::class,
                                'exception_message' => $exception->getMessage(),
                                'exception_code' => $exception->getCode(),
                                'tool_name' => $tool->getName(),
                            ]);
                        }
                    },
                    $batch
                )
            );

            // Unserialize and replace tools with their executed state,
            // mapping batch positions back to the original tool indexes.
            foreach ($serializedTools as $index => $serializedTool) {
                $data = unserialize($serializedTool);

                // Check if this is an error response
                if (is_array($data) && isset($data['error']) && $data['error'] === true) {
                    $exceptionClass = $data['exception_class'];
                    $exception = null;

                    // Recreate the exception
                    if (class_exists($exceptionClass) && is_subclass_of($exceptionClass, Throwable::class)) {
                        $exception = new $exceptionClass($data['exception_message'], (int) $data['exception_code']);
                    } else {
                        $exception = new ToolException($data['exception_message'], (int) $data['exception_code']);
                    }

                    // Get the original tool to handle the error
                    $originalTool = $batch[$index];
                    $this->handleError($exception, $originalTool);
                    $executedTools[$batchIndexes[$index]] = $originalTool;
                } else {
                    // Collect the executed tool with its new state
                    $executedTools[$batchIndexes[$index]] = $data;
                }
            }
        }

        ksort($executedTools);

        // Check if any tools signaled an interrupt request
        $toolsRequest = new ToolsInterruptRequest('Tools require interruption');
        foreach ($executedTools as $tool) {
            if ($tool instanceof HasInterrupt && $tool->getInterruptRequest() instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
                $toolsRequest->addRequest($tool->getName(), $tool->getInterruptRequest());
            }
        }

        if ($toolsRequest->hasRequests()) {
            throw new WorkflowInterrupt($toolsRequest, $this, $this->state, $this->event);
        }

        // Yield results for all executed tools
        foreach ($executedTools as $tool) {
            yield new ToolResultChunk($tool);

            // Notify that the tool was called
            $this->emit('tool-called', new ToolCalled($tool));
        }

        // Return a new ToolResultMessage with the executed tools
        return new ToolResultMessage(array_values($executedTools));
    }
}
