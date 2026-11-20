<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\CsrfCookie;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;

final class CsrfGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function assertValid(Request $request, AuthenticatedPrincipal $principal): void
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            return;
        }

        $headerToken = $request->header('x-csrf-token');
        $cookieToken = CsrfCookie::read($request->header('cookie') ?? '');
        if ($headerToken === null || !preg_match('/^[A-Za-z0-9_-]{43}$/', $headerToken)
            || $cookieToken === null || !hash_equals($cookieToken, $headerToken)) {
            throw new HttpException(403, 'csrf_token_missing', 'A matching CSRF cookie and header are required.');
        }

        if (!hash_equals($principal->csrfTokenHash, hash('sha256', $headerToken))) {
            throw new HttpException(403, 'csrf_token_invalid', 'The CSRF token is invalid.');
        }
    }
}
