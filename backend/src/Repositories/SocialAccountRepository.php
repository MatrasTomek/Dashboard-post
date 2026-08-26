<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\EncryptionService;
use PDO;

final class SocialAccountRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly EncryptionService $encryption,
    ) {
    }

    public function find(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM connected_social_accounts WHERE user_id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function decryptedPageAccessToken(array $account): string
    {
        return $this->encryption->decrypt($account['fb_page_access_token_encrypted']);
    }

    /**
     * Single-user app: upsert replaces any existing connection for this user.
     */
    public function upsert(
        int $userId,
        string $fbPageId,
        ?string $fbPageName,
        string $pageAccessToken,
        ?string $igBusinessAccountId,
        ?string $igUsername,
        ?string $metaUserId,
    ): void {
        $encryptedToken = $this->encryption->encrypt($pageAccessToken);

        $existing = $this->find($userId);

        if ($existing === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO connected_social_accounts
                    (user_id, fb_page_id, fb_page_name, fb_page_access_token_encrypted,
                     ig_business_account_id, ig_username, meta_user_id, status, last_refreshed_at)
                 VALUES
                    (:uid, :page_id, :page_name, :token, :ig_id, :ig_username, :meta_user_id, "active", NOW())'
            );
        } else {
            $stmt = $this->db->prepare(
                'UPDATE connected_social_accounts SET
                    fb_page_id = :page_id,
                    fb_page_name = :page_name,
                    fb_page_access_token_encrypted = :token,
                    ig_business_account_id = :ig_id,
                    ig_username = :ig_username,
                    meta_user_id = :meta_user_id,
                    status = "active",
                    last_error = NULL,
                    last_refreshed_at = NOW()
                 WHERE user_id = :uid'
            );
        }

        $stmt->execute([
            'uid' => $userId,
            'page_id' => $fbPageId,
            'page_name' => $fbPageName,
            'token' => $encryptedToken,
            'ig_id' => $igBusinessAccountId,
            'ig_username' => $igUsername,
            'meta_user_id' => $metaUserId,
        ]);
    }

    public function markStatus(int $userId, string $status, ?string $error = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE connected_social_accounts SET status = :status, last_error = :error WHERE user_id = :uid'
        );
        $stmt->execute(['status' => $status, 'error' => $error, 'uid' => $userId]);
    }

    public function updateToken(int $userId, string $newPageAccessToken): void
    {
        $stmt = $this->db->prepare(
            'UPDATE connected_social_accounts
             SET fb_page_access_token_encrypted = :token, status = "active", last_error = NULL, last_refreshed_at = NOW()
             WHERE user_id = :uid'
        );
        $stmt->execute(['token' => $this->encryption->encrypt($newPageAccessToken), 'uid' => $userId]);
    }

    public function delete(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM connected_social_accounts WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
    }
}
