<?php

declare(strict_types=1);

namespace App\Tests\Platform\Generic\Completions;

use App\Platform\Generic\Completions\ResultConverter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ResultConverterXmlToolCallTest extends TestCase
{
    public function testExtractToolCallsFromThinkingBufferCoercesBooleansAndInts(): void
    {
        $converter = new ResultConverter();
        $buffer = <<<'XML'
<tool_call>
<function=websearch_open>
<parameter=url>
https://github.com/theofidry/PsyshBundle
</parameter>
<parameter=fetchAll>
false
</parameter>
<parameter=numberOfLines>
100
</parameter>
</function>
</tool_call>
XML;

        $method = new ReflectionMethod(ResultConverter::class, 'extractToolCallsFromThinkingBuffer');
        $invokeArgs = [&$buffer, true];
        /** @var list<\Symfony\AI\Platform\Result\ToolCall> $calls */
        $calls = $method->invokeArgs($converter, $invokeArgs);

        $this->assertCount(1, $calls);
        $arguments = $calls[0]->getArguments();
        $this->assertSame('https://github.com/theofidry/PsyshBundle', $arguments['url']);
        $this->assertFalse($arguments['fetchAll']);
        $this->assertSame(100, $arguments['numberOfLines']);
        $this->assertSame('', $buffer);
    }

    public function testExtractToolCallsFromThinkingBufferCoercesTrueAndNull(): void
    {
        $converter = new ResultConverter();
        $buffer = <<<'XML'
<tool_call>
<function=websearch_open>
<parameter=url>
https://a.test
</parameter>
<parameter=fetchAll>
True
</parameter>
<parameter=startAtLine>
null
</parameter>
</function>
</tool_call>
XML;

        $method = new ReflectionMethod(ResultConverter::class, 'extractToolCallsFromThinkingBuffer');
        $invokeArgs = [&$buffer, true];
        $calls = $method->invokeArgs($converter, $invokeArgs);

        $this->assertTrue($calls[0]->getArguments()['fetchAll']);
        $this->assertNull($calls[0]->getArguments()['startAtLine']);
    }
}
