<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\MediaController;
use App\Controllers\PostController;
use App\Controllers\SettingsController;
use App\Controllers\SocialAccountController;
use App\Middleware\AuthMiddleware;
use Slim\App;

/** @var App $app */

$app->group('/auth', function ($group) {
    $group->post('/login', [AuthController::class, 'login']);
    $group->post('/logout', [AuthController::class, 'logout']);
    $group->get('/me', [AuthController::class, 'me']);
});

$app->group('/settings', function ($group) {
    $group->get('', [SettingsController::class, 'show']);
    $group->put('/anthropic-key', [SettingsController::class, 'saveAnthropicKey']);
    $group->post('/anthropic-key/test', [SettingsController::class, 'testAnthropicKey']);
    $group->delete('/anthropic-key', [SettingsController::class, 'deleteAnthropicKey']);
    $group->put('/preferences', [SettingsController::class, 'savePreferences']);
})->add(new AuthMiddleware());

$app->group('/social', function ($group) {
    $group->get('/status', [SocialAccountController::class, 'status']);
    $group->get('/connect/start', [SocialAccountController::class, 'connectStart']);
    $group->get('/connect/callback', [SocialAccountController::class, 'connectCallback']);
    $group->post('/disconnect', [SocialAccountController::class, 'disconnect']);
    $group->post('/refresh-token', [SocialAccountController::class, 'checkHealth']);
})->add(new AuthMiddleware());

$app->group('/posts', function ($group) {
    $group->get('', [PostController::class, 'index']);
    $group->get('/{id}', [PostController::class, 'show']);
    $group->post('', [PostController::class, 'create']);
    $group->put('/{id}', [PostController::class, 'update']);
    $group->post('/{id}/generate-caption', [PostController::class, 'generateCaption']);
    $group->delete('/{id}', [PostController::class, 'delete']);
    $group->post('/{id}/media', [MediaController::class, 'upload']);
    $group->delete('/{id}/media', [MediaController::class, 'delete']);
    $group->post('/{id}/publish/facebook', [PostController::class, 'publishFacebook']);
    $group->post('/{id}/publish/instagram', [PostController::class, 'publishInstagram']);
    $group->post('/{id}/publish', [PostController::class, 'publish']);
})->add(new AuthMiddleware());
