<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\HttpClient\StreamInterface;
use Throwable;

use function strpos;
use function substr;

/**
 * String-backed stream for faking SSE and streaming HTTP responses.
 *
 * Optionally carries a failure thrown once the content is exhausted — from
 * read(), readLine(), and eof() — to model connections that die mid-stream.
 */
class FakeStream implements StreamInterface
{
    public function __construct(protected string $buffer, protected ?Throwable $failOnExhaustion = null)
    {
    }

    public function eof(): bool
    {
        if ($this->buffer === '' && $this->failOnExhaustion instanceof Throwable) {
            throw $this->failOnExhaustion;
        }

        return $this->buffer === '';
    }

    public function read(int $length): string
    {
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        if ($chunk === '' && $this->failOnExhaustion instanceof Throwable) {
            throw $this->failOnExhaustion;
        }

        return $chunk;
    }

    public function readLine(): string
    {
        if ($this->buffer === '' && $this->failOnExhaustion instanceof Throwable) {
            throw $this->failOnExhaustion;
        }

        $pos = strpos($this->buffer, "\n");

        if ($pos === false) {
            $line = $this->buffer;
            $this->buffer = '';
            return $line;
        }

        $line = substr($this->buffer, 0, $pos + 1);
        $this->buffer = substr($this->buffer, $pos + 1);

        return $line;
    }

    public function close(): void
    {
        $this->buffer = '';
    }
}
