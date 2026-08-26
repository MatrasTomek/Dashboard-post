<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Support\ApiError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $loginAttempts,
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $ip = $this->clientIp($request);

        if ($this->loginAttempts->isRateLimited($ip)) {
            return ApiError::json($response, 429, 'too_many_attempts', 'Za dużo nieudanych prób logowania. Spróbuj ponownie później.');
        }

        $user = $username !== '' ? $this->users->findByUsername($username) : null;

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->loginAttempts->recordFailedAttempt($ip);

            return ApiError::json($response, 401, 'invalid_credentials', 'Nieprawidłowa nazwa użytkownika lub hasło.');
        }

        $this->loginAttempts->clearAttempts($ip);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        $payload = json_encode(['id' => (int) $user['id'], 'username' => $user['username']]);
        $response->getBody()->write($payload === false ? '{}' : $payload);

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function logout(Request $request, Response $response): Response
    {
        $_SESSION = [];
        session_destroy();

        return $response->withStatus(204);
    }

    public function me(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user_id'] ?? null;

        if ($userId === null) {
            return ApiError::json($response, 401, 'unauthenticated', 'Nie zalogowano.');
        }

        $user = $this->users->findById((int) $userId);

        if ($user === null) {
            return ApiError::json($response, 401, 'unauthenticated', 'Nie zalogowano.');
        }

        $payload = json_encode(['id' => (int) $user['id'], 'username' => $user['username']]);
        $response->getBody()->write($payload === false ? '{}' : $payload);

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function clientIp(Request $request): string
    {
        $server = $request->getServerParams();

        return (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
