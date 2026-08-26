<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $base64Key)
    {
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $key = $this->key();
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Malformed ciphertext');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $key = base64_decode($this->base64Key, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('encryption_key must be a base64-encoded 32-byte value');
        }

        return $key;
    }
}
