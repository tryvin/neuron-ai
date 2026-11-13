<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use Aws\Api\Parser\EventParsingIterator;
use Aws\Exception\AwsException;
use Aws\Exception\EventStreamDataException;
use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\ProviderErrorClassifier;

use function array_map;
use function base64_encode;
use function count;
use function json_encode;
use function str_ends_with;

trait HandleStream
{
    protected StreamState $streamState;

    /**
     * Stream response from the LLM.
     * https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_ConverseStream.html#API_runtime_ConverseStream_ResponseSyntax
     *
     * @throws ProviderException
     */
    public function stream(Message ...$messages): Generator
    {
        $payload = $this->createPayLoad($messages);

        try {
            $result = $this->bedrockRuntimeClient->converseStream($payload);
        } catch (AwsException $e) {
            $exception = $this->contextWindowException($e);

            if ($exception !== null) {
                throw $exception;
            }

            throw $e;
        }

        $this->streamState = new StreamState();

        $tools = [];
        $toolPositions = [];
        $redactedReasoning = [];
        $stopReason = null;

        foreach ($result as $eventParserIterator) {
            if (!$eventParserIterator instanceof EventParsingIterator) {
                continue;
            }

            $toolContent = null;
            foreach ($this->streamEvents($eventParserIterator) as $event) {
                foreach ($event as $type => $eventPayload) {
                    if ($type === 'error' || str_ends_with((string) $type, 'Exception')) {
                        $exception = ProviderErrorClassifier::classifyStreamError(
                            null,
                            (string) json_encode([$type => $eventPayload])
                        );

                        if ($exception !== null) {
                            throw $exception;
                        }
                    }
                }

                if (isset($event['metadata'])) {
                    $this->streamState->addInputTokens($event['metadata']['usage']['inputTokens'] ?? 0);
                    $this->streamState->addOutputTokens($event['metadata']['usage']['outputTokens'] ?? 0);
                    $this->streamState->addCachedInputTokens(
                        $event['metadata']['usage']['cacheReadInputTokens'] ?? 0
                    );
                }

                if (isset($event['messageStop']['stopReason'])) {
                    $stopReason = $event['messageStop']['stopReason'];
                }

                if (isset($event['contentBlockStart']['start']['toolUse'])) {
                    $toolPositions[] = $event['contentBlockStart']['contentBlockIndex'];
                    $toolContent = $event['contentBlockStart']['start'];
                    $toolContent['toolUse']['input'] = '';
                    continue;
                }

                if (isset($event['contentBlockDelta']['delta']['text'])) {
                    $textChunk = $event['contentBlockDelta']['delta']['text'];
                    $this->streamState->updateContentBlock(
                        $event['contentBlockDelta']['contentBlockIndex'],
                        new TextContent($textChunk)
                    );
                    yield new TextChunk($this->streamState->messageId(), $textChunk);
                }

                if (isset($event['contentBlockDelta']['delta']['reasoningContent'])) {
                    $reasoningContent = $event['contentBlockDelta']['delta']['reasoningContent'];
                    $contentBlockIndex = $event['contentBlockDelta']['contentBlockIndex'];

                    if (isset($reasoningContent['text'])) {
                        $this->streamState->updateContentBlock(
                            $contentBlockIndex,
                            new ReasoningContent($reasoningContent['text'])
                        );
                        yield new ReasoningChunk($this->streamState->messageId(), $reasoningContent['text']);
                    }

                    if (isset($reasoningContent['redactedContent'])) {
                        $redactedReasoning[$contentBlockIndex] ??= '';
                        $redactedReasoning[$contentBlockIndex] .= $reasoningContent['redactedContent'];
                    }

                    if (isset($reasoningContent['signature'])) {
                        $this->streamState->signReasoningContentBlock($contentBlockIndex, $reasoningContent['signature']);
                    }

                    continue;
                }

                if ($toolContent !== null && isset($event['contentBlockStop'])) {
                    $tools[] = $this->createTool($toolContent);
                    $toolContent = null;
                }

                if ($toolContent !== null && isset($event['contentBlockDelta']['delta']['toolUse'])) {
                    $toolContent['toolUse']['input'] .= $event['contentBlockDelta']['delta']['toolUse']['input'];
                }
            }

            if ($toolContent !== null) {
                $tools[] = $this->createTool($toolContent);
            }
        }

        // Build final message
        if ($stopReason === 'tool_use' && count($tools) > 0) {
            $message = new ToolCallMessage($this->streamState->getContentBlocks(), $tools);
        } else {
            $message = new AssistantMessage($this->streamState->getContentBlocks());
        }

        if ($stopReason !== null) {
            $message->setStopReason($stopReason);
        }
        if ($redactedReasoning !== []) {
            $message->addMetadata('aws_redacted_reasoning', array_map(base64_encode(...), $redactedReasoning));
        }
        if ($toolPositions !== []) {
            $message->addMetadata('aws_tool_positions', $toolPositions);
        }
        $message->setUsage($this->streamState->getUsage());

        return $message;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function streamEvents(iterable $events): Generator
    {
        try {
            yield from $events;
        } catch (EventStreamDataException $e) {
            $exception = ProviderErrorClassifier::classifyStreamError(
                null,
                (string) json_encode([
                    'error' => [
                        'code' => $e->getAwsErrorCode(),
                        'message' => $e->getAwsErrorMessage(),
                    ],
                ]),
                $e
            );

            if ($exception !== null) {
                throw $exception;
            }

            throw $e;
        }
    }
}
