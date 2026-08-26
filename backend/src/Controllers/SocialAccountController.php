<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SocialAccountRepository;
use App\Services\MetaGraphException;
use App\Services\MetaGraphService;
use App\Support\ApiError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SocialAccountController
{
    public function __construct(
        private readonly SocialAccountRepository $accounts,
        private readonly MetaGraphService $meta,
    ) {
    }

    public function status(Request $request, Response $response): Response
    {
        $account = $this->accounts->find($this->userId());

        if ($account === null) {
            return $this->json($response, ['connected' => false]);
        }

        return $this->json($response, [
            'connected' => true,
            'fbPageName' => $account['fb_page_name'],
            'igUsername' => $account['ig_username'],
            'status' => $account['status'],
            'lastError' => $account['last_error'],
            'connectedAt' => $account['connected_at'],
        ]);
    }

    public function connectStart(Request $request, Response $response): Response
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        return $this->json($response, ['authorizationUrl' => $this->meta->authorizationUrl($state)]);
    }

    public function connectCallback(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;
        $expectedState = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);

        if ($code === null || $state === null || $expectedState === null || !hash_equals((string) $expectedState, (string) $state)) {
            return $this->redirectToSettings($response, 'invalid_oauth_state');
        }

        try {
            $userToken = $this->meta->exchangeCodeForLongLivedUserToken((string) $code);
            $pages = $this->meta->listManagedPages($userToken);

            if ($pages === []) {
                return $this->redirectToSettings($response, 'no_pages_found');
            }

            // Single-user app: connect the first (typically only) managed Page.
            $page = $pages[0];
            $pageToken = $this->meta->pageAccessToken($page['id'], $userToken);
            $igAccountId = $this->meta->instagramBusinessAccountId($page['id'], $pageToken);
            $igUsername = $igAccountId !== null ? $this->meta->instagramUsername($igAccountId, $pageToken) : null;

            $this->accounts->upsert(
                $this->userId(),
                $page['id'],
                $page['name'],
                $pageToken,
                $igAccountId,
                $igUsername,
                null,
            );
        } catch (MetaGraphException $e) {
            return $this->redirectToSettings($response, $e->errorCode());
        }

        return $this->redirectToSettings($response, null);
    }

    public function disconnect(Request $request, Response $response): Response
    {
        $this->accounts->delete($this->userId());

        return $response->withStatus(204);
    }

    /**
     * There is no way to silently refresh a Page access token without the original
     * user re-approving via the OAuth dialog — this re-validates the stored token
     * and updates its health status so the UI can prompt "Reconnect" if it's dead.
     */
    public function checkHealth(Request $request, Response $response): Response
    {
        $account = $this->accounts->find($this->userId());

        if ($account === null) {
            return ApiError::json($response, 404, 'not_connected', 'Brak połączonego konta Facebook.');
        }

        $token = $this->accounts->decryptedPageAccessToken($account);
        $valid = $this->meta->isPageTokenValid($token);

        $this->accounts->markStatus(
            $this->userId(),
            $valid ? 'active' : 'expired',
            $valid ? null : 'Token dostępu wygasł lub został unieważniony. Połącz konto ponownie.',
        );

        return $this->json($response, ['valid' => $valid]);
    }

    private function redirectToSettings(Response $response, ?string $error): Response
    {
        $query = $error !== null ? ('?error=' . urlencode($error)) : '?connected=1';

        return $response->withStatus(302)->withHeader('Location', '/settings' . $query);
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
}
