<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Kernel;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
use PersiaCoder\PosV3\Http\Router;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PersiaCoder\\PosV3\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/v3/backend/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$router = new Router();
$router->add('GET', '/api/v1/health', static fn (Request $request): Response => Response::success(['status' => 'ok']));
$router->add('GET', '/api/v1/orders/{orderId}', static function (Request $request, array $params): Response {
    return Response::success(['id' => $params['orderId']]);
});
$kernel = new Kernel($router);

$health = $kernel->handle(new Request('GET', '/api/v1/health', [], '', 'test-request-0001'));
$assert($health->status === 200, 'Health route must return 200.');
$assert(str_contains($health->body, '"data":{"status":"ok"}'), 'Success envelope missing.');
$assert(($health->headers['X-Request-ID'] ?? '') === 'test-request-0001', 'Request ID response header missing.');

$order = $kernel->handle(new Request('GET', '/api/v1/orders/ord-123', [], '', 'test-request-0002'));
$assert($order->status === 200 && str_contains($order->body, '"id":"ord-123"'), 'Route parameter dispatch failed.');

$missing = $kernel->handle(new Request('GET', '/api/v1/missing', [], '', 'test-request-0003'));
$assert($missing->status === 404 && str_contains($missing->body, '"code":"not_found"'), '404 mapping failed.');

$method = $kernel->handle(new Request('POST', '/api/v1/health', [], '', 'test-request-0004'));
$assert($method->status === 405 && str_contains($method->body, '"code":"method_not_allowed"'), '405 mapping failed.');

try {
    (new Request('POST', '/api/v1/health', [], '{invalid', 'test-request-0005'))->json();
    throw new RuntimeException('Invalid JSON must throw an HTTP exception.');
} catch (HttpException $exception) {
    $assert($exception->status === 400 && $exception->errorCode === 'invalid_json', 'Invalid JSON error mapping failed.');
}
$emptyObject = (new Request('POST', '/api/v1/orders', ['content-type' => 'application/json'], '{}', 'test-request-0007'))->json();
$assert($emptyObject === [], 'An empty JSON object must be accepted.');
try {
    (new Request('POST', '/api/v1/orders', [], '[]', 'test-request-0008'))->json();
    throw new RuntimeException('A JSON array must not be accepted as an object body.');
} catch (HttpException $exception) {
    $assert($exception->errorCode === 'invalid_json_shape', 'JSON array shape validation failed.');
}

$router->add('GET', '/api/v1/failure', static function (): Response {
    throw new RuntimeException('Sensitive internal detail');
});
$failed = (new Kernel($router))->handle(new Request('GET', '/api/v1/failure', [], '', 'test-request-0006'));
$assert($failed->status === 500, 'Unexpected exception must return 500.');
$assert(!str_contains($failed->body, 'Sensitive internal detail'), 'Internal exception details leaked.');

echo "V3 backend HTTP checks passed.\n";
