<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
use PersiaCoder\PosV3\Infrastructure\Database\BusinessRepository;

final readonly class InstallationOwnerMiddleware
{
    public function __construct(private BusinessRepository $businesses) {}

    /** @param callable(Request): Response $next */
    public function __invoke(Request $request, array $parameters, callable $next): Response
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }
        if ($principal->mustChangePassword) {
            throw new HttpException(403, 'password_change_required', 'Change the initial password before continuing.');
        }
        if (!$this->businesses->isInstallationOwner($principal->userId)) {
            throw new HttpException(403, 'owner_required', 'Only the installation owner can complete this action.');
        }

        return $next($request);
    }
}
