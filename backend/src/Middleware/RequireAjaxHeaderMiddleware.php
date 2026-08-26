<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\ApiError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Lightweight CSRF guard for state-changing (POST/PUT/DELETE) requests: a cross-site
 * <form> submission cannot set custom headers, so requiring this one blocks forged
 * requests even if SameSite=Lax cookie policy is ever bypassed by an older browser.
 */
final class RequireAjaxHeaderMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $method = $request->getMethod();

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if ($request->getHeaderLine('X-Requested-With') !== 'XMLHttpRequest') {
                return ApiError::json(
                    (new ResponseFactory())->createResponse(403),
                    403,
                    'missing_ajax_header',
                    'Brakujący nagłówek X-Requested-With.',
                );
            }
        }

        return $handler->handle($request);
    }
}
