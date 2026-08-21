<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Exceptions\NeuronException;
use Throwable;

class McpException extends NeuronException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        protected ?int $httpStatusCode = null,
    ) {
        parent::__construct($message, $code, $previous);
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
