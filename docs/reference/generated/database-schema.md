---
title: Schémas des bases SQLite
audience:
  - developer
  - installer
  - evaluator
status: stable
source_of_truth: generated
generator: tools/python/generators/generate_documentation.py
owners:
  - core
document_type: reference
generated: true
---
# Schémas des bases SQLite

> Fichier généré. Ne pas modifier directement.

## `ai.sqlite`

| Table | Colonnes |
|---|---|
| `ai_model_usages` | model_id, usage_key, sort_order, created_at |
| `ai_models` | id, provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json, created_at, updated_at |
| `ai_prompts` | id, key, name, category, system_prompt, user_template, output_schema_json, language, enabled, version, created_at, updated_at |
| `ai_provider_types` | id, key, name, description, sort_order, enabled, is_system, created_at, updated_at |
| `ai_providers` | id, type_key, key, name, provider_type, base_url, api_key_ref, enabled, is_default, options_json, created_at, updated_at |
| `ai_settings` | id, key, value_json, description, enabled, created_at, updated_at |
| `ai_site_usage_settings` | id, site_id, usage_key, mode, model_id, provider_key, model_key, translation_enabled, seo_enabled, notes, created_at, updated_at |
| `ai_suggestions` | id, site_id, user_id, title, target_type, target_id, suggestion_type, status, source_field, target_field, suggestion_json, preview_text, reason, created_at, accepted_at, rejected_at, applied_at, expired_at, applied_action_run_id |
| `ai_task_results` | id, task_id, result_type, result_json, summary, warnings_json, created_at |
| `ai_tasks` | id, site_id, user_id, title, task_type, provider_key, model_key, status, input_hash, payload_json, target_type, target_id, result_id, error_type, error_message, attempts, max_attempts, created_at, started_at, finished_at |
| `ai_usage_events` | id, site_id, user_id, provider_key, model_key, task_type, input_tokens, output_tokens, total_tokens, duration_ms, estimated_cost, currency, status, details_json, created_at |
| `schema_migrations` | id, migration, migrated_at |

## `cookies.sqlite`

| Table | Colonnes |
|---|---|
| `cookie_banner_settings` | id, site_id, is_enabled, consent_version, cookie_name, consent_lifetime_days, log_retention_days, banner_position, theme, accent_color, show_floating_button, reject_equal_prominence, respect_dnt, created_at, updated_at |
| `cookie_banner_translations` | id, setting_id, language_code, banner_title, banner_summary, preferences_title, preferences_summary, accept_all_label, reject_all_label, customize_label, save_choices_label, manage_link_label, legal_notice_html, created_at, updated_at |
| `cookie_categories` | id, site_id, category_key, is_required, is_enabled, is_preselected, sort_order, created_at, updated_at |
| `cookie_category_translations` | id, category_id, language_code, name, description, created_at, updated_at |
| `cookie_consent_logs` | id, site_id, consent_uid, consent_version, language_code, action, choices_json, ip_hash, user_agent_hash, created_at |
| `cookie_script_bindings` | id, site_id, service_id, binding_key, location, trigger_mode, script_kind, src_url, inline_code, attributes_json, is_enabled, sort_order, created_at, updated_at |
| `cookie_service_cookies` | id, service_id, cookie_name, purpose, duration, domain, created_at |
| `cookie_service_translations` | id, service_id, language_code, name, purpose, description, fallback_message, created_at, updated_at |
| `cookie_services` | id, site_id, category_id, service_key, provider_name, service_type, cookie_type, domain, duration, privacy_url, is_enabled, sort_order, created_at, updated_at |
| `schema_migrations` | id, migration, migrated_at |

## `core.sqlite`

