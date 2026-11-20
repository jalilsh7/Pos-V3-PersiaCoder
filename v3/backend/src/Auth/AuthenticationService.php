<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\SessionRepository;
use PersiaCoder\PosV3\Infrastructure\Database\TransactionManager;
use PersiaCoder\PosV3\Infrastructure\Database\UserRepository;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\LoginAttemptLimiter;

final readonly class AuthenticationService
{
    private UserRepository $users;
    private SessionRepository $sessions;
    private TransactionManager $transactions;
    private LoginAttemptLimiter $loginLimiter;
    private string $dummyPasswordHash;
    private AuditLogService $audit;

    public function __construct(
        PDO $connection,
        string $applicationKey,
        private int $sessionLifetimeSeconds = 28800,
    ) {
        if (strlen($applicationKey) < 32) {
            throw new \InvalidArgumentException('Application key must contain at least 32 bytes.');
        }
        if ($this->sessionLifetimeSeconds < 300 || $this->sessionLifetimeSeconds > 86400) {
            throw new \InvalidArgumentException('Session lifetime must be between 300 and 86400 seconds.');
        }

        $this->users = new UserRepository($connection);
        $this->sessions = new SessionRepository($connection);
        $this->transactions = new TransactionManager($connection);
        $this->loginLimiter = new LoginAttemptLimiter($connection, $this->transactions, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
        $this->dummyPasswordHash = password_hash('POS_V3_INVALID_LOGIN_DUMMY', PASSWORD_DEFAULT);
    }

    public function login(string $username, string $password, string $ipAddress, string $userAgent, string $requestId): AuthenticatedSession
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 64 || $password === '' || strlen($password) > 4096) {
            throw new HttpException(422, 'invalid_credentials_format', 'Username or password format is invalid.');
        }

        $this->loginLimiter->assertAllowed($username, $ipAddress);
        $user = $this->users->findByUsername($username);
        $hash = is_array($user) ? (string) $user['password_hash'] : $this->dummyPasswordHash;
        $passwordValid = password_verify($password, $hash);

        if (!is_array($user) || (int) $user['is_active'] !== 1 || !$passwordValid) {
            $this->loginLimiter->recordFailure($username, $ipAddress);
            throw new HttpException(401, 'invalid_credentials', 'Username or password is incorrect.');
        }

        $sessionToken = self::randomToken();
        $csrfToken = self::randomToken();
        $tokenHash = hash('sha256', $sessionToken);
        $csrfTokenHash = hash('sha256', $csrfToken);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $this->sessionLifetimeSeconds . ' seconds');

        $safeIp = filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : null;
        $userAgentHash = $userAgent === '' ? null : hash('sha256', substr($userAgent, 0, 1024));
        $userId = (int) $user['id'];

        $this->transactions->run(function (PDO $connection) use (
            $user,
            $password,
            $userId,
            $tokenHash,
            $csrfTokenHash,
            $expiresAt,
            $safeIp,
            $userAgentHash,
            $requestId,
        ): void {
            if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $this->users->updatePasswordHash($userId, $newHash);
            }
            $this->users->touchLastLogin($userId);
            $this->sessions->create(
                $userId,
                (int) $user['session_version'],
                $tokenHash,
                $csrfTokenHash,
                $expiresAt,
                $safeIp,
                $userAgentHash,
            );
            $this->audit->record(
                $userId,
                'auth.login_succeeded',
                'user',
                (string) $user['public_id'],
                null,
                ['username' => (string) $user['username']],
                null,
                $requestId,
                (string) ($safeIp ?? ''),
            );
        });

        $this->loginLimiter->clear($username, $ipAddress);

        return new AuthenticatedSession(
            $sessionToken,
            $csrfToken,
            $expiresAt,
            (string) $user['public_id'],
            (string) $user['username'],
            (string) $user['display_name'],
            (int) $user['must_change_password'] === 1,
        );
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $requestId, string $ipAddress): void
    {
        if ($currentPassword === '' || strlen($currentPassword) > 4096
            || strlen($newPassword) < 12 || strlen($newPassword) > 128) {
            throw new HttpException(422, 'invalid_password_format', 'The new password must contain between 12 and 128 bytes.');
        }

        $user = $this->users->findActiveById($userId);
        if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new HttpException(422, 'current_password_invalid', 'The current password is incorrect.');
        }
        if (password_verify($newPassword, (string) $user['password_hash'])) {
            throw new HttpException(422, 'password_unchanged', 'The new password must be different from the current password.');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->transactions->run(function (PDO $connection) use ($userId, $newHash, $requestId, $ipAddress): void {
            $this->users->changePasswordAndRevokeSessions($userId, $newHash);
            $this->audit->record(
                $userId,
                'auth.password_changed',
                'user',
                (string) $userId,
                null,
                ['forced_change_required' => false, 'all_sessions_invalidated' => true],
                null,
                $requestId,
                $ipAddress,
            );
        });
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
