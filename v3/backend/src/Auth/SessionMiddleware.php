<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class SessionMiddleware
{
    public function __construct(private SessionAuthenticator $authenticator) {}

    /** @param callable(Request): Response $next */
    public function __invoke(Request $request, array $parameters, callable $next): Response
    {
        $principal = $this->authenticator->authenticate($request);
        return $next($request->withAttribute('principal', $principal));
    }
}
