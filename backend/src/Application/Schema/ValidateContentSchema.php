<?php

declare(strict_types=1);

namespace App\Application\Schema;

use App\Domain\Schema\ContentType;
use App\Domain\Schema\FieldDefinition;

final class ValidateContentSchema
{
    /** @return list<string> */
    public function execute(ContentType $contentType): array
    {
        $errors = [];
        $seen = [];
        foreach ($contentType->fields as $field) {
            if (!$field instanceof FieldDefinition) {
                $errors[] = 'Le schéma contient un champ invalide.';
                continue;
            }
            if (isset($seen[$field->fieldKey])) {
                $errors[] = sprintf('Champ dupliqué : %s.', $field->fieldKey);
            }
            $seen[$field->fieldKey] = true;
            if ($field->isTranslatable() && !$contentType->acceptsLocalizations()) {
                $errors[] = sprintf('Le champ "%s" est localisé, mais le type "%s" ne supporte pas les localisations.', $field->fieldKey, $contentType->typeKey);
            }
            if ($field->type()->supportsOptions() && $field->fieldType !== 'media' && $field->options === []) {
                $errors[] = sprintf('Le champ "%s" devrait déclarer ses options.', $field->fieldKey);
            }
        }

        if ($contentType->supportsWorkflow() && trim($contentType->defaultStatus) === '') {
            $errors[] = 'Le statut par défaut est obligatoire quand le workflow est activé.';
        }
        if ($contentType->allowsSeo() && $contentType->effectiveSeoPolicy()->metaTitleMaxLength < 30) {
            $errors[] = 'La longueur maximale du titre SEO est trop basse.';
        }
        if (!$contentType->allowsTaxonomies() && $contentType->effectiveTaxonomyPolicy()->allowedTaxonomyKeys !== []) {
            $errors[] = 'Une politique de taxonomie est déclarée alors que les taxonomies sont désactivées.';
        }

        return $errors;
    }

    public function assertValid(ContentType $contentType): void
    {
        $errors = $this->execute($contentType);
        if ($errors !== []) {
            throw new \InvalidArgumentException("Schéma de type de contenu invalide :\n- " . implode("\n- ", $errors));
        }
    }
}
