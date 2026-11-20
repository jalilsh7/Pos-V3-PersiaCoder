<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Auth\AuthController;
use PersiaCoder\PosV3\Auth\BusinessContextController;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Auth\BusinessSetupController;
use PersiaCoder\PosV3\Auth\BusinessSetupService;
use PersiaCoder\PosV3\Auth\BranchManagementController;
use PersiaCoder\PosV3\Auth\BranchManagementService;
use PersiaCoder\PosV3\Auth\AuthenticationService;
use PersiaCoder\PosV3\Auth\SessionAuthenticator;
use PersiaCoder\PosV3\Catalog\CatalogService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Infrastructure\Database\SessionRepository;
use PersiaCoder\PosV3\Infrastructure\Database\BusinessRepository;
use PersiaCoder\PosV3\Infrastructure\Database\PermissionRepository;
use PersiaCoder\PosV3\Infrastructure\Database\OrderRepository;
use PersiaCoder\PosV3\Orders\OrderService;
use PersiaCoder\PosV3\Security\CsrfGuard;

$root = dirname(__DIR__);
require_once $root . '/database/v3/bin/bootstrap.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PersiaCoder\\PosV3\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/v3/backend/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

$pdo = PosV3DatabaseTools::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$uuid = static fn (): string => sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
);
$prefix = 'ci-auth-' . bin2hex(random_bytes(5));
$username = $prefix;
$initialPassword = 'CI-Initial-Password-123!';
$newPassword = 'CI-New-Password-456!';

$insert = $pdo->prepare(
    'INSERT INTO app_users (public_id, username, password_hash, display_name, is_active, must_change_password)
     VALUES (:public_id, :username, :password_hash, :display_name, 1, 1)'
);
$insert->execute([
    'public_id' => $uuid(),
    'username' => $username,
    'password_hash' => password_hash($initialPassword, PASSWORD_DEFAULT),
    'display_name' => 'CI Auth User',
]);
$userId = (int) $pdo->lastInsertId();
$ownerRoleId = $pdo->query("SELECT id FROM app_roles WHERE code = 'owner' AND is_active = 1")->fetchColumn();
if ($ownerRoleId === false) throw new RuntimeException('The installation owner role is missing.');
$pdo->prepare('INSERT INTO app_user_roles (user_id, role_id) VALUES (:user_id, :role_id)')
    ->execute(['user_id' => $userId, 'role_id' => (int) $ownerRoleId]);

$appKey = str_repeat('ci-v3-auth-integration-key-', 2);
$authentication = new AuthenticationService($pdo, $appKey);
$controller = new AuthController($pdo, $authentication, true);
$authenticator = new SessionAuthenticator(new SessionRepository($pdo));
$requestId = static fn (): string => bin2hex(random_bytes(16));
$cookieValue = static function (array $headers, string $name): string {
    $cookies = $headers['Set-Cookie'] ?? null;
    foreach (is_array($cookies) ? $cookies : (is_string($cookies) ? [$cookies] : []) as $cookie) {
        if (str_starts_with($cookie, $name . '=')) {
            return explode(';', substr($cookie, strlen($name) + 1), 2)[0];
        }
    }
    throw new RuntimeException("Expected {$name} cookie was not issued.");
};
$makeRequest = static function (string $method, string $path, array $headers = [], string $body = '') use ($requestId): Request {
    return new Request($method, $path, $headers, $body, $requestId(), ['remote_ip' => '127.0.0.1']);
};

$login = $controller->login($makeRequest(
    'POST', '/api/v1/auth/login',
    ['content-type' => 'application/json', 'user-agent' => 'POS-V3-CI'],
    json_encode(['username' => $username, 'password' => $initialPassword], JSON_THROW_ON_ERROR),
));
$assert($login->status === 200, 'Initial owner login failed.');
$loginData = json_decode($login->body, true, 32, JSON_THROW_ON_ERROR)['data'];
$assert($loginData['user']['must_change_password'] === true, 'Initial user must be required to change password.');
$sessionToken = $cookieValue($login->headers, 'pos_v3_session');
$csrfToken = $cookieValue($login->headers, 'pos_v3_csrf');
$assert(hash_equals($csrfToken, $loginData['csrf_token']), 'CSRF body token and cookie do not match.');
$assert(str_contains(implode(';', $login->headers['Set-Cookie']), 'HttpOnly')
    && str_contains(implode(';', $login->headers['Set-Cookie']), 'Secure')
    && str_contains(implode(';', $login->headers['Set-Cookie']), 'SameSite=Lax'),
    'Session cookie security attributes are missing.');

