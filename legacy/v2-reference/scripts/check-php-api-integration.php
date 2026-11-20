<?php
declare(strict_types=1);

/**
 * Black-box PHP API integration checks against the disposable CI MySQL database.
 * Uses only synthetic records and removes them by dropping the CI database after the job.
 */

$base = rtrim((string)(getenv('POS_TEST_BASE_URL') ?: 'http://127.0.0.1:8088'), '/');
$setupToken = (string)(getenv('POS_TEST_SETUP_TOKEN') ?: 'ci-only-setup-token-'.str_repeat('x', 48));
$cookie = '';
$csrf = '';

function valid_uuid_for_test(string $value): bool { return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value) === 1; }

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function api(string $method, string $path, ?array $body = null, array $extraHeaders = []): array {
    global $base, $cookie;
    $headers = ['Accept: application/json'];
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    foreach ($extraHeaders as $header) $headers[] = $header;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ];
    $context = stream_context_create($options);
    $response = file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) $status = (int)$m[1];
        if (stripos($header, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $m)) $cookie = $m[1];
    }
    $decoded = is_string($response) ? json_decode($response, true) : null;
    return ['status' => $status, 'json' => is_array($decoded) ? $decoded : [], 'raw' => $response, 'headers' => $responseHeaders];
}

function csrf_headers(): array {
    global $csrf;
    return ['X-CSRF-Token: ' . $csrf];
}


/**
 * Fire two independent authenticated HTTP requests at the same time.
 * Each child waits on a filesystem barrier so both requests reach the multi-worker
 * PHP server together, rather than being serialized by a shared PHP session.
 */
function concurrent_order_attempts(array $attempts): array {
    check(function_exists('pcntl_fork'), 'pcntl is required for concurrent API integration checks');
    $barrier = tempnam(sys_get_temp_dir(), '3food-barrier-');
    check(is_string($barrier), 'create concurrency barrier');
    unlink($barrier);
    $children = [];
    $resultFiles = [];

    foreach ($attempts as $index => $attempt) {
        $resultFile = tempnam(sys_get_temp_dir(), '3food-result-');
        check(is_string($resultFile), 'create concurrency result file');
        $resultFiles[] = $resultFile;
        $pid = pcntl_fork();
        check($pid !== -1, 'fork concurrent API worker');
        if ($pid === 0) {
            while (!is_file($barrier)) usleep(1000);
            $headers = [
                'Accept: application/json',
                'Content-Type: application/json',
                'Cookie: ' . $attempt['cookie'],
                'X-CSRF-Token: ' . $attempt['csrf'],
            ];
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($attempt['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 20,
            ]]);
            $response = file_get_contents($GLOBALS['base'] . '/api/v1/orders', false, $context);
            $status = 0;
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('#^HTTP/\\S+\\s+(\\d+)#', $header, $matches)) $status = (int)$matches[1];
            }
            file_put_contents($resultFile, json_encode([
                'status' => $status,
                'json' => is_string($response) ? (json_decode($response, true) ?: []) : [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            exit(0);
        }
        $children[] = $pid;
    }

    touch($barrier);
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'concurrent API worker exits cleanly');
    }
    $results = [];
    foreach ($resultFiles as $file) {
        $decoded = json_decode((string)file_get_contents($file), true);
        check(is_array($decoded), 'read concurrent API result');
        $results[] = $decoded;
        unlink($file);
    }
    unlink($barrier);
    return $results;
}

