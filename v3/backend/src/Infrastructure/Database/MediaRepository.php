<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class MediaRepository
{
    public function __construct(private PDO $connection) {}

    public function createAsset(
        int $businessId, string $publicId, string $storageKey, string $mimeType,
        int $byteSize, int $width, int $height, string $sha256, int $uploadedBy,
    ): int {
        $s = $this->connection->prepare(
            'INSERT INTO pos_media_assets
                (business_id, public_id, storage_key, mime_type, byte_size, image_width, image_height, sha256, uploaded_by)
             VALUES
                (:business_id, :public_id, :storage_key, :mime_type, :byte_size, :image_width, :image_height, :sha256, :uploaded_by)'
        );
        $s->execute([
            'business_id' => $businessId, 'public_id' => $publicId, 'storage_key' => $storageKey,
            'mime_type' => $mimeType, 'byte_size' => $byteSize, 'image_width' => $width,
            'image_height' => $height, 'sha256' => $sha256, 'uploaded_by' => $uploadedBy,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function findAsset(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, public_id, storage_key, mime_type, byte_size, image_width, image_height, sha256, created_at
             FROM pos_media_assets WHERE business_id = :business_id AND public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findProductId(int $businessId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_products
             WHERE business_id = :business_id AND public_id = :public_id AND archived_at IS NULL LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $value = $s->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findCategoryId(int $businessId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_categories WHERE business_id = :business_id AND public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $value = $s->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findProductImage(int $businessId, int $productId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT m.public_id, m.storage_key, m.mime_type, m.byte_size, m.image_width, m.image_height
             FROM pos_product_media pm
             INNER JOIN pos_media_assets m ON m.id = pm.media_id AND m.business_id = pm.business_id
             WHERE pm.business_id = :business_id AND pm.product_id = :product_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'product_id' => $productId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findCategoryImage(int $businessId, int $categoryId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT m.public_id, m.storage_key, m.mime_type, m.byte_size, m.image_width, m.image_height
             FROM pos_category_media cm
             INNER JOIN pos_media_assets m ON m.id = cm.media_id AND m.business_id = cm.business_id
             WHERE cm.business_id = :business_id AND cm.category_id = :category_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'category_id' => $categoryId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function attachProductImage(int $businessId, int $productId, int $mediaId): void
    {
        $s = $this->connection->prepare(
            'INSERT INTO pos_product_media (business_id, product_id, media_id)
             VALUES (:business_id, :product_id, :media_id)
             ON DUPLICATE KEY UPDATE media_id = VALUES(media_id)'
        );
        $s->execute(['business_id' => $businessId, 'product_id' => $productId, 'media_id' => $mediaId]);
    }

    public function attachCategoryImage(int $businessId, int $categoryId, int $mediaId): void
    {
        $s = $this->connection->prepare(
            'INSERT INTO pos_category_media (business_id, category_id, media_id)
             VALUES (:business_id, :category_id, :media_id)
             ON DUPLICATE KEY UPDATE media_id = VALUES(media_id)'
        );
        $s->execute(['business_id' => $businessId, 'category_id' => $categoryId, 'media_id' => $mediaId]);
    }

    public function detachProductImage(int $businessId, int $productId): void
    {
        $s = $this->connection->prepare(
            'DELETE FROM pos_product_media WHERE business_id = :business_id AND product_id = :product_id'
        );
        $s->execute(['business_id' => $businessId, 'product_id' => $productId]);
    }

    public function detachCategoryImage(int $businessId, int $categoryId): void
    {
        $s = $this->connection->prepare(
            'DELETE FROM pos_category_media WHERE business_id = :business_id AND category_id = :category_id'
        );
        $s->execute(['business_id' => $businessId, 'category_id' => $categoryId]);
    }

}
