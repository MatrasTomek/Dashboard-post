<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\ApiError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        if (!isset($_SESSION['user_id'])) {
            return ApiError::json(
                (new ResponseFactory())->createResponse(401),
                401,
                'unauthenticated',
                'Zaloguj się, aby uzyskać dostęp do tego zasobu.',
            );
        }

        return $handler->handle($request);
    }
}
