PRAGMA foreign_keys = ON;

CREATE TABLE media_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL,
    site_id INTEGER NOT NULL,
    storage_disk TEXT NOT NULL DEFAULT 'local',
    path TEXT NOT NULL,
    quarantine_path TEXT,
    public_path TEXT,
    filename TEXT NOT NULL,
    original_filename TEXT,
    extension TEXT,
    mime_type TEXT NOT NULL,
    media_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    width INTEGER,
    height INTEGER,
    sha256 TEXT NOT NULL,
    duplicate_of_media_id INTEGER,
    lifecycle_status TEXT NOT NULL DEFAULT 'quarantined',
    validation_status TEXT NOT NULL DEFAULT 'pending',
    validation_errors_json TEXT,
    variants_status TEXT NOT NULL DEFAULT 'pending',
    metadata_status TEXT NOT NULL DEFAULT 'incomplete',
    dominant_color TEXT,
    copyright_text TEXT,
    license_type TEXT,
    source_url TEXT,
    folder_id INTEGER NOT NULL,
    metadata_json TEXT,
    uploaded_by_user_id INTEGER,
    validated_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_requested_at TEXT,
    deleted_at TEXT
);

CREATE TABLE media_asset_localizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    media_id INTEGER NOT NULL,
    language_code TEXT NOT NULL,
    alt_text TEXT,
    caption TEXT,
    title TEXT,
    is_alt_verified INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(media_id, language_code)
);

INSERT INTO media_assets(id, uuid, site_id, path, public_path, filename, original_filename, extension, mime_type, media_type, size_bytes, width, height, sha256, lifecycle_status, validation_status, variants_status, metadata_status, folder_id, created_at, updated_at)
VALUES
    (1, '11111111-1111-4111-8111-111111111111', 1, 'public/1/test/image-a.jpg', 'public/1/test/image-a.jpg', 'image-a.jpg', 'image-a.jpg', 'jpg', 'image/jpeg', 'image', 1200, 120, 80, 'sha-image-a', 'ready', 'valid', 'ready', 'complete', 1, '2026-01-01 10:00:00', '2026-01-01 10:00:00'),
    (2, '22222222-2222-4222-8222-222222222222', 1, 'public/1/test/image-b.png', 'public/1/test/image-b.png', 'image-b.png', 'image-b.png', 'png', 'image/png', 'image', 1800, 160, 90, 'sha-image-b', 'ready', 'valid', 'ready', 'complete', 1, '2026-01-01 11:00:00', '2026-01-01 11:00:00'),
    (3, '33333333-3333-4333-8333-333333333333', 1, 'public/1/test/document-a.pdf', 'public/1/test/document-a.pdf', 'document-a.pdf', 'document-a.pdf', 'pdf', 'application/pdf', 'document', 2400, NULL, NULL, 'sha-document-a', 'ready', 'valid', 'ready', 'complete', 1, '2026-01-01 12:00:00', '2026-01-01 12:00:00'),
    (4, '44444444-4444-4444-8444-444444444444', 1, 'public/1/test/not-ready.jpg', 'public/1/test/not-ready.jpg', 'not-ready.jpg', 'not-ready.jpg', 'jpg', 'image/jpeg', 'image', 1200, 120, 80, 'sha-not-ready', 'quarantined', 'valid', 'pending', 'incomplete', 1, '2026-01-01 13:00:00', '2026-01-01 13:00:00'),
    (5, '55555555-5555-4555-8555-555555555555', 2, 'public/2/test/other-site.jpg', 'public/2/test/other-site.jpg', 'other-site.jpg', 'other-site.jpg', 'jpg', 'image/jpeg', 'image', 1200, 120, 80, 'sha-other-site', 'ready', 'valid', 'ready', 'complete', 1, '2026-01-01 14:00:00', '2026-01-01 14:00:00');

INSERT INTO media_asset_localizations(media_id, language_code, alt_text, caption, title, is_alt_verified)
VALUES
    (1, 'fr', 'Image A FR', 'Légende A FR', 'Titre A FR', 1),
    (2, 'en', 'Image B EN', 'Caption B EN', 'Title B EN', 1),
    (3, 'fr', 'Document A FR', 'Légende document FR', 'Titre document FR', 1);
