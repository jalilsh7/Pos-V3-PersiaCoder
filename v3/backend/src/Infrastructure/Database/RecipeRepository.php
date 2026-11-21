<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class RecipeRepository
{
    public function __construct(private PDO $connection) {}

    public function findProductId(int $businessId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_products
             WHERE business_id = :business_id AND public_id = :public_id
               AND is_active = 1 AND archived_at IS NULL LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $value = $s->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findVariantId(int $businessId, int $productId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_product_variants
             WHERE business_id = :business_id AND product_id = :product_id
               AND public_id = :public_id AND is_active = 1 LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'product_id' => $productId, 'public_id' => $publicId]);
        $value = $s->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findVariantIdForRead(int $businessId, int $productId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_product_variants
             WHERE business_id = :business_id AND product_id = :product_id
               AND public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'product_id' => $productId, 'public_id' => $publicId]);
        $value = $s->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findStockItem(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT si.id, si.public_id, si.name, si.base_unit_id, si.is_active,
                    u.dimension AS base_unit_dimension
             FROM pos_stock_items si
             INNER JOIN pos_units u ON u.id = si.base_unit_id
             WHERE si.business_id = :business_id AND si.public_id = :public_id AND si.is_active = 1
             LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findUnit(string $code): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, code, name, dimension, decimal_places FROM pos_units WHERE code = :code LIMIT 1'
        );
        $s->execute(['code' => $code]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findConversionFactor(int $fromUnitId, int $toUnitId): ?string
    {
        $s = $this->connection->prepare(
            'SELECT factor FROM pos_unit_conversions
             WHERE from_unit_id = :from_unit_id AND to_unit_id = :to_unit_id
               AND effective_from <= CURRENT_TIMESTAMP(3)
             ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $s->execute(['from_unit_id' => $fromUnitId, 'to_unit_id' => $toUnitId]);
        $value = $s->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function findRecipeForUpdate(int $businessId, int $productId, ?int $variantId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, public_id, name, is_active
             FROM pos_recipes
             WHERE business_id = :business_id AND product_id = :product_id
               AND variant_scope_id = :variant_scope_id
             LIMIT 1 FOR UPDATE'
        );
        $s->execute([
            'business_id' => $businessId, 'product_id' => $productId,
            'variant_scope_id' => $variantId ?? 0,
        ]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createRecipe(
        int $businessId, string $publicId, int $productId, ?int $variantId, string $name,
    ): int {
        $s = $this->connection->prepare(
            'INSERT INTO pos_recipes (business_id, public_id, product_id, variant_id, name, is_active)
             VALUES (:business_id, :public_id, :product_id, :variant_id, :name, 0)'
        );
        $s->execute([
            'business_id' => $businessId, 'public_id' => $publicId,
            'product_id' => $productId, 'variant_id' => $variantId, 'name' => $name,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function updateRecipeName(int $businessId, int $recipeId, string $name): void
    {
        $s = $this->connection->prepare(
            'UPDATE pos_recipes SET name = :name WHERE business_id = :business_id AND id = :id'
        );
        $s->execute(['name' => $name, 'business_id' => $businessId, 'id' => $recipeId]);
    }

    public function nextVersionNumber(int $businessId, int $recipeId): int
    {
        $s = $this->connection->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM pos_recipe_versions WHERE business_id = :business_id AND recipe_id = :recipe_id'
        );
        $s->execute(['business_id' => $businessId, 'recipe_id' => $recipeId]);
        return (int) $s->fetchColumn();
    }

    public function createVersion(
        int $businessId, string $publicId, int $recipeId, int $versionNo, int $actorUserId,
    ): int {
        $s = $this->connection->prepare(
            "INSERT INTO pos_recipe_versions (business_id, public_id, recipe_id, version_no, status, created_by)
             VALUES (:business_id, :public_id, :recipe_id, :version_no, 'draft', :created_by)"
        );
        $s->execute([
            'business_id' => $businessId, 'public_id' => $publicId, 'recipe_id' => $recipeId,
            'version_no' => $versionNo, 'created_by' => $actorUserId,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function createRecipeLine(
        int $businessId, int $versionId, int $stockItemId, string $quantity,
        int $unitId, string $conversionFactor, string $wastePercent,
    ): void {
        $s = $this->connection->prepare(
            'INSERT INTO pos_recipe_lines
                (business_id, recipe_version_id, stock_item_id, quantity, unit_id,
                 conversion_factor_snapshot, waste_percent)
             VALUES
                (:business_id, :version_id, :stock_item_id, :quantity, :unit_id,
                 :conversion_factor, :waste_percent)'
        );
        $s->execute([
            'business_id' => $businessId, 'version_id' => $versionId, 'stock_item_id' => $stockItemId,
            'quantity' => $quantity, 'unit_id' => $unitId,
            'conversion_factor' => $conversionFactor, 'waste_percent' => $wastePercent,
        ]);
    }

    public function deleteRecipeLinesForVersion(int $businessId, int $versionId): void
    {
        $s = $this->connection->prepare(
            'DELETE FROM pos_recipe_lines
             WHERE business_id = :business_id AND recipe_version_id = :version_id'
        );
        $s->execute(['business_id' => $businessId, 'version_id' => $versionId]);
    }

    public function findVersion(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT rv.id, rv.public_id, rv.recipe_id, rv.version_no, rv.status,
                    r.public_id AS recipe_public_id, r.name AS recipe_name, r.product_id, r.variant_id
             FROM pos_recipe_versions rv
             INNER JOIN pos_recipes r ON r.id = rv.recipe_id AND r.business_id = rv.business_id
             WHERE rv.business_id = :business_id AND rv.public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findVersionForUpdate(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT rv.id, rv.public_id, rv.recipe_id, rv.version_no, rv.status,
                    r.public_id AS recipe_public_id, r.name AS recipe_name
             FROM pos_recipe_versions rv
             INNER JOIN pos_recipes r ON r.id = rv.recipe_id AND r.business_id = rv.business_id
             WHERE rv.business_id = :business_id AND rv.public_id = :public_id
             LIMIT 1 FOR UPDATE'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function retireActiveVersions(int $businessId, int $recipeId): void
    {
        $s = $this->connection->prepare(
            "UPDATE pos_recipe_versions SET status = 'retired'
             WHERE business_id = :business_id AND recipe_id = :recipe_id AND status = 'active'"
        );
        $s->execute(['business_id' => $businessId, 'recipe_id' => $recipeId]);
    }

    public function activateVersion(int $businessId, int $versionId): bool
    {
        $s = $this->connection->prepare(
            "UPDATE pos_recipe_versions
             SET status = 'active', effective_from = CURRENT_TIMESTAMP(3)
             WHERE business_id = :business_id AND id = :id AND status = 'draft'"
        );
        $s->execute(['business_id' => $businessId, 'id' => $versionId]);
        return $s->rowCount() === 1;
    }

    public function activateRecipe(int $businessId, int $recipeId): void
    {
        $s = $this->connection->prepare(
            'UPDATE pos_recipes SET is_active = 1 WHERE business_id = :business_id AND id = :id'
        );
        $s->execute(['business_id' => $businessId, 'id' => $recipeId]);
    }

    public function recipeDetails(int $businessId, int $productId, ?int $variantId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, public_id, name, is_active
             FROM pos_recipes
             WHERE business_id = :business_id AND product_id = :product_id
               AND variant_scope_id = :variant_scope_id LIMIT 1'
        );
        $s->execute([
            'business_id' => $businessId, 'product_id' => $productId,
            'variant_scope_id' => $variantId ?? 0,
        ]);
        $recipe = $s->fetch(PDO::FETCH_ASSOC);
        if (!$recipe) return null;

        $lines = $this->connection->prepare(
            'SELECT rv.public_id AS version_public_id, rv.version_no, rv.status, rv.effective_from,
                    rl.quantity, rl.conversion_factor_snapshot, rl.waste_percent,
                    si.public_id AS stock_item_public_id, si.name AS stock_item_name,
                    u.code AS unit_code, u.name AS unit_name
             FROM pos_recipe_versions rv
             LEFT JOIN pos_recipe_lines rl ON rl.recipe_version_id = rv.id AND rl.business_id = rv.business_id
             LEFT JOIN pos_stock_items si ON si.id = rl.stock_item_id AND si.business_id = rl.business_id
             LEFT JOIN pos_units u ON u.id = rl.unit_id
             WHERE rv.business_id = :business_id AND rv.recipe_id = :recipe_id
             ORDER BY rv.version_no DESC, rl.id ASC LIMIT 5000'
        );
        $lines->execute(['business_id' => $businessId, 'recipe_id' => (int) $recipe['id']]);
        $versions = [];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versionId = (string) $row['version_public_id'];
            if (!isset($versions[$versionId])) {
                $versions[$versionId] = [
                    'id' => $versionId,
                    'version_no' => (int) $row['version_no'],
                    'status' => (string) $row['status'],
                    'effective_from' => $row['effective_from'] === null ? null : (string) $row['effective_from'],
                    'lines' => [],
                ];
            }
            if ($row['stock_item_public_id'] !== null) {
                $versions[$versionId]['lines'][] = [
                    'stock_item_id' => (string) $row['stock_item_public_id'],
                    'stock_item_name' => (string) $row['stock_item_name'],
                    'quantity' => (string) $row['quantity'],
                    'unit_code' => (string) $row['unit_code'],
                    'unit_name' => (string) $row['unit_name'],
                    'conversion_factor_snapshot' => (string) $row['conversion_factor_snapshot'],
                    'waste_percent' => (string) $row['waste_percent'],
                ];
            }
        }
        return [
            'id' => (string) $recipe['public_id'],
            'name' => (string) $recipe['name'],
            'is_active' => (bool) $recipe['is_active'],
            'versions' => array_values($versions),
        ];
    }
}
