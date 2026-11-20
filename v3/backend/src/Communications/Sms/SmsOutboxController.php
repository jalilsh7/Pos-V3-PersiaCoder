<?php
declare(strict_types=1);

namespace PersiaCoder\\PosV3\\Communications\\Sms;

use PersiaCoder\\PosV3\\Auth\\AuthenticatedPrincipal;
use PersiaCoder\\PosV3\\Auth\\BusinessScope;
use PersiaCoder\\PosV3\\Http\\HttpException;
use PersiaCoder\\PosV3\\Http\\Request;
use PersiaCoder\\PosV3\\Http\\Response;

final readonly class SmsOutboxController
{
    public function __construct(private SmsOutboxService $outbox) {}

    public function enqueueTransactional(Request $request): Response
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\\/json(?:\\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }

        $payload = $request->json();
        $recipient = $payload['recipient'] ?? null;
        $message = $payload['message'] ?? null;
        $customerPublicId = $payload['customer_public_id'] ?? null;
        if (!is_string($recipient) || !is_string($message)
            || ($customerPublicId !== null && !is_string($customerPublicId))) {
            throw new HttpException(422, 'sms_payload_invalid', 'Recipient and message must be strings; customer_public_id must be a string when supplied.');
        }

        $result = $this->outbox->enqueueTransactional(
            $this->principal($request),
            $this->scope($request),
            $recipient,
            $message,
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
            $customerPublicId,
        );

        return Response::json($result['body'], $result['status'])
            ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    private function principal(Request $request): AuthenticatedPrincipal
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }
        return $principal;
    }

    private function scope(Request $request): BusinessScope
    {
        $scope = $request->attribute('business_scope');
        if (!$scope instanceof BusinessScope) {
            throw new HttpException(400, 'business_context_required', 'A valid business context is required.');
        }
        return $scope;
    }
}
