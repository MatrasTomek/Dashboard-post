<?php

declare(strict_types=1);

use App\Support\ApiError;
use App\Support\ContainerFactory;
use App\Middleware\RequireAjaxHeaderMiddleware;
use App\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

require __DIR__ . '/../../backend/vendor/autoload.php';

$config = require __DIR__ . '/../../backend/config/config.php';

$container = ContainerFactory::build($config);
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->setBasePath('/api');
$app->addBodyParsingMiddleware();

$app->add(new RequireAjaxHeaderMiddleware());
$app->add(new SessionMiddleware(secureCookies: ($config['app_env'] ?? 'production') === 'production'));

$errorMiddleware = $app->addErrorMiddleware((bool) $config['debug'], true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    Throwable $exception,
) use ($app): Response {
    $response = (new ResponseFactory())->createResponse();

    if ($exception instanceof HttpException) {
        $status = $exception->getCode();
        $code = match ($status) {
            404 => 'not_found',
            405 => 'method_not_allowed',
            default => 'request_error',
        };

        return ApiError::json($response, $status, $code, $exception->getMessage());
    }

    return ApiError::json($response, 500, 'internal_error', 'Wystąpił nieoczekiwany błąd serwera.');
});

(require __DIR__ . '/../../backend/src/routes.php');

$app->run();
