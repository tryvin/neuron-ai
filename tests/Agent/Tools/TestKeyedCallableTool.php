<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;

/**
 * Serializable tool with real result storage and input-based run keys, for
 * tests that need to observe a tool's result after parallel execution.
 */
class TestKeyedCallableTool extends Tool implements HasRunKey
{
    use TrackByInputs;
}
