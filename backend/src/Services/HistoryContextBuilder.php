<?php

declare(strict_types=1);

namespace App\Services;

use Anthropic\Client;

/**
 * Builds the "previous posts" block for the caption-generation prompt, capped to a
 * token budget so a growing history never blows past a safe prompt size. Recency-based
 * truncation only — no embeddings/vector search, by design (see project plan §6.4).
 */
final class HistoryContextBuilder
{
    private const NO_HISTORY_TEXT = 'No previous posts yet — this is the first one.';

    /**
     * @param list<string> $captionsNewestFirst
     */
    public function build(Client $client, array $captionsNewestFirst, int $tokenBudget, string $model): string
    {
        if ($captionsNewestFirst === []) {
            return self::NO_HISTORY_TEXT;
        }

        // Fast local estimate (~4 chars/token) to avoid one countTokens call per post.
        $candidate = [];
        $estimatedChars = 0;
        $charBudget = $tokenBudget * 4;

        foreach ($captionsNewestFirst as $caption) {
            $line = '- ' . str_replace("\n", ' ', trim($caption));
            $estimatedChars += strlen($line) + 1;

            if ($estimatedChars > $charBudget && $candidate !== []) {
                break;
            }

            $candidate[] = $line;
        }

        // Verify (and correct) the estimate with exactly one real token count.
        while ($candidate !== []) {
            $block = implode("\n", $candidate);
            $count = $client->messages->countTokens(
                messages: [['role' => 'user', 'content' => $block]],
                model: $model,
            );

            if ($count->inputTokens <= $tokenBudget || count($candidate) === 1) {
                return $block;
            }

            // Drop the oldest (last) line and retry.
            array_pop($candidate);
        }

        return self::NO_HISTORY_TEXT;
    }
}
