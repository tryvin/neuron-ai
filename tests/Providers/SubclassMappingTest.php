<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\MessageMapper as AnthropicMapper;
use NeuronAI\Providers\AWS\MessageMapper as AwsMapper;
use NeuronAI\Providers\Gemini\MessageMapper as GeminiMapper;
use NeuronAI\Providers\Mistral\MessageMapper as MistralMapper;
use NeuronAI\Providers\Ollama\MessageMapper as OllamaMapper;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMapper;
use NeuronAI\Providers\OpenAI\Responses\MessageMapper as OpenAIResponsesMapper;
use PHPUnit\Framework\TestCase;

class CustomUserMessage extends UserMessage
{
}

class CustomAssistantMessage extends AssistantMessage
{
}

class CustomTextContent extends TextContent
{
}

class SubclassMappingTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    private function mappers(): array
    {
        return [
            AnthropicMapper::class,
            OpenAIMapper::class,
            OpenAIResponsesMapper::class,
            GeminiMapper::class,
            MistralMapper::class,
            AwsMapper::class,
            OllamaMapper::class,
        ];
    }

    private function roleOf(array $item): string
    {
        $role = $item['role'];

        return $role instanceof MessageRole ? $role->value : (string) $role;
    }

    public function test_subclassed_user_message_routes_to_parent(): void
    {
        foreach ($this->mappers() as $mapperClass) {
            $mapper = new $mapperClass();

            $result = $mapper->map([new CustomUserMessage([new CustomTextContent('hello')])]);

            $this->assertIsArray($result);
            $this->assertSame('user', $this->roleOf($result[0]), $mapperClass);
        }
    }

    public function test_subclassed_assistant_message_routes_to_parent(): void
    {
        foreach ($this->mappers() as $mapperClass) {
            $mapper = new $mapperClass();

            $result = $mapper->map([new CustomAssistantMessage([new CustomTextContent('hello')])]);

            $this->assertIsArray($result);
            $this->assertContains($this->roleOf($result[0]), ['assistant', 'model'], $mapperClass);
        }
    }

    public function test_subclassed_text_content_routes_to_parent(): void
    {
        foreach ($this->mappers() as $mapperClass) {
            $mapper = new $mapperClass();

            $result = $mapper->map([new UserMessage([new CustomTextContent('hello')])]);

            $this->assertIsArray($result);
            $this->assertSame('user', $this->roleOf($result[0]), $mapperClass);
        }
    }
}
