<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Support\Configuration;

if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException('POS V3 Backend requires PHP 8.2 or newer.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'PersiaCoder\\PosV3\\';
    if (!str_starts_with($class, $prefix)) return;

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
    if (is_file($file)) require $file;
});

return Configuration::fromEnvironment();
