<?php

declare(strict_types=1);

namespace App\Tests\Research;

use App\Research\ResearchBriefBuilder;
use PHPUnit\Framework\TestCase;

final class ResearchBriefBuilderTest extends TestCase
{
    public function testBuildIncludesWebResearchRules(): void
    {
        $builder = new ResearchBriefBuilder(new \DateTimeImmutable('2026-03-17'));
        $brief = $builder->build('What is Symfony?');

        $this->assertStringContainsString('Today: 2026-03-17', $brief);
        $this->assertStringContainsString('What is Symfony?', $brief);
        $this->assertStringContainsString('websearch_search', $brief);
        $this->assertStringContainsString('websearch_open', $brief);
        $this->assertStringContainsString('websearch_find', $brief);
        $this->assertStringContainsString('Nothing found in reviewed sources', $brief);
        $this->assertStringContainsString('Impossible to verify from available sources', $brief);
        $this->assertStringContainsString('75000', $brief);
        $this->assertStringContainsString('5000', $brief);
    }
}
