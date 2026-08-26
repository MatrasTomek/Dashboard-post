<?php

declare(strict_types=1);

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

final class PostGenerationService
{
    private const DEFAULT_HISTORY_TOKEN_BUDGET = 6000;
    private const MAX_TOKENS = 1024;

    public function __construct(private readonly HistoryContextBuilder $historyContextBuilder)
    {
    }

    /**
     * @param list<string> $historyCaptionsNewestFirst
     *
     * @throws CaptionGenerationException
     */
    public function generateCaption(
        string $apiKey,
        string $productDescription,
        array $historyCaptionsNewestFirst,
        string $model,
        ?string $brandVoiceNotes = null,
        int $historyTokenBudget = self::DEFAULT_HISTORY_TOKEN_BUDGET,
    ): string {
        $client = new Client(apiKey: $apiKey);

        try {
            $historyBlock = $this->historyContextBuilder->build(
                $client,
                $historyCaptionsNewestFirst,
                $historyTokenBudget,
                $model,
            );

            $response = $client->messages->create(
                model: $model,
                maxTokens: self::MAX_TOKENS,
                system: $this->buildSystemPrompt($brandVoiceNotes),
                messages: [
                    ['role' => 'user', 'content' => $this->buildUserMessage($productDescription, $historyBlock)],
                ],
            );
        } catch (AuthenticationException) {
            throw new CaptionGenerationException(
                'Twój klucz API Anthropic jest nieprawidłowy. Sprawdź go w Ustawieniach.',
                'anthropic_invalid_key',
            );
        } catch (PermissionDeniedException) {
            throw new CaptionGenerationException(
                'Twój klucz API Anthropic nie ma uprawnień do tego modelu.',
                'anthropic_permission_denied',
            );
        } catch (RateLimitException) {
            throw new CaptionGenerationException(
                'Limit zapytań Anthropic został przekroczony — spróbuj ponownie za chwilę.',
                'anthropic_rate_limited',
            );
        } catch (InternalServerException) {
            throw new CaptionGenerationException(
                'Claude jest tymczasowo niedostępny. Spróbuj ponownie za chwilę.',
                'anthropic_unavailable',
            );
        } catch (APIConnectionException) {
            throw new CaptionGenerationException(
                'Nie udało się połączyć z API Claude — sprawdź połączenie i spróbuj ponownie.',
                'anthropic_connection_error',
            );
        }

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new CaptionGenerationException('Claude nie zwrócił treści tekstowej.', 'anthropic_empty_response');
    }

    private function buildSystemPrompt(?string $brandVoiceNotes): string
    {
        $notesLine = $brandVoiceNotes !== null && $brandVoiceNotes !== ''
            ? "- {$brandVoiceNotes}\n"
            : '';

        return <<<PROMPT
            You are a marketing copywriter for a single small business. Your job is to write
            one social media caption (for simultaneous use on Facebook and Instagram) that
            promotes a specific product or service the business owner describes to you.

            Style and consistency rules:
            - Match the tone, voice, emoji usage, hashtag style, and typical length of the
              business's previous posts shown to you below, so this caption feels consistent
              with their established brand voice.
            {$notesLine}- Do not invent facts, prices, or claims not present in the product description.
            - Output ONLY the caption text itself — no preamble, no explanation, no quotation
              marks around it, no "Here's a caption:" framing.
            - Keep it suitable for both Facebook and Instagram (avoid platform-specific
              formatting Instagram doesn't render, e.g. clickable links in the caption body).
            PROMPT;
    }

    private function buildUserMessage(string $productDescription, string $historyBlock): string
    {
        return <<<MESSAGE
            PRODUCT/SERVICE TO PROMOTE:
            {$productDescription}

            PREVIOUS POSTS (most recent last, for style/tone/consistency reference only —
            do not repeat their content, just match their voice):
            ---
            {$historyBlock}
            ---

            Write one new caption promoting the product/service above, following the style
            of the previous posts. Return only the caption text.
            MESSAGE;
    }
}
