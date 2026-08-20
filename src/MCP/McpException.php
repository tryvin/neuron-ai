<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Exceptions\NeuronException;

class McpException extends NeuronException
{
    protected ?int $httpStatusCode = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $httpStatusCode = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->httpStatusCode = $httpStatusCode;
    }

    /**
     * The HTTP status code of the failing transport response, when the
     * error originated from an HTTP transport (e.g. 401, 403, 400).
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }
}
