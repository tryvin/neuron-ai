<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\HttpClient\StreamInterface;

/**
 * Shared helper for stream handlers that return early on the tool-call
 * finish reason. Providers emit the trailing usage data AFTER the finish
 * reason; without draining it, the returned tool-call message is billed as
 * zero tokens.
 *
 * Per-provider usage extraction lives in captureUsage(). Line extraction
 * defaults to SSE parsing; override nextStreamEvent() for providers whose
 * stream lines are raw JSON (e.g. Gemini).
 */
trait DrainsTrailingUsage
{
    protected function drainTrailingUsage(StreamInterface $stream): void
    {
        // Providers emit usage after the finish reason; read it before the
        // generator returns or the step is billed as zero tokens.
        for ($read = 0; ! $stream->eof() && $read < 100; $read++) {
            $line = $this->nextStreamEvent($stream);

            if ($line === null) {
                continue;
            }

            if ($this->captureUsage($line)) {
                return;
            }
        }
    }

    /**
     * Read the next stream event as an associative array, or null when the
     * payload cannot be parsed.
     *
     * @return array<string, mixed>|null
     */
    protected function nextStreamEvent(StreamInterface $stream): ?array
    {
        return SSEParser::parseNextSSEEvent($stream);
    }

    /**
     * Capture usage information from the given event. Returns true when usage
     * was seen, signalling the drain to stop.
     *
     * @param array<string, mixed> $line
     */
    protected function captureUsage(array $line): bool
    {
        if (!empty($line['usage'])) {
            $this->streamState->setUsage($this->buildUsage($line['usage']));

            return true;
        }

        return false;
    }
}
