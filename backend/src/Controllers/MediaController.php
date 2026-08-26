<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\PostRepository;
use App\Support\ApiError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class MediaController
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    private const MAX_VIDEO_BYTES = 100 * 1024 * 1024;

    /** @var array<string, array{type: 'image'|'video', extension: string}> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => ['type' => 'image', 'extension' => 'jpg'],
        'image/png' => ['type' => 'image', 'extension' => 'png'],
        'video/mp4' => ['type' => 'video', 'extension' => 'mp4'],
    ];

    public function __construct(
        private readonly PostRepository $posts,
        private readonly string $uploadsDir,
    ) {
    }

    public function upload(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId();
        $postId = (int) $args['id'];

        if ($this->posts->find($userId, $postId) === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['media'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return ApiError::json($response, 400, 'invalid_upload', 'Nie przesłano prawidłowego pliku.');
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $detectedMime = $this->detectMimeType(is_string($tmpPath) ? $tmpPath : null);

        if ($detectedMime === null || !isset(self::ALLOWED_MIME_TYPES[$detectedMime])) {
            return ApiError::json(
                $response,
                400,
                'unsupported_media_type',
                'Wspierane formaty: JPEG, PNG, MP4.',
            );
        }

        $spec = self::ALLOWED_MIME_TYPES[$detectedMime];
        $maxBytes = $spec['type'] === 'video' ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES;

        if ($file->getSize() !== null && $file->getSize() > $maxBytes) {
            return ApiError::json($response, 400, 'file_too_large', 'Plik jest zbyt duży.');
        }

        if (!is_dir($this->uploadsDir) && !mkdir($this->uploadsDir, 0755, true) && !is_dir($this->uploadsDir)) {
            return ApiError::json($response, 500, 'uploads_dir_unavailable', 'Katalog uploadów jest niedostępny.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $spec['extension'];
        $file->moveTo($this->uploadsDir . '/' . $filename);

        $this->posts->setMedia($postId, $filename, $spec['type'], $detectedMime);

        return $this->json($response, [
            'mediaFilename' => $filename,
            'mediaType' => $spec['type'],
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $this->userId();
        $postId = (int) $args['id'];
        $post = $this->posts->find($userId, $postId);

        if ($post === null) {
            return ApiError::json($response, 404, 'post_not_found', 'Nie znaleziono posta.');
        }

        if ($post['media_filename'] !== null) {
            $path = $this->uploadsDir . '/' . $post['media_filename'];

            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->posts->clearMedia($postId);

        return $response->withStatus(204);
    }

    private function detectMimeType(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
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
