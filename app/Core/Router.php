<?php

declare(strict_types=1);

namespace TeamCodeHub\Core;

use Closure;
use InvalidArgumentException;

final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, names:list<string>, handler:Closure}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $method = strtoupper(trim($method));
        if ($method === '' || !str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException('Routes require an HTTP method and an absolute path.');
        }

        $names = [];
        $quoted = preg_quote(rtrim($pattern, '/') ?: '/', '~');
        $regex = preg_replace_callback('/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/', static function (array $match) use (&$names): string {
            $names[] = $match[1];
            return '([^/]+)';
        }, $quoted);

        if (!is_string($regex)) {
            throw new InvalidArgumentException('Invalid route pattern.');
        }

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '~^' . $regex . '/?$~D',
            'names' => $names,
            'handler' => Closure::fromCallable($handler),
        ];
    }

    public function get(string $pattern, callable $handler): void { $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, callable $handler): void { $this->add('POST', $pattern, $handler); }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['names'] as $index => $name) {
                $value = rawurldecode((string) ($matches[$index] ?? ''));
                if (str_contains($value, '/') || str_contains($value, "\0")) {
                    return Response::text('Not Found', 404);
                }
                $params[$name] = $value;
            }

            $response = ($route['handler'])($request, $params);
            return $response instanceof Response ? $response : Response::text('Internal Server Error', 500);
        }
        return Response::text('Not Found', 404);
    }
}