$stored = $pdo->prepare('SELECT token_hash, csrf_token_hash FROM app_user_sessions WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
$stored->execute(['user_id' => $userId]);
$storedSession = $stored->fetch(PDO::FETCH_ASSOC);
$assert(is_array($storedSession), 'Login did not persist a session.');
$assert(hash_equals((string) $storedSession['token_hash'], hash('sha256', $sessionToken)), 'Session token hash is not stored correctly.');
$assert(!hash_equals((string) $storedSession['token_hash'], $sessionToken), 'Raw session token must never be stored.');
$assert(hash_equals((string) $storedSession['csrf_token_hash'], hash('sha256', $csrfToken)), 'CSRF token hash is not stored correctly.');

$cookieHeader = 'pos_v3_session=' . $sessionToken . '; pos_v3_csrf=' . $csrfToken;
$meRequest = $makeRequest('GET', '/api/v1/auth/me', ['cookie' => $cookieHeader]);
$principal = $authenticator->authenticate($meRequest);
$me = $controller->me($meRequest->withAttribute('principal', $principal));
$meData = json_decode($me->body, true, 32, JSON_THROW_ON_ERROR)['data'];
$assert($meData['user']['id'] === $loginData['user']['id'], 'Session introspection returned a different user.');
$assert(hash_equals($csrfToken, $meData['csrf_token']), 'Session introspection changed a valid CSRF token unexpectedly.');

$rotatedRequest = $makeRequest('GET', '/api/v1/auth/me', [
    'cookie' => 'pos_v3_session=' . $sessionToken . '; pos_v3_csrf=' . str_repeat('a', 43),
]);
$rotatedPrincipal = $authenticator->authenticate($rotatedRequest);
$rotated = $controller->me($rotatedRequest->withAttribute('principal', $rotatedPrincipal));
$rotatedToken = $cookieValue($rotated->headers, 'pos_v3_csrf');
$assert(!hash_equals($csrfToken, $rotatedToken), 'Mismatched CSRF cookie was not rotated.');
$csrfPrincipal = $authenticator->authenticate($makeRequest('GET', '/api/v1/auth/me', [
    'cookie' => 'pos_v3_session=' . $sessionToken . '; pos_v3_csrf=' . $rotatedToken,
]));
$csrfGuard = new CsrfGuard();
$mutation = $makeRequest('POST', '/api/v1/auth/change-password', [
    'cookie' => 'pos_v3_session=' . $sessionToken . '; pos_v3_csrf=' . $rotatedToken,
    'x-csrf-token' => $rotatedToken,
    'content-type' => 'application/json',
], json_encode(['current_password' => $initialPassword, 'new_password' => $newPassword], JSON_THROW_ON_ERROR));
$csrfGuard->assertValid($mutation, $csrfPrincipal);
$passwordChanged = $controller->changePassword($mutation->withAttribute('principal', $csrfPrincipal));
$assert($passwordChanged->status === 200, 'Password change did not succeed.');
$assert(str_contains(implode(';', $passwordChanged->headers['Set-Cookie']), 'Max-Age=0'), 'Password change did not clear authentication cookies.');

$activeSessions = $pdo->prepare('SELECT COUNT(*) FROM app_user_sessions s INNER JOIN app_users u ON u.id = s.user_id
     WHERE s.user_id = :user_id AND s.revoked_at IS NULL AND s.session_version = u.session_version');
$activeSessions->execute(['user_id' => $userId]);
$assert((int) $activeSessions->fetchColumn() === 0, 'Password change did not revoke every active session.');
try {
    $authenticator->authenticate($makeRequest('GET', '/api/v1/auth/me', ['cookie' => $cookieHeader]));
    throw new RuntimeException('Old session remained valid after password change.');
} catch (HttpException $error) {
    $assert($error->status === 401, 'Revoked session returned an unexpected status.');
}

$relogin = $controller->login($makeRequest(
    'POST', '/api/v1/auth/login',
    ['content-type' => 'application/json'],
    json_encode(['username' => $username, 'password' => $newPassword], JSON_THROW_ON_ERROR),
));
$reloginData = json_decode($relogin->body, true, 32, JSON_THROW_ON_ERROR)['data'];
$assert($reloginData['user']['must_change_password'] === false, 'Changed password did not clear forced-change flag.');
$newSessionToken = $cookieValue($relogin->headers, 'pos_v3_session');
$newCsrfToken = $cookieValue($relogin->headers, 'pos_v3_csrf');
$newCookieHeader = 'pos_v3_session=' . $newSessionToken . '; pos_v3_csrf=' . $newCsrfToken;
$newRequest = $makeRequest('GET', '/api/v1/auth/me', ['cookie' => $newCookieHeader]);
$newPrincipal = $authenticator->authenticate($newRequest);
$contextController = new BusinessContextController(new BusinessRepository($pdo));
$contextBeforeSetupRequest = $makeRequest('GET', '/api/v1/access/businesses')->withAttribute('principal', $newPrincipal);
$contextBeforeSetup = $contextController->list($contextBeforeSetupRequest);
$contextBeforeData = json_decode($contextBeforeSetup->body, true, 32, JSON_THROW_ON_ERROR)['data'];
$assert($contextBeforeData['businesses'] === [], 'A fresh installation unexpectedly exposed a business.');
$assert($contextBeforeData['can_initialize'] === true, 'Installation owner cannot initialize the first business.');
$permissionRepository = new PermissionRepository($pdo);
$preexistingBusinessId = $pdo->query('SELECT id FROM pos_businesses ORDER BY id LIMIT 1')->fetchColumn();
if ($preexistingBusinessId !== false) {
    $assert(
        !$permissionRepository->userHasPermission($userId, 'orders.read', (int) $preexistingBusinessId, null),
        'Global installation owner received permissions in an unassigned business.',
    );
}

$setupController = new BusinessSetupController(new BusinessSetupService($pdo));
$setupRequest = $makeRequest(
    'POST', '/api/v1/setup/business',
    [
        'content-type' => 'application/json',
        'cookie' => $newCookieHeader,
        'x-csrf-token' => $newCsrfToken,
    ],
    json_encode([
        'name' => 'CI Authentication Business',
        'code' => $prefix,
        'timezone' => 'Asia/Tehran',
        'currency' => 'IRT',
        'branch_name' => 'شعبه اصلی',
        'branch_code' => 'main',
    ], JSON_THROW_ON_ERROR),
)->withAttribute('principal', $newPrincipal);
$csrfGuard->assertValid($setupRequest, $newPrincipal);
$setup = $setupController->create($setupRequest);
$setupData = json_decode($setup->body, true, 32, JSON_THROW_ON_ERROR)['data'];
$businessPublicId = (string) $setupData['business']['id'];
$branchPublicId = (string) $setupData['branch']['id'];
$businessIdStatement = $pdo->prepare('SELECT id FROM pos_businesses WHERE public_id = :public_id');
$businessIdStatement->execute(['public_id' => $businessPublicId]);
$businessId = (int) $businessIdStatement->fetchColumn();
$branchIdStatement = $pdo->prepare('SELECT id FROM pos_branches WHERE public_id = :public_id');
$branchIdStatement->execute(['public_id' => $branchPublicId]);
$branchId = (int) $branchIdStatement->fetchColumn();
$pdo->prepare('UPDATE pos_branches SET timezone = NULL WHERE id = :branch_id AND business_id = :business_id')
    ->execute(['branch_id' => $branchId, 'business_id' => $businessId]);

$ownerMembership = $pdo->prepare(
    'SELECT 1 FROM app_user_business_roles WHERE user_id = :user_id AND business_id = :business_id AND role_id = :role_id LIMIT 1'
);
$ownerMembership->execute(['user_id' => $userId, 'business_id' => $businessId, 'role_id' => (int) $ownerRoleId]);
$assert($ownerMembership->fetchColumn() !== false, 'Initial setup did not assign the installation owner to the created business.');
$businessRepository = new BusinessRepository($pdo);
$assert(!$businessRepository->isInstallationOwner($userId), 'Installation owner privilege was not retired after setup.');
$globalOwner = $pdo->prepare('SELECT 1 FROM app_user_roles WHERE user_id = :user_id AND role_id = :role_id LIMIT 1');
$globalOwner->execute(['user_id' => $userId, 'role_id' => (int) $ownerRoleId]);
$assert($globalOwner->fetchColumn() === false, 'Global installation owner role remained assigned after setup.');

$contextAfterSetupRequest = $makeRequest('GET', '/api/v1/access/businesses')->withAttribute('principal', $newPrincipal);
$contextAfterSetup = $contextController->list($contextAfterSetupRequest);
$contextAfterData = json_decode($contextAfterSetup->body, true, 32, JSON_THROW_ON_ERROR)['data'];
$assert(count($contextAfterData['businesses']) === 1, 'Business membership discovery did not return the assigned business.');
$assert($contextAfterData['businesses'][0]['id'] === $businessPublicId, 'Business context discovery returned the wrong business.');
$assert($contextAfterData['can_initialize'] === false, 'A non-owner business manager was incorrectly allowed to initialize setup.');
$assert(
    $permissionRepository->userHasPermission($userId, 'orders.read', $businessId, null),
    'Business-scoped owner lost permissions in the initial business.',
);
$foreignBusiness = $pdo->prepare('SELECT id FROM pos_businesses WHERE id <> :business_id ORDER BY id LIMIT 1');
$foreignBusiness->execute(['business_id' => $businessId]);
$foreignBusinessId = $foreignBusiness->fetchColumn();
if ($foreignBusinessId !== false) {
    $assert(
        !$permissionRepository->userHasPermission($userId, 'orders.read', (int) $foreignBusinessId, null),
        'Business-scoped owner received permissions in another business.',
    );
}

$branchController = new BranchManagementController(new BranchManagementService($pdo, $appKey));
$branchListRequest = $makeRequest('GET', '/api/v1/access/branches')->withAttribute(
    'business_scope',
    new BusinessScope($businessId, $businessPublicId, null, null),
);
$branchList = $branchController->list($branchListRequest);
$branchListData = json_decode($branchList->body, true, 32, JSON_THROW_ON_ERROR)['data']['branches'];
$assert(count($branchListData) === 1 && $branchListData[0]['id'] === $branchPublicId, 'Business-scoped branch discovery did not return the initial branch.');

$otherBranchId = $businessRepository->createBranch(
    $uuid(),
    $businessId,
    'pagination-test',
    'Pagination Test Branch',
    'Europe/Amsterdam',
);
$insertOrder = $pdo->prepare(
    "INSERT INTO pos_orders
        (business_id, branch_id, public_id, order_number, source_channel, order_status, payment_status, created_by)
     VALUES
        (:business_id, :branch_id, :public_id, :order_number, 'manual', 'draft', :payment_status, :created_by)"
);
$orderIds = [];
foreach ([80001 => 'unpaid', 80002 => 'paid', 80003 => 'partially_paid'] as $orderNumber => $paymentStatus) {
    $insertOrder->execute([
        'business_id' => $businessId,
        'branch_id' => $branchId,
        'public_id' => $uuid(),
        'order_number' => $orderNumber,
        'payment_status' => $paymentStatus,
        'created_by' => $userId,
    ]);
    $orderIds[$orderNumber] = (int) $pdo->lastInsertId();
}
$insertOrder->execute([
    'business_id' => $businessId,
    'branch_id' => $otherBranchId,
    'public_id' => $uuid(),
    'order_number' => 80004,
    'payment_status' => 'unpaid',
    'created_by' => $userId,
]);
$orderRepository = new OrderRepository($pdo);
$assert($orderRepository->findEffectiveTimezone($businessId, $branchId) === 'Asia/Tehran', 'Order date filters did not fall back to the business timezone.');
$assert($orderRepository->findEffectiveTimezone($businessId, $otherBranchId) === 'Europe/Amsterdam', 'Branch timezone did not override the business timezone.');
$orderRepository->createFulfillment($businessId, $branchId, $orderIds[80003], 'dine_in', null, null, null);
$firstOrderPage = $orderRepository->listOrders($businessId, $branchId, null, null, 2);
$assert(count($firstOrderPage['orders']) === 2, 'Order page one did not return the requested page size.');
$assert($firstOrderPage['orders'][0]['order_number'] === 80003, 'Order page one is not sorted newest-first.');
$assert($firstOrderPage['orders'][1]['order_number'] === 80002, 'Order page one returned an unexpected second order.');
$assert($firstOrderPage['has_more'] === true && $firstOrderPage['next_cursor'] === '80002', 'Order page one returned an invalid continuation cursor.');
$secondOrderPage = $orderRepository->listOrders($businessId, $branchId, null, null, 2, (int) $firstOrderPage['next_cursor']);
$assert(count($secondOrderPage['orders']) === 1, 'Order page two did not return the remaining order.');
$assert($secondOrderPage['orders'][0]['order_number'] === 80001, 'Order page two returned an unexpected order.');
$assert($secondOrderPage['has_more'] === false && $secondOrderPage['next_cursor'] === null, 'Final order page incorrectly advertises another page.');
$otherBranchOrders = $orderRepository->listOrders($businessId, $otherBranchId, null, null, 10);
$assert(count($otherBranchOrders['orders']) === 1 && $otherBranchOrders['orders'][0]['order_number'] === 80004, 'Order pagination leaked rows across branches.');
$paidOrders = $orderRepository->listOrders($businessId, $branchId, null, null, 10, null, 'paid');
$assert(count($paidOrders['orders']) === 1 && $paidOrders['orders'][0]['order_number'] === 80002, 'Order payment filter returned unexpected rows.');
$dineInOrders = $orderRepository->listOrders($businessId, $branchId, null, null, 10, null, null, 'dine_in');
$assert(count($dineInOrders['orders']) === 1 && $dineInOrders['orders'][0]['order_number'] === 80003, 'Order fulfillment filter returned unexpected rows.');

$setOrderTime = $pdo->prepare('UPDATE pos_orders SET created_at = :created_at WHERE id = :order_id AND business_id = :business_id AND branch_id = :branch_id');
foreach ([
    80001 => '2026-11-19 20:29:59.999',
    80002 => '2026-11-19 20:30:00.000',
    80003 => '2026-11-20 20:29:59.999',
] as $orderNumber => $createdAt) {
    $setOrderTime->execute([
        'created_at' => $createdAt,
        'order_id' => $orderIds[$orderNumber],
        'business_id' => $businessId,
        'branch_id' => $branchId,
    ]);
}
$insertOrder->execute([
    'business_id' => $businessId,
    'branch_id' => $branchId,
    'public_id' => $uuid(),
    'order_number' => 80005,
    'payment_status' => 'unpaid',
    'created_by' => $userId,
]);
$setOrderTime->execute([
    'created_at' => '2026-11-20 20:30:00.000',
    'order_id' => (int) $pdo->lastInsertId(),
    'business_id' => $businessId,
    'branch_id' => $branchId,
]);

$orderService = new OrderService($pdo, $appKey, new PermissionService($permissionRepository));
$orderScope = new BusinessScope($businessId, $businessPublicId, $branchId, $branchPublicId);

// Exercise a complete POS order against the disposable MySQL/MariaDB database.
$catalogService = new CatalogService($pdo, $appKey);
$createdProduct = $catalogService->createProduct(
    $newPrincipal,
    $orderScope,
    ['name' => 'CI POS Order Product', 'base_price_amount' => 10000, 'tax_rate_bps' => 1000],
    $uuid(),
    $requestId(),
    '127.0.0.1',
);
$productPublicId = (string) $createdProduct['body']['data']['product']['id'];
$createdVariant = $catalogService->createVariant(
    $newPrincipal,
    $orderScope,
    $productPublicId,
    ['name' => 'CI POS Variant', 'price_delta_amount' => 2000],
    $uuid(),
    $requestId(),
    '127.0.0.1',
);
$variantPublicId = (string) $createdVariant['body']['data']['variant']['id'];
$createdOptionGroup = $catalogService->createOptionGroup(
    $newPrincipal,
    $orderScope,
    [
        'name' => 'CI POS Required Option',
        'selection_type' => 'single',
        'min_select' => 1,
        'max_select' => 1,
        'is_required_default' => false,
        'options' => [['name' => 'CI POS Extra', 'price_delta_amount' => 500]],
    ],
    $uuid(),
    $requestId(),
    '127.0.0.1',
);
$optionGroupPublicId = (string) $createdOptionGroup['body']['data']['option_group']['id'];
$optionPublicId = (string) $createdOptionGroup['body']['data']['option_group']['options'][0]['id'];
$catalogService->attachOptionGroup(
    $newPrincipal,
    $orderScope,
    $productPublicId,
    ['option_group_id' => $optionGroupPublicId, 'sort_order' => 0, 'is_required' => true],
    $uuid(),
    $requestId(),
    '127.0.0.1',
);
$orderPayload = [
    'source_channel' => 'pos',
    'fulfillment_method' => 'dine_in',
    'items' => [[
        'product_id' => $productPublicId,
        'variant_id' => $variantPublicId,
        'quantity' => 2,
        'options' => [$optionPublicId],
    ]],
];
$orderIdempotencyKey = 'ci-pos-order-' . bin2hex(random_bytes(12));
$createdOrder = $orderService->create(
    $newPrincipal, $orderScope, $orderPayload, $orderIdempotencyKey, $requestId(), '127.0.0.1',
);
$createdOrderData = $createdOrder['body']['data']['order'];
$assert($createdOrder['status'] === 201 && $createdOrder['replayed'] === false, 'POS order creation did not return a fresh 201 response.');
$assert($createdOrderData['subtotal_amount'] === 25000, 'POS order subtotal did not use server-side product, variant and option prices.');
$assert($createdOrderData['tax_amount'] === 2500 && $createdOrderData['total_amount'] === 27500, 'POS order tax or total was not calculated authoritatively by the server.');
$assert($createdOrderData['order_status'] === 'draft' && $createdOrderData['payment_status'] === 'unpaid', 'New POS order did not remain draft and unpaid.');
$createdOrderPublicId = (string) $createdOrderData['id'];
$replayedOrder = $orderService->create(
    $newPrincipal, $orderScope, $orderPayload, $orderIdempotencyKey, $requestId(), '127.0.0.1',
);
$replayedOrderBody = json_decode(
    json_encode($replayedOrder['body'], JSON_THROW_ON_ERROR),
    true,
    32,
    JSON_THROW_ON_ERROR,
);
$assert($replayedOrder['replayed'] === true
    && $replayedOrderBody['data']['order']['id'] === $createdOrderPublicId,
    'POS order idempotency replay did not return the original order.');
$orderCount = $pdo->prepare('SELECT COUNT(*) FROM pos_orders WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id');
$orderCount->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $createdOrderPublicId]);
$assert((int) $orderCount->fetchColumn() === 1, 'Idempotent POS retry created a duplicate order.');

