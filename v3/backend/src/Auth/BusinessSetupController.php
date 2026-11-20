<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class BusinessSetupController
{
    public function __construct(private BusinessSetupService $setup) {}

    public function create(Request $request): Response
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
        $required = ['name', 'code'];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                throw new HttpException(422, 'invalid_setup_payload', 'Business name and code are required.');
            }
        }

        $optional = [
            'timezone' => 'UTC',
            'currency' => 'IRT',
            'branch_name' => 'شعبه اصلی',
            'branch_code' => 'main',
        ];
        foreach ($optional as $field => $default) {
            if (isset($payload[$field]) && !is_string($payload[$field])) {
                throw new HttpException(422, 'invalid_setup_payload', 'Optional setup values must be strings.');
            }
        }

        $result = $this->setup->createInitialBusiness(
            $principal->userId,
            $payload['name'],
            $payload['code'],
            $payload['timezone'] ?? $optional['timezone'],
            $payload['currency'] ?? $optional['currency'],
            $payload['branch_name'] ?? $optional['branch_name'],
            $payload['branch_code'] ?? $optional['branch_code'],
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        );

        return Response::success([
            'business' => [
                'id' => $result['business_id'],
                'name' => $result['business_name'],
            ],
            'branch' => [
                'id' => $result['branch_id'],
                'name' => $result['branch_name'],
            ],
            'setup_completed' => true,
        ], [], 201);
    }
}
