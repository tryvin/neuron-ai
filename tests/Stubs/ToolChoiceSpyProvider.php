<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stubs;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\MessageMapper;
use NeuronAI\Providers\OpenAI\ToolMapper;
use NeuronAI\Providers\SupportsToolChoice;
use NeuronAI\Providers\ToolMapperInterface;

class ToolChoiceSpyProvider implements AIProviderInterface, SupportsToolChoice
{
    /**
     * @var array<int, string|array>
     */
    public array $toolChoices = [];

    /**
     * @var array<int, string>
     */
    public array $calls = [];

    public function toolChoice(string|array $toolChoice): static
    {
        $this->toolChoices[] = $toolChoice;

        return $this;
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new MessageMapper();
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new ToolMapper();
    }

    public function chat(Message ...$messages): Message
    {
        $this->calls[] = 'chat';

        return new AssistantMessage('ok');
    }

    public function stream(Message ...$messages): Generator
    {
        $this->calls[] = 'stream';

        yield new TextChunk('msg_test', 'ok');

        return new AssistantMessage('ok');
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        $this->calls[] = 'structured';

        return new AssistantMessage('{"value":"ok"}');
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }
}
