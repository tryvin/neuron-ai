<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
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
use function array_splice;
use function ksort;
use function array_values;

class MessageMapper implements MessageMapperInterface
{
    public function map(array $messages): array
    {
        $mapping = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                $mapping[] = $this->mapToolCall($message);
            } elseif ($message instanceof ToolResultMessage) {
                $mapping[] = $this->mapToolsResult($message);
            } elseif ($message instanceof Message) {
                $mapping[] = $this->mapMessage($message);
            } else {
                throw new ProviderException('Could not map message type '.$message::class);
            }
        }

        return $mapping;
    }

    protected function mapMessageContent(Message $message, array $toolContents = []): array
    {
        $contents = $this->mapBlocks($message->getContentBlocks());
        $insertions = [];
        foreach ($message->getMetadata('anthropic_redacted_thinking') ?? [] as $index => $data) {
            $insertions[$index] = ['type' => 'redacted_thinking', 'data' => $data];
        }

        $toolPositions = $message->getMetadata('anthropic_tool_positions') ?? [];
        foreach ($toolContents as $index => $toolContent) {
            if (isset($toolPositions[$index])) {
                $insertions[$toolPositions[$index]] = $toolContent;
            } else {
                $contents[] = $toolContent;
            }
        }

        // Positions refer to the original response, including redacted blocks and tool calls.
        ksort($insertions);
        foreach ($insertions as $index => $content) {
            array_splice($contents, $index, 0, [$content]);
        }

        return $contents;
    }

    protected function mapMessage(Message $message): array
    {
        return [
            'role' => $message->getRole(),
            'content' => $this->mapMessageContent($message),
        ];
    }

    protected function mapBlocks(array $blocks): array
    {
        return array_values(array_filter(array_map($this->mapSingleBlock(...), $blocks)));
    }

    protected function mapSingleBlock(ContentBlockInterface $block): ?array
    {
        if ($block instanceof ReasoningContent) {
            return [
                'type' => 'thinking',
                'thinking' => $block->content,
                'signature' => $block->id,
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
            return $this->mapFileBlock($block);
        }

        return null;
    }

    protected function mapImageBlock(ImageContent $block): ?array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'type' => 'image',
                'source' => [
                    'type' => 'url',
                    'url' => $block->content,
                ],
            ],
            SourceType::BASE64 => [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType,
                    'data' => $block->content,
                ],
            ],
            SourceType::ID => [
                'type' => 'image',
                'source' => [
                    'type' => 'file',
                    'file_id' => $block->content,
                ],
            ],
        };
    }

    protected function mapFileBlock(FileContent $block): array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'type' => 'document',
                'source' => [
                    'type' => 'url',
                    'url' => $block->content,
                ],
            ],
            SourceType::BASE64 => [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mediaType,
                    'data' => $block->content,
                ],
            ],
            SourceType::ID => [
                'type' => 'document',
                'source' => [
                    'type' => 'file',
                    'file_id' => $block->content,
                ],
            ],
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $parts = [];
        foreach ($message->getTools() as $tool) {
            $parts[] = [
                'type' => 'tool_use',
                'id' => $tool->getCallId(),
                'name' => $tool->getName(),
                'input' => $tool->getInputs() ?: new stdClass(),
            ];
        }

        return [
            'role' => MessageRole::ASSISTANT,
            'content' => $this->mapMessageContent($message, $parts),
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        $parts = array_map(function (ToolInterface $tool): array {
            $content = ($tool instanceof HasOutput && $tool->getOutput()->hasBlocks())
                ? $this->mapBlocks($tool->getOutput()->getBlocks())
                : $tool->getResult();

            return [
                'type' => 'tool_result',
                'tool_use_id' => $tool->getCallId(),
                'content' => $content,
            ];
        }, $message->getTools());

        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = [...$parts, ...$this->mapBlocks($contentBlocks)];
        }

        return [
            'role' => MessageRole::USER,
            'content' => array_values($parts),
        ];
    }
}
