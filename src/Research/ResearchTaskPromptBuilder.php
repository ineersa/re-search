<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;

final class ResearchTaskPromptBuilder
{
    public function __construct(
        private readonly ?PlatformInterface $platform = null,
        private readonly ?string $model = null,
    ) {
    }

    public function build(string $rawQuery): string
    {
        if (null === $this->platform || null === $this->model || '' === trim($this->model)) {
            return $this->fallback($rawQuery);
        }

        $messages = new MessageBag(
            Message::forSystem('You rewrite raw user queries into a concise web-research task brief. Return markdown only.'),
            Message::ofUser(<<<INPUT
Rewrite the following user query into a single clear web-research task for the assistant.
Keep the original intent, improve clarity, and ask for a direct comprehensive answer.
Make it explicit that the task is for web research.

User query:
{$rawQuery}
INPUT)
        );

        try {
            $result = $this->platform->invoke($this->model, $messages, ['stream' => false])->getResult();
            if ($result instanceof TextResult && '' !== trim($result->getContent())) {
                return trim($result->getContent());
            }
        } catch (\Throwable) {
        }

        return $this->fallback($rawQuery);
    }

    private function fallback(string $rawQuery): string
    {
        return <<<PROMPT
User asks:
{$rawQuery}

PROMPT;
    }
}
