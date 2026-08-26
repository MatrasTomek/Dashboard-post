<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class ApiError
{
    public static function json(Response $response, int $status, string $code, string $message): Response
    {
        $payload = json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($payload === false ? '{}' : $payload);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
