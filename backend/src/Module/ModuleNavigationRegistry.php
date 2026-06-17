<?php

declare(strict_types=1);

namespace App\Module;

final class ModuleNavigationRegistry
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /** @return list<array<string,mixed>> */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->registry->activeProviders() as $provider) {
            foreach ($provider->adminNavigation() as $entry) {
                $entry['module_key'] = $provider->key();
                $entry['source'] = 'module';
                $entries[] = $entry;
            }
        }
        usort($entries, static fn(array $a, array $b): int => ((int) ($a['sort_order'] ?? 500)) <=> ((int) ($b['sort_order'] ?? 500)));
        return $entries;
    }
}
