<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Support\Configuration;

$root = dirname(__DIR__);
$setEnvironment = static function (string $name, ?string $value): void {
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name]);
        return;
    }
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
};
$expectFailure = static function (callable $operation, string $label): void {
    try { $operation(); } catch (RuntimeException) { return; }
    throw new RuntimeException("Expected configuration failure: {$label}");
};

$valid = [
    'POS_V3_APP_ENV' => 'test',
    'POS_V3_APP_DEBUG' => 'false',
    'POS_V3_DB_HOST' => '127.0.0.1',
    'POS_V3_DB_PORT' => '3306',
    'POS_V3_DB_NAME' => 'pos_v3_test',
    'POS_V3_DB_USER' => 'pos_test',
    'POS_V3_DB_PASSWORD' => '',
    'POS_V3_APP_KEY' => str_repeat('k', 32),
];
foreach ($valid as $name => $value) $setEnvironment($name, $value);

$config = require $root . '/v3/backend/bootstrap.php';
if (!$config instanceof Configuration || $config->environment !== 'test' || $config->databasePort !== 3306) {
    throw new RuntimeException('Bootstrap did not return the expected immutable configuration.');
}

$setEnvironment('POS_V3_DB_PORT', '70000');
$expectFailure(static fn () => Configuration::fromEnvironment(), 'out-of-range database port');
$setEnvironment('POS_V3_DB_PORT', '3306');
$setEnvironment('POS_V3_DB_NAME', 'invalid-name');
$expectFailure(static fn () => Configuration::fromEnvironment(), 'unsafe database identifier');
$setEnvironment('POS_V3_DB_NAME', 'pos_v3_test');
$setEnvironment('POS_V3_APP_ENV', 'production');
$setEnvironment('POS_V3_APP_DEBUG', 'true');
$expectFailure(static fn () => Configuration::fromEnvironment(), 'debug enabled in production');
$setEnvironment('POS_V3_APP_ENV', 'test');
$setEnvironment('POS_V3_APP_DEBUG', 'sometimes');
$expectFailure(static fn () => Configuration::fromEnvironment(), 'malformed boolean');
$setEnvironment('POS_V3_APP_DEBUG', 'false');
$setEnvironment('POS_V3_DB_PASSWORD', null);
$expectFailure(static fn () => Configuration::fromEnvironment(), 'missing database password variable');
$setEnvironment('POS_V3_DB_PASSWORD', '');
$setEnvironment('POS_V3_APP_KEY', 'short');
$expectFailure(static fn () => Configuration::fromEnvironment(), 'short application key');
$setEnvironment('POS_V3_APP_KEY', null);
$expectFailure(static fn () => Configuration::fromEnvironment(), 'missing application key');

echo "V3 backend bootstrap checks passed.\n";
