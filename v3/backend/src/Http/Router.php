<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Http;

use Closure;

final class Router
{
    /** @var list<array{method:string,pattern:string,names:list<string>,handler:Closure,middleware:list<Closure>}> */
    private array $routes = [];

    /**
     * @param callable(Request, array<string, string>): Response $handler
     * @param list<callable(Request, array<string, string>, callable): Response> $middleware
     */
    public function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '?') || str_contains($path, '#')) {
            throw new \InvalidArgumentException('Route path must be an absolute path without query or fragment.');
        }

        preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $path, $matches);
        $names = $matches[1];
        if (count($names) !== count(array_unique($names))) {
            throw new \InvalidArgumentException('Route parameter names must be unique.');
        }

        $quoted = preg_quote($path, '~');
        $pattern = preg_replace_callback('/\\\{([A-Za-z][A-Za-z0-9_]*)\\\}/', static fn (array $m): string => '([^/]+)', $quoted);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '~^' . $pattern . '$~D',
            'names' => $names,
            'handler' => Closure::fromCallable($handler),
            'middleware' => array_map(Closure::fromCallable(...), $middleware),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->path, $matches)) continue;
            $pathMatched = true;
            if ($route['method'] !== $request->method) continue;

            array_shift($matches);
            $parameters = [];
            foreach ($route['names'] as $index => $name) {
                $value = rawurldecode($matches[$index] ?? '');
                if ($value === '' || str_contains($value, '/')) {
                    throw new HttpException(400, 'invalid_route_parameter', 'A route parameter is invalid.');
                }
                $parameters[$name] = $value;
            }
            $next = static fn (Request $current): Response => ($route['handler'])($current, $parameters);
            foreach (array_reverse($route['middleware']) as $middleware) {
                $previous = $next;
                $next = static fn (Request $current): Response => $middleware($current, $parameters, $previous);
            }
            return $next($request);
        }

        if ($pathMatched) {
            throw new HttpException(405, 'method_not_allowed', 'The HTTP method is not allowed for this endpoint.');
        }
        throw new HttpException(404, 'not_found', 'The requested resource was not found.');
    }
}
