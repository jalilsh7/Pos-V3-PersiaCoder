<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class SessionRepository
{
    public function __construct(private PDO $connection) {}

    public function create(
        int $userId,
        int $sessionVersion,
        string $tokenHash,
        string $csrfTokenHash,
        DateTimeImmutable $expiresAt,
        ?string $ipAddress = null,
        ?string $userAgentHash = null,
    ): int {
        $utcExpiry = $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $statement = $this->connection->prepare(
            'INSERT INTO app_user_sessions
                (user_id, token_hash, csrf_token_hash, session_version, ip_address, user_agent_hash, expires_at)
             VALUES
                (:user_id, :token_hash, :csrf_token_hash, :session_version, :ip_address, :user_agent_hash, :expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'csrf_token_hash' => $csrfTokenHash,
            'session_version' => $sessionVersion,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'expires_at' => $utcExpiry,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findActiveByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT s.id, s.user_id, s.csrf_token_hash, s.session_version, s.expires_at,
                    u.public_id, u.username, u.display_name, u.is_active, u.must_change_password,
                    u.session_version AS current_session_version
             FROM app_user_sessions s
             INNER JOIN app_users u ON u.id = s.user_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > UTC_TIMESTAMP(3)
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $session = $statement->fetch();

        if (!is_array($session) || (int) $session['is_active'] !== 1) {
            return null;
        }
        if ((int) $session['session_version'] !== (int) $session['current_session_version']) {
            return null;
        }

        return $session;
    }

    public function replaceCsrfTokenHash(int $sessionId, string $csrfTokenHash): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_user_sessions
             SET csrf_token_hash = :csrf_token_hash
             WHERE id = :session_id
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP(3)'
        );
        $statement->execute([
            'csrf_token_hash' => $csrfTokenHash,
            'session_id' => $sessionId,
        ]);
    }

    public function revokeByTokenHash(string $tokenHash): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_user_sessions
             SET revoked_at = UTC_TIMESTAMP(3)
             WHERE token_hash = :token_hash AND revoked_at IS NULL'
        );
        $statement->execute(['token_hash' => $tokenHash]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_user_sessions
             SET revoked_at = UTC_TIMESTAMP(3)
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
    }
}
