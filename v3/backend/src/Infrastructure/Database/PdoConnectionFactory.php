<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;
use PDOException;
use PersiaCoder\PosV3\Support\Configuration;
use RuntimeException;

final class PdoConnectionFactory
{
    public static function dsn(Configuration $configuration): string
    {
        if (!preg_match('/^[A-Za-z0-9.:-]+$/', $configuration->databaseHost)) {
            throw new RuntimeException('Database host contains invalid DSN characters.');
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $configuration->databaseHost,
            $configuration->databasePort,
            $configuration->databaseName,
        );
    }

    public static function connect(Configuration $configuration): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('The PDO MySQL extension is required by POS V3 Backend.');
        }

        try {
            return new PDO(
                self::dsn($configuration),
                $configuration->databaseUsername,
                $configuration->databasePassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ],
            );
        } catch (PDOException $exception) {
            error_log(sprintf(
                'POS_V3 database_connection_failed sqlstate=%s',
                (string) $exception->getCode(),
            ));
            throw new RuntimeException('Database connection failed. Check server configuration and connectivity.', 0, $exception);
        }
    }
}
