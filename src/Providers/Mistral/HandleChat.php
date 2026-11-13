<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;

use function array_filter;
use function array_reduce;
use function array_unshift;
use function is_string;

trait HandleChat
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function chat(Message ...$messages): Message
    {
        // Include the system prompt
        if (isset($this->system)) {
            array_unshift($messages, new Message(MessageRole::SYSTEM, $this->system));
        }

        $body = [
            'model' => $this->model,
            'messages' => $this->messageMapper()->map($messages),
            ...$this->parameters,
        ];

        // Attach tools
        if (!empty($this->tools)) {
            $body['tools'] = $this->toolPayloadMapper()->map($this->tools);

            if (($toolChoice = $this->toolChoicePayload()) !== null) {
                $body['tool_choice'] = $toolChoice;
            }
        }

        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'chat/completions',
                body: $body
            )
        );

        return $this->processChatResult($response->json());
    }

    /**
     * @throws ProviderException
     */
    protected function processChatResult(array $result): AssistantMessage
    {
        $choice = $result['choices'][0];
        $blocks = $this->extractContent($choice['message']['content'] ?? null);

        if ($choice['finish_reason'] === 'tool_calls') {
            $response = $this->createToolCallMessage($choice['message']['tool_calls'], $blocks);
        } else {
            $response = new AssistantMessage($blocks);
        }

        if (isset($result['usage'])) {
            $response->setUsage($this->buildUsage($result['usage']));
        }

        $response->setStopReason($choice['finish_reason']);

        return $response;
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
            $usage['prompt_tokens'] ?? 0,
            $usage['completion_tokens'] ?? 0,
        );
    }

    /**
     * Content is a string, a list of chunks (reasoning models answer with thinking and text chunks) or null.
     *
     * @param string|array<int, array<string, mixed>>|null $content
     * @return ContentBlockInterface[]
     */
    protected function extractContent(string|array|null $content): array
    {
        if (is_string($content)) {
            return [new TextContent($content)];
        }

        $blocks = [];
        foreach ($content ?? [] as $chunk) {
            $blocks[] = match ($chunk['type']) {
                'text' => new TextContent($chunk['text'] ?? ''),
                'thinking' => new ReasoningContent(array_reduce(array_filter($chunk['thinking'], fn (array $item): bool => $item['type'] === 'text'), fn (string $carry, array $item): string => $carry . $item['text'], '')),
                'image_url' => new ImageContent(
                    $chunk['image_url']['url'] ?? '',
                    SourceType::BASE64
                ),
                'document_url' => new FileContent(
                    content: $chunk['document_url'] ?? '',
                    sourceType: SourceType::BASE64,
                    filename: $chunk['document_name'] ?? null
                ),
                'input_audio' => new AudioContent($chunk['input_audio'], SourceType::BASE64),
                default => null
            };
        }

        return array_filter($blocks);
    }
}
