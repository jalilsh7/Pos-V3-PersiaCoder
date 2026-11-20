<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Infrastructure\Database\PdoConnectionFactory;
use PersiaCoder\PosV3\Support\Configuration;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PersiaCoder\\PosV3\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/v3/backend/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$config = new Configuration('test', false, '127.0.0.1', 3307, 'pos_v3_test', 'pos_test', 'not-in-dsn', str_repeat('k', 32), '/tmp/pos-v3-test-media', 5242880);
$dsn = PdoConnectionFactory::dsn($config);
$assert($dsn === 'mysql:host=127.0.0.1;port=3307;dbname=pos_v3_test;charset=utf8mb4', 'Unexpected PDO DSN.');
$assert(!str_contains($dsn, $config->databasePassword), 'Database password must never be part of the DSN.');

$badHost = new Configuration('test', false, '127.0.0.1;dbname=other', 3306, 'pos_v3_test', 'pos_test', '', str_repeat('k', 32), '/tmp/pos-v3-test-media', 5242880);
try {
    PdoConnectionFactory::dsn($badHost);
    throw new RuntimeException('Unsafe host must not be accepted.');
} catch (RuntimeException $exception) {
    $assert($exception->getMessage() !== 'Unsafe host must not be accepted.', 'Unsafe host was accepted.');
}

$migrationPath = $root . '/database/v3/migrations/001_create-user-sessions.sql';
$migration = file_get_contents($migrationPath);
if (!is_string($migration)) throw new RuntimeException('Session migration file is missing.');
$assert(str_contains($migration, 'CREATE TABLE app_user_sessions'), 'Session table migration missing.');
$assert(str_contains($migration, 'UNIQUE KEY uq_app_user_sessions_token_hash'), 'Session token hash must be unique.');
$assert(str_contains($migration, 'REFERENCES app_users(id)'), 'Session user foreign key missing.');
$assert(str_contains($migration, 'expires_at > created_at'), 'Session expiry constraint missing.');
$assert(!preg_match('/\\b(DROP|TRUNCATE|ALTER)\\b/i', $migration), 'Session migration must remain additive.');

$loginMigrationPath = $root . '/database/v3/migrations/002_create-auth-login-attempts.sql';
$loginMigration = file_get_contents($loginMigrationPath);
if (!is_string($loginMigration)) throw new RuntimeException('Login throttling migration file is missing.');
$assert(str_contains($loginMigration, 'CREATE TABLE app_auth_login_attempts'), 'Login throttling table migration missing.');
$assert(str_contains($loginMigration, 'identifier_hash CHAR(64)'), 'Login throttling identifier must be hashed.');
$assert(str_contains($loginMigration, 'blocked_until DATETIME(3)'), 'Login throttling block expiry missing.');
$assert(!preg_match('/\\b(DROP|TRUNCATE|ALTER)\\b/i', $loginMigration), 'Login throttling migration must remain additive.');

echo "V3 backend database configuration checks passed.\n";
