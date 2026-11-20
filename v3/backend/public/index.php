<?php
declare(strict_types=1);

use Closure;
use PersiaCoder\PosV3\Catalog\CatalogController;
use PersiaCoder\PosV3\Catalog\CatalogService;
use PersiaCoder\PosV3\Media\MediaController;
use PersiaCoder\PosV3\Inventory\InventoryController;
use PersiaCoder\PosV3\Inventory\InventoryService;
use PersiaCoder\PosV3\Inventory\InventoryDocumentService;
use PersiaCoder\PosV3\Inventory\InventoryPostingService;
use PersiaCoder\PosV3\Inventory\SupplierService;
use PersiaCoder\PosV3\Recipes\RecipeController;
use PersiaCoder\PosV3\Recipes\RecipeService;
use PersiaCoder\PosV3\Media\MediaService;
use PersiaCoder\PosV3\Infrastructure\Storage\PrivateMediaStorage;
use PersiaCoder\PosV3\Orders\OrderController;
use PersiaCoder\PosV3\Orders\OrderService;
use PersiaCoder\PosV3\Dining\DiningController;
use PersiaCoder\PosV3\Dining\DiningService;
use PersiaCoder\PosV3\Finance\CashRegisterController;
use PersiaCoder\PosV3\Finance\CashRegisterService;
use PersiaCoder\PosV3\Finance\OrderPaymentController;
use PersiaCoder\PosV3\Finance\OrderPaymentService;
use PersiaCoder\PosV3\Communications\Sms\SmsOutboxController;
use PersiaCoder\PosV3\Communications\Sms\SmsOutboxService;
use PersiaCoder\PosV3\Customers\CustomerController;
use PersiaCoder\PosV3\Customers\CustomerService;
use PersiaCoder\PosV3\Auth\AuthController;
use PersiaCoder\PosV3\Auth\AuditLogController;
use PersiaCoder\PosV3\Auth\BusinessSetupController;
use PersiaCoder\PosV3\Auth\BusinessContextController;
use PersiaCoder\PosV3\Auth\BranchManagementController;
use PersiaCoder\PosV3\Auth\BranchManagementService;
use PersiaCoder\PosV3\Auth\BusinessSetupService;
use PersiaCoder\PosV3\Auth\BusinessScopeResolver;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Auth\RoleManagementController;
use PersiaCoder\PosV3\Auth\RoleManagementService;
use PersiaCoder\PosV3\Auth\UserManagementController;
use PersiaCoder\PosV3\Auth\UserManagementService;
use PersiaCoder\PosV3\Auth\AuthenticationService;
use PersiaCoder\PosV3\Auth\SessionAuthenticator;
use PersiaCoder\PosV3\Auth\SessionMiddleware;
use PersiaCoder\PosV3\Http\Kernel;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
use PersiaCoder\PosV3\Http\Router;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\BusinessRepository;
use PersiaCoder\PosV3\Infrastructure\Database\PdoConnectionFactory;
use PersiaCoder\PosV3\Infrastructure\Database\PermissionRepository;
use PersiaCoder\PosV3\Infrastructure\Database\RoleRepository;
use PersiaCoder\PosV3\Infrastructure\Database\SessionRepository;
use PersiaCoder\PosV3\Security\CsrfGuard;
use PersiaCoder\PosV3\Security\CsrfMiddleware;
use PersiaCoder\PosV3\Security\SameOriginGuard;
use PersiaCoder\PosV3\Security\InstallationOwnerMiddleware;
use PersiaCoder\PosV3\Security\PermissionMiddleware;

