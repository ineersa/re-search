<?php

declare(strict_types=1);

namespace App\Research\Guardrail\Exception;

/**
 * Thrown when a research run exceeds its token budget.
 */
final class BudgetExhaustedException extends \RuntimeException
{
}
