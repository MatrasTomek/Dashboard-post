<?php

declare(strict_types=1);

// Run daily via the OVH control panel's cron scheduler:
//   php /path/to/backend/cron/refresh_fb_token.php
//
// There is no way to silently refresh a Page access token without the user
// re-approving via the OAuth dialog (see MetaGraphService/SocialAccountController
// for why), so this just re-validates the stored token and flags it as expired if
// it's dead — the "Reconnect" prompt in Settings is the actual recovery path. This
// script is deliberately independent of the recurring OVH cron's exact timing:
// the pre-publish check in PostController re-validates on every publish anyway.

require __DIR__ . '/../vendor/autoload.php';

use App\Repositories\SocialAccountRepository;
use App\Services\EncryptionService;
use App\Services\MetaGraphService;
use App\Support\Database;

$config = require __DIR__ . '/../config/config.php';

$db = Database::connection($config['db']);
$encryption = new EncryptionService($config['encryption_key']);
$accounts = new SocialAccountRepository($db, $encryption);
$meta = new MetaGraphService(
    (string) $config['meta_app_id'],
    (string) $config['meta_app_secret'],
    (string) $config['meta_redirect_uri'],
);

$stmt = $db->query('SELECT user_id FROM connected_social_accounts');
$userIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

foreach ($userIds as $userId) {
    $account = $accounts->find((int) $userId);

    if ($account === null) {
        continue;
    }

    $token = $accounts->decryptedPageAccessToken($account);
    $valid = $meta->isPageTokenValid($token);

    $accounts->markStatus(
        (int) $userId,
        $valid ? 'active' : 'expired',
        $valid ? null : 'Token dostępu wygasł lub został unieważniony. Połącz konto ponownie.',
    );

    echo sprintf(
        "[%s] user_id=%d: %s\n",
        date('Y-m-d H:i:s'),
        $userId,
        $valid ? 'token valid' : 'token INVALID — marked as expired',
    );
}

if ($userIds === []) {
    echo sprintf("[%s] no connected accounts to check\n", date('Y-m-d H:i:s'));
}
