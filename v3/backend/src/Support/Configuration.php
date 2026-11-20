<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Support;

use RuntimeException;

final readonly class Configuration
{
    public function __construct(
        public string $environment,
        public bool $debug,
        public string $databaseHost,
        public int $databasePort,
        public string $databaseName,
        public string $databaseUsername,
        public string $databasePassword,
        public string $applicationKey,
        public string $privateStoragePath,
        public int $mediaMaxBytes,
    ) {}

    public static function fromEnvironment(): self
    {
        $environment = strtolower(trim(Environment::requiredString('POS_V3_APP_ENV')));
        if (!in_array($environment, ['production', 'staging', 'development', 'test'], true)) {
            throw new RuntimeException('POS_V3_APP_ENV must be production, staging, development or test.');
        }

        $debug = Environment::boolean('POS_V3_APP_DEBUG', false);
        if ($debug && !in_array($environment, ['development', 'test'], true)) {
            throw new RuntimeException('Debug mode is only permitted in development or test environments.');
        }

        $host = Environment::requiredString('POS_V3_DB_HOST');
        $username = Environment::requiredString('POS_V3_DB_USER');
        $databaseName = Environment::requiredString('POS_V3_DB_NAME');
        $databasePassword = Environment::requiredString('POS_V3_DB_PASSWORD', true);
        $applicationKey = Environment::requiredString('POS_V3_APP_KEY');
        if (strlen($applicationKey) < 32) {
            throw new RuntimeException('POS_V3_APP_KEY must contain at least 32 bytes.');
        }
        $portValue = Environment::string('POS_V3_DB_PORT', '3306');
        $privateStoragePath = trim(Environment::string(
            'POS_V3_PRIVATE_STORAGE_PATH',
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'private-media',
        ));
        $mediaMaxBytesValue = Environment::string('POS_V3_MEDIA_MAX_BYTES', '5242880');
        if ($privateStoragePath === '' || strlen($privateStoragePath) > 512
            || !str_starts_with($privateStoragePath, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('POS_V3_PRIVATE_STORAGE_PATH must be an absolute path no longer than 512 bytes.');
        }
        if (!ctype_digit($mediaMaxBytesValue) || (int) $mediaMaxBytesValue < 1024 || (int) $mediaMaxBytesValue > 20971520) {
            throw new RuntimeException('POS_V3_MEDIA_MAX_BYTES must be between 1024 and 20971520.');
        }

        if (strlen($host) > 255 || !preg_match('/^[A-Za-z0-9.:-]+$/', $host)) {
            throw new RuntimeException('POS_V3_DB_HOST contains invalid characters.');
        }
        if (strlen($username) > 128 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new RuntimeException('POS_V3_DB_USER contains invalid characters.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
            throw new RuntimeException('POS_V3_DB_NAME must contain only letters, digits and underscores.');
        }
        if (!ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535) {
            throw new RuntimeException('POS_V3_DB_PORT must be an integer between 1 and 65535.');
        }

        return new self($environment, $debug, $host, (int) $portValue, $databaseName, $username, $databasePassword, $applicationKey, $privateStoragePath, (int) $mediaMaxBytesValue);
    }
}
