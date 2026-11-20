<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Media;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class MediaController
{
    public function __construct(private MediaService $media) {}

    public function upload(Request $request): Response
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^multipart\\/form-data\\s*;/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be multipart/form-data.');
        }
        $file = $request->uploadedFile('file');
        if ($file === null) {
            throw new HttpException(422, 'media_file_required', 'A single image file field named file is required.');
        }
        return $this->idempotentResponse($this->media->upload(
            $this->principal($request), $this->scope($request), $file,
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function show(Request $request, array $parameters): Response
    {
        $result = $this->media->read($this->scope($request), (string) ($parameters['mediaPublicId'] ?? ''));
        $metadata = $result['metadata'];
        return new Response(200, $result['contents'], [
            'Content-Type' => (string) $metadata['mime_type'],
            'Content-Length' => (string) strlen($result['contents']),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function attachProductImage(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->media->attachProductImage(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['productPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function attachCategoryImage(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->media->attachCategoryImage(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['categoryPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    private function jsonPayload(Request $request): array
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\\/json(?:\\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        return $request->json();
    }

    private function idempotentResponse(array $result): Response
    {
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
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }
        return $scope;
    }

    public function detachProductImage(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->media->detachProductImage(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['productPublicId'] ?? ''),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function detachCategoryImage(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->media->detachCategoryImage(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['categoryPublicId'] ?? ''),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

}