| Table | Colonnes |
|---|---|
| `action_runs` | id, site_id, user_id, module_key, action_key, mode, status, risk_level, input_summary_json, output_summary_json, created_at |
| `blueprint_fields` | id, blueprint_id, section_id, source_fieldset_id, field_handle, field_type, label, help_text, field_purpose, width, is_required, is_localized, is_system, is_deletable, sort_order, default_value_json, options_json, validation_json, conditions_json, config_json, created_at, updated_at |
| `blueprint_fieldsets` | id, blueprint_id, section_id, fieldset_id, mount_handle, label, sort_order, conditions_json, config_json, created_at, updated_at |
| `blueprint_migrations` | id, blueprint_id, from_version_id, to_version_id, migration_key, migration_json, status, applied_at, created_at |
| `blueprint_publications` | id, blueprint_id, blueprint_version_id, site_id, language_code, publication_status, published_at, published_by_iam_user_id |
| `blueprint_sections` | id, blueprint_id, section_key, label, description, layout, sort_order, conditions_json, config_json, created_at, updated_at |
| `blueprint_usage` | id, blueprint_id, blueprint_version_id, usage_type, resource_type, resource_id, site_id, language_code, created_at |
| `blueprint_versions` | id, blueprint_id, version, version_label, status, schema_json, ui_schema_json, validation_json, seo_policy_json, routing_policy_json, workflow_policy_json, translation_policy_json, permissions_policy_json, checksum_sha256, is_active, created_by_iam_user_id, created_at, activated_at, archived_at |
| `blueprints` | id, blueprint_key, resource_type, site_id, legacy_content_type_id, label, description, is_active, active_version_id, created_at, updated_at |
| `configuration_revisions` | id, site_id, group_key, value_json, updated_by_iam_user_id, created_at |
| `content_entries` | id, site_id, content_type_id, entry_key, parent_entry_id, author_iam_user_id, owner_iam_user_id, created_by_iam_user_id, updated_by_iam_user_id, published_by_iam_user_id, status, workflow_state, is_active, publish_at, unpublish_at, sort_order, created_at, updated_at, published_at |
| `content_entry_field_values` | id, entry_id, localization_id, content_type_id, field_id, field_key, language_code, projection_scope, source_revision_id, source_revision_checksum_sha256, value_text, value_number, value_boolean, value_date, value_datetime, value_json, value_media_id, value_relation_entry_id, sort_order, projected_at |
| `content_entry_localizations` | id, entry_id, language_code, title, draft_slug, draft_full_path, translation_status, source_language_code, translated_from_revision_id, localized_at, updated_by_iam_user_id, draft_status, is_active, admin_cache_published_at, updated_at |
| `content_entry_publications` | id, site_id, entry_id, language_code, published_revision_id, workflow_status, published_by_iam_user_id, published_at, unpublished_by_iam_user_id, unpublished_at, updated_at |
| `content_entry_taxonomy_terms` | id, entry_id, term_id, sort_order |
| `content_entry_working_revisions` | id, site_id, entry_id, language_code, working_revision_id, workflow_status, updated_by_iam_user_id, created_at, updated_at |
| `content_field_unique_values` | id, site_id, content_type_id, field_key, language_code, normalized_value, entry_id, updated_at |
| `content_relations` | id, source_entry_id, target_entry_id, relation_type, sort_order, metadata_json |
| `content_slug_registry` | id, site_id, language_code, slug, entry_id, updated_at |
| `content_type_taxonomies` | id, content_type_id, taxonomy_id, is_required, max_terms |
| `content_types` | id, module_id, type_key, name, singular_label, plural_label, description, icon, is_system, is_hidden, storage_mode, has_localizations, has_revisions, has_workflow, has_permalink, has_layout, has_taxonomies, has_seo, has_publish_window, default_status, default_sort, frontend_template, frontend_resolver, api_enabled, admin_enabled, created_at, updated_at |
| `cross_database_operations` | id, correlation_id, operation_key, operation_type, primary_store, status, step, metadata_json, attempts, last_error, created_at, updated_at |
| `editor_block_types` | id, block_type, label, category, schema_json, is_enabled, sort_order |
| `field_groups` | id, content_type_id, group_key, label, tab_key, sort_order |
| `field_validation_rules` | id, scope_type, field_id, blueprint_field_id, fieldset_field_id, rule_key, rule_value_json, message, sort_order, created_at |
| `field_visibility_conditions` | id, scope_type, field_id, blueprint_field_id, fieldset_field_id, blueprint_section_id, blueprint_fieldset_id, effect, condition_json, sort_order, created_at |
| `fields` | id, content_type_id, group_id, field_key, label, field_type, storage_mode, interface_key, help_text, placeholder, default_value_json, options_json, validation_json, conditions_json, config_json, field_purpose, width, is_required, is_unique, is_localized, is_indexed, is_filterable, is_sortable, is_searchable, is_hidden, is_system, is_deletable, sort_order |
| `fieldset_fields` | id, fieldset_id, field_handle, field_type, label, help_text, field_purpose, width, is_required, is_localized, is_system, is_deletable, sort_order, default_value_json, options_json, validation_json, conditions_json, config_json, created_at, updated_at |
| `fieldsets` | id, fieldset_key, label, description, fieldset_purpose, schema_version, is_system, is_deletable, config_json, created_at, updated_at |
| `global_variable_localizations` | id, variable_id, language_code, value_json, updated_at |
| `global_variables` | id, site_id, variable_key, variable_type, value_json, is_localized, is_public, description, created_at, updated_at |
| `languages` | id, code, name, native_name, locale, is_default, is_active, sort_order |
| `layout_block_localizations` | id, block_id, language_code, title, content_text, embed_code, settings_json, is_active, status |
| `layout_blocks` | id, container_id, module_id, block_type, block_key, component_key, layout_width, column_position, row_group, sort_order, visibility_rules_json, css_class, inline_style, is_active, status |
| `layout_containers` | id, entry_id, localization_id, container_key, container_type, theme_id, sort_order |
| `media_asset_events` | id, media_id, site_id, event_type, actor_iam_user_id, payload_json, created_at |
| `media_asset_localizations` | id, media_id, language_code, alt_text, caption, title, is_alt_verified, updated_at |
| `media_asset_variants` | id, media_id, preset_id, variant_key, path, width, height, format, mime_type, size_bytes, sha256, generator, generation_status, generated_at |
| `media_assets` | id, uuid, site_id, storage_disk, path, quarantine_path, public_path, filename, original_filename, extension, mime_type, media_type, size_bytes, width, height, sha256, duplicate_of_media_id, lifecycle_status, validation_status, validation_errors_json, variants_status, metadata_status, dominant_color, copyright_text, license_type, source_url, folder_id, metadata_json, uploaded_by_user_id, validated_at, created_at, updated_at, delete_requested_at, deleted_at |
| `media_folders` | id, site_id, folder_key, name, parent_id, sort_order, created_at, updated_at |
| `media_usages` | id, media_id, site_id, resource_type, resource_id, field_key, language_code, usage_context, source_revision_id, alt_policy, alt_text_snapshot, usage_hash, created_at, updated_at |
| `media_variant_presets` | id, site_id, preset_key, width, height, format, quality, mode, sort_order, is_active, created_at, updated_at |
| `menu_item_localizations` | id, menu_item_id, language_code, label, title_attr |
| `menu_items` | id, menu_id, language_code, parent_id, resource_type, resource_id, term_id, manual_url, open_in_new_tab, css_class, visibility_rules_json, sort_order, is_active |
| `menus` | id, site_id, menu_key, name, menu_location, language_mode, is_active |
| `module_api_contracts` | id, module_key, contract_key, version, schema_json, created_at, updated_at |
| `module_blueprints` | id, module_key, resource, blueprint_key, resource_type, declared_version, storage_json, capabilities_json, permissions_json, headless_json, admin_schema_json, relations_json, export_policy_json, contract_json, validation_errors_json, is_published, created_at, updated_at |
| `module_databases` | id, module_key, database_key, driver, path, schema_path, is_required, exists_at_last_check, created_at, updated_at |
| `module_dependencies` | id, module_id, depends_on_module_id, dependency_type |
| `module_hooks` | id, module_id, hook_name, handler_class, sort_order |
| `module_lifecycle_events` | id, module_key, event, payload_json, created_at |
| `module_permissions` | id, module_key, permission_key, created_at |
| `module_routes` | id, module_key, route_key, scope, method, path, handler, is_published, created_at, updated_at |
| `modules` | id, module_key, name, version, provider_class, is_system, is_installed, is_enabled, config_json, installed_at, updated_at |
| `outbox_events` | id, topic, payload_json, status, attempts, last_error, created_at, available_at, claimed_at, processed_at |
| `public_content_snapshots` | id, site_id, language_code, resource_type, resource_id, route_path, title, slug, blocks_json, block_count, document_json, seo_json, source_published_revision_id, source_revision_checksum_sha256, published_at, projected_at |
| `redirects` | id, site_id, language_code, old_path, new_path, http_code, redirect_reason, resource_type, resource_id, is_active, created_at, updated_at |
| `revision_comments` | id, revision_id, language_code, anchor_path, comment_text, status, created_by_iam_user_id, created_at |
| `revision_locks` | id, resource_type, resource_id, locked_by_iam_user_id, lock_token, expires_at, created_at |
| `revisions` | id, resource_type, resource_id, blueprint_id, blueprint_version_id, revision_number, language_code, workflow_status, document_schema_version, base_revision_id, source_published_revision_id, created_from_event, revision_label, summary, change_notes, document_json, checksum_sha256, scheduled_for, created_by_iam_user_id, updated_by_iam_user_id, published_by_iam_user_id, created_at, updated_at, published_at |
| `routes` | id, site_id, language_code, resource_type, resource_id, route_type, slug, full_path, is_primary, is_canonical, status, source_published_revision_id, source_revision_checksum_sha256, created_at, updated_at |
| `schema_field_types` | id, type_key, label, storage_mode, component_key, is_implemented, definition_json, created_at, updated_at |
| `schema_migrations` | id, migration, migrated_at |
| `search_documents` | id, site_id, resource_type, resource_id, language_code, path, title, summary, search_text, source_published_revision_id, source_revision_checksum_sha256, updated_at |
| `search_documents_fts` | title, summary, search_text |
| `search_documents_fts_config` | k, v |
| `search_documents_fts_data` | id, block |
| `search_documents_fts_docsize` | id, sz |
| `search_documents_fts_idx` | segid, term, pgno |
| `seo_audit_issues` | id, site_id, resource_type, resource_id, language_code, issue_code, severity, message, is_resolved, detected_at |
| `seo_metadata` | id, site_id, resource_type, resource_id, language_code, meta_title, meta_description, meta_robots, canonical_url, og_title, og_description, og_image_media_id, twitter_title, twitter_description, twitter_image_media_id, hreflang_code, json_ld, seo_score, source_published_revision_id, source_revision_checksum_sha256, updated_at |
| `seo_templates` | id, site_id, resource_type, resource_subtype, language_code, meta_title_template, meta_description_template, og_title_template, og_description_template, robots_default, json_ld_template |
| `site_domains` | id, site_id, host, base_path, scheme, is_primary, is_active, enforce_https, canonical_host_strategy, created_at, updated_at |
| `site_languages` | id, site_id, language_code, locale, url_prefix, hreflang_code, fallback_language_code, is_default, is_active, is_rtl, sort_order, created_at, updated_at |
| `site_localizations` | id, site_id, language_code, site_title, baseline, footer_text, default_meta_title_suffix, default_meta_description, og_default_image_media_id, apple_touch_icon_media_id |
| `site_preferences` | id, site_id, preference_key, value_json, updated_by_iam_user_id, created_at, updated_at |
| `site_settings` | id, site_id, namespace, setting_key, value_json, is_public, updated_by_iam_user_id, created_at, updated_at |
| `sites` | id, site_key, name, default_language_code, is_active, created_at, updated_at |
| `system_jobs` | id, job_key, last_run_at, last_status, last_message, updated_at |
| `taxonomies` | id, site_id, taxonomy_key, name, description, is_hierarchical, is_localized, seo_enabled, archive_enabled, sort_order |
| `taxonomy_term_localizations` | id, site_id, taxonomy_id, term_id, language_code, name, slug, full_path, description, meta_title, meta_description, canonical_url, json_ld |
| `taxonomy_terms` | id, taxonomy_id, parent_id, term_key, is_active, sort_order |
| `themes` | id, theme_key, name, version, is_default, is_active, config_json |
| `tombstones` | id, site_id, language_code, old_path, resource_type, resource_id, replacement_path, gone_reason, gone_at, updated_at, expires_at, is_active |
| `webhook_deliveries` | id, webhook_id, outbox_event_id, delivery_id, event_topic, payload_json, status, attempts, http_status, response_body, last_error, created_at, next_attempt_at, last_attempt_at, delivered_at |
| `webhook_endpoints` | id, site_id, name, url, events_json, secret, is_active, max_attempts, last_attempt_at, next_attempt_at, created_at, updated_at |

