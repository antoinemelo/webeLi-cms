# Client Notes Example

This is a minimal, non-activated example of a client module. It documents the expected structure only; it is not loaded by the CMS and must not be copied into production as-is without review.

To adapt it for a real client instance:

1. Copy the directory to `local/modules/<module-key>/`.
2. Change `module.json` so `provider_file`, `schema` and `migrations` point to `local/modules/<module-key>/...`.
3. Declare the module explicitly in `ops/modules.local.json`.
4. Create the dedicated SQLite database from `database/schema.sql` or let the controlled migration flow handle it.
5. Run `python3 tools/cms.py backup`, `python3 tools/cms.py migrate --module <module-key> --plan`, then apply only after backup.

The example contains one dedicated database declaration, one initial migration and one simple permission. It does not add a product feature to the CMS.
