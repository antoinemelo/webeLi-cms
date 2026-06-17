<?php

declare(strict_types=1);

namespace App\EditorialPackage;

final class EditorialBlueprint
{
    /** @param array<string,mixed> $definition @param list<array<string,mixed>> $blockBlueprints */
    public function __construct(
        public readonly string $blueprintKey,
        public readonly string $resourceType,
        public readonly int $version,
        public readonly string $label,
        public readonly array $definition,
        public readonly array $blockBlueprints = [],
    ) { new PortableKey($blueprintKey); }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'blueprint_key' => $this->blueprintKey,
            'resource_type' => $this->resourceType,
            'version' => $this->version,
            'status' => 'published',
            'is_active' => true,
            'label' => $this->label,
            'definition' => $this->definition,
            'block_blueprints' => array_values($this->blockBlueprints),
        ];
    }
}
