<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PostRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Captions of past posts, newest first, for use as style/tone context.
     * Falls back to the generated caption when the user never edited it.
     *
     * @return list<string>
     */
    public function captionHistoryNewestFirst(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(final_caption, generated_caption) AS caption
             FROM posts
             WHERE user_id = :uid
               AND status IN ('published', 'partially_published')
               AND COALESCE(final_caption, generated_caption) IS NOT NULL
             ORDER BY created_at DESC"
        );
        $stmt->execute(['uid' => $userId]);

        /** @var list<string> $captions */
        $captions = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        return $captions;
    }

    public function create(int $userId, string $productDescription): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO posts (user_id, product_description, status) VALUES (:uid, :desc, "draft")'
        );
        $stmt->execute(['uid' => $userId, 'desc' => $productDescription]);

        return (int) $this->db->lastInsertId();
    }

    public function find(int $userId, int $postId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM posts WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $postId, 'uid' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function setGeneratedCaption(int $postId, string $caption): void
    {
        $stmt = $this->db->prepare('UPDATE posts SET generated_caption = :c WHERE id = :id');
        $stmt->execute(['c' => $caption, 'id' => $postId]);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listPaginated(int $userId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM posts WHERE user_id = :uid');
        $countStmt->execute(['uid' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM posts WHERE user_id = :uid ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function updateDraft(int $userId, int $postId, ?string $productDescription, ?string $finalCaption): void
    {
        $fields = [];
        $params = ['id' => $postId, 'uid' => $userId];

        if ($productDescription !== null) {
            $fields[] = 'product_description = :desc';
            $params['desc'] = $productDescription;
        }

        if ($finalCaption !== null) {
            $fields[] = 'final_caption = :caption';
            $params['caption'] = $finalCaption;
        }

        if ($fields === []) {
            return;
        }

        $sql = 'UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $userId, int $postId): void
    {
        $stmt = $this->db->prepare('DELETE FROM posts WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $postId, 'uid' => $userId]);
    }

    public function setMedia(int $postId, string $filename, string $mediaType, string $mime): void
    {
        $stmt = $this->db->prepare(
            'UPDATE posts SET media_filename = :filename, media_type = :type, media_mime = :mime WHERE id = :id'
        );
        $stmt->execute(['filename' => $filename, 'type' => $mediaType, 'mime' => $mime, 'id' => $postId]);
    }

    public function clearMedia(int $postId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE posts SET media_filename = NULL, media_type = NULL, media_mime = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $postId]);
    }

    public function markFacebookSuccess(int $postId, string $fbPostId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE posts SET fb_status = "success", fb_post_id = :fb_post_id, fb_error = NULL, fb_published_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['fb_post_id' => $fbPostId, 'id' => $postId]);
        $this->recomputeStatus($postId);
    }

    public function markFacebookFailure(int $postId, string $error): void
    {
        $stmt = $this->db->prepare('UPDATE posts SET fb_status = "failed", fb_error = :error WHERE id = :id');
        $stmt->execute(['error' => $error, 'id' => $postId]);
        $this->recomputeStatus($postId);
    }

    public function markInstagramSuccess(int $postId, string $igMediaId, ?string $igContainerId = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE posts SET ig_status = "success", ig_media_id = :ig_media_id, ig_container_id = :ig_container_id,
                    ig_error = NULL, ig_published_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['ig_media_id' => $igMediaId, 'ig_container_id' => $igContainerId, 'id' => $postId]);
        $this->recomputeStatus($postId);
    }

    public function markInstagramFailure(int $postId, string $error, ?string $igContainerId = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE posts SET ig_status = "failed", ig_error = :error, ig_container_id = :ig_container_id WHERE id = :id'
        );
        $stmt->execute(['error' => $error, 'ig_container_id' => $igContainerId, 'id' => $postId]);
        $this->recomputeStatus($postId);
    }

    /**
     * Derives the coarse overall `status` from the two per-platform statuses. A
     * single-platform success (the other never attempted) counts as fully
     * published; "partially_published" is reserved for one-succeeded-one-failed.
     */
    private function recomputeStatus(int $postId): void
    {
        $stmt = $this->db->prepare('SELECT fb_status, ig_status FROM posts WHERE id = :id');
        $stmt->execute(['id' => $postId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return;
        }

        $fb = $row['fb_status'];
        $ig = $row['ig_status'];
        $statuses = [$fb, $ig];

        if ($fb === 'not_attempted' && $ig === 'not_attempted') {
            $status = 'draft';
        } elseif (in_array('success', $statuses, true) && in_array('failed', $statuses, true)) {
            $status = 'partially_published';
        } elseif (in_array('success', $statuses, true)) {
            $status = 'published';
        } elseif (in_array('failed', $statuses, true)) {
            $status = 'failed';
        } else {
            $status = 'publishing';
        }

        $update = $this->db->prepare('UPDATE posts SET status = :status WHERE id = :id');
        $update->execute(['status' => $status, 'id' => $postId]);
    }
}
