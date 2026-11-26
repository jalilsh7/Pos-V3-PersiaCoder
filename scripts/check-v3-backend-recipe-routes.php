<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$entrypoint = file_get_contents($root . '/v3/backend/public/index.php');
$service = file_get_contents($root . '/v3/backend/src/Recipes/RecipeService.php');
$controller = file_get_contents($root . '/v3/backend/src/Recipes/RecipeController.php');
$catalogService = file_get_contents($root . '/v3/backend/src/Catalog/CatalogService.php');
$catalogRepository = file_get_contents($root . '/v3/backend/src/Infrastructure/Database/CatalogRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
foreach (['RecipeController', 'RecipeService', '$getRecipeController'] as $symbol) {
    $assert(is_string($entrypoint) && str_contains($entrypoint, $symbol), 'Recipe controller wiring is missing: ' . $symbol);
}
$routes = [
    [
        "'GET', '/api/v1/catalog/products/{productPublicId}/recipe'",
        "showProductRecipe",
        "catalog.read",
    ],
    [
        "'POST', '/api/v1/catalog/products/{productPublicId}/recipe-versions'",
        "createDraftVersion",
        "catalog.manage",
    ],
    [
        "'PATCH', '/api/v1/catalog/recipe-versions/{versionPublicId}'",
        "updateDraftVersion",
        "catalog.manage",
    ],
    [
        "'POST', '/api/v1/catalog/recipe-versions/{versionPublicId}/activate'",
        "activateVersion",
        "catalog.manage",
    ],
];
foreach ($routes as [$route, $handler, $permission]) {
    $assert(str_contains($entrypoint, $route), 'Recipe route is missing: ' . $route);
    $assert(str_contains($entrypoint, $handler), 'Recipe route handler is missing: ' . $handler);
    $assert(str_contains($entrypoint, "makePermissionMiddleware('" . $permission . "', false)"), 'Recipe permission middleware is missing: ' . $permission);
}
$assert(str_contains($entrypoint, '$csrfMiddleware, $originMiddleware'), 'Recipe write routes must use CSRF and origin guards.');
$assert(is_string($controller) && str_contains($controller, "header('idempotency-key')"), 'Recipe writes must forward idempotency keys.');
$assert(is_string($service) && str_contains($service, 'catalog.recipe.version.create'), 'Recipe draft creation must be idempotent.');
$assert(str_contains($service, 'catalog.recipe.version.activate'), 'Recipe activation must be idempotent.');
$assert(str_contains($service, 'catalog.recipe.version.update'), 'Recipe draft updates must be idempotent.');
$assert(str_contains($service, 'Only a draft recipe version can be edited.'), 'Only draft versions may be edited.');
$assert(str_contains($service, 'deleteRecipeLinesForVersion'), 'Draft line replacement must be persisted.');
$assert(str_contains($service, 'countRecipeVersionLines'), 'Audit snapshot must include the prior draft line count.');
$assert(str_contains($service, 'findConversionFactor') && str_contains($service, 'createRecipeLine'), 'Recipe lines must resolve and persist unit conversion snapshots.');
$assert(str_contains($service, 'retireActiveVersions'), 'Activating a recipe must retire the previous active version.');
$assert(is_string($catalogService) && str_contains($catalogService, "'variant_sku_exists'")
    && str_contains($catalogService, 'variantSkuExists($scope->businessId, $productId, $sku)'),
    'Duplicate variant SKU must be rejected with a stable conflict response.');
$assert(is_string($catalogRepository) && str_contains($catalogRepository, 'WHERE business_id = :business_id AND product_id = :product_id AND sku = :sku'),
    'Variant SKU conflict lookup must be scoped to the current business and product.');
$assert(str_contains($catalogRepository, 'findProductSkuConflict(int $businessId, string $sku, int $exceptProductId)')
    && str_contains($catalogService, 'findProductSkuConflict($scope->businessId, $sku, $productId)'),
    'Product SKU conflict lookup must respect business scope and allow identical SKUs in separate businesses.');
$assert(str_contains($catalogRepository, '?int $exceptVariantId = null')
    && str_contains($catalogService, 'variantSkuExists($scope->businessId, $productId, $sku, $variantId)'),
    'Variant updates must exclude the current variant while rejecting another variant with the same SKU.');

$assert(str_contains($catalogRepository, 'categoryParentWouldCycle(int $businessId, int $categoryId, int $candidateParentId)')
    && str_contains($catalogService, 'categoryParentWouldCycle($scope->businessId, $categoryId, $parentId)')
    && str_contains($catalogService, "'category_hierarchy_cycle'"),
    'Category updates must prevent nesting a category beneath itself or its descendants.');

echo "V3 recipe route and security contract checks passed.\n";
