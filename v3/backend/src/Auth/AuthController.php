<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PDO;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
use PersiaCoder\PosV3\Infrastructure\Database\SessionRepository;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\TransactionManager;
use PersiaCoder\PosV3\Security\AuditLogService;

final readonly class AuthController
{
    private SessionRepository $sessions;
    private AuditLogService $audit;
    private TransactionManager $transactions;

    public function __construct(
        PDO $connection,
        private AuthenticationService $authentication,
        private bool $secureCookies = true,
    ) {
        $this->sessions = new SessionRepository($connection);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
        $this->transactions = new TransactionManager($connection);
    }

    public function login(Request $request): Response
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }

        $payload = $request->json();
        $username = $payload['username'] ?? null;
        $password = $payload['password'] ?? null;
        if (!is_string($username) || !is_string($password)) {
            throw new HttpException(422, 'invalid_credentials_format', 'Username and password are required.');
        }

        $ipAddress = (string) ($request->attribute('remote_ip') ?? '');
        $userAgent = $request->header('user-agent') ?? '';
        $session = $this->authentication->login($username, $password, $ipAddress, $userAgent, $request->requestId);

        return Response::success([
            'user' => [
                'id' => $session->userPublicId,
                'username' => $session->username,
                'display_name' => $session->displayName,
                'must_change_password' => $session->mustChangePassword,
            ],
            'csrf_token' => $session->csrfToken,
            'expires_at' => $session->expiresAt->format(DATE_ATOM),
        ])->withHeader('Set-Cookie', [
            SessionCookie::issue($session->sessionToken, $session->expiresAt, $this->secureCookies),
            CsrfCookie::issue($session->csrfToken, $session->expiresAt, $this->secureCookies),
        ]);
    }

    public function me(Request $request): Response
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }

        $csrfToken = CsrfCookie::read($request->header('cookie') ?? '');
        $setCookie = null;
        if ($csrfToken === null || !hash_equals($principal->csrfTokenHash, hash('sha256', $csrfToken))) {
            $csrfToken = self::randomToken();
            $this->sessions->replaceCsrfTokenHash($principal->sessionId, hash('sha256', $csrfToken));
            $setCookie = CsrfCookie::issue($csrfToken, $principal->expiresAt, $this->secureCookies);
        }

        $response = Response::success([
            'user' => [
                'id' => $principal->userPublicId,
                'username' => $principal->username,
                'display_name' => $principal->displayName,
                'must_change_password' => $principal->mustChangePassword,
            ],
            'csrf_token' => $csrfToken,
        ]);

        return $setCookie === null ? $response : $response->withHeader('Set-Cookie', $setCookie);
    }

    public function logout(Request $request): Response
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }

        $sessionToken = self::cookieValue($request->header('cookie') ?? '', SessionAuthenticator::COOKIE_NAME);
        $this->transactions->run(function (PDO $connection) use ($sessionToken, $principal, $request): void {
            if ($sessionToken !== null && preg_match('/^[A-Za-z0-9_-]{43}$/', $sessionToken)) {
                $this->sessions->revokeByTokenHash(hash('sha256', $sessionToken));
            }
            $this->audit->record(
                $principal->userId,
                'auth.logout',
                'user',
                $principal->userPublicId,
                null,
                ['session_id' => $principal->sessionId],
                null,
                $request->requestId,
                (string) ($request->attribute('remote_ip') ?? ''),
            );
        });

        return Response::success(['logged_out' => true])->withHeader('Set-Cookie', [
            SessionCookie::clear($this->secureCookies),
            CsrfCookie::clear($this->secureCookies),
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }

        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }

        $payload = $request->json();
        $currentPassword = $payload['current_password'] ?? null;
        $newPassword = $payload['new_password'] ?? null;
        if (!is_string($currentPassword) || !is_string($newPassword)) {
            throw new HttpException(422, 'invalid_password_format', 'Current and new passwords are required.');
        }

        $this->authentication->changePassword($principal->userId, $currentPassword, $newPassword, $request->requestId, (string) ($request->attribute('remote_ip') ?? ''));

        return Response::success(['password_changed' => true])->withHeader('Set-Cookie', [
            SessionCookie::clear($this->secureCookies),
            CsrfCookie::clear($this->secureCookies),
        ]);
    }

    private static function cookieValue(string $header, string $name): ?string
    {
        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && trim($pair[0]) === $name) {
                return trim($pair[1]);
            }
        }
        return null;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
