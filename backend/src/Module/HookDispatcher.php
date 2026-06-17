<?php

declare(strict_types=1);

namespace App\Module;

use App\Core\Logger;

final class HookDispatcher
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ?Logger $logger = null,
    ) {}

    /** @param array<string,mixed> $payload */
    public function dispatch(string $hookName, array $payload = []): void
    {
        foreach ($this->handlers($hookName) as $handler) {
            $this->callHandler($hookName, $handler, $payload);
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function filter(string $hookName, array $payload = []): array
    {
        foreach ($this->handlers($hookName) as $handler) {
            $result = $this->callHandler($hookName, $handler, $payload);
            if (is_array($result)) {
                $payload = array_replace_recursive($payload, $result);
            }
        }
        return $payload;
    }

    /** @return list<callable|string> */
    private function handlers(string $hookName): array
    {
        return $this->registry->hooks()[$hookName] ?? [];
    }

    /** @param callable|string $handler @param array<string,mixed> $payload */
    private function callHandler(string $hookName, callable|string $handler, array $payload): mixed
    {
        try {
            if (is_callable($handler)) {
                return $handler($payload);
            }
            if (is_string($handler) && class_exists($handler)) {
                $instance = new $handler();
                if (is_callable($instance)) {
                    return $instance($payload);
                }
            }
            if (is_string($handler) && str_contains($handler, '::') && is_callable($handler)) {
                return $handler($payload);
            }
            $this->logger?->warning('module.hook_handler_invalid', ['hook' => $hookName, 'handler' => is_string($handler) ? $handler : 'callable']);
        } catch (\Throwable $e) {
            $this->logger?->error('module.hook_handler_failed', ['hook' => $hookName, 'error' => $e->getMessage()]);
            throw $e;
        }
        return null;
    }
}
