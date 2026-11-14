<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\HttpClient\StreamInterface;

use function strpos;
use function substr;

/**
 * String-backed stream for faking SSE and streaming HTTP responses.
 */
class FakeStream implements StreamInterface
{
    protected string $buffer;

    public function __construct(string $content)
    {
        $this->buffer = $content;
    }

    public function eof(): bool
    {
        return $this->buffer === '';
    }

    public function read(int $length): string
    {
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $chunk;
    }

    public function readLine(): string
    {
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
