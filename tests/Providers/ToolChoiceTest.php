<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use ArrayIterator;
use GuzzleHttp\Promise\FulfilledPromise;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Testing\FakeHttpClient;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class ToolChoiceTest extends TestCase
{
    public function test_openai_chat_and_stream_payloads(): void
    {
        $provider = $this->withTool((new OpenAI('', 'gpt-4o'))->toolChoice('none'));
        $chat = $this->chatPayload($provider, $this->openAiBody());
        $stream = $this->streamPayload($provider);

        $this->assertSame('none', $chat['tool_choice']);
        $this->assertArrayHasKey('tools', $chat);
        $this->assertSame('none', $stream['tool_choice']);
        $this->assertArrayHasKey('tools', $stream);

        $named = $this->withTool(
            (new OpenAI('', 'gpt-4o'))->toolChoice(['type' => 'function', 'name' => 'lookup'])
        );

        $this->assertSame(
            ['type' => 'function', 'function' => ['name' => 'lookup']],
            $this->chatPayload($named, $this->openAiBody())['tool_choice']
        );
    }

    public function test_openai_responses_chat_and_stream_payloads(): void
    {
        $provider = $this->withTool((new OpenAIResponses('', 'gpt-5'))->toolChoice('none'));
        $chat = $this->chatPayload($provider, $this->responsesBody());
        $stream = $this->streamPayload($provider);

        $this->assertSame('none', $chat['tool_choice']);
        $this->assertArrayHasKey('tools', $chat);
        $this->assertSame('none', $stream['tool_choice']);

        $named = $this->withTool(
            (new OpenAIResponses('', 'gpt-5'))->toolChoice(['type' => 'function', 'name' => 'lookup'])
        );

        $this->assertSame(
            ['type' => 'function', 'name' => 'lookup'],
            $this->chatPayload($named, $this->responsesBody())['tool_choice']
        );
    }

    public function test_anthropic_chat_and_stream_payloads(): void
    {
        $none = $this->withTool((new Anthropic('', 'claude-sonnet-4-5'))->toolChoice('none'));
        $chat = $this->chatPayload($none, $this->anthropicBody());

        $this->assertSame(['type' => 'none'], $chat['tool_choice']);
        $this->assertArrayHasKey('tools', $chat);

        $required = $this->withTool((new Anthropic('', 'claude-sonnet-4-5'))->toolChoice('required'));
        $this->assertSame(
            ['type' => 'any'],
            $this->streamPayload($required)['tool_choice']
        );

        $named = $this->withTool(
            (new Anthropic('', 'claude-sonnet-4-5'))->toolChoice(['type' => 'function', 'name' => 'lookup'])
        );
        $this->assertSame(
            ['type' => 'tool', 'name' => 'lookup'],
            $this->chatPayload($named, $this->anthropicBody())['tool_choice']
        );
    }

    public function test_gemini_chat_and_stream_payloads(): void
    {
        $none = $this->withTool((new Gemini('', 'gemini-2.0-flash'))->toolChoice('none'));
        $chat = $this->chatPayload($none, $this->geminiBody());

        $this->assertSame('NONE', $chat['toolConfig']['functionCallingConfig']['mode']);
        $this->assertArrayHasKey('tools', $chat);

        $required = $this->withTool((new Gemini('', 'gemini-2.0-flash'))->toolChoice('required'));
        $this->assertSame(
            'ANY',
            $this->streamPayload($required)['toolConfig']['functionCallingConfig']['mode']
        );

        $named = $this->withTool(
            (new Gemini('', 'gemini-2.0-flash'))->toolChoice(['type' => 'function', 'name' => 'lookup'])
        );
        $namedPayload = $this->chatPayload($named, $this->geminiBody());

        $this->assertSame('ANY', $namedPayload['toolConfig']['functionCallingConfig']['mode']);
        $this->assertSame(
            ['lookup'],
            $namedPayload['toolConfig']['functionCallingConfig']['allowedFunctionNames']
        );
    }

    public function test_bedrock_chat_and_stream_payloads(): void
    {
        $none = $this->bedrockChatPayload('none');
        $this->assertArrayNotHasKey('toolConfig', $none);

        $auto = $this->bedrockStreamPayload('auto');
        $this->assertSame(['auto' => []], $auto['toolConfig']['toolChoice']);
        $this->assertArrayHasKey('tools', $auto['toolConfig']);

        $required = $this->bedrockChatPayload('required');
        $this->assertSame(['any' => []], $required['toolConfig']['toolChoice']);

        $named = $this->bedrockChatPayload(['type' => 'function', 'name' => 'lookup']);
        $this->assertSame(['tool' => ['name' => 'lookup']], $named['toolConfig']['toolChoice']);
    }

    public function test_ollama_none_omits_tools_and_auto_keeps_them(): void
    {
        $none = $this->withTool((new Ollama(url: '', model: 'llama3.2'))->toolChoice('none'));
        $chat = $this->chatPayload($none, $this->ollamaBody());

        $this->assertArrayNotHasKey('tools', $chat);
        $this->assertArrayNotHasKey('tool_choice', $chat);

        $auto = $this->withTool((new Ollama(url: '', model: 'llama3.2'))->toolChoice('auto'));
        $stream = $this->streamPayload($auto);

        $this->assertArrayHasKey('tools', $stream);
        $this->assertArrayNotHasKey('tool_choice', $stream);
    }

    public function test_ollama_rejects_forced_tool_choice(): void
    {
        $this->expectException(ProviderException::class);

        (new Ollama(url: '', model: 'llama3.2'))->toolChoice('required');
    }

    public function test_mistral_chat_and_stream_payloads(): void
    {
        $none = $this->withTool((new Mistral('', 'mistral-large-latest'))->toolChoice('none'));
        $chat = $this->chatPayload($none, $this->openAiBody());

        $this->assertSame('none', $chat['tool_choice']);
        $this->assertArrayHasKey('tools', $chat);

        $named = $this->withTool(
            (new Mistral('', 'mistral-large-latest'))->toolChoice(['type' => 'function', 'name' => 'lookup'])
        );
        $this->assertSame(
            ['type' => 'function', 'function' => ['name' => 'lookup']],
            $this->streamPayload($named)['tool_choice']
        );
    }

    public function test_cohere_native_spelling_and_auto_omission(): void
    {
        $none = $this->withTool((new Cohere('', 'command-a'))->toolChoice('none'));
        $chat = $this->chatPayload($none, $this->cohereBody());

        $this->assertSame('NONE', $chat['tool_choice']);
        $this->assertArrayHasKey('tools', $chat);

        $required = $this->withTool((new Cohere('', 'command-a'))->toolChoice('required'));
        $this->assertSame('REQUIRED', $this->streamPayload($required)['tool_choice']);

        $named = $this->withTool(
            (new Cohere('', 'command-a'))->toolChoice(['type' => 'function', 'name' => 'lookup'])
        );
        $this->assertSame('lookup', $this->chatPayload($named, $this->cohereBody())['tool_choice']);

        $auto = $this->withTool((new Cohere('', 'command-a'))->toolChoice('auto'));
        $this->assertArrayNotHasKey('tool_choice', $this->chatPayload($auto, $this->cohereBody()));
    }

    public function test_default_tool_choice_does_not_add_mapping_fields(): void
    {
        $this->assertArrayNotHasKey(
            'tool_choice',
            $this->chatPayload($this->withTool(new OpenAI('', 'gpt-4o')), $this->openAiBody())
        );
        $this->assertArrayNotHasKey(
            'tool_choice',
            $this->chatPayload($this->withTool(new Anthropic('', 'claude-sonnet-4-5')), $this->anthropicBody())
        );
        $this->assertArrayNotHasKey(
            'tool_choice',
            $this->chatPayload($this->withTool(new OpenAIResponses('', 'gpt-5')), $this->responsesBody())
        );
        $this->assertArrayNotHasKey(
            'tool_choice',
            $this->chatPayload($this->withTool(new Mistral('', 'mistral-large-latest')), $this->openAiBody())
        );
        $this->assertArrayNotHasKey(
            'tool_choice',
            $this->chatPayload($this->withTool(new Cohere('', 'command-a')), $this->cohereBody())
        );
        $this->assertArrayHasKey(
            'tools',
            $this->chatPayload($this->withTool(new Ollama(url: '', model: 'llama3.2')), $this->ollamaBody())
        );
        $this->assertSame(
            ['functionCallingConfig' => ['mode' => 'AUTO']],
            $this->chatPayload($this->withTool(new Gemini('', 'gemini-2.0-flash')), $this->geminiBody())['toolConfig']
        );
        $this->assertArrayNotHasKey(
            'toolChoice',
            $this->bedrockChatPayload(null)['toolConfig']
        );
    }

    protected function withTool(AIProviderInterface $provider): AIProviderInterface
    {
        return $provider->setTools([Tool::make('lookup', 'Look it up')]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatPayload(AIProviderInterface $provider, string $body): array
    {
        $client = FakeHttpClient::make()->queueResponse(new HttpResponse(200, $body));

        $provider->setHttpClient($client)->chat(new UserMessage('Hi'));

        return (array) $client->requests()[0]->body;
    }

    /**
     * @return array<string, mixed>
     */
    protected function streamPayload(AIProviderInterface $provider): array
    {
        $client = FakeHttpClient::make()->queueSse();

        $stream = $provider->setHttpClient($client)->stream(new UserMessage('Hi'));
        iterator_to_array($stream, false);

        return (array) $client->requests()[0]->body;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bedrockChatPayload(string|array|null $toolChoice): array
    {
        $capturedPayload = null;
        $client = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseAsync'])
            ->getMock();

        $client->expects($this->once())
            ->method('converseAsync')
            ->with($this->callback(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;
                return true;
            }))
            ->willReturn(new FulfilledPromise(new Result([
                'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
                'output' => ['message' => ['content' => [['text' => 'ok']]]],
                'stopReason' => 'end_turn',
            ])));

        $provider = new BedrockRuntime($client, 'model');
        $provider->setTools([Tool::make('lookup', 'Look it up')]);
        if ($toolChoice !== null) {
            $provider->toolChoice($toolChoice);
        }
        $provider->chat(new UserMessage('Hi'));

        return (array) $capturedPayload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bedrockStreamPayload(string|array $toolChoice): array
    {
        $capturedPayload = null;
        $client = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['converseStream'])
            ->getMock();

        $client->expects($this->once())
            ->method('converseStream')
            ->with($this->callback(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;
                return true;
            }))
            ->willReturn(new Result(['stream' => new ArrayIterator([])]));

        $provider = new BedrockRuntime($client, 'model');
        $provider->setTools([Tool::make('lookup', 'Look it up')]);
        $provider->toolChoice($toolChoice);
        iterator_to_array($provider->stream(new UserMessage('Hi')), false);

        return (array) $capturedPayload;
    }

    protected function openAiBody(): string
    {
        return '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"ok"}}]}';
    }

    protected function responsesBody(): string
    {
        return '{"status":"completed","output":[{"type":"message","content":[{"text":"ok"}]}]}';
    }

    protected function anthropicBody(): string
    {
        return '{"content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn"}';
    }

    protected function geminiBody(): string
    {
        return '{"candidates":[{"content":{"parts":[{"text":"ok"}]},"finishReason":"STOP"}]}';
    }

    protected function ollamaBody(): string
    {
        return '{"message":{"role":"assistant","content":"ok"},"done":true}';
    }

    protected function cohereBody(): string
    {
        return '{"finish_reason":"COMPLETE","message":{"content":[{"type":"text","text":"ok"}]}}';
    }
}
