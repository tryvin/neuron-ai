<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\Chat\Messages\Message;
use Throwable;

/**
 * A queued stream outcome: the message to stream, optionally cut short by a
 * failure after a number of chunks. Internal to the FakeAIProvider queue.
 *
 * @see FakeAIProvider::addStreamingResponse()
 */
class StreamingResponse
{
    public function __construct(
        public readonly Message $message,
        public readonly ?Throwable $failure = null,
        public readonly int $failAfterChunks = 0,
    ) {
    }
}
