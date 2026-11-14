<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Stubs\StructuredOutput\ToolChoiceOutput;
use NeuronAI\Tests\Stubs\ToolChoiceSpyProvider;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function json_decode;

class ToolChoiceNodeTest extends TestCase
{
    public function test_chat_node_forwards_tool_choice(): void
    {
        $provider = new ToolChoiceSpyProvider();
        $event = $this->event('none');
        $state = new AgentState();

        $node = new ChatNode($provider);
        $node->setWorkflowContext($state, $event);
        $node($event, $state);

        $this->assertSame(['chat'], $provider->calls);
        $this->assertSame(['none'], $provider->toolChoices);
    }

    public function test_streaming_node_forwards_tool_choice(): void
    {
        $provider = new ToolChoiceSpyProvider();
        $event = $this->event('required');
        $state = new AgentState();

        $node = new StreamingNode($provider);
        $node->setWorkflowContext($state, $event);

        foreach ($node($event, $state) as $chunk) {
            $chunk = $chunk;
        }

        $this->assertSame(['stream'], $provider->calls);
        $this->assertSame(['required'], $provider->toolChoices);
    }

    public function test_structured_output_node_forwards_non_none_tool_choice(): void
    {
        $provider = new ToolChoiceSpyProvider();
        $event = $this->event('auto');
        $state = new AgentState();

        $node = new StructuredOutputNode($provider, ToolChoiceOutput::class);
        $node->setWorkflowContext($state, $event);
        $node($event, $state);

        $this->assertSame(['structured'], $provider->calls);
        $this->assertSame(['auto'], $provider->toolChoices);
        $this->assertSame('ok', $state->get('structured_output')->value);
    }

    public function test_structured_output_node_ignores_none_tool_choice(): void
    {
        $provider = new ToolChoiceSpyProvider();
        $event = $this->event('none');
        $state = new AgentState();

        $node = new StructuredOutputNode($provider, ToolChoiceOutput::class);
        $node->setWorkflowContext($state, $event);
        $node($event, $state);

        $this->assertSame(['structured'], $provider->calls);
        $this->assertSame([], $provider->toolChoices);
        $this->assertSame('ok', $state->get('structured_output')->value);
    }

    public function test_null_tool_choice_does_not_change_chat_payload(): void
    {
        $direct = $this->openAiChatPayload(false);
        $throughNode = $this->openAiChatPayload(true);

        $this->assertSame($direct, $throughNode);
        $this->assertArrayNotHasKey('tool_choice', $throughNode);
    }

    public function test_rewriting_the_same_event_is_reflected_in_the_next_request(): void
    {
        $provider = new ToolChoiceSpyProvider();
        $event = $this->event('none');
        $state = new AgentState();

        $node = new ChatNode($provider);
        $node->setWorkflowContext($state, $event);
        $node($event, $state);

        $event->toolChoice = 'auto';
        $node($event, $state);

        $this->assertSame(['none', 'auto'], $provider->toolChoices);
    }

    public function test_chat_node_forwards_tool_choice_to_fake_provider(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('ok'));
        $event = $this->event('none');
        $state = new AgentState();

        $node = new ChatNode($provider);
        $node->setWorkflowContext($state, $event);
        $node($event, $state);

        $this->assertSame('none', $provider->getRecorded()[0]->toolChoice);
        $provider->assertToolChoice('none');
    }

    protected function event(string $toolChoice): AIInferenceEvent
    {
        $event = new AIInferenceEvent('Answer in text only.', [Tool::make('lookup', 'Look it up')]);
        $event->setMessages(new UserMessage('Hi'));
        $event->toolChoice = $toolChoice;

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    protected function openAiChatPayload(bool $throughNode): array
    {
        $sentRequests = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, body: '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"ok"}}]}'),
        ]));
        $stack->push(Middleware::history($sentRequests));

        $provider = (new OpenAI('', 'gpt-4o'))
            ->setTools([Tool::make('lookup', 'Look it up')])
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        if ($throughNode) {
            $event = new AIInferenceEvent('Answer in text only.', [Tool::make('lookup', 'Look it up')]);
            $event->setMessages(new UserMessage('Hi'));
            $state = new AgentState();

            $node = new ChatNode($provider);
            $node->setWorkflowContext($state, $event);
            $node($event, $state);
        } else {
            $provider->systemPrompt('Answer in text only.')->chat(new UserMessage('Hi'));
        }

        return json_decode((string) $sentRequests[0]['request']->getBody(), true);
    }
}
