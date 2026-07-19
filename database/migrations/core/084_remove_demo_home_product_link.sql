PRAGMA foreign_keys = ON;

-- The historical demo seed attached product 1 ("Vol découverte") to the
-- homepage. It was rendered as editorial content even when Shop was disabled.
-- Restrict the cleanup to the exact seeded relation so user-created links stay.
DELETE FROM business_product_content_links
WHERE site_id = 1
  AND product_id = 1
  AND relation_type = 'storytelling'
  AND locale IS NULL
  AND is_canonical = 1
  AND status = 'active'
  AND seo_config_json = '{"schema_type":"Service"}'
  AND content_entry_id IN (
      SELECT id FROM content_entries WHERE site_id = 1 AND entry_key = 'home'
  );
