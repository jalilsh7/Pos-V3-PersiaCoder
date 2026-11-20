<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Infrastructure\Database\SessionRepository;

final readonly class SessionAuthenticator
{
    public const COOKIE_NAME = 'pos_v3_session';

    public function __construct(private SessionRepository $sessions) {}

    public function authenticate(Request $request): AuthenticatedPrincipal
    {
        $token = $this->cookieValue($request->header('cookie') ?? '');
        if ($token === null || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }

        $session = $this->sessions->findActiveByTokenHash(hash('sha256', $token));
        if ($session === null) {
            throw new HttpException(401, 'session_invalid', 'The session is invalid or has expired.');
        }

        $expiresAt = new DateTimeImmutable((string) $session['expires_at'], new DateTimeZone('UTC'));
        return new AuthenticatedPrincipal(
            (int) $session['id'],
            (int) $session['user_id'],
            (string) $session['public_id'],
            (string) $session['username'],
            (string) $session['display_name'],
            (int) $session['must_change_password'] === 1,
            (string) $session['csrf_token_hash'],
            $expiresAt,
        );
    }

    private function cookieValue(string $header): ?string
    {
        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && trim($pair[0]) === self::COOKIE_NAME) {
                return trim($pair[1]);
            }
        }
        return null;
    }
}
