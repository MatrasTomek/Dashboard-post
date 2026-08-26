<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class SessionMiddleware implements MiddlewareInterface
{
    private const IDLE_TIMEOUT_SECONDS = 7200; // 2 hours

    public function __construct(private readonly bool $secureCookies)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $this->secureCookies,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        $lastActivity = $_SESSION['last_activity'] ?? null;

        if ($lastActivity !== null && (time() - $lastActivity) > self::IDLE_TIMEOUT_SECONDS) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        $_SESSION['last_activity'] = time();

        return $handler->handle($request);
    }
}
