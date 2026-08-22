<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
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

class MessageMapper implements MessageMapperInterface
{
    /**
     * @throws ProviderException
     */
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

    protected function mapMessage(Message $message): array
    {
        return [
            'role' => $message->getRole() === MessageRole::ASSISTANT->value ? MessageRole::MODEL : $message->getRole(),
            'parts' => $this->mapBlocks($message->getContentBlocks()),
        ];
    }

    protected function mapBlocks(array $blocks): array
    {
        return array_values(array_filter(array_map($this->mapContentBlock(...), $blocks)));
    }

    protected function mapContentBlock(ContentBlock $block): ?array
    {
        if ($block instanceof ReasoningContent) {
            $item = [
                'thought' => true,
                'text' => $block->content,
            ];
        } elseif ($block instanceof TextContent) {
            $item = [
                'text' => $block->content,
            ];
        } elseif ($block instanceof ImageContent || $block instanceof FileContent || $block instanceof AudioContent || $block instanceof VideoContent) {
            $item = $this->mapMediaBlock($block);
        } else {
            $item = null;
        }

        if ($signature = $block->getMetadata('thought_signature')) {
            $item['thought_signature'] = $signature;
        }

        return $item;
    }

    protected function mapMediaBlock(ImageContent|FileContent|AudioContent|VideoContent $block): ?array
    {
        return match ($block->sourceType) {
            SourceType::URL => [
                'file_data' => [
                    'file_uri' => $block->content,
                    'mime_type' => $block->mediaType,
                ],
            ],
            SourceType::BASE64 => [
                'inline_data' => [
                    'data' => $block->content,
                    'mime_type' => $block->mediaType,
                ],
            ],
            default => null
        };
    }

    protected function mapToolCall(ToolCallMessage $message): array
    {
        $parts = [];

        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = $this->mapBlocks($contentBlocks);
        }

        foreach ($message->getTools() as $index => $tool) {
            $part = [
                'functionCall' => [
                    'name' => $tool->getName(),
                    'args' => $tool->getInputs() !== [] ? $tool->getInputs() : new stdClass(),
                ],
            ];

            if ($index === 0 && $signature = $message->getMetadata('thought_signature')) {
                $part['thought_signature'] = $signature;
            }

            $parts[] = $part;
        }

        return [
            'role' => MessageRole::MODEL,
            'parts' => $parts,
        ];
    }

    protected function mapToolsResult(ToolResultMessage $message): array
    {
        $parts = array_map(function (ToolInterface $tool): array {
            $response = ['name' => $tool->getName()];

            if ($tool instanceof HasOutput && $tool->getOutput()->hasBlocks()) {
                $response['content'] = ['parts' => $this->mapBlocks($tool->getOutput()->getBlocks())];
            } else {
                $response['content'] = $tool->getResult();
            }

            return [
                'functionResponse' => [
                    'name' => $tool->getName(),
                    'response' => $response,
                ],
            ];
        }, $message->getTools());

        if ($contentBlocks = $message->getContentBlocks()) {
            $parts = [...$parts, ...$this->mapBlocks($contentBlocks)];
        }

        return [
            'role' => MessageRole::USER,
            'parts' => $parts,
        ];
    }
}