try {
    $configuration = require dirname(__DIR__) . '/bootstrap.php';

    $connection = null;
    $getConnection = static function () use (&$connection, $configuration): PDO {
        if (!$connection instanceof PDO) {
            $connection = PdoConnectionFactory::connect($configuration);
        }
        return $connection;
    };

    $controller = null;
    $getController = static function () use (&$controller, $getConnection, $configuration): AuthController {
        if (!$controller instanceof AuthController) {
            $secureCookies = !in_array($configuration->environment, ['development', 'test'], true);
            $controller = new AuthController(
                $getConnection(),
                new AuthenticationService($getConnection(), $configuration->applicationKey),
                $secureCookies,
            );
        }
        return $controller;
    };

    $allowInsecureLocalhost = in_array($configuration->environment, ['development', 'test'], true);
    $originGuard = new SameOriginGuard($allowInsecureLocalhost);

    $sessionMiddleware = static function (Request $request, array $parameters, callable $next) use ($getConnection): Response {
        $middleware = new SessionMiddleware(
            new SessionAuthenticator(new SessionRepository($getConnection()))
        );
        return $middleware($request, $parameters, $next);
    };

    $csrfMiddleware = static function (Request $request, array $parameters, callable $next) use ($getConnection): Response {
        $middleware = new CsrfMiddleware(new CsrfGuard());
        return $middleware($request, $parameters, $next);
    };

    $originMiddleware = static function (Request $request, array $parameters, callable $next) use ($originGuard): Response {
        $originGuard->assert($request);
        return $next($request);
    };

    $setupController = null;
    $getSetupController = static function () use (&$setupController, $getConnection): BusinessSetupController {
        if (!$setupController instanceof BusinessSetupController) {
            $setupController = new BusinessSetupController(new BusinessSetupService($getConnection()));
        }
        return $setupController;
    };

    $businessContextController = null;
    $getBusinessContextController = static function () use (&$businessContextController, $getConnection): BusinessContextController {
        if (!$businessContextController instanceof BusinessContextController) {
            $businessContextController = new BusinessContextController(new BusinessRepository($getConnection()));
        }
        return $businessContextController;
    };

    $cashRegisterController = null;
    $getCashRegisterController = static function () use (&$cashRegisterController, $getConnection, $configuration): CashRegisterController {
        if (!$cashRegisterController instanceof CashRegisterController) {
            $cashRegisterController = new CashRegisterController(
                new CashRegisterService($getConnection(), $configuration->applicationKey),
            );
        }
        return $cashRegisterController;
    };

    $smsOutboxController = null;
    $getSmsOutboxController = static function () use (&$smsOutboxController, $getConnection, $configuration): SmsOutboxController {
        if (!$smsOutboxController instanceof SmsOutboxController) {
            $smsOutboxController = new SmsOutboxController(
                new SmsOutboxService(
                    $getConnection(),
                    $configuration->applicationKey,
                    new PermissionService(new PermissionRepository($getConnection())),
                ),
            );
        }
        return $smsOutboxController;
    };

    $customerController = null;
    $getCustomerController = static function () use (&$customerController, $getConnection, $configuration): CustomerController {
        if (!$customerController instanceof CustomerController) {
            $customerController = new CustomerController(
                new CustomerService(
                    $getConnection(),
                    $configuration->applicationKey,
                    new PermissionService(new PermissionRepository($getConnection())),
                ),
            );
        }
        return $customerController;
    };

    $orderPaymentController = null;
    $getOrderPaymentController = static function () use (&$orderPaymentController, $getConnection, $configuration): OrderPaymentController {
        if (!$orderPaymentController instanceof OrderPaymentController) {
            $permissionRepository = new PermissionRepository($getConnection());
            $orderPaymentController = new OrderPaymentController(
                new OrderPaymentService(
                    $getConnection(),
                    $configuration->applicationKey,
                    new PermissionService($permissionRepository),
                ),
            );
        }
        return $orderPaymentController;
    };

    $diningController = null;
    $getDiningController = static function () use (&$diningController, $getConnection, $configuration): DiningController {
        if (!$diningController instanceof DiningController) {
            $diningController = new DiningController(
                new DiningService($getConnection(), $configuration->applicationKey),
            );
        }
        return $diningController;
    };

    $orderController = null;
    $getOrderController = static function () use (&$orderController, $getConnection, $configuration): OrderController {
        if (!$orderController instanceof OrderController) {
            $permissionRepository = new PermissionRepository($getConnection());
            $orderController = new OrderController(
                new OrderService(
                    $getConnection(),
                    $configuration->applicationKey,
                    new PermissionService($permissionRepository),
                ),
            );
        }
        return $orderController;
    };

    $roleController = null;
    $getRoleController = static function () use (&$roleController, $getConnection, $configuration): RoleManagementController {
        if (!$roleController instanceof RoleManagementController) {
            $repository = new PermissionRepository($getConnection());
            $roleController = new RoleManagementController(
                new RoleManagementService(
                    $getConnection(),
                    $configuration->applicationKey,
                    new RoleRepository($getConnection()),
                    new PermissionService($repository),
                ),
            );
        }
        return $roleController;
    };

    $branchController = null;
    $getBranchController = static function () use (&$branchController, $getConnection, $configuration): BranchManagementController {
        if (!$branchController instanceof BranchManagementController) {
            $branchController = new BranchManagementController(
                new BranchManagementService($getConnection(), $configuration->applicationKey),
            );
        }
        return $branchController;
    };

    $userController = null;
    $getUserController = static function () use (&$userController, $getConnection, $configuration): UserManagementController {
        if (!$userController instanceof UserManagementController) {
            $repository = new PermissionRepository($getConnection());
            $userController = new UserManagementController(
                new UserManagementService(
                    $getConnection(),
                    $configuration->applicationKey,
                    new PermissionService($repository),
                ),
            );
        }
        return $userController;
    };

    $makePermissionMiddleware = static function (string $permissionCode, bool $requireBranch, bool $allowBranchContext = false) use ($getConnection): Closure {
        return static function (Request $request, array $parameters, callable $next) use ($getConnection, $permissionCode, $requireBranch, $allowBranchContext): Response {
            $repository = new PermissionRepository($getConnection());
            $middleware = new PermissionMiddleware(
                new PermissionService($repository),
                new BusinessScopeResolver($repository),
                $permissionCode,
                $requireBranch,
                $allowBranchContext,
            );
            return $middleware($request, $parameters, $next);
        };
    };

    $ownerMiddleware = static function (Request $request, array $parameters, callable $next) use ($getConnection): Response {
        $middleware = new InstallationOwnerMiddleware(new BusinessRepository($getConnection()));
        return $middleware($request, $parameters, $next);
    };

    $auditController = null;
    $getAuditController = static function () use (&$auditController, $getConnection): AuditLogController {
        if (!$auditController instanceof AuditLogController) {
            $auditController = new AuditLogController(new AuditLogRepository($getConnection()));
        }
        return $auditController;
    };

    $catalogController = null;
    $getCatalogController = static function () use (&$catalogController, $getConnection, $configuration): CatalogController {
        if (!$catalogController instanceof CatalogController) {
            $catalogController = new CatalogController(
                new CatalogService($getConnection(), $configuration->applicationKey),
            );
        }
        return $catalogController;
    };


    $mediaController = null;
    $getMediaController = static function () use (&$mediaController, $getConnection, $configuration): MediaController {
        if (!$mediaController instanceof MediaController) {
            $mediaController = new MediaController(new MediaService(
                $getConnection(),
                $configuration->applicationKey,
                new PrivateMediaStorage($configuration->privateStoragePath),
                $configuration->mediaMaxBytes,
            ));
        }
        return $mediaController;
    };


    $inventoryController = null;
    $getInventoryController = static function () use (&$inventoryController, $getConnection, $configuration): InventoryController {
        if (!$inventoryController instanceof InventoryController) {
            $inventoryController = new InventoryController(
                new InventoryService($getConnection(), $configuration->applicationKey),
                new InventoryDocumentService($getConnection(), $configuration->applicationKey),
                new InventoryPostingService($getConnection(), $configuration->applicationKey),
                new SupplierService($getConnection(), $configuration->applicationKey),
            );
        }
        return $inventoryController;
    };

    $recipeController = null;
    $getRecipeController = static function () use (&$recipeController, $getConnection, $configuration): RecipeController {
        if (!$recipeController instanceof RecipeController) {
            $recipeController = new RecipeController(
                new RecipeService($getConnection(), $configuration->applicationKey),
            );
        }
        return $recipeController;
    };

    $router = new Router();
    $router->add('GET', '/api/v1/health', static fn (Request $request): Response =>
        Response::success(['status' => 'ok'])
    );

    $router->add('POST', '/api/v1/setup/business',
        static fn (Request $request): Response => $getSetupController()->create($request),
        [$sessionMiddleware, $csrfMiddleware, $originMiddleware, $ownerMiddleware],
    );

    $router->add('POST', '/api/v1/auth/login',
        static fn (Request $request): Response => $getController()->login($request),
        [$originMiddleware],
    );

    $router->add('GET', '/api/v1/auth/me',
        static fn (Request $request): Response => $getController()->me($request),
        [$sessionMiddleware],
    );

    $router->add('POST', '/api/v1/auth/change-password',
        static fn (Request $request): Response => $getController()->changePassword($request),
        [$sessionMiddleware, $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/cash/registers',
        static fn (Request $request): Response => $getCashRegisterController()->list($request),
        [$sessionMiddleware, $makePermissionMiddleware('finance.read', true)],
    );

    $router->add('POST', '/api/v1/cash/registers',
        static fn (Request $request): Response => $getCashRegisterController()->createRegister($request),
        [$sessionMiddleware, $makePermissionMiddleware('finance.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/cash/registers/{registerPublicId}/sessions',
        static fn (Request $request, array $parameters): Response => $getCashRegisterController()->openSession($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('cash.session.open', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/cash/sessions/{sessionPublicId}/close',
        static fn (Request $request, array $parameters): Response => $getCashRegisterController()->closeSession($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('cash.session.close', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/cash/sessions/{sessionPublicId}/movements',
        static fn (Request $request, array $parameters): Response => $getCashRegisterController()->createManualMovement($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('finance.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/cash/sessions/{sessionPublicId}/review',
        static fn (Request $request, array $parameters): Response => $getCashRegisterController()->reviewSession($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('finance.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/dining',
        static fn (Request $request): Response => $getDiningController()->list($request),
        [$sessionMiddleware, $makePermissionMiddleware('orders.read', true)],
    );

    $router->add('POST', '/api/v1/dining/areas',
        static fn (Request $request): Response => $getDiningController()->createArea($request),
        [$sessionMiddleware, $makePermissionMiddleware('settings.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/dining/areas/{areaPublicId}/tables',
        static fn (Request $request, array $parameters): Response => $getDiningController()->createTable($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('settings.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/dining/tables/{tablePublicId}/sessions',
        static fn (Request $request, array $parameters): Response => $getDiningController()->openSession($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('orders.create', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/dining/sessions/{sessionPublicId}/close',
        static fn (Request $request, array $parameters): Response => $getDiningController()->closeSession($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('orders.update', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/orders',
        static fn (Request $request): Response => $getOrderController()->list($request),
        [$sessionMiddleware, $makePermissionMiddleware('orders.read', true)],
    );

    $router->add('GET', '/api/v1/cash/payment-sessions',
        static fn (Request $request): Response => $getOrderPaymentController()->listOpenCashSessions($request),
        [$sessionMiddleware, $makePermissionMiddleware('pos.sell', true)],
    );

    $router->add('GET', '/api/v1/cash/refund-sessions',
        static fn (Request $request): Response => $getOrderPaymentController()->listOpenCashSessions($request),
        [$sessionMiddleware, $makePermissionMiddleware('finance.manage', true)],
    );

    $router->add('GET', '/api/v1/orders/{orderPublicId}/payments',
        static fn (Request $request, array $parameters): Response => $getOrderPaymentController()->list($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('orders.read', true)],
    );

    $router->add('POST', '/api/v1/orders/{orderPublicId}/payments/cash',
        static fn (Request $request, array $parameters): Response => $getOrderPaymentController()->captureCash($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('pos.sell', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/orders/{orderPublicId}/payments/{paymentPublicId}/refunds/cash',
        static fn (Request $request, array $parameters): Response => $getOrderPaymentController()->refundCash($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('finance.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/communications/sms/transactional',
        static fn (Request $request): Response => $getSmsOutboxController()->enqueueTransactional($request),
        [$sessionMiddleware, $makePermissionMiddleware('pos.sell'), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/pos/customers',
        static fn (Request $request): Response => $getCustomerController()->quickSearch($request),
        [$sessionMiddleware, $makePermissionMiddleware('pos.sell')],
    );

    $router->add('GET', '/api/v1/customers',
        static fn (Request $request): Response => $getCustomerController()->list($request),
        [$sessionMiddleware, $makePermissionMiddleware('customers.read')],
    );

    $router->add('GET', '/api/v1/customers/{customerPublicId}',
        static fn (Request $request, array $parameters): Response => $getCustomerController()->show($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('customers.read')],
    );

    $router->add('POST', '/api/v1/customers',
        static fn (Request $request): Response => $getCustomerController()->create($request),
        [$sessionMiddleware, $makePermissionMiddleware('customers.manage'), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/customers/{customerPublicId}',
        static fn (Request $request, array $parameters): Response => $getCustomerController()->update($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('customers.manage'), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/orders/{orderPublicId}',
        static fn (Request $request, array $parameters): Response => $getOrderController()->show($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('orders.read', true)],
    );

    $router->add('POST', '/api/v1/orders',
        static fn (Request $request): Response => $getOrderController()->create($request),
        [$sessionMiddleware, $makePermissionMiddleware('orders.create', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/orders/{orderPublicId}/status',
        static fn (Request $request, array $parameters): Response => $getOrderController()->transition($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('orders.update', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/orders/{orderPublicId}/kitchen-status',
        static fn (Request $request, array $parameters): Response => $getOrderController()->transitionKitchen($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('kitchen.update', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/catalog/categories',
        static fn (Request $request): Response => $getCatalogController()->categories($request),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.read', false)],
    );

    $router->add('POST', '/api/v1/catalog/categories',
        static fn (Request $request): Response => $getCatalogController()->createCategory($request),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/catalog/products',
        static fn (Request $request): Response => $getCatalogController()->products($request),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.read', false)],
    );

    $router->add('POST', '/api/v1/catalog/products',
        static fn (Request $request): Response => $getCatalogController()->createProduct($request),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );


    $router->add('GET', '/api/v1/catalog/products/{productPublicId}',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->productDetails($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.read', false)],
    );

    $router->add('GET', '/api/v1/catalog/products/{productPublicId}/recipe',
        static fn (Request $request, array $parameters): Response => $getRecipeController()->showProductRecipe($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.read', false)],
    );

    $router->add('POST', '/api/v1/catalog/products/{productPublicId}/recipe-versions',
        static fn (Request $request, array $parameters): Response => $getRecipeController()->createDraftVersion($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/catalog/recipe-versions/{versionPublicId}/activate',
        static fn (Request $request, array $parameters): Response => $getRecipeController()->activateVersion($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );
    $router->add('POST', '/api/v1/catalog/products/{productPublicId}/variants',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->createVariant($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );
    $router->add('POST', '/api/v1/catalog/option-groups',
        static fn (Request $request): Response => $getCatalogController()->createOptionGroup($request),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );
    $router->add('POST', '/api/v1/catalog/products/{productPublicId}/option-groups',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->attachOptionGroup($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );


    $router->add('PATCH', '/api/v1/catalog/categories/{categoryPublicId}',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->updateCategory($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/catalog/products/{productPublicId}',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->updateProduct($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('DELETE', '/api/v1/catalog/products/{productPublicId}',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->archiveProduct($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );


    $router->add('PATCH', '/api/v1/catalog/variants/{variantPublicId}',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->updateVariant($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/catalog/option-groups/{groupPublicId}',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->updateOptionGroup($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/catalog/options/{optionPublicId}',
        static fn (Request $request, array $parameters): Response => $getCatalogController()->updateOption($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );


    $router->add('POST', '/api/v1/media',
        static fn (Request $request): Response => $getMediaController()->upload($request),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/media/{mediaPublicId}',
        static fn (Request $request, array $parameters): Response => $getMediaController()->show($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.read', false)],
    );

    $router->add('PUT', '/api/v1/catalog/products/{productPublicId}/image',
        static fn (Request $request, array $parameters): Response => $getMediaController()->attachProductImage($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PUT', '/api/v1/catalog/categories/{categoryPublicId}/image',
        static fn (Request $request, array $parameters): Response => $getMediaController()->attachCategoryImage($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );


    $router->add('DELETE', '/api/v1/catalog/products/{productPublicId}/image',
        static fn (Request $request, array $parameters): Response => $getMediaController()->detachProductImage($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('DELETE', '/api/v1/catalog/categories/{categoryPublicId}/image',
        static fn (Request $request, array $parameters): Response => $getMediaController()->detachCategoryImage($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('catalog.manage', false), $csrfMiddleware, $originMiddleware],
    );



    $router->add('GET', '/api/v1/inventory/documents',
        static fn (Request $request): Response => $getInventoryController()->documents($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', true)],
    );


    $router->add('POST', '/api/v1/inventory/documents/{documentPublicId}/lines',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->createDocumentLine($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/inventory/documents/{documentPublicId}/lines/{linePublicId}',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->updateDocumentLine($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('DELETE', '/api/v1/inventory/documents/{documentPublicId}/lines/{linePublicId}',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->deleteDocumentLine($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/inventory/documents/{documentPublicId}',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->document($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', true)],
    );

    $router->add('GET', '/api/v1/inventory/documents/{documentPublicId}/valuation',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->documentValuation($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true)],
    );

    $router->add('POST', '/api/v1/inventory/documents/{documentPublicId}/post',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->postDocument($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/inventory/documents',
        static fn (Request $request): Response => $getInventoryController()->createDocument($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/inventory/suppliers',
        static fn (Request $request): Response => $getInventoryController()->suppliers($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', false)],
    );

    $router->add('POST', '/api/v1/inventory/suppliers',
        static fn (Request $request): Response => $getInventoryController()->createSupplier($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/inventory/suppliers/{supplierPublicId}',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->updateSupplier($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/inventory/units',
        static fn (Request $request): Response => $getInventoryController()->units($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', false)],
    );

    $router->add('GET', '/api/v1/inventory/items',
        static fn (Request $request): Response => $getInventoryController()->stockItems($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', false)],
    );

    $router->add('POST', '/api/v1/inventory/items',
        static fn (Request $request): Response => $getInventoryController()->createStockItem($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/inventory/items/{stockItemPublicId}',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->updateStockItem($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/inventory/locations',
        static fn (Request $request): Response => $getInventoryController()->locations($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', true)],
    );

    $router->add('GET', '/api/v1/inventory/balances',
        static fn (Request $request): Response => $getInventoryController()->balances($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', true)],
    );

    $router->add('GET', '/api/v1/inventory/ledger',
        static fn (Request $request): Response => $getInventoryController()->ledger($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.read', true)],
    );

    $router->add('POST', '/api/v1/inventory/locations',
        static fn (Request $request): Response => $getInventoryController()->createLocation($request),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/inventory/locations/{locationPublicId}',
        static fn (Request $request, array $parameters): Response => $getInventoryController()->updateLocation($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('inventory.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/audit-logs',
        static fn (Request $request): Response => $getAuditController()->list($request),
        [$sessionMiddleware, $makePermissionMiddleware('audit.read', false, true)],
    );

    $router->add('GET', '/api/v1/access/businesses',
        static fn (Request $request): Response => $getBusinessContextController()->list($request),
        [$sessionMiddleware],
    );

    $router->add('GET', '/api/v1/access/branches',
        static fn (Request $request): Response => $getBranchController()->list($request),
        [$sessionMiddleware, $makePermissionMiddleware('branches.read', false)],
    );

    $router->add('POST', '/api/v1/access/branches',
        static fn (Request $request): Response => $getBranchController()->create($request),
        [$sessionMiddleware, $makePermissionMiddleware('branches.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/access/users',
        static fn (Request $request): Response => $getUserController()->listBusinessUsers($request),
        [$sessionMiddleware, $makePermissionMiddleware('users.read', false)],
    );

    $router->add('GET', '/api/v1/access/branches/users',
        static fn (Request $request): Response => $getUserController()->listBranchUsers($request),
        [$sessionMiddleware, $makePermissionMiddleware('users.read', true)],
    );

    $router->add('POST', '/api/v1/access/users',
        static fn (Request $request): Response => $getUserController()->createBusinessUser($request),
        [$sessionMiddleware, $makePermissionMiddleware('users.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/access/branches/users',
        static fn (Request $request): Response => $getUserController()->createBranchUser($request),
        [$sessionMiddleware, $makePermissionMiddleware('users.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/access/permissions',
        static fn (Request $request): Response => $getRoleController()->permissions($request),
        [$sessionMiddleware, $makePermissionMiddleware('roles.read', false)],
    );

    $router->add('POST', '/api/v1/access/roles',
        static fn (Request $request): Response => $getRoleController()->create($request),
        [$sessionMiddleware, $makePermissionMiddleware('roles.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('PATCH', '/api/v1/access/roles/{roleCode}',
        static fn (Request $request, array $parameters): Response => $getRoleController()->update($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('roles.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('GET', '/api/v1/access/roles',
        static fn (Request $request): Response => $getRoleController()->list($request),
        [$sessionMiddleware, $makePermissionMiddleware('roles.read', false)],
    );

    $router->add('POST', '/api/v1/access/users/{userPublicId}/business-roles',
        static fn (Request $request, array $parameters): Response => $getRoleController()->assignBusiness($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('roles.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('DELETE', '/api/v1/access/users/{userPublicId}/business-roles/{roleCode}',
        static fn (Request $request, array $parameters): Response => $getRoleController()->removeBusiness($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('roles.manage', false), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/access/users/{userPublicId}/branch-roles',
        static fn (Request $request, array $parameters): Response => $getRoleController()->assignBranch($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('roles.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('DELETE', '/api/v1/access/users/{userPublicId}/branch-roles/{roleCode}',
        static fn (Request $request, array $parameters): Response => $getRoleController()->removeBranch($request, $parameters),
        [$sessionMiddleware, $makePermissionMiddleware('roles.manage', true), $csrfMiddleware, $originMiddleware],
    );

    $router->add('POST', '/api/v1/auth/logout',
        static fn (Request $request): Response => $getController()->logout($request),
        [$sessionMiddleware, $csrfMiddleware, $originMiddleware],
    );

    (new Kernel($router))->handle(Request::fromGlobals())->send();
} catch (Throwable $exception) {
    error_log('POS_V3 bootstrap_failure=' . $exception::class);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo '{"error":{"code":"service_unavailable","message":"The service is temporarily unavailable."}}';
}
