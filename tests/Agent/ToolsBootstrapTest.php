<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Skills\SkillRegister;
use NeuronAI\Skills\SkillTool;
use NeuronAI\Skills\Skills;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Tools\StatefulTool;
use NeuronAI\Tests\Agent\Tools\TestCallable;
use NeuronAI\Tests\Stubs\ArrayStorage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;

class ToolsBootstrapTest extends TestCase
{
    protected function tool(string $name, string $description = ''): Tool
    {
        return Tool::make($name, $description)->setCallable(new TestCallable());
    }

    protected function skills(string $name): Skills
    {
        return new Skills(new ArrayStorage([$name.'/SKILL.md' => "---\nname: {$name}\ndescription: The {$name} skill.\n---\n\n# {$name}\n"]));
    }

    protected function agent(): Agent
    {
        return Agent::make();
    }

    /**
     * @param ToolInterface[] $tools
     *
     * @return string[]
     */
    protected function names(array $tools): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    public function test_keeps_the_first_tool_registered_with_a_name(): void
    {
        $agent = $this->agent();
        $agent->addTool([$this->tool('search', 'first'), $this->tool('search', 'second')]);

        $tools = $agent->bootstrapTools();

        $this->assertSame(['search'], $this->names($tools));
        $this->assertSame('first', $tools[0]->getDescription());
    }

    public function test_fluent_tools_win_over_the_class_tools(): void
    {
        $agent = new class () extends Agent {
            protected function tools(): array
            {
                return [Tool::make('search', 'from the class')];
            }
        };
        $agent->addTool($this->tool('search', 'added fluently'));

        $this->assertSame('added fluently', $agent->bootstrapTools()[0]->getDescription());
    }

    public function test_deduplicates_the_toolkit_tools(): void
    {
        $toolkit = new class () extends AbstractToolkit {
            public function provide(): array
            {
                return [Tool::make('sum', 'sums'), Tool::make('search', 'from the toolkit')];
            }
        };
        $agent = $this->agent();
        $agent->addTool([$this->tool('search', 'added fluently'), $toolkit]);

        $this->assertSame(['search', 'sum'], $this->names($agent->bootstrapTools()));
    }

    public function test_toolkit_guidelines_list_the_kept_tools(): void
    {
        $toolkit = new class () extends AbstractToolkit {
            public function guidelines(): string
            {
                return 'Use them well.';
            }

            public function provide(): array
            {
                return [Tool::make('sum', 'sums'), Tool::make('search', 'from the toolkit')];
            }
        };
        $agent = $this->agent();
        $agent->addTool([$this->tool('search', 'added fluently'), $toolkit]);
        $agent->bootstrapTools();

        $instructions = $agent->resolveInstructions();

        $this->assertStringContainsString("<TOOLS-GUIDELINES>\n# AbstractToolkit@anonymous", $instructions);
        // search was dropped from the toolkit: the guidelines end after sum
        $this->assertStringContainsString("Use them well.\nsum\n</TOOLS-GUIDELINES>", $instructions);
    }

    public function test_skill_tools_share_one_register(): void
    {
        $agent = $this->agent();
        $agent->addTool([$this->skills('alpha'), $this->skills('beta')]);

        $tools = $agent->bootstrapTools();

        // One tool survives, but it holds the skills of both sources
        $this->assertSame(['skill'], $this->names($tools));

        $description = $tools[0]->getDescription();

        $this->assertNotNull($description);
        $this->assertStringContainsString('- alpha: The alpha skill.', $description);
        $this->assertStringContainsString('- beta: The beta skill.', $description);

        $register = $agent->resolveState()->get(SkillRegister::STATE_KEY);

        $this->assertInstanceOf(SkillRegister::class, $register);
        $this->assertSame(['alpha', 'beta'], array_keys($register->all()));
    }

    public function test_a_plugin_or_skills_toolkit_is_required_to_get_a_skill_tool(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello!'));
        $agent = $this->agent();
        $agent->setAiProvider($provider);

        $this->assertSame([], $agent->bootstrapTools());
        $this->assertFalse($agent->resolveState()->has(SkillRegister::STATE_KEY));

        $agent->chat(new UserMessage('Hi'))->getMessage();

        $this->assertFalse($agent->resolveState()->has(SkillRegister::STATE_KEY));
    }

    public function test_the_register_survives_the_turns_and_the_re_bootstrap(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('first'), new AssistantMessage('second'), new AssistantMessage('third'));
        $agent = $this->agent();
        $agent->setAiProvider($provider);
        $agent->addTool($this->skills('alpha'));

        $agent->chat(new UserMessage('Hi'))->getMessage();
        $register = $agent->resolveState()->get(SkillRegister::STATE_KEY);

        $this->assertInstanceOf(SkillRegister::class, $register);

        // The register lives as long as the agent, also when addTool() empties the cache
        // and the tools are bootstrapped again
        $agent->chat(new UserMessage('Again'))->getMessage();
        $agent->addTool($this->tool('search'));
        $agent->chat(new UserMessage('More'))->getMessage();

        $this->assertSame($register, $agent->resolveState()->get(SkillRegister::STATE_KEY));
        $this->assertSame(['alpha'], array_keys($register->all()));
        $this->assertSame([], $register->skipped());
        $this->assertSame(['skill', 'search'], $this->names($agent->bootstrapTools()));
        $provider->assertCallCount(3);
    }

    public function test_dropped_tools_receive_the_state_anyway(): void
    {
        $agent = $this->agent();
        $dropped = new StatefulTool('search');
        $agent->addTool([$this->tool('search'), $dropped]);

        $this->assertSame(['search'], $this->names($agent->bootstrapTools()));
        $this->assertSame($agent->resolveState(), $dropped->state);
    }

    public function test_hidden_tools_do_not_receive_the_state(): void
    {
        $hidden = new StatefulTool();
        $hidden->visible(false);
        $agent = $this->agent();
        $agent->addTool($hidden);
        $agent->bootstrapTools();

        $this->assertNull($hidden->state);
        $this->assertSame([], $agent->bootstrapTools());
    }

    public function test_the_model_loads_a_skill_through_the_tool(): void
    {
        $agent = $this->agent();
        $agent->addTool([$this->skills('alpha'), $this->skills('beta')]);

        $skill = $agent->bootstrapTools()[0];

        $this->assertInstanceOf(SkillTool::class, $skill);

        // The model picks a skill of the second source
        $call = (clone $skill)->setCallId('call_1')->setInputs(['name' => 'beta']);
        $provider = new FakeAIProvider(new ToolCallMessage(null, [$call]), new AssistantMessage('Done.'));
        $agent->setAiProvider($provider);

        $response = $agent->chat(new UserMessage('Use the beta skill'))->getMessage();

        $this->assertSame('Done.', $response->getContent());

        $results = array_filter(
            $agent->resolveState()->getChatHistory()->getMessages(),
            static fn (Message $message): bool => $message instanceof ToolResultMessage
        );

        $this->assertCount(1, $results);
        $this->assertSame("# beta\n", array_values($results)[0]->getTools()[0]->getResult());
    }
}
