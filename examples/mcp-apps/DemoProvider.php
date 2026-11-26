<?php

declare(strict_types=1);

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;

/**
 * Deterministic provider so the example runs with no model key.
 *
 * It still goes through the real agent tool loop: the tool call is executed by
 * ToolNode and the tool result is seen by the provider on the next inference.
 */
final class DemoProvider extends FakeAIProvider
{
    public function chat(Message ...$messages): Message
    {
        $last = $messages === [] ? null : $messages[\count($messages) - 1];

        if ($last instanceof ToolResultMessage) {
            $tools = $last->getTools();
            $tool = $tools[0] ?? null;
            $name = $tool !== null ? $tool->getName() : 'tool';
            $this->addResponses(new AssistantMessage(
                "The demo tool {$name} returned a result. The dashboard UI is attached to its tool card; "
                .'you can keep the conversation going.'
            ));

            return parent::chat(...$messages);
        }

        $text = '';

        foreach (\array_reverse($messages) as $message) {
            if ($message instanceof UserMessage) {
                $text = (string) $message->getContent();
                break;
            }
        }

        if (\stripos($text, 'sales') !== false) {
            $tool = $this->findTool('get_sales')
                ->setInputs(['region' => 'all'])
                ->setCallId($this->nextCallId());

            $this->addResponses(new ToolCallMessage(null, [$tool]));
        } elseif (\stripos($text, 'time') !== false) {
            $tool = $this->findTool('get_time')
                ->setInputs([])
                ->setCallId($this->nextCallId());

            $this->addResponses(new ToolCallMessage(null, [$tool]));
        } else {
            $this->addResponses(new AssistantMessage(
                'This is the offline demo. Ask about "sales" to open an MCP App, or about "time" '
                .'to see a plain text tool result with no view.'
            ));
        }

        return parent::chat(...$messages);
    }

    protected function nextCallId(): string
    {
        return \uniqid('demo_call_', true);
    }
}
