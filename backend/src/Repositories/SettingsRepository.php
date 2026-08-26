<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\EncryptionService;
use PDO;

final class SettingsRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly EncryptionService $encryption,
    ) {
    }

    public function getEncrypted(int $userId, string $key): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT setting_value_encrypted FROM settings WHERE user_id = :uid AND setting_key = :k'
        );
        $stmt->execute(['uid' => $userId, 'k' => $key]);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        return $this->encryption->decrypt($value);
    }

    public function setEncrypted(int $userId, string $key, string $plaintext): void
    {
        $encrypted = $this->encryption->encrypt($plaintext);

        $stmt = $this->db->prepare(
            'INSERT INTO settings (user_id, setting_key, setting_value_encrypted)
             VALUES (:uid, :k, :v)
             ON DUPLICATE KEY UPDATE setting_value_encrypted = :v2'
        );
        $stmt->execute(['uid' => $userId, 'k' => $key, 'v' => $encrypted, 'v2' => $encrypted]);
    }

    public function delete(int $userId, string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM settings WHERE user_id = :uid AND setting_key = :k');
        $stmt->execute(['uid' => $userId, 'k' => $key]);
    }

    public function getPlain(int $userId, string $key, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT setting_value_plain FROM settings WHERE user_id = :uid AND setting_key = :k'
        );
        $stmt->execute(['uid' => $userId, 'k' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? $default : $value;
    }

    public function setPlain(int $userId, string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (user_id, setting_key, setting_value_plain)
             VALUES (:uid, :k, :v)
             ON DUPLICATE KEY UPDATE setting_value_plain = :v2'
        );
        $stmt->execute(['uid' => $userId, 'k' => $key, 'v' => $value, 'v2' => $value]);
    }

    public function has(int $userId, string $key): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM settings WHERE user_id = :uid AND setting_key = :k AND setting_value_encrypted IS NOT NULL'
        );
        $stmt->execute(['uid' => $userId, 'k' => $key]);

        return $stmt->fetchColumn() !== false;
    }
}
