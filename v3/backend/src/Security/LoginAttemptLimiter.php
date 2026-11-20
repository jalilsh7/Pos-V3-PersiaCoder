<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use PDO;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\TransactionManager;

final readonly class LoginAttemptLimiter
{
    private const ACCOUNT_MAX_ATTEMPTS = 5;
    private const IP_MAX_ATTEMPTS = 30;

    public function __construct(
        private PDO $connection,
        private TransactionManager $transactions,
        private string $applicationKey,
    ) {
        if (strlen($this->applicationKey) < 32) {
            throw new \InvalidArgumentException('Application key must contain at least 32 bytes.');
        }
    }

    public function assertAllowed(string $username, string $ipAddress): void
    {
        [$accountHash, $ipHash] = $this->identifierHashes($username, $ipAddress);
        $statement = $this->connection->prepare(
            'SELECT 1 FROM app_auth_login_attempts
             WHERE identifier_hash IN (:account_hash, :ip_hash)
               AND blocked_until IS NOT NULL
               AND blocked_until > UTC_TIMESTAMP(3)
             LIMIT 1'
        );
        $statement->execute(['account_hash' => $accountHash, 'ip_hash' => $ipHash]);

        if ($statement->fetchColumn() !== false) {
            throw new HttpException(429, 'login_rate_limited', 'Too many login attempts. Try again later.');
        }
    }

    public function recordFailure(string $username, string $ipAddress): void
    {
        [$accountHash, $ipHash] = $this->identifierHashes($username, $ipAddress);

        $this->transactions->run(function (PDO $connection) use ($accountHash, $ipHash): void {
            $this->incrementBucket($connection, $accountHash, self::ACCOUNT_MAX_ATTEMPTS);
            if ($ipHash !== null) {
                $this->incrementBucket($connection, $ipHash, self::IP_MAX_ATTEMPTS);
            }
        });
    }

    public function clear(string $username, string $ipAddress): void
    {
        [$accountHash] = $this->identifierHashes($username, $ipAddress);
        $statement = $this->connection->prepare(
            'DELETE FROM app_auth_login_attempts WHERE identifier_hash = :identifier_hash'
        );
        $statement->execute(['identifier_hash' => $accountHash]);
    }

    /** @return array{string, string|null} */
    private function identifierHashes(string $username, string $ipAddress): array
    {
        $normalizedUsername = strtolower(trim($username));
        $accountHash = hash_hmac('sha256', 'account' . "\0" . $normalizedUsername, $this->applicationKey);
        $normalizedIp = filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
        $ipHash = $normalizedIp === null
            ? null
            : hash_hmac('sha256', 'ip' . "\0" . $normalizedIp, $this->applicationKey);

        return [$accountHash, $ipHash];
    }

    private function incrementBucket(PDO $connection, string $identifierHash, int $maxAttempts): void
    {
        $insert = $connection->prepare(
            'INSERT IGNORE INTO app_auth_login_attempts (identifier_hash, attempts, window_started_at)
             VALUES (:identifier_hash, 0, UTC_TIMESTAMP(3))'
        );
        $insert->execute(['identifier_hash' => $identifierHash]);

        $select = $connection->prepare(
            'SELECT attempts,
                    (window_started_at < UTC_TIMESTAMP(3) - INTERVAL 15 MINUTE) AS window_expired
             FROM app_auth_login_attempts
             WHERE identifier_hash = :identifier_hash
             FOR UPDATE'
        );
        $select->execute(['identifier_hash' => $identifierHash]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Login attempt state could not be locked.');
        }

        $expired = (int) $row['window_expired'] === 1;
        $attempts = ($expired ? 0 : (int) $row['attempts']) + 1;
        $blocked = $attempts >= $maxAttempts;

        $update = $connection->prepare(
            'UPDATE app_auth_login_attempts
             SET attempts = :attempts,
                 window_started_at = IF(:reset_window_start = 1, UTC_TIMESTAMP(3), window_started_at),
                 blocked_until = CASE
                     WHEN :blocked = 1 THEN DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 15 MINUTE)
                     WHEN :reset_window_block = 1 THEN NULL
                     ELSE blocked_until
                 END
             WHERE identifier_hash = :identifier_hash'
        );
        $update->execute([
            'attempts' => $attempts,
            'reset_window_start' => $expired ? 1 : 0,
            'reset_window_block' => $expired ? 1 : 0,
            'blocked' => $blocked ? 1 : 0,
            'identifier_hash' => $identifierHash,
        ]);
    }
}
