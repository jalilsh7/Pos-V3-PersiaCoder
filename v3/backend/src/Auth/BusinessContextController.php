<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
use PersiaCoder\PosV3\Infrastructure\Database\BusinessRepository;

final readonly class BusinessContextController
{
    public function __construct(private BusinessRepository $businesses) {}

    public function list(Request $request): Response
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }

        return Response::success([
            'businesses' => $this->businesses->listAccessibleBusinesses($principal->userId),
            'can_initialize' => $this->businesses->isInstallationOwner($principal->userId),
        ]);
    }
}
