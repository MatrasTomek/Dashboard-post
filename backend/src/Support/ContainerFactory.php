<?php

declare(strict_types=1);

namespace App\Support;

use App\Controllers\MediaController;
use App\Controllers\PostController;
use App\Controllers\SettingsController;
use App\Controllers\SocialAccountController;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\PostRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SocialAccountRepository;
use App\Repositories\UserRepository;
use App\Services\EncryptionService;
use App\Services\HistoryContextBuilder;
use App\Services\MetaGraphService;
use App\Services\PostGenerationService;
use DI\Container;
use DI\ContainerBuilder;
use PDO;

final class ContainerFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function build(array $config): Container
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            'config' => $config,
            PDO::class => static fn () => Database::connection($config['db']),
            EncryptionService::class => static fn () => new EncryptionService($config['encryption_key']),
            UserRepository::class => static fn (Container $c) => new UserRepository($c->get(PDO::class)),
            LoginAttemptRepository::class => static fn (Container $c) => new LoginAttemptRepository($c->get(PDO::class)),
            SettingsRepository::class => static fn (Container $c) => new SettingsRepository(
                $c->get(PDO::class),
                $c->get(EncryptionService::class),
            ),
            PostRepository::class => static fn (Container $c) => new PostRepository($c->get(PDO::class)),
            HistoryContextBuilder::class => static fn () => new HistoryContextBuilder(),
            PostGenerationService::class => static fn (Container $c) => new PostGenerationService(
                $c->get(HistoryContextBuilder::class),
            ),
            SettingsController::class => static fn (Container $c) => new SettingsController(
                $c->get(SettingsRepository::class),
                $c->get('config'),
            ),
            SocialAccountRepository::class => static fn (Container $c) => new SocialAccountRepository(
                $c->get(PDO::class),
                $c->get(EncryptionService::class),
            ),
            MetaGraphService::class => static fn (Container $c) => new MetaGraphService(
                (string) $c->get('config')['meta_app_id'],
                (string) $c->get('config')['meta_app_secret'],
                (string) $c->get('config')['meta_redirect_uri'],
            ),
            SocialAccountController::class => static fn (Container $c) => new SocialAccountController(
                $c->get(SocialAccountRepository::class),
                $c->get(MetaGraphService::class),
            ),
            PostController::class => static fn (Container $c) => new PostController(
                $c->get(PostRepository::class),
                $c->get(SettingsRepository::class),
                $c->get(PostGenerationService::class),
                $c->get(SocialAccountRepository::class),
                $c->get(MetaGraphService::class),
                $c->get('config'),
            ),
            'uploads_dir' => dirname(__DIR__, 2) . '/../public/uploads',
            MediaController::class => static fn (Container $c) => new MediaController(
                $c->get(PostRepository::class),
                $c->get('uploads_dir'),
            ),
        ]);

        return $builder->build();
    }
}