try {
    $health = api('GET', '/api/v1/health');
    check($health['status'] === 200 && ($health['json']['database'] ?? '') === 'mysql', 'health endpoint');
    $healthHeaders = array_map('strtolower', $health['headers']);
    check((bool)array_filter($healthHeaders, fn($h) => str_starts_with($h, 'x-content-type-options: nosniff')), 'API sets nosniff response header');
    check((bool)array_filter($healthHeaders, fn($h) => str_starts_with($h, 'x-frame-options: deny')), 'API denies framing');
    check((bool)array_filter($healthHeaders, fn($h) => str_starts_with($h, 'content-security-policy: frame-ancestors')), 'API sets restrictive CSP');

    $preflight = api('GET', '/api/v1/preflight');
    check($preflight['status'] === 200 && ($preflight['json']['ok'] ?? false) === true, 'preflight endpoint responds');
    $preflightChecks = $preflight['json']['checks'] ?? [];
    foreach (['php_version', 'pdo_mysql', 'json', 'database_connection', 'innodb_schema'] as $checkName) {
        check(($preflightChecks[$checkName] ?? false) === true, 'preflight check passes: ' . $checkName);
    }
    check(($preflight['json']['missing_innodb_tables'] ?? ['unexpected']) === [], 'preflight confirms complete InnoDB schema');
    check(is_bool($preflightChecks['https'] ?? null), 'preflight reports HTTPS state');
    check(is_bool($preflight['json']['production_ready'] ?? null), 'preflight reports production readiness separately');

    $appearancePublic = api('GET', '/api/v1/appearance');
    check($appearancePublic['status'] === 200 && ($appearancePublic['json']['settings']['mode'] ?? '') === 'light', 'global appearance is readable before login');
    $appearanceUnauth = api('POST', '/api/v1/appearance', ['settings' => []]);
    check($appearanceUnauth['status'] === 401, 'appearance writes require an authenticated manager');

    $setup = api('POST', '/api/v1/setup/manager', [
        'username' => 'ci_manager',
        'password' => 'CI-Only-Strong-Password-2026!',
        'display_name' => 'CI Manager',
    ], ['X-Setup-Token: ' . $setupToken]);
    check($setup['status'] === 201, 'first manager setup: ' . json_encode($setup['json']));
    $csrf = (string)($setup['json']['csrf_token'] ?? '');
    check(strlen($csrf) >= 32 && $cookie !== '', 'setup session and CSRF token');

    $meAfterSetup = api('GET', '/api/v1/auth/me');
    check($meAfterSetup['status'] === 200 && ($meAfterSetup['json']['user']['must_change_password'] ?? null) === false, 'auth/me returns a real boolean for the password-change flag');
    $wrongCurrentPassword = api('POST', '/api/v1/auth/change-password', [
        'current_password' => 'definitely-not-the-current-password',
        'new_password' => 'CI-Only-New-Password-2026!',
    ], csrf_headers());
    check($wrongCurrentPassword['status'] === 401 && ($wrongCurrentPassword['json']['error'] ?? '') === 'current_password_invalid', 'wrong current password is a field error, not a session expiry');
    $passwordChanged = api('POST', '/api/v1/auth/change-password', [
        'current_password' => 'CI-Only-Strong-Password-2026!',
        'new_password' => 'CI-Only-New-Password-2026!',
    ], csrf_headers());
    check($passwordChanged['status'] === 200 && strlen((string)($passwordChanged['json']['csrf_token'] ?? '')) >= 32, 'valid password change rotates the session CSRF token');
    $csrf = (string)$passwordChanged['json']['csrf_token'];
    $meAfterPasswordChange = api('GET', '/api/v1/auth/me');
    check($meAfterPasswordChange['status'] === 200 && ($meAfterPasswordChange['json']['user']['must_change_password'] ?? null) === false, 'password change clears forced-password flag for refresh');


    // Terminal API is exercised with a synthetic agent callback only; this does not
    // claim that a physical bank terminal or a provider protocol has been tested.
    $agentCreated = api('POST', '/api/v1/terminal-agents', ['name' => 'CI Terminal Agent'], csrf_headers());
    check($agentCreated['status'] === 201, 'manager creates a local terminal agent');
    $agentToken = (string)($agentCreated['json']['token'] ?? '');
    $agentId = (string)($agentCreated['json']['agent']['id'] ?? '');
    check(strlen($agentToken) === 64 && valid_uuid_for_test($agentId), 'agent token and UUID are generated');
    $agentList = api('GET', '/api/v1/terminal-agents');
    check($agentList['status'] === 200 && !str_contains(json_encode($agentList['json']), $agentToken), 'agent list never exposes raw token');
    $publicTerminalHost = api('POST', '/api/v1/terminals', [
        'id' => 'ci-public-terminal', 'agentId' => $agentId, 'localTerminalKey' => 'ci-public',
        'name' => 'Rejected Public IP', 'providerCode' => 'ci_test', 'driverCode' => 'ci_stub',
        'connectionType' => 'lan', 'host' => '8.8.8.8', 'port' => 5000, 'enabled' => true, 'isDefault' => false,
    ], csrf_headers());
    check($publicTerminalHost['status'] === 422, 'terminal API rejects public IP addresses');
    $terminalSaved = api('POST', '/api/v1/terminals', [
        'id' => 'ci-terminal',
        'agentId' => $agentId,
        'localTerminalKey' => 'ci-local-1',
        'name' => 'CI Network Terminal',
        'providerCode' => 'ci_test',
        'modelName' => 'Synthetic ECR',
        'driverCode' => 'ci_stub',
        'connectionType' => 'lan',
        'host' => '192.168.1.200',
        'port' => 5000,
        'enabled' => true,
        'isDefault' => true,
    ], csrf_headers());
    check($terminalSaved['status'] === 201, 'manager saves a LAN terminal configuration');
    $agentHeaders = ['X-Terminal-Agent-Token: ' . $agentToken, 'X-Terminal-Agent-Version: ci-test/1.0'];
    $agentHeartbeat = api('GET', '/api/v1/terminal-agent/next', null, $agentHeaders);
    check($agentHeartbeat['status'] === 200 && (array_key_exists('payment', $agentHeartbeat['json']) && $agentHeartbeat['json']['payment'] === null), 'agent authenticates and sends heartbeat: ' . $agentHeartbeat['status'] . ' ' . json_encode($agentHeartbeat['json']));
    $agentAfterHeartbeat = api('GET', '/api/v1/terminal-agents');
    check(($agentAfterHeartbeat['json']['items'][0]['agent_version'] ?? '') === 'ci-test/1.0', 'agent heartbeat stores its software version');
    $availableTerminals = api('GET', '/api/v1/terminals/available');
    check($availableTerminals['status'] === 200 && count($availableTerminals['json']['items'] ?? []) === 1, 'cashier terminal discovery includes online configured drivers');
    $terminalPaymentKey = '44444444-4444-4444-8444-000000000010';
    $terminalPaymentCreated = api('POST', '/api/v1/terminal-payments', [
        'terminalId' => 'ci-terminal',
        'amount' => 125000,
        'idempotencyKey' => $terminalPaymentKey,
    ], csrf_headers());
    check($terminalPaymentCreated['status'] === 201, 'manager queues a terminal payment intent');
    $terminalPaymentId = (string)($terminalPaymentCreated['json']['payment']['id'] ?? '');
    check(valid_uuid_for_test($terminalPaymentId), 'terminal payment intent UUID is returned');
    $agentJob = api('GET', '/api/v1/terminal-agent/next', null, $agentHeaders);
    check($agentJob['status'] === 200 && ($agentJob['json']['payment']['id'] ?? '') === $terminalPaymentId && ($agentJob['json']['payment']['status'] ?? '') === 'processing', 'authenticated agent atomically claims queued payment');
    $agentResult = api('POST', '/api/v1/terminal-agent/payments/' . rawurlencode($terminalPaymentId) . '/result', [
        'status' => 'declined',
        'amount' => 125000,
        'traceNumber' => 'CI-TRACE-001',
        'retrievalReferenceNumber' => 'CI-RRN-001',
        'providerCode' => 'ci_test',
        'responseCode' => '00',
    ], $agentHeaders);
    check($agentResult['status'] === 200 && ($agentResult['json']['status'] ?? '') === 'declined', 'authenticated agent submits an approved synthetic result');
    $terminalPaymentStatus = api('GET', '/api/v1/terminal-payments/' . rawurlencode($terminalPaymentId));
    check($terminalPaymentStatus['status'] === 200 && ($terminalPaymentStatus['json']['payment']['status'] ?? '') === 'declined', 'cashier can read final terminal payment state');
    check((int)($terminalPaymentStatus['json']['payment']['amount'] ?? 0) === 125000 && ($terminalPaymentStatus['json']['payment']['trace_number'] ?? '') === 'CI-TRACE-001', 'terminal payment read returns amount and trace reference');
    $missingTerminalPayment = api('GET', '/api/v1/terminal-payments/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    check($missingTerminalPayment['status'] === 404 && ($missingTerminalPayment['json']['error'] ?? '') === 'terminal_payment_not_found', 'terminal payment lookup hides unknown payment ids');
    $terminalPaymentReplay = api('POST', '/api/v1/terminal-payments', [
        'terminalId' => 'ci-terminal',
        'amount' => 125000,
        'idempotencyKey' => $terminalPaymentKey,
    ], csrf_headers());
    check($terminalPaymentReplay['status'] === 200 && ($terminalPaymentReplay['json']['payment']['id'] ?? '') === $terminalPaymentId, 'terminal payment retry reuses the original idempotency record');

    $managerBootstrapForFeatures = api('GET', '/api/v1/bootstrap');
    check($managerBootstrapForFeatures['status'] === 200, 'manager bootstrap for next-release resources');
    check(count($managerBootstrapForFeatures['json']['data']['expense_categories'] ?? []) >= 8, 'default restaurant expense categories are seeded automatically');
    $supplierIngredient = api('POST', '/api/v1/records/ingredients', ['item' => ['id'=>'ci-supplier-ingredient','name'=>'مواد آزمایشی تأمین‌کننده','unit'=>'kg','stock'=>0,'minStock'=>0,'costPerUnit'=>100]], csrf_headers());
    check($supplierIngredient['status'] === 201, 'manager can create an ingredient for supplier receiving');
    $supplierCreate = api('POST', '/api/v1/records/suppliers', ['item' => ['id'=>'ci-supplier','name'=>'CI Supplier','phone'=>'09120000000','contact'=>'Test','notes'=>'','balance'=>0,'active'=>true]], csrf_headers());
    check($supplierCreate['status'] === 201, 'manager can create supplier record');
    $supplierPurchase = api('POST', '/api/v1/suppliers/ci-supplier/purchases', ['item' => ['id'=>'ci-supplier-purchase','kind'=>'purchase','title'=>'مواد اولیه آزمایشی','amount'=>1000,'paid'=>200,'ingredientId'=>'ci-supplier-ingredient','quantity'=>2,'unitCost'=>500,'note'=>'CI']], csrf_headers());
    check($supplierPurchase['status'] === 200 && (int)($supplierPurchase['json']['supplier']['balance'] ?? -1) === 800, 'supplier purchase and debt update commit atomically');
    $receivedIngredient = api('GET', '/api/v1/records/ingredients');
    $receivedIngredientItem = array_values(array_filter($receivedIngredient['json']['items'] ?? [], fn($item) => ($item['id'] ?? '') === 'ci-supplier-ingredient'))[0] ?? [];
    check((float)($receivedIngredientItem['stock'] ?? -1) === 2.0 && (float)($receivedIngredientItem['costPerUnit'] ?? -1) === 500.0, 'supplier purchase increases ingredient stock and updates weighted cost');
    $supplierPayment = api('POST', '/api/v1/suppliers/ci-supplier/purchases', ['item' => ['id'=>'ci-supplier-payment','kind'=>'payment','title'=>'تسویه','amount'=>0,'paid'=>300,'note'=>'CI']], csrf_headers());
    check($supplierPayment['status'] === 200 && (int)($supplierPayment['json']['supplier']['balance'] ?? -1) === 500, 'supplier settlement reduces outstanding debt atomically');
    $reportDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $reportSummary = api('GET', '/api/v1/reports/summary?from=' . $reportDate . '&to=' . $reportDate);
    check($reportSummary['status'] === 200 && isset($reportSummary['json']['net_profit']) && isset($reportSummary['json']['monthly']), 'manager can retrieve server-side sales, profit and monthly reports');



    $appearanceSettings = [
        'primary' => '#123456', 'secondary' => '#654321', 'mode' => 'dark',
        'fontFamily' => 'byekan', 'fontSize' => 17, 'cardRadius' => 20,
        'buttonRadius' => 10, 'logoUrl' => null,
    ];
    $appearanceSaved = api('POST', '/api/v1/appearance', ['settings' => $appearanceSettings], csrf_headers());
    check($appearanceSaved['status'] === 200 && ($appearanceSaved['json']['settings']['primary'] ?? '') === '#123456', 'manager can save global appearance');
    $appearanceFetched = api('GET', '/api/v1/appearance');
    check(($appearanceFetched['json']['settings']['mode'] ?? '') === 'dark' && ($appearanceFetched['json']['settings']['fontSize'] ?? 0) === 17, 'global appearance persists for public reads');
    $invalidLogo = api('POST', '/api/v1/appearance/logo', ['image' => base64_encode('not-an-image')], csrf_headers());
    check($invalidLogo['status'] === 422, 'logo upload rejects non-image content');
    $appearanceReset = api('POST', '/api/v1/appearance/reset', [], csrf_headers());
    check($appearanceReset['status'] === 200 && ($appearanceReset['json']['settings']['mode'] ?? '') === 'light', 'manager can reset global appearance');


    $setupAgain = api('POST', '/api/v1/setup/manager', [
        'username' => 'ci_manager2',
        'password' => 'CI-Only-Strong-Password-2026!',
        'display_name' => 'CI Manager 2',
    ], ['X-Setup-Token: ' . $setupToken]);
    check($setupAgain['status'] === 409, 'one-time setup must close');

    $csrfRejected = api('POST', '/api/v1/records/products', ['item' => ['id' => 'bad']], ['X-CSRF-Token: invalid']);
    check($csrfRejected['status'] === 419, 'write request rejects missing/invalid CSRF');
    $wrongPortOrigin = api('POST', '/api/v1/records/products', ['item' => ['id' => 'bad']], ['X-CSRF-Token: ' . $csrf, 'Origin: http://127.0.0.1:8089']);
    check($wrongPortOrigin['status'] === 403 && ($wrongPortOrigin['json']['error'] ?? '') === 'origin_rejected', 'same hostname on a different port is rejected as cross-origin');
    $fetchSiteRejected = api('POST', '/api/v1/records/products', ['item' => ['id' => 'bad']], ['X-CSRF-Token: ' . $csrf, 'Sec-Fetch-Site: cross-site']);
    check($fetchSiteRejected['status'] === 403, 'cross-site fetch metadata is rejected even without Origin');

    $product = [
        'id' => 'ci-product',
        'name' => 'CI Test Product',
        'price' => 1000,
        'active' => true,
        'options' => [],
        'recipe' => [['ingredientId' => 'ci-ingredient', 'qty' => 2]],
    ];
    $ingredient = ['id' => 'ci-ingredient', 'name' => 'CI Test Ingredient', 'stock' => 10];
    $customer = ['id' => 'ci-customer', 'name' => 'CI Customer', 'phone' => '09000000000', 'points' => 0, 'totalSpent' => 0, 'visits' => 0];
    foreach ([['products', $product], ['ingredients', $ingredient], ['customers', $customer]] as [$resource, $item]) {
        $saved = api('POST', '/api/v1/records/' . $resource, ['item' => $item], csrf_headers());
        check($saved['status'] === 201, 'seed ' . $resource . ': ' . json_encode($saved['json']));
    }
    $editedProduct = $product;
    $editedProduct['name'] = 'CI Edited Product';
    $productUpdated = api('POST', '/api/v1/records/products', ['item' => $editedProduct], csrf_headers());
    check($productUpdated['status'] === 201 && ($productUpdated['json']['item']['name'] ?? '') === 'CI Edited Product', 'product edit saves updated product data after a validation 401');
    $productRead = api('GET', '/api/v1/records/products');
    check(($productRead['json']['items'][0]['name'] ?? '') === 'CI Edited Product', 'edited product persists in MySQL');
    $restoreProduct = api('POST', '/api/v1/records/products', ['item' => $product], csrf_headers());
    check($restoreProduct['status'] === 201, 'restore product fixture after edit persistence assertion');

    $key = '11111111-1111-4111-8111-111111111111';
    $orderBody = [
        'idempotencyKey' => $key,
        'items' => [['key' => 'ci-line', 'productId' => 'ci-product', 'qty' => 1, 'unitPrice' => 1000, 'optionIds' => []]],
        'customer' => ['id' => 'ci-customer'],
        'payment' => 'cash',
        'totals' => ['subtotal' => 1000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 0, 'total' => 1000],
        'note' => 'CI integration order',
    ];
    $missingKeyBody = $orderBody;
    unset($missingKeyBody['idempotencyKey']);
    $missingKey = api('POST', '/api/v1/orders', $missingKeyBody, csrf_headers());
    check($missingKey['status'] === 422 && ($missingKey['json']['error'] ?? '') === 'invalid_idempotency_key', 'new orders require a valid idempotency key');

    $unverifiedCardBody = $orderBody;
    $unverifiedCardBody['payment'] = 'card';
    $unverifiedCardBody['idempotencyKey'] = '11111111-1111-4111-8111-111111111112';
    $unverifiedCard = api('POST', '/api/v1/orders', $unverifiedCardBody, csrf_headers());
    check($unverifiedCard['status'] === 422, 'API rejects card orders without a terminal payment reference');
    $unverifiedOnlineBody = $orderBody;
    $unverifiedOnlineBody['payment'] = 'online';
    $unverifiedOnlineBody['idempotencyKey'] = '11111111-1111-4111-8111-111111111113';
    $unverifiedOnline = api('POST', '/api/v1/orders', $unverifiedOnlineBody, csrf_headers());
    check($unverifiedOnline['status'] === 409 && ($unverifiedOnline['json']['error'] ?? '') === 'online_payment_unavailable', 'API blocks online orders until a real gateway confirms payment');
    $created = api('POST', '/api/v1/orders', $orderBody, csrf_headers());
    check($created['status'] === 201, 'create order: ' . json_encode($created['json']));
    $orderId = (string)($created['json']['order']['id'] ?? '');
    check($orderId !== '', 'created order id');
    check(($created['json']['order']['items'][0]['name'] ?? '') === 'CI Test Product', 'server canonical product name');
    $managerSnapshot = api('GET', '/api/v1/bootstrap');
    check($managerSnapshot['status'] === 200 && ($managerSnapshot['json']['data']['orders'][0]['payment'] ?? '') === 'cash', 'bootstrap preserves selected payment method');
    $editBody = $orderBody;
    $editBody['items'][0]['qty'] = 2;
    $editBody['totals'] = ['subtotal' => 2000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 0, 'total' => 2000];
    $editBody['note'] = 'CI edited order';
    $switchPaymentBody = $editBody;
    $switchPaymentBody['payment'] = 'card';
    $switchPaymentBody['terminalPaymentId'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $paymentMethodSwitch = api('PATCH', '/api/v1/orders/' . rawurlencode($orderId), $switchPaymentBody, csrf_headers());
    check($paymentMethodSwitch['status'] === 409 && ($paymentMethodSwitch['json']['error'] ?? '') === 'payment_method_change_requires_new_transaction', 'order edit cannot switch cash to card without a separately approved transaction');
    $stockPolicyBlockEdit = api('POST', '/api/v1/records/settings', ['item' => ['id' => 'main', 'stockPolicy' => 'block']], csrf_headers());
    check($stockPolicyBlockEdit['status'] === 201, 'manager can configure stock policy to block insufficient stock');
    $overStockEditBody = $editBody;
    $overStockEditBody['items'][0]['qty'] = 6;
    $overStockEditBody['totals'] = ['subtotal' => 6000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 0, 'total' => 6000];
    $blockedEdit = api('PATCH', '/api/v1/orders/' . rawurlencode($orderId), $overStockEditBody, csrf_headers());
    check($blockedEdit['status'] === 409 && ($blockedEdit['json']['error'] ?? '') === 'insufficient_stock', 'block stock policy rejects insufficient inventory during order edit');
    $stockAfterBlockedEdit = api('GET', '/api/v1/records/ingredients');
    $stockAfterBlockedEditItem = array_values(array_filter($stockAfterBlockedEdit['json']['items'] ?? [], fn($item) => ($item['id'] ?? '') === 'ci-ingredient'))[0] ?? [];
    check((float)($stockAfterBlockedEditItem['stock'] ?? -1) === 8.0, 'rejected order edit rolls back stock restoration and deduction');
    $stockPolicyWarn = api('POST', '/api/v1/records/settings', ['item' => ['id' => 'main', 'stockPolicy' => 'warn_and_allow']], csrf_headers());
    check($stockPolicyWarn['status'] === 201, 'manager can configure warn and allow stock policy');
    $edited = api('PATCH', '/api/v1/orders/' . rawurlencode($orderId), $editBody, csrf_headers());
    check($edited['status'] === 200 && ($edited['json']['order']['id'] ?? '') === $orderId, 'manager edits existing order without changing its identity');
    $editedAgain = api('PATCH', '/api/v1/orders/' . rawurlencode($orderId), $editBody, csrf_headers());
    check($editedAgain['status'] === 200, 'repeated order edit remains idempotent for inventory and loyalty');

    $replay = api('POST', '/api/v1/orders', $orderBody, csrf_headers());
    check($replay['status'] === 200 && ($replay['json']['replayed'] ?? false) === true, 'idempotent retry returns original order');

    $conflictBody = $orderBody;
    $conflictBody['note'] = 'different payload';
    $conflict = api('POST', '/api/v1/orders', $conflictBody, csrf_headers());
    check($conflict['status'] === 409 && ($conflict['json']['error'] ?? '') === 'idempotency_conflict', 'idempotency key rejects changed payload');

    $tampered = $orderBody;
    $tampered['idempotencyKey'] = '22222222-2222-4222-8222-222222222222';
    $tampered['items'][0]['unitPrice'] = 999;
    $priceRejected = api('POST', '/api/v1/orders', $tampered, csrf_headers());
    check($priceRejected['status'] === 409, 'client cannot override server product price');

    $stockFailure = [
        'idempotencyKey' => '33333333-3333-4333-8333-333333333333',
        'items' => [['key' => 'ci-overstock', 'productId' => 'ci-product', 'qty' => 5, 'unitPrice' => 1000, 'optionIds' => []]],
        'customer' => ['id' => 'ci-customer'],
        'totals' => ['subtotal' => 5000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 0, 'total' => 5000],
    ];
    $stockPolicyInvalid = api('POST', '/api/v1/records/settings', ['item' => ['id' => 'main', 'stockPolicy' => 'silently-ignore']], csrf_headers());
    check($stockPolicyInvalid['status'] === 422 && ($stockPolicyInvalid['json']['error'] ?? '') === 'invalid_stock_policy', 'server rejects unknown stock policy values');
    $stockPolicyBlockCreate = api('POST', '/api/v1/records/settings', ['item' => ['id' => 'main', 'stockPolicy' => 'block']], csrf_headers());
    check($stockPolicyBlockCreate['status'] === 201, 'manager can enable stock blocking for new orders');
    $blockedStockOrder = api('POST', '/api/v1/orders', $stockFailure, csrf_headers());
    check($blockedStockOrder['status'] === 409 && ($blockedStockOrder['json']['error'] ?? '') === 'insufficient_stock', 'block stock policy rejects new orders with insufficient ingredients');
    $stockPolicyWarnAgain = api('POST', '/api/v1/records/settings', ['item' => ['id' => 'main', 'stockPolicy' => 'warn_and_allow']], csrf_headers());
    check($stockPolicyWarnAgain['status'] === 201, 'manager can restore warn and allow stock policy');
    $acceptedStock = api('POST', '/api/v1/orders', $stockFailure, csrf_headers());
    check($acceptedStock['status'] === 201 && !empty($acceptedStock['json']['order']['id']), 'checkout succeeds even when recipe stock is depleted');
    $stockDepletionOrderId = (string)($acceptedStock['json']['order']['id'] ?? '');
    $stockDepletionCancel = api('PATCH', '/api/v1/orders/' . rawurlencode($stockDepletionOrderId) . '/status', ['status' => 'cancelled'], csrf_headers());
    check($stockDepletionCancel['status'] === 200, 'cancel stock depletion fixture and restore its inventory effect');
    $stockPolicyNegative = api('POST', '/api/v1/records/settings', ['item' => ['id' => 'main', 'stockPolicy' => 'allow_negative']], csrf_headers());
    check($stockPolicyNegative['status'] === 201, 'manager can configure silent negative stock policy');
    $negativeStockOrderBody = $stockFailure;
    $negativeStockOrderBody['idempotencyKey'] = '33333333-3333-4333-8333-333333333334';
    $negativeStockOrder = api('POST', '/api/v1/orders', $negativeStockOrderBody, csrf_headers());
    check($negativeStockOrder['status'] === 201, 'allow negative stock policy permits sale without blocking');
    $negativeStockOrderId = (string)($negativeStockOrder['json']['order']['id'] ?? '');
    $negativeStockCancel = api('PATCH', '/api/v1/orders/' . rawurlencode($negativeStockOrderId) . '/status', ['status' => 'cancelled'], csrf_headers());
    check($negativeStockCancel['status'] === 200, 'cancel silent negative stock fixture and restore inventory');

    $pdo = new PDO(
        'mysql:host=' . (getenv('MYSQL_TEST_HOST') ?: '127.0.0.1') . ';port=' . (getenv('MYSQL_TEST_PORT') ?: '3306') . ';dbname=' . (getenv('MYSQL_TEST_DATABASE') ?: 'threefood_test') . ';charset=utf8mb4',
        (string)(getenv('MYSQL_TEST_USERNAME') ?: 'root'),
        (string)(getenv('MYSQL_TEST_PASSWORD') ?: 'root-ci-password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $getPayload = static function(PDO $pdo, string $resource, string $id): array {
        $q = $pdo->prepare('SELECT payload FROM pos_records WHERE resource = ? AND id = ?');
        $q->execute([$resource, $id]);
        $raw = $q->fetchColumn();
        return is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    };
    $stockAfterOrder = $getPayload($pdo, 'ingredients', 'ci-ingredient');
    $customerAfterOrder = $getPayload($pdo, 'customers', 'ci-customer');
    check((float)($stockAfterOrder['stock'] ?? -1) === 6.0, 'edited order replaces prior stock effect exactly once');
    check((int)($customerAfterOrder['visits'] ?? -1) === 1 && (int)($customerAfterOrder['totalSpent'] ?? -1) === 2000, 'edited order replaces customer loyalty effects exactly once');

    $cancel = api('PATCH', '/api/v1/orders/' . rawurlencode($orderId) . '/status', ['status' => 'cancelled'], csrf_headers());
    check($cancel['status'] === 200 && ($cancel['json']['status'] ?? '') === 'cancelled', 'cancel order');
    $cancelAgain = api('PATCH', '/api/v1/orders/' . rawurlencode($orderId) . '/status', ['status' => 'cancelled'], csrf_headers());
    check($cancelAgain['status'] === 409, 'duplicate cancellation rejected');
    $stockAfterCancel = $getPayload($pdo, 'ingredients', 'ci-ingredient');
    $customerAfterCancel = $getPayload($pdo, 'customers', 'ci-customer');
    check((float)($stockAfterCancel['stock'] ?? -1) === 10.0, 'stock restored on cancellation');
    check((int)($customerAfterCancel['visits'] ?? -1) === 0 && (int)($customerAfterCancel['totalSpent'] ?? -1) === 0, 'customer effects reversed on cancellation');
    $effects = $pdo->prepare('SELECT COUNT(*) FROM pos_order_effects WHERE order_id = ? AND reversed_at IS NOT NULL');
    $effects->execute([(int)$orderId]);
    check((int)$effects->fetchColumn() === 2, 'effect ledger marked reversed exactly once');
    $editCancelled = api('PATCH', '/api/v1/orders/' . rawurlencode($orderId), $editBody, csrf_headers());
    check($editCancelled['status'] === 409 && ($editCancelled['json']['error'] ?? '') === 'order_not_editable', 'cancelled order cannot be edited');

    $terminalProduct = api('POST', '/api/v1/records/products', ['item' => [
        'id' => 'ci-terminal-product', 'name' => 'CI Terminal Product', 'price' => 1000, 'active' => true, 'options' => [], 'recipe' => [],
    ]], csrf_headers());
    check($terminalProduct['status'] === 201, 'create recipe-free product for terminal order test');
    $agentHeartbeatBeforeOrder = api('GET', '/api/v1/terminal-agent/next', null, $agentHeaders);
    check($agentHeartbeatBeforeOrder['status'] === 200, 'refresh local agent heartbeat before terminal order');
    $orderPaymentKey = '44444444-4444-4444-8444-000000000011';
    $orderPayment = api('POST', '/api/v1/terminal-payments', [
        'terminalId' => 'ci-terminal', 'amount' => 1000, 'idempotencyKey' => $orderPaymentKey,
    ], csrf_headers());
    check($orderPayment['status'] === 201, 'create second terminal payment for order linking');
    $orderPaymentId = (string)($orderPayment['json']['payment']['id'] ?? '');
    $orderPaymentJob = api('GET', '/api/v1/terminal-agent/next', null, $agentHeaders);
    check(($orderPaymentJob['json']['payment']['id'] ?? '') === $orderPaymentId, 'agent claims payment intended for order');
    $orderPaymentResult = api('POST', '/api/v1/terminal-agent/payments/' . rawurlencode($orderPaymentId) . '/result', [
        'status' => 'approved', 'amount' => 1000, 'traceNumber' => 'CI-TRACE-ORDER',
    ], $agentHeaders);
    check($orderPaymentResult['status'] === 200, 'agent approves second synthetic payment');
    $pendingRecovery = api('GET', '/api/v1/terminal-payments/pending');
    check(count($pendingRecovery['json']['items'] ?? []) === 1 && ($pendingRecovery['json']['items'][0]['status'] ?? '') === 'approved', 'cashier can recover approved unlinked payment after refresh');
    $duplicateAfterApproval = api('POST', '/api/v1/terminal-payments', [
        'terminalId' => 'ci-terminal', 'amount' => 1000,
        'idempotencyKey' => '44444444-4444-4444-8444-000000000013',
    ], csrf_headers());
    check($duplicateAfterApproval['status'] === 409, 'server blocks a second charge while an approved payment is not yet linked to an order');
    $cardOrder = api('POST', '/api/v1/orders', [
        'idempotencyKey' => '44444444-4444-4444-8444-000000000012',
        'items' => [['key' => 'ci-card-line', 'productId' => 'ci-terminal-product', 'qty' => 1, 'unitPrice' => 1000, 'optionIds' => []]],
        'totals' => ['subtotal' => 1000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 0, 'total' => 1000],
        'guestName' => 'Terminal Test', 'guestPhone' => '09120000001', 'payment' => 'card', 'terminalPaymentId' => $orderPaymentId,
    ], csrf_headers());
    check($cardOrder['status'] === 201, 'create card order after synthetic terminal approval');
    $cardOrderId = (int)($cardOrder['json']['order']['id'] ?? 0);
    $cardOrderCancel = api('PATCH', '/api/v1/orders/' . rawurlencode((string)$cardOrderId) . '/status', ['status' => 'cancelled'], csrf_headers());
    check($cardOrderCancel['status'] === 409 && ($cardOrderCancel['json']['error'] ?? '') === 'payment_reconciliation_required', 'paid card order cannot be cancelled before refund reconciliation');
    $cardOrderEdit = api('PATCH', '/api/v1/orders/' . rawurlencode((string)$cardOrderId), ['payment' => 'card'], csrf_headers());
    check($cardOrderEdit['status'] === 409 && ($cardOrderEdit['json']['error'] ?? '') === 'payment_reconciliation_required', 'approved card order cannot be edited before payment reconciliation');
    $paymentAttached = api('POST', '/api/v1/terminal-payments/' . rawurlencode($orderPaymentId) . '/attach-order', ['orderId' => $cardOrderId], csrf_headers());
    check($paymentAttached['status'] === 200 && (int)($paymentAttached['json']['order_id'] ?? 0) === $cardOrderId, 'approved payment attaches only to matching card order');
    $paymentLinkedRead = api('GET', '/api/v1/terminal-payments/' . rawurlencode($orderPaymentId));
    check((int)($paymentLinkedRead['json']['payment']['order_id'] ?? 0) === $cardOrderId, 'payment ledger retains linked order id');
    $pendingAfterLink = api('GET', '/api/v1/terminal-payments/pending');
    check(count($pendingAfterLink['json']['items'] ?? []) === 0, 'linked payment disappears from unresolved recovery list');

    // Manager-only user administration and role-specific resource permissions.
    $cashierCreated = api('POST', '/api/v1/users', [
        'username' => 'ci_cashier',
        'password' => 'CI-Cashier-Password-2026!',
        'display_name' => 'CI Cashier',
        'role' => 'cashier',
    ], csrf_headers());
    check($cashierCreated['status'] === 201, 'manager creates cashier account');

    $kitchenCreated = api('POST', '/api/v1/users', [
        'username' => 'ci_kitchen',
        'password' => 'CI-Kitchen-Password-2026!',
        'display_name' => 'CI Kitchen',
        'role' => 'kitchen',
    ], csrf_headers());
    check($kitchenCreated['status'] === 201, 'manager creates kitchen account');

    $managerUsers = api('GET', '/api/v1/users');
    check($managerUsers['status'] === 200 && count($managerUsers['json']['users'] ?? []) === 3, 'manager can list users');

    $settingsWrite = api('POST', '/api/v1/records/settings', [
        'item' => [
            'id' => 'main',
            'shopName' => 'CI Shop',
            'taxPercent' => 9,
            'taxEnabled' => true,
            'pointsPerToman' => 20000,
            'tomanPerPoint' => 500,
            'loyaltySilverPoints' => 500,
            'loyaltyGoldPoints' => 1500,
            'loyaltyPlatinumPoints' => 4000,
            'smsUser' => 'must-not-persist-user',
            'smsKey' => 'must-not-persist-secret',
            'smsFrom' => 'must-not-persist-sender',
        ],
    ], csrf_headers());
    check($settingsWrite['status'] === 201, 'manager can save settings while stripping SMS credentials');
    $settingsRead = api('GET', '/api/v1/records/settings');
    check($settingsRead['status'] === 200 && count($settingsRead['json']['items'] ?? []) === 1, 'manager can read settings');
    foreach (['smsUser', 'smsKey', 'smsFrom'] as $secretField) {
        check(!array_key_exists($secretField, $settingsRead['json']['items'][0]), 'settings API never returns ' . $secretField);
    }
    $storedSettings = $getPayload($pdo, 'settings', 'main');
    foreach (['smsUser', 'smsKey', 'smsFrom'] as $secretField) {
        check(!array_key_exists($secretField, $storedSettings), 'settings database never stores ' . $secretField);
    }
    $backup = api('GET', '/api/v1/backup/export');
    $backupData = $backup['json']['backup'] ?? [];
    check($backup['status'] === 200 && ($backupData['format'] ?? '') === '3food-pos-mysql-backup', 'manager can export MySQL backup');
    check(count($backupData['orders'] ?? []) >= 1 && count($backupData['order_effects'] ?? []) >= 2, 'backup includes all order history and reversible effect ledger: ' . count($backupData['orders'] ?? []) . '/' . count($backupData['order_effects'] ?? []));
    check(count($backupData['terminal_agents'] ?? []) === 1 && count($backupData['payment_terminals'] ?? []) === 1 && count($backupData['terminal_payments'] ?? []) === 2, 'backup includes terminal setup and payment ledger');
    check(!str_contains(json_encode($backupData['terminal_agents']), 'token_hash') && !str_contains(json_encode($backupData['terminal_agents']), $agentToken), 'backup never exports agent credentials');
    $backupSettings = [];
    foreach ($backupData['records'] ?? [] as $record) {
        if (($record['resource'] ?? '') === 'settings') $backupSettings = $record['payload'] ?? [];
    }
    foreach (['smsUser', 'smsKey', 'smsFrom'] as $secretField) {
        check(!array_key_exists($secretField, $backupSettings), 'backup never exports ' . $secretField);
    }
    $occupiedRestore = api('POST', '/api/v1/backup/restore', ['backup' => $backupData], csrf_headers());
    check($occupiedRestore['status'] === 409 && ($occupiedRestore['json']['error'] ?? '') === 'restore_requires_empty_pos', 'restore refuses populated database (HTTP ' . $occupiedRestore['status'] . ', ' . ($occupiedRestore['json']['error'] ?? 'no_error') . ')');
    $pdo->exec('DELETE FROM pos_terminal_payments');
    $pdo->exec('DELETE FROM pos_payment_terminals');
    $pdo->exec('DELETE FROM app_terminal_agents');
    $pdo->exec('DELETE FROM pos_discount_redemptions');
    $pdo->exec('DELETE FROM pos_order_effects');
    $pdo->exec('DELETE FROM pos_orders');
    $pdo->exec('DELETE FROM pos_records');
    $pdo->exec("UPDATE pos_counters SET counter_value = 0 WHERE counter_key = 'order_number'");
    foreach ($backupData['records'] as &$backupRecord) {
        if (($backupRecord['resource'] ?? '') === 'settings') {
            $backupRecord['payload']['smsUser'] = 'must-not-restore';
            $backupRecord['payload']['smsKey'] = 'must-not-restore';
            $backupRecord['payload']['smsFrom'] = 'must-not-restore';
        }
    }
    unset($backupRecord);
    $restored = api('POST', '/api/v1/backup/restore', ['backup' => $backupData], csrf_headers());
    check($restored['status'] === 200 && ($restored['json']['restored']['orders'] ?? 0) === count($backupData['orders']), 'manager restores complete backup into empty POS database');
    check((int)$pdo->query('SELECT COUNT(*) FROM pos_records')->fetchColumn() === count($backupData['records']), 'restore reconstructs all POS records');
    $restoredSettings = $getPayload($pdo, 'settings', 'main');
    foreach (['smsUser', 'smsKey', 'smsFrom'] as $secretField) {
        check(!array_key_exists($secretField, $restoredSettings), 'restore strips untrusted ' . $secretField);
    }
    check((int)$pdo->query('SELECT COUNT(*) FROM pos_order_effects')->fetchColumn() === count($backupData['order_effects']), 'restore reconstructs reversible order effects');
    check((int)$pdo->query('SELECT COUNT(*) FROM pos_discount_redemptions')->fetchColumn() === count($backupData['discount_redemptions']), 'restore reconstructs discount redemption ledger');
    check((int)$pdo->query('SELECT COUNT(*) FROM app_terminal_agents WHERE enabled=0')->fetchColumn() === count($backupData['terminal_agents']), 'restore recreates agents disabled so they must be securely paired again');
    check((int)$pdo->query('SELECT COUNT(*) FROM pos_payment_terminals WHERE enabled=0')->fetchColumn() === count($backupData['payment_terminals']), 'restore recreates terminal configs disabled');
    check((int)$pdo->query('SELECT COUNT(*) FROM pos_terminal_payments')->fetchColumn() === count($backupData['terminal_payments']), 'restore reconstructs terminal payment ledger');
    check((int)$pdo->query('SELECT counter_value FROM pos_counters WHERE counter_key = "order_number"')->fetchColumn() === (int)$backupData['counters'][0]['counter_value'], 'restore preserves order number counter');

    $guestOrder = api('POST', '/api/v1/orders', [
        'idempotencyKey' => '44444444-4444-4444-8444-444444444444',
        'items' => [['key' => 'ci-guest-line', 'productId' => 'ci-product', 'qty' => 1, 'unitPrice' => 1000, 'optionIds' => []]],
        'totals' => ['subtotal' => 1000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 90, 'total' => 1090],
        'guestName' => 'Guest Test',
        'guestPhone' => '09120000000',
        'payment' => 'cash',
    ], csrf_headers());
    check($guestOrder['status'] === 201, 'create guest order with cash payment');
    $guestSnapshot = api('GET', '/api/v1/bootstrap');
    $guestData = $guestSnapshot['json']['data']['orders'][0] ?? [];
    check(($guestData['customerName'] ?? '') === 'Guest Test' && ($guestData['customerPhone'] ?? '') === '09120000000', 'bootstrap preserves guest identity');
    check(($guestData['payment'] ?? '') === 'cash', 'bootstrap preserves guest payment method');
    $guestOrderId = (string)($guestOrder['json']['order']['id'] ?? '');



    // Keep this order new until the cashier authorization check below.

    $smsLogWrite = api('POST', '/api/v1/records/sms_logs', ['item' => [
        'id' => 'ci-sms-log', 'at' => gmdate('c'), 'to' => '09120000000', 'event' => 'test',
        'text' => 'CI SMS log', 'ok' => false, 'detail' => 'test failure',
    ]], csrf_headers());
    check($smsLogWrite['status'] === 201, 'manager can persist SMS delivery log');
    $smsSnapshot = api('GET', '/api/v1/bootstrap');
    check(($smsSnapshot['json']['data']['sms_logs'][0]['id'] ?? '') === 'ci-sms-log', 'manager bootstrap includes persistent SMS logs');

    $logout = api('POST', '/api/v1/auth/logout', [], csrf_headers());
    check($logout['status'] === 200, 'manager logout');
    $csrf = '';

    $cashierLogin = api('POST', '/api/v1/auth/login', [
        'username' => 'ci_cashier',
        'password' => 'CI-Cashier-Password-2026!',
    ]);
    check($cashierLogin['status'] === 200 && ($cashierLogin['json']['user']['role'] ?? '') === 'cashier', 'cashier login');
    $cashierCannotReadManagerPayment = api('GET', '/api/v1/terminal-payments/' . rawurlencode($terminalPaymentId));
    check($cashierCannotReadManagerPayment['status'] === 404 && ($cashierCannotReadManagerPayment['json']['error'] ?? '') === 'terminal_payment_not_found', 'cashier cannot read another users terminal payment');
    $cashierEdit = api('PATCH', '/api/v1/orders/' . rawurlencode($guestOrderId), [
        'items' => [['key' => 'ci-guest-line', 'productId' => 'ci-product', 'qty' => 1, 'unitPrice' => 1000, 'optionIds' => []]],
        'totals' => ['subtotal' => 1000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 0, 'total' => 1000],
        'guestName' => 'Guest Test',
        'guestPhone' => '09120000000',
        'payment' => 'online',
    ], csrf_headers());
    check($cashierEdit['status'] === 403, 'cashier cannot edit orders');
    $cashierBackup = api('GET', '/api/v1/backup/export');
    check($cashierBackup['status'] === 403, 'cashier cannot export full customer and order backup');
    $cashierRestore = api('POST', '/api/v1/backup/restore', ['backup' => $backupData], csrf_headers());
    check($cashierRestore['status'] === 403, 'cashier cannot restore MySQL backup');
    $csrf = (string)($cashierLogin['json']['csrf_token'] ?? '');
    check(strlen($csrf) >= 32, 'cashier receives CSRF token');

    $cashierBootstrap = api('GET', '/api/v1/bootstrap');
    check($cashierBootstrap['status'] === 200, 'cashier bootstrap');
    foreach (['expenses', 'templates'] as $restrictedResource) {
        check(!array_key_exists($restrictedResource, $cashierBootstrap['json']['data'] ?? []), 'cashier bootstrap excludes ' . $restrictedResource);
    }
    $cashierIngredient = array_values(array_filter($cashierBootstrap['json']['data']['ingredients'] ?? [], fn($item) => ($item['id'] ?? '') === 'ci-ingredient'))[0] ?? [];
    check((float)($cashierIngredient['stock'] ?? -1) >= 0 && ($cashierIngredient['name'] ?? '') !== '', 'cashier receives ingredient stock needed for checkout warnings');
    foreach (['costPerUnit', 'cost', 'unitCost'] as $costField) {
        check(!array_key_exists($costField, $cashierIngredient), 'cashier bootstrap hides ingredient ' . $costField);
    }
    $cashierSettings = $cashierBootstrap['json']['data']['settings'][0] ?? [];
    check((float)($cashierSettings['taxPercent'] ?? -1) === 9.0 && ($cashierSettings['taxEnabled'] ?? false) === true, 'cashier bootstrap receives authoritative tax settings');
    check((int)($cashierSettings['pointsPerToman'] ?? 0) === 20000 && (int)($cashierSettings['tomanPerPoint'] ?? 0) === 500, 'cashier bootstrap receives authoritative loyalty settings');
    foreach (['smsUser', 'smsKey', 'smsFrom'] as $secretField) {
        check(!array_key_exists($secretField, $cashierSettings), 'cashier bootstrap never exposes ' . $secretField);
    }

    $cashierUsers = api('GET', '/api/v1/users');
    check($cashierUsers['status'] === 403, 'cashier cannot list user accounts');
    $cashierProducts = api('GET', '/api/v1/records/products');
    check($cashierProducts['status'] === 403, 'cashier cannot read manager-only products');
    $cashierCustomers = api('GET', '/api/v1/records/customers');
    check($cashierCustomers['status'] === 200, 'cashier can read customers');
    $cashierCustomerWrite = api('POST', '/api/v1/records/customers', [
        'item' => ['id' => 'ci-customer-updated', 'name' => 'Cashier Added Customer', 'phone' => '09111111111'],
    ], csrf_headers());
    check($cashierCustomerWrite['status'] === 201, 'cashier can add customer');
    check(($cashierCustomerWrite['json']['item']['points'] ?? -1) === 0, 'cashier cannot assign initial loyalty points');
    $cashierExistingCustomerWrite = api('POST', '/api/v1/records/customers', [
        'item' => ['id' => 'ci-customer', 'name' => 'CI Customer Updated', 'phone' => '09000000000', 'points' => 99999, 'totalSpent' => 999999, 'visits' => 99],
    ], csrf_headers());
    check($cashierExistingCustomerWrite['status'] === 201, 'cashier can update customer contact details');
    check(($cashierExistingCustomerWrite['json']['item']['points'] ?? -1) === 0 && ($cashierExistingCustomerWrite['json']['item']['totalSpent'] ?? -1) === 0 && ($cashierExistingCustomerWrite['json']['item']['visits'] ?? -1) === 0, 'cashier cannot modify existing loyalty counters');
    $cashierCustomerDelete = api('DELETE', '/api/v1/records/customers?id=ci-customer-updated', null, csrf_headers());
    check($cashierCustomerDelete['status'] === 403, 'cashier cannot delete customer records');

    // Disabling an account must invalidate its already-issued PHP session.
    $cashierSessionCookie = $cookie;
    check($cashierSessionCookie !== '', 'cashier session cookie captured');
    $cashierLogout = api('POST', '/api/v1/auth/logout', [], csrf_headers());
    check($cashierLogout['status'] === 200, 'cashier logout before manager revocation');
    $csrf = '';
    $managerRelogin = api('POST', '/api/v1/auth/login', [
        'username' => 'ci_manager',
        'password' => 'CI-Only-New-Password-2026!',
    ]);
    check($managerRelogin['status'] === 200 && ($managerRelogin['json']['user']['role'] ?? '') === 'manager', 'manager relogin for account revocation');
    $csrf = (string)($managerRelogin['json']['csrf_token'] ?? '');
    check(strlen($csrf) >= 32, 'manager receives fresh CSRF token');
    $guestCancel = api('PATCH', '/api/v1/orders/' . rawurlencode($guestOrderId) . '/status', ['status' => 'cancelled'], csrf_headers());
    check($guestCancel['status'] === 200, 'cancel guest order after persistence check');
    $cashierId = (int)($cashierCreated['json']['user']['id'] ?? 0);
    check($cashierId > 0, 'cashier account id returned');
    $disableCashier = api('PATCH', '/api/v1/users/' . $cashierId, ['is_active' => false], csrf_headers());
    check($disableCashier['status'] === 200, 'manager disables cashier account');
    $managerSessionCookie = $cookie;
    $cookie = $cashierSessionCookie;
    $revokedSession = api('GET', '/api/v1/auth/me');
    check($revokedSession['status'] === 200 && ($revokedSession['json']['authenticated'] ?? true) === false, 'disabled cashier existing session is revoked');
    $cookie = $managerSessionCookie;

    $stockDown = api('POST', '/api/v1/ingredients/ci-ingredient/stock', ['delta' => -2], csrf_headers());
    check($stockDown['status'] === 200 && (float)($stockDown['json']['item']['stock'] ?? -1) === 8.0, 'atomic stock adjustment applies against current server value');
    $stockUnderflow = api('POST', '/api/v1/ingredients/ci-ingredient/stock', ['delta' => -20], csrf_headers());
    check($stockUnderflow['status'] === 409 && ($stockUnderflow['json']['error'] ?? '') === 'insufficient_stock', 'stock adjustment cannot make inventory negative');
    $stockUp = api('POST', '/api/v1/ingredients/ci-ingredient/stock', ['delta' => 3], csrf_headers());
    check($stockUp['status'] === 200 && (float)($stockUp['json']['item']['stock'] ?? -1) === 11.0, 'stock increment uses locked current value');
    $invalidStockDelta = api('POST', '/api/v1/ingredients/ci-ingredient/stock', ['delta' => 0], csrf_headers());
    check($invalidStockDelta['status'] === 422, 'zero stock adjustment is rejected');

    // Two different PHP sessions submit orders concurrently against eight
    // units of ingredient stock; each order needs six, so exactly one may commit.
    $managerPrimaryCookie = $cookie;
    $managerPrimaryCsrf = $csrf;
    $stockToEight = api('POST', '/api/v1/ingredients/ci-ingredient/stock', ['delta' => -3], csrf_headers());
    check($stockToEight['status'] === 200 && (float)($stockToEight['json']['item']['stock'] ?? -1) === 8.0, 'prepare constrained stock for concurrency test');

    $cookie = '';
    $secondManagerLogin = api('POST', '/api/v1/auth/login', [
        'username' => 'ci_manager',
        'password' => 'CI-Only-New-Password-2026!',
    ]);
    check($secondManagerLogin['status'] === 200, 'second independent manager session for concurrency test');
    $managerSecondCookie = $cookie;
    $managerSecondCsrf = (string)($secondManagerLogin['json']['csrf_token'] ?? '');
    check($managerSecondCookie !== '' && strlen($managerSecondCsrf) >= 32, 'second manager session credentials');
    $cookie = $managerPrimaryCookie;
    $csrf = $managerPrimaryCsrf;

    $concurrentBodies = [];
    foreach ([4, 5] as $suffix) {
        $concurrentBodies[] = [
            'idempotencyKey' => sprintf('44444444-4444-4444-8444-%012d', $suffix),
            'items' => [['key' => 'ci-concurrent-' . $suffix, 'productId' => 'ci-product', 'qty' => 3, 'unitPrice' => 1000, 'optionIds' => []]],
            'customer' => ['id' => 'ci-customer'],
            'totals' => ['subtotal' => 3000, 'tierDiscount' => 0, 'codeDiscount' => 0, 'pointsDiscount' => 0, 'tax' => 270, 'total' => 3270],
            'note' => 'Concurrent stock safety test',
        ];
    }
    // Do not fork while a PDO/MySQL connection is open: inherited PDO handles
    // share one logical MySQL session, and a child destructor can send COM_QUIT.
    $pdo = null;
    $concurrentResults = concurrent_order_attempts([
        ['cookie' => $managerPrimaryCookie, 'csrf' => $managerPrimaryCsrf, 'body' => $concurrentBodies[0]],
        ['cookie' => $managerSecondCookie, 'csrf' => $managerSecondCsrf, 'body' => $concurrentBodies[1]],
    ]);
    // Reopen the inspection connection only after all forked clients have exited.
    $pdo = new PDO(
        'mysql:host=' . (getenv('MYSQL_TEST_HOST') ?: '127.0.0.1') . ';port=' . (getenv('MYSQL_TEST_PORT') ?: '3306') . ';dbname=' . (getenv('MYSQL_TEST_DATABASE') ?: 'threefood_test') . ';charset=utf8mb4',
        (string)(getenv('MYSQL_TEST_USERNAME') ?: 'root'),
        (string)(getenv('MYSQL_TEST_PASSWORD') ?: 'root-ci-password'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $concurrentStatuses = array_column($concurrentResults, 'status');
    sort($concurrentStatuses);
    check($concurrentStatuses === [201, 201], 'concurrent orders both persist when stock is depleted; responses=' . json_encode($concurrentResults));
    $stockAfterRace = $getPayload($pdo, 'ingredients', 'ci-ingredient');
    check((float)($stockAfterRace['stock'] ?? -1) === -4.0, 'concurrent checkout records negative stock for replenishment');
    $orderCountAfterRace = (int)$pdo->query('SELECT COUNT(*) FROM pos_orders')->fetchColumn();
    check($orderCountAfterRace >= 4, 'both concurrent orders persist with the earlier checkout fixtures');

    $kitchenLogin = api('POST', '/api/v1/auth/login', [
        'username' => 'ci_kitchen',
        'password' => 'CI-Kitchen-Password-2026!',
    ]);
    check($kitchenLogin['status'] === 200 && ($kitchenLogin['json']['user']['role'] ?? '') === 'kitchen', 'kitchen login');
    $kitchenBootstrap = api('GET', '/api/v1/bootstrap');
    check($kitchenBootstrap['status'] === 200, 'kitchen bootstrap');
    $kitchenData = $kitchenBootstrap['json']['data'] ?? [];
    check(array_keys($kitchenData) === ['categories', 'products', 'orders'], 'kitchen bootstrap contains only preparation resources');
    check(!array_key_exists('customerName', $kitchenData['orders'][0] ?? []), 'kitchen cannot see customer name');
    check(!array_key_exists('customerPhone', $kitchenData['orders'][0] ?? []), 'kitchen cannot see customer phone');
    check(!array_key_exists('customerId', $kitchenData['orders'][0] ?? []), 'kitchen cannot see customer id');
    check(!array_key_exists('note', $kitchenData['orders'][0] ?? []), 'kitchen cannot see private order note');

    // Eight failed attempts are allowed; the ninth is throttled for this IP.
    $lastLogin = [];
    for ($attempt = 1; $attempt <= 9; $attempt++) {
        $lastLogin = api('POST', '/api/v1/auth/login', [
            'username' => 'ci_lockout',
            'password' => 'Definitely-Wrong-Password!',
        ]);
        if ($attempt < 9) check($lastLogin['status'] === 401, 'failed login attempt ' . $attempt);
    }
    check($lastLogin['status'] === 429 && ($lastLogin['json']['error'] ?? '') === 'too_many_attempts', 'login rate limit activates after eight failures');

    echo "PASS: PHP API integration (setup, session/CSRF, records, canonical pricing, idempotency, transactional stock adjustment, stock underflow protection, loyalty, cancellation rollback).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