$changedPayloadRejected = false;
$changedPayload = $orderPayload;
$changedPayload['items'][0]['quantity'] = 3;
try {
    $orderService->create(
        $newPrincipal, $orderScope, $changedPayload, $orderIdempotencyKey, $requestId(), '127.0.0.1',
    );
} catch (HttpException $error) {
    $changedPayloadRejected = $error->status === 409;
}
$assert($changedPayloadRejected, 'Reusing a POS idempotency key with a changed payload was not rejected.');

$missingOptionRejected = false;
$invalidOptionPayload = $orderPayload;
$invalidOptionPayload['items'][0]['options'] = [];
try {
    $orderService->create(
        $newPrincipal, $orderScope, $invalidOptionPayload, 'ci-pos-invalid-option-' . bin2hex(random_bytes(8)), $requestId(), '127.0.0.1',
    );
} catch (HttpException $error) {
    $missingOptionRejected = $error->status === 422;
}
$assert($missingOptionRejected, 'POS order creation accepted a missing required option.');
$orderCount->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $createdOrderPublicId]);
$assert((int) $orderCount->fetchColumn() === 1, 'A rejected POS order attempt changed the existing order count.');

$acceptKey = 'ci-order-accept-' . bin2hex(random_bytes(8));
$accepted = $orderService->transition(
    $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'accepted'], $acceptKey, $requestId(), '127.0.0.1',
);
$assert($accepted['status'] === 200 && $accepted['body']['data']['to_status'] === 'accepted', 'Draft POS order could not be accepted.');
$acceptedReplay = $orderService->transition(
    $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'accepted'], $acceptKey, $requestId(), '127.0.0.1',
);
$assert($acceptedReplay['replayed'] === true, 'Order acceptance idempotency replay was not recognized.');

