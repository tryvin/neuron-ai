<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Inspector\Exceptions\InspectorException;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Observability\EventBus;
use NeuronAI\Observability\Events\ToolsBootstrapped;
use NeuronAI\Tools\HasWorkflowState;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use ReflectionClass;

use function array_map;
use function array_merge;
use function implode;
use function in_array;
use function is_array;

use const PHP_EOL;

trait HandleTools
{
    /**
     * Registered tools.
     *
     * @var ToolInterface[]|ToolkitInterface[]|ProviderToolInterface[]
     */
    protected array $tools = [];

    /**
     * @var ToolInterface[]
     */
    protected array $toolsBootstrapCache = [];

    /**
     * Global max runs for all tools. An int, or a callable evaluated once per
     * tool attempt at enforcement time: fn(ToolInterface $tool, int $runsSoFar): int.
     *
     * @var int|callable
     */
    protected $toolMaxRuns = 10;

    /**
     * Callback to handle tool execution errors.
     * If null, exceptions are re-thrown.
     *
     * @var callable|null fn(Throwable $e, ToolInterface $tool): string
     */
    protected $toolErrorHandler;

    /**
     * Set a callback to handle tool execution errors.
     * The callback receives the exception, and the tool, if it returns a value
     * it will be used as the tool result (visible to the LLM).
     *
     * @param callable|null $handler fn(Throwable $e, ToolInterface $tool): string
     */
    public function toolErrorHandler(?callable $handler): Agent
    {
        $this->toolErrorHandler = $handler;
        return $this;
    }

    /**
     * Resolve the tool error handler.
     * Override this method to provide a default error handler in your agent.
     *
     * @return callable|null fn(Throwable $e, ToolInterface $tool): string
     */
    protected function resolveToolErrorHandler(): ?callable
    {
        return $this->toolErrorHandler;
    }

    public function toolMaxRuns(int|callable $num): Agent
    {
        $this->toolMaxRuns = $num;
        return $this;
    }

    /**
     * @deprecated Use toolMaxRuns instead.
     */
    public function toolMaxTries(int|callable $tries): Agent
    {
        $this->toolMaxRuns = $tries;
        return $this;
    }

    /**
     * Override to provide tools to the agent.
     *
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    protected function tools(): array
    {
        return [];
    }

    /**
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    public function getTools(): array
    {
        return array_merge($this->tools, $this->tools());
    }

    /**
     * If toolkits have already bootstrapped, this function
     * just traverses the array of tools without any action.
     *
     * @return ToolInterface[]
     * @throws InspectorException
     */
    public function bootstrapTools(): array
    {
        if (!empty($this->toolsBootstrapCache)) {
            return $this->toolsBootstrapCache;
        }

        /*EventBus::emit(
            'tools-bootstrapping',
            $this,
            null,
            $this->workflowId,
            $this->resolveState()->get('__branchId', '__main__')
        );*/

        $guidelines = [];
        $names = [];
        $state = null;

        foreach ($this->getTools() as $tool) {
            $kitGuidelines = null;

            if ($tool instanceof ToolkitInterface) {
                $kitGuidelines = $tool->guidelines();
                if ($kitGuidelines !== null && $kitGuidelines !== '') {
                    $name = (new ReflectionClass($tool))->getShortName();
                    $kitGuidelines = '# '.$name.PHP_EOL.$kitGuidelines;
                }
                $innerTools = $tool->tools();
            } elseif ($tool->isVisible()) {
                // If the item is a simple tool, add to the list if it's authorized
                $innerTools = [$tool];
            } else {
                continue;
            }

            $kept = [];

            foreach ($innerTools as $innerTool) {
                // Tools opt-in through HasWorkflowState to receive the state before
                // the de-duplication, so also a tool dropped below contributes its part.
                if ($innerTool instanceof HasWorkflowState) {
                    $state ??= $this->resolveState();
                    $innerTool->setWorkflowState($state);
                }

                // The first tool registered with a name wins
                $toolName = $innerTool->getName();

                if ($toolName !== null && isset($names[$toolName])) {
                    continue;
                }

                $names[(string) $toolName] = true;
                $kept[] = $innerTool;
            }

            // Merge the tools
            $this->toolsBootstrapCache = array_merge($this->toolsBootstrapCache, $kept);

            if ($tool instanceof ToolkitInterface && !in_array($kitGuidelines, [null, '', '0'], true)) {
                // Add guidelines to the system prompt
                $kitGuidelines .= PHP_EOL.implode(
                    PHP_EOL.'- ',
                    array_map(
                        fn (ToolInterface $tool): string => $tool->getName(),
                        $kept
                    )
                );

                $guidelines[] = $kitGuidelines;
            }
        }

        $instructions = $this->removeDelimitedContent($this->resolveInstructions(), '<TOOLS-GUIDELINES>', '</TOOLS-GUIDELINES>');
        if ($guidelines !== []) {
            $this->setInstructions(
                $instructions.PHP_EOL.'<TOOLS-GUIDELINES>'.PHP_EOL.implode(PHP_EOL.PHP_EOL, $guidelines).PHP_EOL.'</TOOLS-GUIDELINES>'
            );
        }

        /*EventBus::emit(
            'tools-bootstrapped',
            $this,
            new ToolsBootstrapped($this->toolsBootstrapCache, $guidelines),
            $this->workflowId,
            $this->resolveState()->get('__branchId', '__main__')
        );*/

        return $this->toolsBootstrapCache;
    }

    /**
     * Add tools.
     *
     * @param  ToolInterface|ToolkitInterface|ProviderToolInterface|array<ToolInterface|ToolkitInterface|ProviderToolInterface>  $tools
     * @throws AgentException
     */
    public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): AgentInterface
    {
        $tools = is_array($tools) ? $tools : [$tools];

        foreach ($tools as $t) {
            if (! $t instanceof ToolInterface && ! $t instanceof ToolkitInterface && ! $t instanceof ProviderToolInterface) {
                throw new AgentException('Tools must be an instance of ToolInterface, ToolkitInterface, or ProviderToolInterface');
            }
            $this->tools[] = $t;
        }

        // Empty the cache for the next turn.
        $this->toolsBootstrapCache = [];

        return $this;
    }
}
