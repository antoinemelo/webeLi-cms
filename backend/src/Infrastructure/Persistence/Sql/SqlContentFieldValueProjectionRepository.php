<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sql;

use App\Application\Content\ContentFieldValueProjectionRepository;
use App\Core\Database;
use App\Domain\Schema\ContentType;
use App\Domain\Schema\FieldDefinition;
use App\Domain\Schema\FieldType;

final class SqlContentFieldValueProjectionRepository implements ContentFieldValueProjectionRepository
{
    public function __construct(private readonly Database $db) {}

    public function replaceDraftIndexForRevision(
        int $entryId,
        int $localizationId,
        int $contentTypeId,
        ContentType $contentType,
        string $languageCode,
        int $revisionId,
        array $document
    ): void {
        $this->db->run(
            "DELETE FROM content_entry_field_values WHERE entry_id = :entry_id AND language_code = :language_code AND projection_scope = 'draft_index'",
            ['entry_id' => $entryId, 'language_code' => $languageCode]
        );

        $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
        $checksum = $this->revisionChecksum($revisionId);
        foreach ($contentType->fields as $field) {
            if (!$field instanceof FieldDefinition) {
                continue;
            }
            $fieldId = $this->fieldId($contentTypeId, $field->fieldKey);
            if ($fieldId === null) {
                continue;
            }
            $values = $this->rowsForValue($field, $fields[$field->fieldKey] ?? null);
            foreach ($values as $sortOrder => $value) {
                $this->insertRow(
                    $entryId,
                    $localizationId,
                    $contentTypeId,
                    $fieldId,
                    $field,
                    $languageCode,
                    $revisionId,
                    $checksum,
                    $sortOrder,
                    $value
                );
            }
        }
    }

    private function revisionChecksum(int $revisionId): ?string
    {
        $row = $this->db->one('SELECT checksum_sha256 FROM revisions WHERE id = :id LIMIT 1', ['id' => $revisionId]);
        return $row && isset($row['checksum_sha256']) ? (string) $row['checksum_sha256'] : null;
    }

    private function fieldId(int $contentTypeId, string $fieldKey): ?int
    {
        $row = $this->db->one(
            'SELECT id FROM fields WHERE content_type_id = :content_type_id AND field_key = :field_key LIMIT 1',
            ['content_type_id' => $contentTypeId, 'field_key' => $fieldKey]
        );
        return $row ? (int) $row['id'] : null;
    }

    /** @return list<mixed> */
    private function rowsForValue(FieldDefinition $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (in_array($field->fieldType, [FieldType::MULTISELECT, FieldType::MEDIA, FieldType::RELATION], true)) {
            return array_values((array) $value);
        }
        return [$value];
    }

    private function insertRow(
        int $entryId,
        int $localizationId,
        int $contentTypeId,
        int $fieldId,
        FieldDefinition $field,
        string $languageCode,
        int $revisionId,
        ?string $checksum,
        int $sortOrder,
        mixed $value
    ): void {
        $params = [
            'entry_id' => $entryId,
            'localization_id' => $localizationId > 0 ? $localizationId : null,
            'content_type_id' => $contentTypeId,
            'field_id' => $fieldId,
            'field_key' => $field->fieldKey,
            'language_code' => $languageCode,
            'source_revision_id' => $revisionId,
            'source_revision_checksum_sha256' => $checksum,
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_date' => null,
            'value_datetime' => null,
            'value_json' => null,
            'value_media_id' => null,
            'value_relation_entry_id' => null,
            'sort_order' => $sortOrder,
            'projected_at' => now_utc(),
        ];

        switch ($field->fieldType) {
            case FieldType::NUMBER:
                if (is_numeric($value)) {
                    $params['value_number'] = (float) $value;
                } else {
                    $params['value_text'] = (string) $value;
                }
                break;
            case FieldType::BOOLEAN:
                $params['value_boolean'] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                break;
            case FieldType::DATE:
                $params['value_date'] = (string) $value;
                break;
            case FieldType::DATETIME:
                $params['value_datetime'] = (string) $value;
                break;
            case FieldType::JSON:
            case FieldType::MULTISELECT:
                $params['value_json'] = $this->json($value);
                break;
            case FieldType::MEDIA:
                $params['value_media_id'] = $this->positiveInt($value);
                break;
            case FieldType::RELATION:
                $params['value_relation_entry_id'] = $this->positiveInt($value);
                break;
            default:
                $params['value_text'] = is_scalar($value) ? (string) $value : $this->json($value);
        }

        $this->db->run(
            'INSERT INTO content_entry_field_values(
                entry_id, localization_id, content_type_id, field_id, field_key, language_code, projection_scope,
                source_revision_id, source_revision_checksum_sha256,
                value_text, value_number, value_boolean, value_date, value_datetime, value_json,
                value_media_id, value_relation_entry_id, sort_order, projected_at
            ) VALUES(
                :entry_id, :localization_id, :content_type_id, :field_id, :field_key, :language_code, \'draft_index\',
                :source_revision_id, :source_revision_checksum_sha256,
                :value_text, :value_number, :value_boolean, :value_date, :value_datetime, :value_json,
                :value_media_id, :value_relation_entry_id, :sort_order, :projected_at
            )',
            $params
        );
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_array($value) && isset($value['id'])) {
            $value = $value['id'];
        }
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Impossible de sérialiser une valeur de champ projetée.');
        }
        return $json;
    }
}