$prepareKey = 'ci-kitchen-prepare-' . bin2hex(random_bytes(8));
$preparing = $orderService->transitionKitchen(
    $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'preparing'], $prepareKey, $requestId(), '127.0.0.1',
);
$assert($preparing['body']['data']['to_status'] === 'preparing', 'Kitchen could not start preparing an accepted order.');
$prepareReplay = $orderService->transitionKitchen(
    $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'preparing'], $prepareKey, $requestId(), '127.0.0.1',
);
$assert($prepareReplay['replayed'] === true, 'Kitchen preparing transition idempotency replay was not recognized.');

$readyKey = 'ci-kitchen-ready-' . bin2hex(random_bytes(8));
$ready = $orderService->transitionKitchen(
    $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'ready'], $readyKey, $requestId(), '127.0.0.1',
);
$assert($ready['body']['data']['to_status'] === 'ready', 'Kitchen could not mark a preparing order ready.');

$kitchenCompletionForbidden = false;
try {
    $orderService->transitionKitchen(
        $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'completed'],
        'ci-kitchen-complete-' . bin2hex(random_bytes(8)), $requestId(), '127.0.0.1',
    );
} catch (HttpException $error) {
    $kitchenCompletionForbidden = $error->status === 403;
}
$assert($kitchenCompletionForbidden, 'Kitchen-only transition accepted a forbidden completed target.');

