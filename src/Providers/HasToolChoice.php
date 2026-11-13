<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Exceptions\ProviderException;

use function in_array;
use function is_array;
use function is_string;

trait HasToolChoice
{
    /**
     * @var string|array{type: string, name?: string}|null
     */
    protected string|array|null $toolChoice = null;

    public function toolChoice(string|array $toolChoice): static
    {
        $this->assertValidToolChoice($toolChoice);
        $this->toolChoice = $toolChoice;

        return $this;
    }

    protected function assertValidToolChoice(string|array $toolChoice): void
    {
        if (is_string($toolChoice)) {
            if (!in_array($toolChoice, ['auto', 'none', 'required'], true)) {
                throw new ProviderException(
                    "Unsupported tool choice '{$toolChoice}'. Expected auto, none, required, or a function definition."
                );
            }

            return;
        }

        if (($toolChoice['type'] ?? null) !== 'function' || !is_string($toolChoice['name'] ?? null)) {
            throw new ProviderException(
                "Unsupported tool choice array. Expected ['type' => 'function', 'name' => '<tool>']."
            );
        }
    }

    protected function toolChoiceName(): ?string
    {
        return is_array($this->toolChoice) ? $this->toolChoice['name'] : null;
    }
}
