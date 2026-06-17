CREATE VIEW IF NOT EXISTS v_search_ready_entries AS
SELECT
    ce.site_id,
    ce.id AS entry_id,
    ct.type_key,
    cep.language_code,
    r.full_path,
    cep.published_revision_id AS source_published_revision_id,
    rv.checksum_sha256 AS source_revision_checksum_sha256,
    json_extract(rv.document_json, '$.content.title') AS title,
    COALESCE(
        json_extract(rv.document_json, '$.content.summary'),
        json_extract(rv.document_json, '$.content.excerpt'),
        ''
    ) AS summary
FROM content_entry_publications cep
JOIN content_entries ce ON ce.id = cep.entry_id AND ce.site_id = cep.site_id
JOIN content_types ct ON ct.id = ce.content_type_id
JOIN revisions rv ON rv.id = cep.published_revision_id
LEFT JOIN routes r
  ON r.resource_type = 'content_entry'
 AND r.resource_id = ce.id
 AND r.site_id = cep.site_id
 AND r.language_code = cep.language_code
 AND r.is_primary = 1
 AND r.status = 'active'
 AND r.source_published_revision_id = cep.published_revision_id
WHERE ce.is_active = 1
  AND cep.workflow_status = 'published'
  AND rv.workflow_status = 'published';
