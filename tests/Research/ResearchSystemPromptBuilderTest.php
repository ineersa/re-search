<?php

declare(strict_types=1);

namespace App\Tests\Research;

use App\Research\ResearchSystemPromptBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ResearchSystemPromptBuilderTest extends TestCase
{
    public function testBuildIncludesWebResearchRules(): void
    {
        $builder = new ResearchSystemPromptBuilder(new MockClock('2026-03-17 00:00:00'));
        $brief = $builder->build('What is Symfony?');

        self::assertStringContainsString('Today: 2026-03-17', $brief);
        self::assertStringContainsString('What is Symfony?', $brief);
        self::assertStringContainsString('websearch_search', $brief);
        self::assertStringContainsString('websearch_open', $brief);
        self::assertStringContainsString('websearch_find', $brief);
        self::assertStringContainsString('Nothing found in reviewed sources', $brief);
        self::assertStringContainsString('Impossible to verify from available sources', $brief);
        self::assertStringContainsString('75000', $brief);
        self::assertStringContainsString('20k-30k', $brief);
    }
}
