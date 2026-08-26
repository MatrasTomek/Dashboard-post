<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class CaptionGenerationException extends RuntimeException
{
    public function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
