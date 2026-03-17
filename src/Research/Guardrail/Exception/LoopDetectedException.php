<?php

declare(strict_types=1);

namespace App\Research\Guardrail\Exception;

/**
 * Thrown when duplicate tool calls indicate a research loop.
 */
final class LoopDetectedException extends \RuntimeException
{
}
