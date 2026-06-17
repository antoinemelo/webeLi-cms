<?php

declare(strict_types=1);

namespace App\Application\Schema;

final class ListContentTypes
{
    public function __construct(private readonly ContentTypeRepository $contentTypes) {}

    /** @return list<array<string,mixed>> */
    public function execute(bool $adminOnly = true): array
    {
        return array_map(
            static fn($contentType): array => [
                'type_key' => $contentType->typeKey,
                'name' => $contentType->name,
                'singular_label' => $contentType->singularLabel,
                'plural_label' => $contentType->pluralLabel,
                'capabilities' => [
                    'localizations' => $contentType->acceptsLocalizations(),
                    'revisions' => $contentType->supportsRevisions(),
                    'workflow' => $contentType->supportsWorkflow(),
                    'permalink' => $contentType->supportsPermalink(),
                    'seo' => $contentType->allowsSeo(),
                    'taxonomies' => $contentType->allowsTaxonomies(),
                ],
                'field_count' => count($contentType->fields),
                'template' => $contentType->allowedTemplate(),
            ],
            $this->contentTypes->listEnabled($adminOnly)
        );
    }
}
