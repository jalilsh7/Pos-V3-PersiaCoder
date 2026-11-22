<?php
declare(strict_types=1);

namespace PersiaCoder\\PosV3\\Communications\\Sms;

use PersiaCoder\\PosV3\\Auth\\AuthenticatedPrincipal;
use PersiaCoder\\PosV3\\Auth\\BusinessScope;
use PersiaCoder\\PosV3\\Http\\HttpException;
use PersiaCoder\\PosV3\\Http\\Request;
use PersiaCoder\\PosV3\\Http\\Response;

final readonly class SmsMarketingCampaignController
{
    public function __construct(private SmsMarketingCampaignService $service) {}

    public function list(Request $request): Response
    {
        return Response::success($this->service->list($this->principal($request), $this->scope($request)));
    }

    public function create(Request $request): Response
    {
        $this->assertJson($request);
        $result = $this->service->create($this->principal($request), $this->scope($request), $request->json(),
            $request->header('idempotency-key') ?? '', $request->requestId, (string) ($request->attribute('remote_ip') ?? ''));
        return $this->idempotent($result);
    }

    public function prepareAudience(Request $request, array $parameters): Response
    {
        $result = $this->service->prepareAudience($this->principal($request), $this->scope($request),
            (string) ($parameters['campaignPublicId'] ?? ''), $request->header('idempotency-key') ?? '',
            $request->requestId, (string) ($request->attribute('remote_ip') ?? ''));
        return $this->idempotent($result);
    }

    private function assertJson(Request $request): void
    {
        if (!preg_match('/^application\\/json(?:\\s*;|$)/i', trim($request->header('content-type') ?? ''))) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
    }
    private function principal(Request $request): AuthenticatedPrincipal
    {
        $v = $request->attribute('principal');
        if (!$v instanceof AuthenticatedPrincipal) throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        return $v;
    }
    private function scope(Request $request): BusinessScope
    {
        $v = $request->attribute('business_scope');
        if (!$v instanceof BusinessScope) throw new HttpException(400, 'business_context_required', 'A valid business context is required.');
        return $v;
    }
    private function idempotent(array $r): Response
    {
        return Response::json($r['body'], $r['status'])->withHeader('Idempotency-Replayed', $r['replayed'] ? 'true' : 'false');
    }
}