$completeKey = 'ci-order-complete-' . bin2hex(random_bytes(8));
$completed = $orderService->transition(
    $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'completed'], $completeKey, $requestId(), '127.0.0.1',
);
$assert($completed['body']['data']['to_status'] === 'completed', 'Ready order could not be completed by the order workflow.');

$invalidTransitionRejected = false;
try {
    $orderService->transition(
        $newPrincipal, $orderScope, $createdOrderPublicId, ['to_status' => 'preparing'],
        'ci-order-invalid-transition-' . bin2hex(random_bytes(8)), $requestId(), '127.0.0.1',
    );
} catch (HttpException $error) {
    $invalidTransitionRejected = $error->status === 409;
}
$assert($invalidTransitionRejected, 'Order state machine accepted a transition out of completed.');
$statusEventCount = $pdo->prepare('SELECT COUNT(*) FROM pos_order_status_events WHERE business_id = :business_id AND branch_id = :branch_id AND order_id = (SELECT id FROM pos_orders WHERE business_id = :order_business_id AND branch_id = :order_branch_id AND public_id = :public_id)');
$statusEventCount->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'order_business_id' => $businessId, 'order_branch_id' => $branchId, 'public_id' => $createdOrderPublicId]);
$assert((int) $statusEventCount->fetchColumn() === 5, 'Order status transition replay created duplicate status events or a rejected transition was persisted.');

