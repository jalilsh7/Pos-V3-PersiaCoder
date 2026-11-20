<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use DateTimeImmutable;
use InvalidArgumentException;

final class SessionCookie
{
    private const COOKIE_NAME = SessionAuthenticator::COOKIE_NAME;

    public static function issue(string $token, DateTimeImmutable $expiresAt, bool $secure = true): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new InvalidArgumentException('Session token format is invalid.');
        }

        $maxAge = max(0, $expiresAt->getTimestamp() - time());
        return self::COOKIE_NAME . '=' . $token . '; ' . self::baseAttributes($secure) . '; Max-Age=' . $maxAge;
    }

    public static function clear(bool $secure = true): string
    {
        return self::COOKIE_NAME . '=; ' . self::baseAttributes($secure) .
            '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
    }

    private static function baseAttributes(bool $secure): string
    {
        return 'Path=/; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : '');
    }
}
