<?php

declare(strict_types=1);

$localConfig = __DIR__ . '/config.local.php';

if (file_exists($localConfig)) {
    return require $localConfig;
}

return require __DIR__ . '/config.example.php';
