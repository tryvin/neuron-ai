<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Enums\MediaType;
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
use function array_is_list;
use function array_map;
use function array_merge;
use function array_values;
use function json_encode;
use function strtolower;

class MessageMapper implements MessageMapperInterface
{
    protected array $mapping = [];

    public function map(array $messages): array
    {
        $this->mapping = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                $item = $this->mapToolCall($message);
            } elseif ($message instanceof ToolResultMessage) {
                $item = $this->mapToolsResult($message);
            } elseif ($message instanceof Message) {
                $item = $this->mapMessage($message);
            } else {
                throw new ProviderException('Unknown message type '.$message::class);
            }

            if (array_is_list($item)) {
                $this->mapping = array_merge($this->mapping, $item);
            } else {
                $this->mapping[] = $item;
            }
        }

        return $this->mapping;
    }

    protected function mapMessage(Message $message): array
    {
        return [
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
            return null;
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

        if ($block instanceof AudioContent) {
            return $this->mapAudioBlock($block);
        }

        if ($block instanceof FileContent) {
            return $this->mapFileBlock($block);
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

    protected function mapAudioBlock(AudioContent $block): ?array
    {
        $type = MediaType::tryFrom($block->mediaType);
        $format = $type instanceof MediaType ? strtolower($type->name) : 'wav';

        return match ($block->sourceType) {
            SourceType::BASE64 => [
                'type' => 'input_audio',
                'input_audio' => [
                    'data' => $block->content,
                    'format' => $format,
                ],
            ],
            default => null
        };
    }

    protected function mapFileBlock(FileContent $block): ?array
    {
        return match ($block->sourceType) {
            SourceType::BASE64 => [
                'type' => 'file',
                'file' => [
                    'filename' => $block->filename,
                    'file_data' => "data:{$block->mediaType};base64,{$block->content}",
                ],
            ],
            SourceType::ID => [
                'type' => 'file',
                'file' => [
                    'file_id' => $block->content,
                ],
            ],
            SourceType::URL => null
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $item = [
            'role' => MessageRole::ASSISTANT,
            'tool_calls' => array_map(fn (ToolInterface $tool): array => [
                'id' => $tool->getCallId(),
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'arguments' => json_encode($tool->getInputs() === [] ? new stdClass() : $tool->getInputs()),
                ],
            ], $message->getTools()),
        ];

        $content = $this->mapBlocks($message->getContentBlocks());
        if ($content !== []) {
            $item['content'] = $content;
        }

        return $item;
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        return array_map(function (ToolInterface $tool): array {
            $content = ($tool instanceof HasOutput && $tool->getOutput()->hasBlocks())
                ? $this->mapBlocks($tool->getOutput()->getBlocks())
                : $tool->getResult();

            return [
                'role' => MessageRole::TOOL,
                'tool_call_id' => $tool->getCallId(),
                'content' => $content,
            ];
        }, $message->getTools());
    }
}
