<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use Throwable;

class ContextWindowExceededException extends HttpException
{
    public function __construct(
        string $message,
        ?HttpRequest $request = null,
        ?HttpResponse $response = null,
        ?Throwable $previous = null,
        public readonly ?int $promptTokens = null,
        public readonly ?int $maxContextTokens = null,
        public readonly ?string $providerCode = null,
    ) {
        parent::__construct($message, $request, $response, $previous);
    }

    public static function forResponse(
        string $message,
        HttpRequest $request,
        HttpResponse $response,
        ?int $promptTokens = null,
        ?int $maxContextTokens = null,
        ?string $providerCode = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            $message,
            $request,
            $response,
            $previous,
            $promptTokens,
            $maxContextTokens,
            $providerCode,
        );
    }
}
