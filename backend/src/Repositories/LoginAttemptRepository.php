<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class LoginAttemptRepository
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $db)
    {
    }

    public function isRateLimited(string $ip): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND attempted_at > (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->execute(['ip' => $ip, 'minutes' => self::WINDOW_MINUTES]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function recordFailedAttempt(string $ip): void
    {
        $stmt = $this->db->prepare('INSERT INTO login_attempts (ip_address) VALUES (:ip)');
        $stmt->execute(['ip' => $ip]);
    }

    public function clearAttempts(string $ip): void
    {
        $stmt = $this->db->prepare('DELETE FROM login_attempts WHERE ip_address = :ip');
        $stmt->execute(['ip' => $ip]);
    }
}
