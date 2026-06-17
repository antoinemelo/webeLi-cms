<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /**
     * @param list<array{0:string,1:string,2:string}> $routes
     * @return array{handler:string,params:array<string,string|int>}|null
     */
    public function match(string $method, string $path, array $routes): ?array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        foreach ($routes as $route) {
            [$routeMethod, $pattern, $handler] = $route;
            if (strtoupper($routeMethod) !== $method) {
                continue;
            }

            $names = [];
            $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#', static function (array $m) use (&$names): string {
                $names[] = $m[1];
                return '(' . ($m[2] ?? '[^/]+') . ')';
            }, $pattern);
            $regex = '#^' . $regex . '$#u';

            if (!preg_match($regex, $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($names as $i => $name) {
                $value = $matches[$i + 1] ?? '';
                $params[$name] = ctype_digit($value) ? (int) $value : $value;
            }

            return ['handler' => $handler, 'params' => $params];
        }

        return null;
    }
}
