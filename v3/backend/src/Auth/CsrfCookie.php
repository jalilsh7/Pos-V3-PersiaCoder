<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use DateTimeImmutable;

final class CsrfCookie
{
    public const COOKIE_NAME = 'pos_v3_csrf';

    public static function issue(string $token, DateTimeImmutable $expiresAt, bool $secure = true): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            throw new \InvalidArgumentException('CSRF token format is invalid.');
        }

        $maxAge = max(0, $expiresAt->getTimestamp() - time());
        return self::COOKIE_NAME . '=' . $token . '; Path=/; SameSite=Lax; Max-Age=' . $maxAge
            . ($secure ? '; Secure' : '');
    }

    public static function clear(bool $secure = true): string
    {
        return self::COOKIE_NAME . '=; Path=/; SameSite=Lax; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
            . ($secure ? '; Secure' : '');
    }

    public static function read(string $cookieHeader): ?string
    {
        foreach (explode(';', $cookieHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && trim($pair[0]) === self::COOKIE_NAME) {
                $value = trim($pair[1]);
                return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) ? $value : null;
            }
        }

        return null;
    }
}
