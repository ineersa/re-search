<?php

declare(strict_types=1);

namespace App\Research\Orchestration\Dto;

final readonly class NextAction
{
    /**
     * @param list<string> $operationKeys
     */
    private function __construct(
        public string $type,
        public array $operationKeys = [],
    ) {
    }

    public static function none(): self
    {
        return new self('none');
    }

    public static function dispatchLlm(string $operationKey): self
    {
        return new self('dispatch_llm', [$operationKey]);
    }

    /**
     * @param list<string> $operationKeys
     */
    public static function dispatchTools(array $operationKeys): self
    {
        return new self('dispatch_tools', array_values($operationKeys));
    }
}
