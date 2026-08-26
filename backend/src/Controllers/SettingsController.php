<?php

declare(strict_types=1);

namespace App\Controllers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Repositories\SettingsRepository;
use App\Support\ApiError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SettingsController
{
    private const KEY_ANTHROPIC_API_KEY = 'anthropic_api_key';
    private const KEY_DEFAULT_MODEL = 'default_model';
    private const KEY_HISTORY_TOKEN_BUDGET = 'history_token_budget';
    private const KEY_BRAND_VOICE_NOTES = 'brand_voice_notes';

    /** @var list<string> */
    private const ALLOWED_MODELS = ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly array $config,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $userId = $this->userId();

        $payload = [
            'anthropicKeyConfigured' => $this->settings->has($userId, self::KEY_ANTHROPIC_API_KEY),
            'defaultModel' => $this->settings->getPlain(
                $userId,
                self::KEY_DEFAULT_MODEL,
                (string) $this->config['default_anthropic_model'],
            ),
            'historyTokenBudget' => (int) $this->settings->getPlain(
                $userId,
                self::KEY_HISTORY_TOKEN_BUDGET,
                (string) $this->config['default_history_token_budget'],
            ),
            'brandVoiceNotes' => $this->settings->getPlain($userId, self::KEY_BRAND_VOICE_NOTES, ''),
        ];

        return $this->json($response, $payload);
    }

    public function saveAnthropicKey(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $apiKey = trim((string) ($body['apiKey'] ?? ''));

        if ($apiKey === '') {
            return ApiError::json($response, 400, 'missing_api_key', 'Podaj klucz API Anthropic.');
        }

        $validation = $this->testKey($apiKey);

        if ($validation !== null) {
            return $validation;
        }

        $this->settings->setEncrypted($this->userId(), self::KEY_ANTHROPIC_API_KEY, $apiKey);

        return $this->json($response, ['saved' => true]);
    }

    public function testAnthropicKey(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $apiKey = trim((string) ($body['apiKey'] ?? ''));

        if ($apiKey === '') {
            $apiKey = (string) ($this->settings->getEncrypted($this->userId(), self::KEY_ANTHROPIC_API_KEY) ?? '');
        }

        if ($apiKey === '') {
            return ApiError::json($response, 400, 'missing_api_key', 'Brak zapisanego klucza API do przetestowania.');
        }

        $validation = $this->testKey($apiKey);

        if ($validation !== null) {
            return $validation;
        }

        return $this->json($response, ['valid' => true]);
    }

    public function deleteAnthropicKey(Request $request, Response $response): Response
    {
        $this->settings->delete($this->userId(), self::KEY_ANTHROPIC_API_KEY);

        return $response->withStatus(204);
    }

    public function savePreferences(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $userId = $this->userId();

        if (isset($body['defaultModel'])) {
            $model = (string) $body['defaultModel'];

            if (!in_array($model, self::ALLOWED_MODELS, true)) {
                return ApiError::json($response, 400, 'invalid_model', 'Nieznany model Claude.');
            }

            $this->settings->setPlain($userId, self::KEY_DEFAULT_MODEL, $model);
        }

        if (isset($body['historyTokenBudget'])) {
            $budget = (int) $body['historyTokenBudget'];

            if ($budget < 500 || $budget > 100000) {
                return ApiError::json($response, 400, 'invalid_token_budget', 'Budżet tokenów historii musi być w zakresie 500–100000.');
            }

            $this->settings->setPlain($userId, self::KEY_HISTORY_TOKEN_BUDGET, (string) $budget);
        }

        if (isset($body['brandVoiceNotes'])) {
            $this->settings->setPlain($userId, self::KEY_BRAND_VOICE_NOTES, (string) $body['brandVoiceNotes']);
        }

        return $this->json($response, ['saved' => true]);
    }

    /**
     * Sends a trivial (max_tokens: 1) request to confirm the key is valid without
     * generating real content. Returns null on success, or an error Response to
     * return immediately.
     */
    private function testKey(string $apiKey): ?Response
    {
        $client = new Client(apiKey: $apiKey);

        try {
            $client->messages->create(
                model: 'claude-haiku-4-5',
                maxTokens: 1,
                messages: [['role' => 'user', 'content' => 'Hi']],
            );
        } catch (AuthenticationException) {
            return ApiError::json(
                $this->responseFactory(),
                400,
                'anthropic_invalid_key',
                'Ten klucz API Anthropic jest nieprawidłowy.',
            );
        } catch (PermissionDeniedException) {
            return ApiError::json(
                $this->responseFactory(),
                400,
                'anthropic_permission_denied',
                'Ten klucz API Anthropic nie ma wymaganych uprawnień.',
            );
        } catch (APIConnectionException) {
            return ApiError::json(
                $this->responseFactory(),
                502,
                'anthropic_connection_error',
                'Nie udało się połączyć z API Anthropic — spróbuj ponownie.',
            );
        }

        return null;
    }

    private function userId(): int
    {
        return (int) $_SESSION['user_id'];
    }

    private function json(Response $response, array $payload): Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($body === false ? '{}' : $body);

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function responseFactory(): Response
    {
        return new \Slim\Psr7\Response();
    }
}
