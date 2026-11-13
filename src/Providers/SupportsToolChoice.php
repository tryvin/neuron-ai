<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

interface SupportsToolChoice
{
    public function toolChoice(string|array $toolChoice): static;
}
