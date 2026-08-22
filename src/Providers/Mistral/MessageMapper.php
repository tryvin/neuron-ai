<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Mistral;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\HasOutput;
use NeuronAI\Tools\ToolInterface;
use stdClass;

use function array_filter;
use function array_map;
use function array_values;
use function json_encode;

class MessageMapper implements MessageMapperInterface
{
    protected array $mapping = [];

    public function map(array $messages): array
    {
        $this->mapping = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                $this->mapToolCall($message);
            } elseif ($message instanceof ToolResultMessage) {
                $this->mapToolsResult($message);
            } elseif ($message instanceof Message) {
                $this->mapMessage($message);
            } else {
                throw new ProviderException('Unknown message type '.$message::class);
            }
        }

        return $this->mapping;
    }

    protected function mapMessage(Message $message): void
    {
        $this->mapping[] = [
            'role' => $message->getRole(),
            'content' => $this->mapBlocks($message->getContentBlocks()),
        ];
    }

    protected function mapBlocks(array $blocks): array
    {
        return array_values(array_filter(array_map($this->mapContentBlock(...), $blocks)));
    }

    protected function mapContentBlock(ContentBlockInterface $block): ?array
    {
        if ($block instanceof ReasoningContent) {
            return [
                'type' => 'thinking',
                'thinking' => [
                    'type' => 'text',
                    'text' => $block->content,
                ],
            ];
        }

        if ($block instanceof TextContent) {
            return [
                'type' => 'text',
                'text' => $block->content,
            ];
        }

        if ($block instanceof ImageContent) {
            return $this->mapImageBlock($block);
        }

        if ($block instanceof FileContent) {
            return $this->mapDocumentBlock($block);
        }

        if ($block instanceof AudioContent) {
            return $this->mapAudioBlock($block);
        }

        return null;
    }

    protected function mapImageBlock(ImageContent $block): array
    {
        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => match ($block->sourceType) {
                    SourceType::URL, SourceType::ID => $block->content,
                    SourceType::BASE64 => 'data:'.$block->mediaType.';base64,'.$block->content,
                },
            ],
        ];
    }

    protected function mapDocumentBlock(FileContent $block): ?array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'type' => 'document_url',
                'document_url' => $block->content,
                'document_name' => $block->filename,
            ],
            SourceType::ID => [
                'type' => 'file',
                'file_id' => $block->content,
            ],
            SourceType::BASE64 => null,
        };
    }

    protected function mapAudioBlock(AudioContent $block): array
    {
        return [
            'type' => 'input_audio',
            'input_audio' => $block->content,
        ];
    }

    protected function mapToolCall(ToolCallMessage $message): void
    {
        $item = [
            'role' => MessageRole::ASSISTANT,
            'tool_calls' => array_map(fn (ToolInterface $tool): array => [
                'id' => $tool->getCallId(),
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'arguments' => json_encode($tool->getInputs() ?: new stdClass()),
                ],
            ], $message->getTools()),
        ];

        $contents = $this->mapBlocks($message->getContentBlocks());
        if ($contents !== []) {
            $item['content'] = $contents;
        }

        $this->mapping[] = $item;
    }

    protected function mapToolsResult(ToolResultMessage $message): void
    {
        foreach ($message->getTools() as $tool) {
            $content = ($tool instanceof HasOutput && $tool->getOutput()->hasBlocks())
                ? $this->mapBlocks($tool->getOutput()->getBlocks())
                : $tool->getResult();

            $this->mapping[] = [
                'role' => MessageRole::TOOL,
                'tool_call_id' => $tool->getCallId(),
                'content' => $content,
            ];
        }
    }
}
