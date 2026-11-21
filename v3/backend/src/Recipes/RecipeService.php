<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Recipes;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\RecipeRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class RecipeService
{
    private RecipeRepository $recipes;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(PDO $connection, string $applicationKey)
    {
        $this->recipes = new RecipeRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function getProductRecipe(BusinessScope $scope, string $productPublicId, ?string $variantPublicId): ?array
    {
        if (!self::isUuid($productPublicId)) {
            throw new HttpException(422, 'product_id_invalid', 'The product identifier is invalid.');
        }
        $productId = $this->recipes->findProductId($scope->businessId, $productPublicId);
        if ($productId === null) throw new HttpException(404, 'product_not_found', 'The product was not found.');
        $variantId = null;
        if ($variantPublicId !== null) {
            if (!self::isUuid($variantPublicId)) {
                throw new HttpException(422, 'variant_id_invalid', 'The variant identifier is invalid.');
            }
            $variantId = $this->recipes->findVariantIdForRead($scope->businessId, $productId, $variantPublicId);
            if ($variantId === null) throw new HttpException(404, 'variant_not_found', 'The variant was not found for this product.');
        }
        return $this->recipes->recipeDetails($scope->businessId, $productId, $variantId);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function createDraftVersion(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $productPublicId,
        array $payload, string $key, string $requestId, string $ip,
    ): array {
        if (!self::isUuid($productPublicId)) {
            throw new HttpException(422, 'product_id_invalid', 'The product identifier is invalid.');
        }
        $variantPublicId = $payload['variant_id'] ?? null;
        if ($variantPublicId !== null && (!is_string($variantPublicId) || !self::isUuid($variantPublicId))) {
            throw new HttpException(422, 'variant_id_invalid', 'The variant identifier is invalid.');
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || self::unicodeLength($name) > 160) {
            throw new HttpException(422, 'recipe_name_invalid', 'Recipe name is required and must not exceed 160 characters.');
        }
        $inputLines = $payload['lines'] ?? null;
        if (!is_array($inputLines) || count($inputLines) < 1 || count($inputLines) > 100) {
            throw new HttpException(422, 'recipe_lines_invalid', 'A recipe must contain between 1 and 100 ingredient lines.');
        }

        $normalizedLines = [];
        $seen = [];
        foreach ($inputLines as $line) {
            if (!is_array($line) || !is_string($line['stock_item_id'] ?? null)
                || !self::isUuid($line['stock_item_id'])
                || !is_string($line['unit_code'] ?? null)
                || !preg_match('/^[a-z0-9._-]{1,32}$/', $line['unit_code'])) {
                throw new HttpException(422, 'recipe_line_invalid', 'Each recipe line requires a stock item UUID and unit code.');
            }
            $quantity = self::decimalInput($line['quantity'] ?? null, 6, false, 'recipe_quantity_invalid');
            $waste = self::decimalInput($line['waste_percent'] ?? '0', 4, true, 'recipe_waste_invalid');
            if ((float) $waste > 100) {
                throw new HttpException(422, 'recipe_waste_invalid', 'Waste percent must not exceed 100.');
            }
            $lineKey = $line['stock_item_id'] . ':' . $line['unit_code'];
            if (isset($seen[$lineKey])) {
                throw new HttpException(422, 'recipe_line_duplicate', 'A stock item and unit combination can appear only once in a recipe version.');
            }
            $seen[$lineKey] = true;
            $normalizedLines[] = [
                'stock_item_public_id' => $line['stock_item_id'],
                'quantity' => $quantity,
                'unit_code' => $line['unit_code'],
                'waste_percent' => $waste,
            ];
        }

        $fingerprint = hash('sha256', json_encode(
            [$productPublicId, $variantPublicId, $name, $normalizedLines],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'catalog.recipe.version.create', $key, $fingerprint,
            function (PDO $db) use (
                $scope, $actor, $productPublicId, $variantPublicId, $name,
                $normalizedLines, $requestId, $ip,
            ): array {
                $productId = $this->recipes->findProductId($scope->businessId, $productPublicId);
                if ($productId === null) {
                    throw new HttpException(404, 'product_not_found', 'The product was not found or is inactive.');
                }
                $variantId = $variantPublicId === null
                    ? null
                    : $this->recipes->findVariantId($scope->businessId, $productId, $variantPublicId);
                if ($variantPublicId !== null && $variantId === null) {
                    throw new HttpException(404, 'variant_not_found', 'The active variant was not found for this product.');
                }

                $lines = [];
                foreach ($normalizedLines as $line) {
                    $stockItem = $this->recipes->findStockItem($scope->businessId, $line['stock_item_public_id']);
                    if ($stockItem === null) {
                        throw new HttpException(404, 'recipe_stock_item_not_found', 'A stock item was not found or is inactive in this business.');
                    }
                    $unit = $this->recipes->findUnit($line['unit_code']);
                    if ($unit === null) {
                        throw new HttpException(422, 'recipe_unit_not_found', 'A recipe unit code does not exist.');
                    }
                    if ((string) $unit['dimension'] !== (string) $stockItem['base_unit_dimension']) {
                        throw new HttpException(422, 'recipe_unit_dimension_mismatch', 'Recipe units must match the stock item base unit dimension.');
                    }
                    $conversionFactor = (int) $unit['id'] === (int) $stockItem['base_unit_id']
                        ? '1.00000000'
                        : $this->recipes->findConversionFactor((int) $unit['id'], (int) $stockItem['base_unit_id']);
                    if ($conversionFactor === null || (float) $conversionFactor <= 0) {
                        throw new HttpException(422, 'recipe_unit_conversion_missing', 'No valid conversion exists from the recipe unit to the stock item base unit.');
                    }
                    $lines[] = [
                        'stock_item_id' => (int) $stockItem['id'],
                        'stock_item_public_id' => $line['stock_item_public_id'],
                        'quantity' => $line['quantity'], 'unit_id' => (int) $unit['id'],
                        'unit_code' => $line['unit_code'], 'conversion_factor' => $conversionFactor,
                        'waste_percent' => $line['waste_percent'],
                    ];
                }

                $recipe = $this->recipes->findRecipeForUpdate($scope->businessId, $productId, $variantId);
                $before = $recipe === null ? null : [
                    'name' => (string) $recipe['name'], 'is_active' => (bool) $recipe['is_active'],
                ];
                if ($recipe === null) {
                    $recipePublicId = self::uuidV4();
                    $recipeId = $this->recipes->createRecipe(
                        $scope->businessId, $recipePublicId, $productId, $variantId, $name,
                    );
                } else {
                    $recipeId = (int) $recipe['id'];
                    $recipePublicId = (string) $recipe['public_id'];
                    if ((string) $recipe['name'] !== $name) {
                        $this->recipes->updateRecipeName($scope->businessId, $recipeId, $name);
                    }
                }
                $versionPublicId = self::uuidV4();
                $versionNo = $this->recipes->nextVersionNumber($scope->businessId, $recipeId);
                $versionId = $this->recipes->createVersion(
                    $scope->businessId, $versionPublicId, $recipeId, $versionNo, $actor->userId,
                );
                foreach ($lines as $line) {
                    $this->recipes->createRecipeLine(
                        $scope->businessId, $versionId, $line['stock_item_id'], $line['quantity'],
                        $line['unit_id'], $line['conversion_factor'], $line['waste_percent'],
                    );
                }
                $after = [
                    'name' => $name, 'recipe_id' => $recipePublicId,
                    'version_id' => $versionPublicId, 'version_no' => $versionNo,
                    'status' => 'draft', 'line_count' => count($lines),
                ];
                $this->audit->record(
                    $actor->userId, 'catalog.recipe_version.created', 'recipe', $recipePublicId,
                    $before, $after, null, $requestId, $ip, $scope->businessId,
                );
                return ['status' => 201, 'body' => [
                    'data' => ['recipe' => [
                        'id' => $recipePublicId, 'name' => $name,
                        'version' => ['id' => $versionPublicId, 'version_no' => $versionNo, 'status' => 'draft'],
                    ]],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    /**
     * Replace the contents of a draft recipe version atomically.
     * Active and retired versions are immutable.
     *
     * @param array<string,mixed> $payload
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function updateDraftVersion(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $versionPublicId,
        array $payload, string $key, string $requestId, string $ip,
    ): array {
        if (!self::isUuid($versionPublicId)) {
            throw new HttpException(422, 'recipe_version_id_invalid', 'The recipe version identifier is invalid.');
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || self::unicodeLength($name) > 160) {
            throw new HttpException(422, 'recipe_name_invalid', 'Recipe name is required and must not exceed 160 characters.');
        }
        $inputLines = $payload['lines'] ?? null;
        if (!is_array($inputLines) || count($inputLines) < 1 || count($inputLines) > 100) {
            throw new HttpException(422, 'recipe_lines_invalid', 'A recipe must contain between 1 and 100 ingredient lines.');
        }

        $normalized = [];
        $seen = [];
        foreach ($inputLines as $line) {
            if (!is_array($line) || !is_string($line['stock_item_id'] ?? null)
                || !self::isUuid($line['stock_item_id'])
                || !is_string($line['unit_code'] ?? null)
                || !preg_match('/^[a-z0-9._-]{1,32}$/', $line['unit_code'])) {
                throw new HttpException(422, 'recipe_line_invalid', 'Each recipe line requires a stock item UUID and unit code.');
            }
            $quantity = self::decimalInput($line['quantity'] ?? null, 6, false, 'recipe_quantity_invalid');
            $waste = self::decimalInput($line['waste_percent'] ?? '0', 4, true, 'recipe_waste_invalid');
            if ((float) $waste > 100) {
                throw new HttpException(422, 'recipe_waste_invalid', 'Waste percent must not exceed 100.');
            }
            $lineKey = $line['stock_item_id'] . ':' . $line['unit_code'];
            if (isset($seen[$lineKey])) {
                throw new HttpException(422, 'recipe_line_duplicate', 'A stock item and unit combination can appear only once in a recipe version.');
            }
            $seen[$lineKey] = true;
            $normalized[] = [
                'stock_item_public_id' => $line['stock_item_id'],
                'quantity' => $quantity, 'unit_code' => $line['unit_code'], 'waste_percent' => $waste,
            ];
        }
        $fingerprint = hash('sha256', json_encode(
            [$versionPublicId, $name, $normalized], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'catalog.recipe.version.update', $key, $fingerprint,
            function (PDO $db) use ($scope, $actor, $versionPublicId, $name, $normalized, $requestId, $ip): array {
                $locked = $this->recipes->findVersionForUpdate($scope->businessId, $versionPublicId);
                if ($locked === null) {
                    throw new HttpException(404, 'recipe_version_not_found', 'The recipe version was not found.');
                }
                if ((string) $locked['status'] !== 'draft') {
                    throw new HttpException(409, 'recipe_version_not_draft', 'Only a draft recipe version can be edited.');
                }
                $resolved = [];
                foreach ($normalized as $line) {
                    $stockItem = $this->recipes->findStockItem($scope->businessId, $line['stock_item_public_id']);
                    if ($stockItem === null) {
                        throw new HttpException(404, 'recipe_stock_item_not_found', 'A stock item was not found or is inactive in this business.');
                    }
                    $unit = $this->recipes->findUnit($line['unit_code']);
                    if ($unit === null) {
                        throw new HttpException(422, 'recipe_unit_not_found', 'A recipe unit code does not exist.');
                    }
                    if ((string) $unit['dimension'] !== (string) $stockItem['base_unit_dimension']) {
                        throw new HttpException(422, 'recipe_unit_dimension_mismatch', 'Recipe units must match the stock item base unit dimension.');
                    }
                    $factor = (int) $unit['id'] === (int) $stockItem['base_unit_id']
                        ? '1.00000000'
                        : $this->recipes->findConversionFactor((int) $unit['id'], (int) $stockItem['base_unit_id']);
                    if ($factor === null || (float) $factor <= 0) {
                        throw new HttpException(422, 'recipe_unit_conversion_missing', 'No valid conversion exists from the recipe unit to the stock item base unit.');
                    }
                    $resolved[] = [
                        'stock_item_id' => (int) $stockItem['id'], 'unit_id' => (int) $unit['id'],
                        'quantity' => $line['quantity'], 'conversion_factor' => $factor,
                        'waste_percent' => $line['waste_percent'],
                    ];
                }

                $recipeId = (int) $locked['recipe_id'];
                $before = [
                    'status' => 'draft', 'name' => (string) $locked['recipe_name'],
                    'line_count' => $this->recipes->countRecipeVersionLines($scope->businessId, (int) $locked['id']),
                ];
                if ((string) $locked['recipe_name'] !== $name) {
                    $this->recipes->updateRecipeName($scope->businessId, $recipeId, $name);
                }
                $this->recipes->deleteRecipeLinesForVersion($scope->businessId, (int) $locked['id']);
                foreach ($resolved as $line) {
                    $this->recipes->createRecipeLine(
                        $scope->businessId, (int) $locked['id'], $line['stock_item_id'], $line['quantity'],
                        $line['unit_id'], $line['conversion_factor'], $line['waste_percent'],
                    );
                }
                $after = [
                    'status' => 'draft', 'name' => $name,
                    'version_no' => (int) $locked['version_no'], 'line_count' => count($resolved),
                ];
                $this->audit->record(
                    $actor->userId, 'catalog.recipe_version.updated', 'recipe_version', $versionPublicId,
                    $before, $after, null, $requestId, $ip, $scope->businessId,
                );
                return ['status' => 200, 'body' => [
                    'data' => ['recipe_version' => ['id' => $versionPublicId] + $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    /** @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function activateVersion(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $versionPublicId,
        string $key, string $requestId, string $ip,
    ): array {
        if (!self::isUuid($versionPublicId)) {
            throw new HttpException(422, 'recipe_version_id_invalid', 'The recipe version identifier is invalid.');
        }
        $current = $this->recipes->findVersion($scope->businessId, $versionPublicId);
        if ($current === null) throw new HttpException(404, 'recipe_version_not_found', 'The recipe version was not found.');
        $fingerprint = hash('sha256', json_encode([$versionPublicId, 'activate'], JSON_THROW_ON_ERROR));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'catalog.recipe.version.activate', $key, $fingerprint,
            function (PDO $db) use ($scope, $actor, $versionPublicId, $requestId, $ip): array {
                $locked = $this->recipes->findVersionForUpdate($scope->businessId, $versionPublicId);
                if ($locked === null) throw new HttpException(404, 'recipe_version_not_found', 'The recipe version was not found.');
                if ((string) $locked['status'] !== 'draft') {
                    throw new HttpException(409, 'recipe_version_not_draft', 'Only a draft recipe version can be activated.');
                }
                $recipeId = (int) $locked['recipe_id'];
                $this->recipes->retireActiveVersions($scope->businessId, $recipeId);
                if (!$this->recipes->activateVersion($scope->businessId, (int) $locked['id'])) {
                    throw new HttpException(409, 'recipe_version_activation_conflict', 'The recipe version could not be activated.');
                }
                $this->recipes->activateRecipe($scope->businessId, $recipeId);
                $before = ['status' => 'draft'];
                $after = [
                    'status' => 'active', 'version_no' => (int) $locked['version_no'],
                    'recipe_id' => (string) $locked['recipe_public_id'],
                ];
                $this->audit->record(
                    $actor->userId, 'catalog.recipe_version.activated', 'recipe_version', $versionPublicId,
                    $before, $after, null, $requestId, $ip, $scope->businessId,
                );
                return ['status' => 200, 'body' => [
                    'data' => ['recipe_version' => ['id' => $versionPublicId] + $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    private static function decimalInput(mixed $value, int $scale, bool $allowZero, string $errorCode): string
    {
        if (is_int($value)) $value = (string) $value;
        if (!is_string($value) || !preg_match('/^\d{1,12}(?:\.\d{1,' . $scale . '})?$/', $value)) {
            throw new HttpException(422, $errorCode, 'A decimal value has an invalid format or precision.');
        }
        $digits = str_replace('.', '', $value);
        $positive = trim($digits, '0') !== '';
        if (!$allowZero && !$positive) {
            throw new HttpException(422, $errorCode, 'The value must be greater than zero.');
        }
        return $value;
    }

    private static function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
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
}