## `forms.sqlite`

| Table | Colonnes |
|---|---|
| `form_field_translations` | id, field_id, language_code, label, placeholder, help_text, options_json, updated_at |
| `form_fields` | id, form_id, field_key, field_type, sort_order, is_required, is_active, width, default_value, validation_json, settings_json, created_at, updated_at |
| `form_notification_deliveries` | id, form_id, submission_id, channel, recipient, status, subject, error_message, created_at, sent_at |
| `form_rate_limit_hits` | id, form_id, ip_hash, created_at |
| `form_submission_values` | id, submission_id, field_id, field_key, field_label, value_json, value_text |
| `form_submissions` | id, form_id, site_id, language_code, submission_status, spam_score, spam_reasons_json, ip_hash, user_agent, referer_url, payload_json, created_at |
| `form_translations` | id, form_id, language_code, name, description_text, submit_label, success_message, updated_at |
| `forms` | id, site_id, form_key, status, is_active, store_submissions, notification_enabled, notification_recipients_json, notification_subject, honeypot_field, min_submit_seconds, rate_limit_max_attempts, rate_limit_window_seconds, settings_json, created_by_iam_user_id, updated_by_iam_user_id, created_at, updated_at |
| `schema_migrations` | id, migration, migrated_at |

## `iam.sqlite`

