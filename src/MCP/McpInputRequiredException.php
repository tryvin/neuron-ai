<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

/**
 * Thrown when a modern (2026-07-28+) server answers a request with an
 * InputRequiredResult (resultType "input_required") instead of a final
 * result: the server needs additional input before it can continue, per
 * the Multi Round-Trip Requests (MRTR) pattern.
 *
 * The original request must be re-issued with the matching
 * "inputResponses" filled from the carried input requests.
 */
class McpInputRequiredException extends McpException
{
    /**
     * @param array<int, array<string, mixed>> $inputRequests
     */
    public function __construct(
        string $message,
        protected array $inputRequests = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The requests the server issued to collect the missing input.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getInputRequests(): array
    {
        return $this->inputRequests;
    }
}
