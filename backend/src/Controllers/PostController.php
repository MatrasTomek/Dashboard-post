<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PostRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SocialAccountRepository;
use App\Services\CaptionGenerationException;
use App\Services\MetaGraphException;
use App\Services\MetaGraphService;
use App\Services\PostGenerationService;
use App\Support\ApiError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PostController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly SettingsRepository $settings,
        private readonly PostGenerationService $generationService,
        private readonly SocialAccountRepository $socialAccounts,
        private readonly MetaGraphService $meta,
        private readonly array $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($query['perPage'] ?? 20)));

        $result = $this->posts->listPaginated($this->userId(), $page, $perPage);

        return $this->json($response, [
            'items' => array_map($this->toApiShape(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $post = $this->posts->find($this->userId(), (int) $args['id']);

        if ($post === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        return $this->json($response, $this->toApiShape($post));
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $description = trim((string) ($body['productDescription'] ?? ''));

        if ($description === '') {
            return ApiError::json($response, 400, 'missing_description', 'Podaj opis produktu/usługi.');
        }

        $postId = $this->posts->create($this->userId(), $description);
        $post = $this->posts->find($this->userId(), $postId);

        return $this->json($response, $this->toApiShape($post), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId();
        $postId = (int) $args['id'];

        if ($this->posts->find($userId, $postId) === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        $body = (array) $request->getParsedBody();
        $this->posts->updateDraft(
            $userId,
            $postId,
            isset($body['productDescription']) ? (string) $body['productDescription'] : null,
            isset($body['finalCaption']) ? (string) $body['finalCaption'] : null,
        );

        return $this->json($response, $this->toApiShape($this->posts->find($userId, $postId)));
    }

    public function generateCaption(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId();
        $postId = (int) $args['id'];
        $post = $this->posts->find($userId, $postId);

        if ($post === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        $apiKey = $this->settings->getEncrypted($userId, 'anthropic_api_key');

        if ($apiKey === null) {
            return ApiError::json(
                $response,
                400,
                'anthropic_key_missing',
                'Dodaj swój klucz API Anthropic w Ustawieniach, aby generować podpisy.',
            );
        }

        $model = $this->settings->getPlain($userId, 'default_model', (string) $this->config['default_anthropic_model']);
        $tokenBudget = (int) $this->settings->getPlain(
            $userId,
            'history_token_budget',
            (string) $this->config['default_history_token_budget'],
        );
        $brandVoiceNotes = $this->settings->getPlain($userId, 'brand_voice_notes', '');
        $history = $this->posts->captionHistoryNewestFirst($userId);

        try {
            $caption = $this->generationService->generateCaption(
                $apiKey,
                (string) $post['product_description'],
                $history,
                (string) $model,
                $brandVoiceNotes !== '' ? $brandVoiceNotes : null,
                $tokenBudget,
            );
        } catch (CaptionGenerationException $e) {
            return ApiError::json($response, 502, $e->errorCode(), $e->getMessage());
        }

        $this->posts->setGeneratedCaption($postId, $caption);

        return $this->json($response, ['generatedCaption' => $caption]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->posts->delete($this->userId(), (int) $args['id']);

        return $response->withStatus(204);
    }

    public function publishFacebook(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId();
        $postId = (int) $args['id'];
        $post = $this->posts->find($userId, $postId);

        if ($post === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        $result = $this->attemptFacebookPublish($userId, $post);

        if ($result['status'] === 'skipped') {
            return ApiError::json($response, 400, $result['code'], $result['error']);
        }

        return $this->json($response, $this->toApiShape($this->posts->find($userId, $postId)));
    }

    public function publishInstagram(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId();
        $postId = (int) $args['id'];
        $post = $this->posts->find($userId, $postId);

        if ($post === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        $result = $this->attemptInstagramPublish($userId, $post);

        if ($result['status'] === 'skipped') {
            return ApiError::json($response, 400, $result['code'], $result['error']);
        }

        return $this->json($response, $this->toApiShape($this->posts->find($userId, $postId)));
    }

    /**
     * Publishes to Facebook and Instagram independently (no rollback across the two
     * external APIs) and aggregates the outcome — the same attempt* helpers the
     * single-platform endpoints use, so there is one publish code path, not two.
     */
    public function publish(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId();
        $postId = (int) $args['id'];
        $post = $this->posts->find($userId, $postId);

        if ($post === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        $facebook = $this->attemptFacebookPublish($userId, $post);
        $instagram = $this->attemptInstagramPublish($userId, $post);

        return $this->json($response, [
            'facebook' => ['status' => $facebook['status'], 'error' => $facebook['error']],
            'instagram' => ['status' => $instagram['status'], 'error' => $instagram['error']],
            'post' => $this->toApiShape($this->posts->find($userId, $postId)),
        ]);
    }

    /**
     * @return array{status: 'success'|'failed'|'skipped', code: ?string, error: ?string}
     */
    private function attemptFacebookPublish(int $userId, array $post): array
    {
        $postId = (int) $post['id'];
        $account = $this->socialAccounts->find($userId);

        if ($account === null) {
            return $this->skipped('social_not_connected', 'Połącz konto Facebook w Ustawieniach.');
        }

        $caption = $post['final_caption'] ?? $post['generated_caption'];

        if ($caption === null) {
            return $this->skipped('missing_caption', 'Wygeneruj lub wpisz treść posta przed publikacją.');
        }

        $pageToken = $this->socialAccounts->decryptedPageAccessToken($account);

        try {
            $fbPostId = $post['media_filename'] !== null
                ? $this->meta->publishFacebookPhoto(
                    $account['fb_page_id'],
                    $pageToken,
                    $caption,
                    $this->publicMediaUrl($post['media_filename']),
                )
                : $this->meta->publishFacebookText($account['fb_page_id'], $pageToken, $caption);
        } catch (MetaGraphException $e) {
            $this->posts->markFacebookFailure($postId, $e->getMessage());

            return ['status' => 'failed', 'code' => $e->errorCode(), 'error' => $e->getMessage()];
        }

        $this->posts->markFacebookSuccess($postId, $fbPostId);

        return ['status' => 'success', 'code' => null, 'error' => null];
    }

    /**
     * @return array{status: 'success'|'failed'|'skipped', code: ?string, error: ?string}
     */
    private function attemptInstagramPublish(int $userId, array $post): array
    {
        $postId = (int) $post['id'];
        $account = $this->socialAccounts->find($userId);

        if ($account === null || $account['ig_business_account_id'] === null) {
            return $this->skipped(
                'instagram_not_connected',
                'Połącz konto Instagram Business (przez Ustawienia) przed publikacją.',
            );
        }

        $caption = $post['final_caption'] ?? $post['generated_caption'];

        if ($caption === null) {
            return $this->skipped('missing_caption', 'Wygeneruj lub wpisz treść posta przed publikacją.');
        }

        if ($post['media_filename'] === null) {
            return $this->skipped(
                'missing_media',
                'Instagram wymaga zdjęcia lub wideo — dodaj medium przed publikacją.',
            );
        }

        $pageToken = $this->socialAccounts->decryptedPageAccessToken($account);
        $isVideo = $post['media_type'] === 'video';

        try {
            $containerId = $this->meta->createInstagramContainer(
                $account['ig_business_account_id'],
                $pageToken,
                $this->publicMediaUrl($post['media_filename']),
                $caption,
                $isVideo,
            );

            $status = $this->waitForContainerReady($containerId, $pageToken);

            if ($status === 'FINISHED') {
                $igMediaId = $this->meta->publishInstagramContainer(
                    $account['ig_business_account_id'],
                    $containerId,
                    $pageToken,
                );
                $this->posts->markInstagramSuccess($postId, $igMediaId, $containerId);

                return ['status' => 'success', 'code' => null, 'error' => null];
            }

            $error = $status === 'IN_PROGRESS'
                ? 'Przetwarzanie medium trwa dłużej niż oczekiwano — spróbuj opublikować ponownie za chwilę.'
                : "Instagram odrzucił medium (status: {$status}).";
            $this->posts->markInstagramFailure($postId, $error, $containerId);

            return ['status' => 'failed', 'code' => 'instagram_processing_failed', 'error' => $error];
        } catch (MetaGraphException $e) {
            $this->posts->markInstagramFailure($postId, $e->getMessage());

            return ['status' => 'failed', 'code' => $e->errorCode(), 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: 'skipped', code: string, error: string}
     */
    private function skipped(string $code, string $message): array
    {
        return ['status' => 'skipped', 'code' => $code, 'error' => $message];
    }

    /**
     * Bounded synchronous poll — there are no background workers on OVH shared
     * hosting, so this happens inline. Images finish almost immediately; video can
     * still be IN_PROGRESS when the bound is hit (see the caller's handling of that).
     */
    private function waitForContainerReady(string $containerId, string $pageToken): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $status = $this->meta->instagramContainerStatus($containerId, $pageToken);

            if ($status !== 'IN_PROGRESS') {
                return $status;
            }

            usleep(500_000);
        }

        return 'IN_PROGRESS';
    }

    private function publicMediaUrl(string $filename): string
    {
        return rtrim((string) $this->config['app_base_url'], '/') . '/uploads/' . $filename;
    }

    private function toApiShape(array $post): array
    {
        return [
            'id' => (int) $post['id'],
            'productDescription' => $post['product_description'],
            'generatedCaption' => $post['generated_caption'],
            'finalCaption' => $post['final_caption'],
            'mediaFilename' => $post['media_filename'],
            'mediaType' => $post['media_type'],
            'status' => $post['status'],
            'fbStatus' => $post['fb_status'],
            'fbError' => $post['fb_error'],
            'igStatus' => $post['ig_status'],
            'igError' => $post['ig_error'],
            'createdAt' => $post['created_at'],
        ];
    }

    private function userId(): int
    {
        return (int) $_SESSION['user_id'];
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($body === false ? '{}' : $body);

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
