INSERT OR REPLACE INTO site_localizations(site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description)
SELECT id, 'fr', 'DEC CMS Runtime', 'Un CMS simple, modulaire et SEO-native', '© DEC CMS Runtime', ' | DEC CMS', 'CMS modulaire multilingue et multi-site'
FROM sites WHERE site_key='main';

INSERT OR REPLACE INTO site_localizations(site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description)
SELECT id, 'en', 'DEC CMS Runtime', 'A simple modular SEO-native CMS', '© DEC CMS Runtime', ' | DEC CMS', 'Modular multi-language and multi-site CMS'
FROM sites WHERE site_key='main';
