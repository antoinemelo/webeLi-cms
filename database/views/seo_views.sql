CREATE VIEW IF NOT EXISTS v_seo_missing_meta_description AS
SELECT
    ce.site_id,
    'content_entry' AS resource_type,
    cep.entry_id AS resource_id,
    cep.language_code
FROM content_entry_publications cep
JOIN content_entries ce ON ce.id = cep.entry_id AND ce.site_id = cep.site_id
LEFT JOIN seo_metadata sm
  ON sm.site_id = cep.site_id
 AND sm.resource_type = 'content_entry'
 AND sm.resource_id = cep.entry_id
 AND sm.language_code = cep.language_code
WHERE cep.workflow_status = 'published'
  AND cep.published_revision_id IS NOT NULL
  AND (sm.id IS NULL OR sm.source_published_revision_id <> cep.published_revision_id OR COALESCE(sm.meta_description, '') = '');

CREATE VIEW IF NOT EXISTS v_routes_duplicates_check AS
SELECT site_id, language_code, full_path, COUNT(*) AS duplicates
FROM routes
GROUP BY site_id, language_code, full_path
HAVING COUNT(*) > 1;
