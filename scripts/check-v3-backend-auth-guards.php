<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\SessionCookie;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
use PersiaCoder\PosV3\Http\Router;
use PersiaCoder\PosV3\Security\CsrfGuard;

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

$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$principal = new AuthenticatedPrincipal(1, 2, 'user-public-id', 'cashier', 'Cashier', false, hash('sha256', $token), new DateTimeImmutable('+1 hour'));
$guard = new CsrfGuard();
$guard->assertValid(new Request('GET', '/api/v1/orders', [], '', 'test-request-0010'), $principal);

try {
    $guard->assertValid(new Request('POST', '/api/v1/orders', [], '', 'test-request-0011'), $principal);
    throw new RuntimeException('Mutating requests must require CSRF.');
} catch (HttpException $exception) {
    $assert($exception->status === 403 && $exception->errorCode === 'csrf_token_missing', 'Missing CSRF token was not rejected.');
}

$guard->assertValid(new Request('POST', '/api/v1/orders', ['x-csrf-token' => $token, 'cookie' => 'pos_v3_csrf=' . $token], '', 'test-request-0012'), $principal);
try {
    $guard->assertValid(new Request('DELETE', '/api/v1/orders/1', ['x-csrf-token' => str_repeat('a', 43), 'cookie' => 'pos_v3_csrf=' . str_repeat('a', 43)], '', 'test-request-0013'), $principal);
    throw new RuntimeException('Invalid CSRF token must be rejected.');
} catch (HttpException $exception) {
    $assert($exception->errorCode === 'csrf_token_invalid', 'Invalid CSRF token error mismatch.');
}

$expiresAt = new DateTimeImmutable('+1 hour');
$cookie = SessionCookie::issue($token, $expiresAt, true);
$assert(str_starts_with($cookie, 'pos_v3_session='), 'Session cookie must start with its name and value.');
$assert(str_contains($cookie, 'Secure'), 'Production session cookie must be Secure.');
$assert(str_contains($cookie, 'HttpOnly'), 'Session cookie must be HttpOnly.');
$assert(str_contains($cookie, 'SameSite=Lax'), 'Session cookie must use SameSite=Lax.');
$assert(str_contains($cookie, 'Path=/'), 'Session cookie must use root path.');
$assert(str_contains(SessionCookie::clear(), 'Max-Age=0'), 'Logout cookie must expire immediately.');

$router = new Router();
$router->add(
    'POST',
    '/api/v1/middleware-check',
    static fn (Request $request): Response => Response::success(['ordered' => $request->attribute('first') === true && $request->attribute('second') === true]),
    [
        static fn (Request $request, array $params, callable $next): Response => $next($request->withAttribute('first', true)),
        static fn (Request $request, array $params, callable $next): Response => $next($request->withAttribute('second', $request->attribute('first') === true)),
    ],
);
$middlewareResponse = $router->dispatch(new Request('POST', '/api/v1/middleware-check', [], '', 'test-request-0014'));
$assert(str_contains($middlewareResponse->body, '"ordered":true'), 'Route middleware must execute in registration order.');

echo "V3 backend authentication guard checks passed.\n";