// Keep the newly created POS fixture outside the separate Tehran date-filter fixture window.
$pdo->prepare('UPDATE pos_orders SET created_at = :created_at WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id')
    ->execute([
        'created_at' => '2026-11-19 19:30:00.000',
        'business_id' => $businessId,
        'branch_id' => $branchId,
        'public_id' => $createdOrderPublicId,
    ]);

$localDayOrders = $orderService->list($orderScope, null, null, 10, null, null, null, '2026-11-20', '2026-11-20');
$localDayNumbers = array_column($localDayOrders['orders'], 'order_number');
$assert($localDayNumbers === [80003, 80002], 'Order date filter did not honor the inclusive Tehran local day and exclusive next-day boundary.');
$datePageOne = $orderService->list($orderScope, null, null, 1, null, null, null, '2026-11-20', '2026-11-20');
$datePageTwo = $orderService->list($orderScope, null, null, 1, $datePageOne['next_cursor'], null, null, '2026-11-20', '2026-11-20');
$assert($datePageOne['has_more'] === true && $datePageOne['orders'][0]['order_number'] === 80003, 'Date-filtered first cursor page is incorrect.');
$assert($datePageTwo['has_more'] === false && $datePageTwo['orders'][0]['order_number'] === 80002, 'Date filters were not preserved with the next cursor page.');
$invalidDateRejected = false;
try {
    $orderService->list($orderScope, null, null, 10, null, null, null, '2026-02-30', null);
} catch (HttpException $error) {
    $invalidDateRejected = $error->status === 422 || str_contains($error->getMessage(), 'calendar date');
}
$assert($invalidDateRejected, 'Invalid calendar date was not rejected.');
$reversedRangeRejected = false;
try {
    $orderService->list($orderScope, null, null, 10, null, null, null, '2026-11-21', '2026-11-20');
} catch (HttpException $error) {
    $reversedRangeRejected = str_contains($error->getMessage(), 'start date');
}
$assert($reversedRangeRejected, 'Reversed order date range was not rejected.');

