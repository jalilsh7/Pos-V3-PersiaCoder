<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class CsrfMiddleware
{
    public function __construct(private CsrfGuard $guard) {}

    /** @param callable(Request): Response $next */
    public function __invoke(Request $request, array $parameters, callable $next): Response
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }

        $this->guard->assertValid($request, $principal);
        return $next($request);
    }
}
