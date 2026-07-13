PRAGMA foreign_keys = ON;

-- Un même compte IAM peut avoir un profil CRM distinct sur plusieurs sites.
DROP INDEX IF EXISTS idx_business_contacts_active_iam_user;
CREATE UNIQUE INDEX IF NOT EXISTS idx_business_contacts_active_iam_user
    ON business_contacts(site_id, iam_user_id)
    WHERE iam_user_id IS NOT NULL AND archived_at IS NULL;
