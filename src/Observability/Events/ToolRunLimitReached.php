<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Tools\ToolInterface;

class ToolRunLimitReached
{
    public function __construct(
        public readonly ToolInterface $tool,
        public readonly int $runs,
        public readonly int $limit,
    ) {
    }
}
