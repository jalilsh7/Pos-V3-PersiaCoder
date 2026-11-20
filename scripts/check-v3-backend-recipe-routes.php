<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$entrypoint = file_get_contents($root . '/v3/backend/public/index.php');
$service = file_get_contents($root . '/v3/backend/src/Recipes/RecipeService.php');
$controller = file_get_contents($root . '/v3/backend/src/Recipes/RecipeController.php');

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
$assert(str_contains($service, 'findConversionFactor') && str_contains($service, 'createRecipeLine'), 'Recipe lines must resolve and persist unit conversion snapshots.');
$assert(str_contains($service, 'retireActiveVersions'), 'Activating a recipe must retire the previous active version.');

echo "V3 recipe route and security contract checks passed.\n";