| Table | Colonnes |
|---|---|
| `api_tokens` | id, name, token_hash, site_id, scopes, is_active, expires_at, last_used_at, created_at, updated_at |
| `iam_audit_logs` | id, actor_user_id, action_key, resource_type, resource_id, context_json, ip_address, user_agent, created_at |
| `iam_email_2fa_challenges` | id, user_id, code_hash, ip_address, user_agent, attempt_count, expires_at, consumed_at, created_at |
| `iam_permissions` | id, permission_key, name, description |
| `iam_rate_limits` | id, rate_key, created_at |
| `iam_role_permissions` | id, role_id, permission_id |
| `iam_roles` | id, role_key, name, description |
| `iam_sessions` | id, user_id, session_token_hash, ip_address, user_agent, last_seen_at, expires_at, created_at |
| `iam_user_roles` | id, user_id, role_id |
| `iam_user_site_roles` | id, user_id, site_id, role_id, created_at |
| `iam_users` | id, email, email_normalized, password_hash, first_name, last_name, locale, is_active, disabled_at, disabled_reason, last_login_at, password_reset_selector, password_reset_token_hash, password_reset_expires_at, password_reset_requested_at, password_reset_sent_at, last_password_change_at, login_mode, totp_enabled, totp_required, totp_secret_protected, totp_recovery_codes_json, totp_enabled_at, created_at, updated_at |
| `schema_migrations` | id, migration, migrated_at |

