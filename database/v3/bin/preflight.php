<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
PosV3DatabaseTools::requireCli();

$checks = [];
$check = static function (string $label, callable $run) use (&$checks): void {
    try {
        $detail = $run();
        $checks[] = ['label' => $label, 'ok' => true, 'detail' => $detail];
    } catch (Throwable $error) {
        $checks[] = ['label' => $label, 'ok' => false, 'detail' => $error->getMessage()];
    }
};

$check('PHP version', static function (): string {
    if (PHP_VERSION_ID < 80200) {
        throw new RuntimeException('PHP 8.2 or newer is required; detected ' . PHP_VERSION . '.');
    }
    return PHP_VERSION;
});

$check('Required PHP extensions', static function (): string {
    $required = ['pdo_mysql', 'json', 'zlib', 'openssl', 'fileinfo'];
    $missing = array_values(array_filter($required, static fn (string $extension): bool => !extension_loaded($extension)));
    if ($missing !== []) {
        throw new RuntimeException('Missing extensions: ' . implode(', ', $missing));
    }
    return implode(', ', $required) . ' available';
});

$check('Database connection and supported engine', static function (): string {
    $pdo = PosV3DatabaseTools::connect();
    return (string) $pdo->query('SELECT VERSION()')->fetchColumn();
});

$check('Database charset', static function (): string {
    $pdo = PosV3DatabaseTools::connect();
    $charset = (string) $pdo->query(
        'SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
    )->fetchColumn();
    if (strtolower($charset) !== 'utf8mb4') {
        throw new RuntimeException('Database default charset must be utf8mb4; detected ' . ($charset ?: 'unknown') . '.');
    }
    return $charset;
});

$check('Fresh and empty V3 target', static function (): string {
    $pdo = PosV3DatabaseTools::connect();
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchColumn();
    if ($count !== 0) {
        throw new RuntimeException("Target database contains {$count} table(s)/view(s); installer will not modify it.");
    }
    return 'No tables or views found';
});

$check('Baseline schema file', static function (): string {
    $path = dirname(__DIR__) . '/schema.sql';
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('database/v3/schema.sql is missing or unreadable.');
    }
    $checksum = hash_file('sha256', $path);
    if (!is_string($checksum)) {
        throw new RuntimeException('Unable to calculate baseline checksum.');
    }
    return 'Readable; SHA-256 ' . $checksum;
});

$failed = array_filter($checks, static fn (array $item): bool => !$item['ok']);
foreach ($checks as $item) {
    printf("[%s] %s: %s\n", $item['ok'] ? 'PASS' : 'FAIL', $item['label'], $item['detail']);
}

if ($failed !== []) {
    fwrite(STDERR, sprintf("V3 preflight failed: %d of %d check(s) need attention. No schema or data was changed.\n", count($failed), count($checks)));
    exit(1);
}

echo "V3 preflight passed. No schema or data was changed.\n";
