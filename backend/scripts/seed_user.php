<?php

declare(strict_types=1);

// One-time setup script: creates the single owner account.
// Usage: php scripts/seed_user.php <username> <password>

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Database;

$config = require __DIR__ . '/../config/config.php';

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($username === null || $password === null) {
    fwrite(STDERR, "Usage: php scripts/seed_user.php <username> <password>\n");
    exit(1);
}

$db = Database::connection($config['db']);
$hash = password_hash($password, PASSWORD_ARGON2ID);

$stmt = $db->prepare(
    'INSERT INTO users (username, password_hash) VALUES (:u, :h)
     ON DUPLICATE KEY UPDATE password_hash = :h2'
);
$stmt->execute(['u' => $username, 'h' => $hash, 'h2' => $hash]);

echo "User '{$username}' created/updated.\n";
