<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\ResultInterface;
use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;

trait HandleChat
{
    public function chat(Message ...$messages): Message
    {
        return $this->chatAsync(...$messages)->wait();
    }

    public function chatAsync(Message ...$messages): PromiseInterface
    {
        $payload = $this->createPayLoad($messages);

        return $this->bedrockRuntimeClient
            ->converseAsync($payload)
            ->then(function (ResultInterface $result): ToolCallMessage|AssistantMessage {
                $usage = $this->buildUsage($result['usage'] ?? []);

                $stopReason = $result['stopReason'] ?? '';
                if ($stopReason === 'tool_use') {
                    $tools = [];
                    foreach ($result['output']['message']['content'] ?? [] as $toolContent) {
                        if (isset($toolContent['toolUse'])) {
                            $tools[] = $this->createTool($toolContent);
                        }
                    }

                    $message = new ToolCallMessage(tools: $tools);
                    $message->setUsage($usage);
                    $message->setStopReason($stopReason);
                    return $message;
                }

                $text = '';
                foreach ($result['output']['message']['content'] ?? [] as $content) {
                    if (isset($content['text'])) {
                        $text .= $content['text'];
                    }
                }

                $message = new AssistantMessage($text);
                $message->setUsage($usage);
                $message->setStopReason($stopReason);
                return $message;
            });
    }

    /**
     * Build the Usage DTO from the provider usage payload.
     *
     * Override in subclasses to enrich the DTO with provider-specific data
     * such as cost.
     *
     * @param array<string, mixed> $usage
     */
    protected function buildUsage(array $usage): Usage
    {
        return new Usage(
            $usage['inputTokens'] ?? 0,
            $usage['outputTokens'] ?? 0,
            $usage['cacheReadInputTokens'] ?? 0,
        );
    }
}
