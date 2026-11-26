<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Opt-in interface for tools that carry metadata the model never sees.
 *
 * Provider mappers ignore it. It travels with the tool through chat history
 * and stream chunks, so a backend can bind information to a tool call.
 */
interface HasMeta
{
    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array;

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): static;
}
