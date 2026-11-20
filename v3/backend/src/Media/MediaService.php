<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Media;

use PDO;
use Throwable;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\MediaRepository;
use PersiaCoder\PosV3\Infrastructure\Storage\PrivateMediaStorage;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class MediaService
{
    private MediaRepository $media;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(
        PDO $connection,
        string $applicationKey,
        private PrivateMediaStorage $storage,
        private int $maxBytes,
    ) {
        $this->media = new MediaRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
        if ($maxBytes < 1024 || $maxBytes > 20971520) {
            throw new \InvalidArgumentException('Media size limit is outside the supported range.');
        }
    }

    /**
     * @param array{tmp_name:string,size:int,error:int} $file
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function upload(
        AuthenticatedPrincipal $actor, BusinessScope $scope, array $file,
        string $key, string $requestId, string $ip,
    ): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $status = in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
            throw new HttpException($status, 'media_upload_failed', 'The image upload did not complete successfully.');
        }
        if ($file['size'] < 1 || $file['size'] > $this->maxBytes || !is_uploaded_file($file['tmp_name'])) {
            throw new HttpException(413, 'media_size_invalid', 'The uploaded image is empty, too large or invalid.');
        }
        $actualSize = filesize($file['tmp_name']);
        if (!is_int($actualSize) || $actualSize !== $file['size'] || $actualSize > $this->maxBytes) {
            throw new HttpException(422, 'media_size_mismatch', 'The uploaded image size could not be verified.');
        }
        if (!class_exists(\finfo::class) || !function_exists('getimagesize')) {
            throw new HttpException(503, 'media_validation_unavailable', 'Image validation is unavailable on this server.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        $imageInfo = @getimagesize($file['tmp_name']);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!is_string($detectedMime) || !isset($allowed[$detectedMime])
            || !is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== $detectedMime) {
            throw new HttpException(415, 'media_type_not_allowed', 'Only valid JPEG, PNG and WebP images are accepted.');
        }
        $width = $imageInfo[0] ?? 0;
        $height = $imageInfo[1] ?? 0;
        if (!is_int($width) || !is_int($height) || $width < 1 || $height < 1
            || $width > 6000 || $height > 6000 || ($width * $height) > 20000000) {
            throw new HttpException(422, 'media_dimensions_invalid', 'Image dimensions exceed the allowed limits.');
        }
        $sha256 = hash_file('sha256', $file['tmp_name']);
        if (!is_string($sha256)) {
            throw new HttpException(422, 'media_hash_failed', 'The uploaded image could not be verified.');
        }

        $publicId = self::uuidV4();
        $extension = $allowed[$detectedMime];
        $fingerprint = hash('sha256', json_encode(
            [$sha256, $actualSize, $detectedMime, $width, $height],
            JSON_THROW_ON_ERROR,
        ));
        $storedKey = null;
        try {
            return $this->idempotency->execute(
                $actor->userId, $scope, 'media.upload', $key, $fingerprint,
                function (PDO $db) use (
                    $scope, $actor, $file, $publicId, $extension, $detectedMime,
                    $actualSize, $width, $height, $sha256, $requestId, $ip, &$storedKey,
                ): array {
                    $storedKey = $this->storage->storeUploadedFile($file['tmp_name'], $publicId, $extension);
                    $this->media->createAsset(
                        $scope->businessId, $publicId, $storedKey, $detectedMime,
                        $actualSize, $width, $height, $sha256, $actor->userId,
                    );
                    $this->audit->record(
                        $actor->userId, 'media.uploaded', 'media_asset', $publicId, null,
                        ['mime_type' => $detectedMime, 'byte_size' => $actualSize, 'width' => $width, 'height' => $height, 'sha256' => $sha256],
                        null, $requestId, $ip, $scope->businessId,
                    );
                    return [
                        'status' => 201,
                        'body' => ['data' => ['media' => [
                            'id' => $publicId, 'mime_type' => $detectedMime, 'byte_size' => $actualSize,
                            'width' => $width, 'height' => $height,
                        ]], 'meta' => (object) []],
                    ];
                },
            );
        } catch (Throwable $exception) {
            if (is_string($storedKey)) {
                try { $this->storage->delete($storedKey); } catch (Throwable) {}
            }
            throw $exception;
        }
    }

    /** @return array{metadata:array<string,mixed>,contents:string} */
    public function read(BusinessScope $scope, string $publicId): array
    {
        if (!self::isUuid($publicId)) {
            throw new HttpException(422, 'media_id_invalid', 'The media identifier is invalid.');
        }
        $asset = $this->media->findAsset($scope->businessId, $publicId);
        if ($asset === null) {
            throw new HttpException(404, 'media_not_found', 'The media asset was not found.');
        }
        return ['metadata' => $asset, 'contents' => $this->storage->read((string) $asset['storage_key'])];
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function attachProductImage(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $productPublicId,
        array $payload, string $key, string $requestId, string $ip,
    ): array {
        $mediaPublicId = $payload['media_id'] ?? null;
        if (!self::isUuid($productPublicId) || !is_string($mediaPublicId) || !self::isUuid($mediaPublicId)) {
            throw new HttpException(422, 'product_media_invalid', 'Product and media identifiers are required.');
        }
        $productId = $this->media->findProductId($scope->businessId, $productPublicId);
        $asset = $this->media->findAsset($scope->businessId, $mediaPublicId);
        if ($productId === null) throw new HttpException(404, 'product_not_found', 'The product was not found.');
        if ($asset === null) throw new HttpException(404, 'media_not_found', 'The media asset was not found in this business.');
        $before = $this->media->findProductImage($scope->businessId, $productId);
        $after = ['media_id' => $mediaPublicId];
        $fingerprint = hash('sha256', json_encode([$productPublicId, $mediaPublicId], JSON_THROW_ON_ERROR));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'catalog.product.image.attach', $key, $fingerprint,
            function (PDO $db) use ($scope, $productId, $productPublicId, $asset, $mediaPublicId, $actor, $requestId, $ip, $before, $after): array {
                $this->media->attachProductImage($scope->businessId, $productId, (int) $asset['id']);
                $this->audit->record(
                    $actor->userId, 'catalog.product.image_attached', 'product', $productPublicId,
                    $before === null ? null : ['media_id' => (string) $before['public_id']],
                    $after, null, $requestId, $ip, $scope->businessId,
                );
                return ['status' => 200, 'body' => ['data' => ['product_id' => $productPublicId] + $after, 'meta' => (object) []]];
            },
        );
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function attachCategoryImage(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $categoryPublicId,
        array $payload, string $key, string $requestId, string $ip,
    ): array {
        $mediaPublicId = $payload['media_id'] ?? null;
        if (!self::isUuid($categoryPublicId) || !is_string($mediaPublicId) || !self::isUuid($mediaPublicId)) {
            throw new HttpException(422, 'category_media_invalid', 'Category and media identifiers are required.');
        }
        $categoryId = $this->media->findCategoryId($scope->businessId, $categoryPublicId);
        $asset = $this->media->findAsset($scope->businessId, $mediaPublicId);
        if ($categoryId === null) throw new HttpException(404, 'category_not_found', 'The category was not found.');
        if ($asset === null) throw new HttpException(404, 'media_not_found', 'The media asset was not found in this business.');
        $before = $this->media->findCategoryImage($scope->businessId, $categoryId);
        $after = ['media_id' => $mediaPublicId];
        $fingerprint = hash('sha256', json_encode([$categoryPublicId, $mediaPublicId], JSON_THROW_ON_ERROR));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'catalog.category.image.attach', $key, $fingerprint,
            function (PDO $db) use ($scope, $categoryId, $categoryPublicId, $asset, $mediaPublicId, $actor, $requestId, $ip, $before, $after): array {
                $this->media->attachCategoryImage($scope->businessId, $categoryId, (int) $asset['id']);
                $this->audit->record(
                    $actor->userId, 'catalog.category.image_attached', 'category', $categoryPublicId,
                    $before === null ? null : ['media_id' => (string) $before['public_id']],
                    $after, null, $requestId, $ip, $scope->businessId,
                );
                return ['status' => 200, 'body' => ['data' => ['category_id' => $categoryPublicId] + $after, 'meta' => (object) []]];
            },
        );
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /** @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function detachProductImage(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $productPublicId,
        string $key, string $requestId, string $ip,
    ): array {
        if (!self::isUuid($productPublicId)) {
            throw new HttpException(422, 'product_id_invalid', 'The product identifier is invalid.');
        }
        $productId = $this->media->findProductId($scope->businessId, $productPublicId);
        if ($productId === null) throw new HttpException(404, 'product_not_found', 'The product was not found.');
        $before = $this->media->findProductImage($scope->businessId, $productId);
        $fingerprint = hash('sha256', json_encode([$productPublicId, 'detach-image'], JSON_THROW_ON_ERROR));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'catalog.product.image.detach', $key, $fingerprint,
            function (PDO $db) use ($scope, $productId, $productPublicId, $actor, $requestId, $ip, $before): array {
                $this->media->detachProductImage($scope->businessId, $productId);
                if ($before !== null) {
                    $this->audit->record(
                        $actor->userId, 'catalog.product.image_detached', 'product', $productPublicId,
                        ['media_id' => (string) $before['public_id']], ['media_id' => null],
                        null, $requestId, $ip, $scope->businessId,
                    );
                }
                return ['status' => 200, 'body' => [
                    'data' => ['product_id' => $productPublicId, 'image' => null],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    /** @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function detachCategoryImage(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $categoryPublicId,
        string $key, string $requestId, string $ip,
    ): array {
        if (!self::isUuid($categoryPublicId)) {
            throw new HttpException(422, 'category_id_invalid', 'The category identifier is invalid.');
        }
        $categoryId = $this->media->findCategoryId($scope->businessId, $categoryPublicId);
        if ($categoryId === null) throw new HttpException(404, 'category_not_found', 'The category was not found.');
        $before = $this->media->findCategoryImage($scope->businessId, $categoryId);
        $fingerprint = hash('sha256', json_encode([$categoryPublicId, 'detach-image'], JSON_THROW_ON_ERROR));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'catalog.category.image.detach', $key, $fingerprint,
            function (PDO $db) use ($scope, $categoryId, $categoryPublicId, $actor, $requestId, $ip, $before): array {
                $this->media->detachCategoryImage($scope->businessId, $categoryId);
                if ($before !== null) {
                    $this->audit->record(
                        $actor->userId, 'catalog.category.image_detached', 'category', $categoryPublicId,
                        ['media_id' => (string) $before['public_id']], ['media_id' => null],
                        null, $requestId, $ip, $scope->businessId,
                    );
                }
                return ['status' => 200, 'body' => [
                    'data' => ['category_id' => $categoryPublicId, 'image' => null],
                    'meta' => (object) [],
                ]];
            },
        );
    }


}
