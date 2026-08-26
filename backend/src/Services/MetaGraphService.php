<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\StreamInterface;

/**
 * Wraps the Meta Graph API OAuth exchange and health-check calls used to connect a
 * Facebook Page (+ linked Instagram Business account). Publish calls live in the
 * phases that implement them (Facebook/Instagram publish).
 */
final class MetaGraphService
{
    private const GRAPH_VERSION = 'v21.0';
    private const GRAPH_BASE_URI = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/';
    private const OAUTH_DIALOG_URL = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth';

    /** @var list<string> */
    private const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'instagram_basic',
        'instagram_content_publish',
        'business_management',
    ];

    private readonly Client $http;

    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $redirectUri,
    ) {
        $this->http = new Client(['base_uri' => self::GRAPH_BASE_URI, 'timeout' => 15]);
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
        ]);

        return self::OAUTH_DIALOG_URL . '?' . $query;
    }

    /**
     * Exchanges the OAuth `code` for a short-lived user token, then immediately
     * exchanges that for a long-lived one (~60 days) — the page access tokens
     * derived from it (see pageAccessToken()) are effectively long-lived in turn.
     */
    public function exchangeCodeForLongLivedUserToken(string $code): string
    {
        $shortLived = $this->get('oauth/access_token', [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        return $this->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => (string) $shortLived['access_token'],
        ])['access_token'];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function listManagedPages(string $userAccessToken): array
    {
        $data = $this->get('me/accounts', ['access_token' => $userAccessToken])['data'] ?? [];

        return array_map(
            static fn (array $page) => ['id' => (string) $page['id'], 'name' => (string) $page['name']],
            $data,
        );
    }

    /**
     * A Page access token derived this way from a long-lived user token is itself
     * long-lived as long as the user token stays valid and the user remains a Page admin.
     */
    public function pageAccessToken(string $pageId, string $userAccessToken): string
    {
        return $this->get($pageId, ['fields' => 'access_token', 'access_token' => $userAccessToken])['access_token'];
    }

    public function instagramBusinessAccountId(string $pageId, string $pageAccessToken): ?string
    {
        $response = $this->get($pageId, ['fields' => 'instagram_business_account', 'access_token' => $pageAccessToken]);

        return isset($response['instagram_business_account']['id'])
            ? (string) $response['instagram_business_account']['id']
            : null;
    }

    public function instagramUsername(string $igBusinessAccountId, string $pageAccessToken): ?string
    {
        $response = $this->get($igBusinessAccountId, ['fields' => 'username', 'access_token' => $pageAccessToken]);

        return isset($response['username']) ? (string) $response['username'] : null;
    }

    /**
     * Cheap pre-publish validity check — verify on use, don't trust blindly. A Page
     * token has no fixed expiry to poll proactively, so this is the health signal.
     */
    public function isPageTokenValid(string $pageAccessToken): bool
    {
        try {
            $this->get('me', ['fields' => 'id', 'access_token' => $pageAccessToken]);

            return true;
        } catch (MetaGraphException) {
            return false;
        }
    }

    /**
     * Text-only Facebook Page post. Returns the published post's id.
     */
    public function publishFacebookText(string $pageId, string $pageAccessToken, string $message): string
    {
        $response = $this->post($pageId . '/feed', [
            'message' => $message,
            'access_token' => $pageAccessToken,
        ]);

        return (string) $response['id'];
    }

    /**
     * Facebook Page post with a photo. $imageUrl must be a publicly reachable HTTPS
     * URL — the Graph API fetches it server-side, it cannot read a local file path.
     * Returns the published post's id.
     */
    public function publishFacebookPhoto(
        string $pageId,
        string $pageAccessToken,
        string $message,
        string $imageUrl,
    ): string {
        $response = $this->post($pageId . '/photos', [
            'url' => $imageUrl,
            'caption' => $message,
            'access_token' => $pageAccessToken,
        ]);

        return (string) ($response['post_id'] ?? $response['id']);
    }

    /**
     * Step 1 of Instagram publishing: create a media container. $mediaUrl must be a
     * publicly reachable HTTPS URL. Returns the container id.
     */
    public function createInstagramContainer(
        string $igAccountId,
        string $pageAccessToken,
        string $mediaUrl,
        string $caption,
        bool $isVideo,
    ): string {
        $params = [
            'caption' => $caption,
            'access_token' => $pageAccessToken,
        ];
        $params[$isVideo ? 'video_url' : 'image_url'] = $mediaUrl;

        if ($isVideo) {
            $params['media_type'] = 'REELS';
        }

        $response = $this->post($igAccountId . '/media', $params);

        return (string) $response['id'];
    }

    /**
     * Step 2 (poll): container processing status — 'IN_PROGRESS' | 'FINISHED' | 'ERROR' | 'EXPIRED'.
     */
    public function instagramContainerStatus(string $containerId, string $pageAccessToken): string
    {
        $response = $this->get($containerId, ['fields' => 'status_code', 'access_token' => $pageAccessToken]);

        return (string) ($response['status_code'] ?? 'IN_PROGRESS');
    }

    /**
     * Step 3: publish a finished container. Returns the published media id.
     */
    public function publishInstagramContainer(string $igAccountId, string $containerId, string $pageAccessToken): string
    {
        $response = $this->post($igAccountId . '/media_publish', [
            'creation_id' => $containerId,
            'access_token' => $pageAccessToken,
        ]);

        return (string) $response['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = $this->http->get($path, ['query' => $query]);

            return $this->decode($response->getBody());
        } catch (RequestException $e) {
            throw $this->toGraphException($e);
        } catch (GuzzleException $e) {
            throw $this->connectionException($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, array $formParams): array
    {
        try {
            $response = $this->http->post($path, ['form_params' => $formParams]);

            return $this->decode($response->getBody());
        } catch (RequestException $e) {
            throw $this->toGraphException($e);
        } catch (GuzzleException $e) {
            throw $this->connectionException($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(StreamInterface $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $body, true) ?? [];

        return $decoded;
    }

    private function toGraphException(RequestException $e): MetaGraphException
    {
        $body = $e->getResponse() !== null ? (string) $e->getResponse()->getBody() : '';
        $decoded = json_decode($body, true);
        $message = $decoded['error']['message'] ?? $e->getMessage();

        return new MetaGraphException((string) $message, 'meta_graph_error');
    }

    private function connectionException(GuzzleException $e): MetaGraphException
    {
        return new MetaGraphException(
            'Nie udało się połączyć z Meta Graph API: ' . $e->getMessage(),
            'meta_connection_error',
        );
    }
}