$amsterdamOrderTimes = [
    80006 => '2026-03-28 23:00:00.000',
    80007 => '2026-03-29 22:00:00.000',
];
foreach ($amsterdamOrderTimes as $orderNumber => $createdAt) {
    $insertOrder->execute([
        'business_id' => $businessId,
        'branch_id' => $otherBranchId,
        'public_id' => $uuid(),
        'order_number' => $orderNumber,
        'payment_status' => 'unpaid',
        'created_by' => $userId,
    ]);
    $setOrderTime->execute([
        'created_at' => $createdAt,
        'order_id' => (int) $pdo->lastInsertId(),
        'business_id' => $businessId,
        'branch_id' => $otherBranchId,
    ]);
}
$amsterdamScope = new BusinessScope($businessId, $businessPublicId, $otherBranchId, $otherBranchPublicId);
$amsterdamDstDay = $orderService->list($amsterdamScope, null, null, 10, null, null, null, '2026-03-29', '2026-03-29');
$assert(
    array_column($amsterdamDstDay['orders'], 'order_number') === [80006],
    'Europe/Amsterdam DST local-day filtering did not use the 23-hour calendar-day boundary.',
);


$logoutRequest = $makeRequest('POST', '/api/v1/auth/logout', [
    'cookie' => $newCookieHeader,
    'x-csrf-token' => $newCsrfToken,
]);
$csrfGuard->assertValid($logoutRequest, $newPrincipal);
$logout = $controller->logout($logoutRequest->withAttribute('principal', $newPrincipal));
$assert($logout->status === 200, 'Logout failed.');
$assert(str_contains(implode(';', $logout->headers['Set-Cookie']), 'Max-Age=0'), 'Logout did not clear authentication cookies.');
$activeSessions->execute(['user_id' => $userId]);
$assert((int) $activeSessions->fetchColumn() === 0, 'Logout did not revoke the active session.');

echo "V3 authentication/context integration checks passed: login, hashed cookies, session introspection, CSRF rotation, forced password change, session invalidation, logout, first-business setup, business membership discovery, branch discovery, branch-scoped order cursor pagination, branch/business timezone resolution, inclusive local-day UTC boundaries, DST transition boundaries, date-filtered cursor continuity and invalid-date rejection.\n";
