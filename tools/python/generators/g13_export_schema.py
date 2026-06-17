#!/usr/bin/env python3
from pathlib import Path
import json
import sqlite3

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
DB_DIR = ROOT / "storage" / "database"
OUT = ROOT / "storage" / "exports"
OUT.mkdir(parents=True, exist_ok=True)

REPOSITORY_PORTS = {
    "content_types": {"port": "Application/Schema/ContentTypeRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlContentTypeRepository.php"},
    "fields": {"port": "Application/Schema/FieldDefinitionRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlFieldDefinitionRepository.php"},
    "content_entries": {"port": "Application/Content/ContentEntryRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlContentEntryRepository.php"},
    "content_revisions": {"port": "Application/Content/ContentRevisionRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlContentRevisionRepository.php"},
    "content_localizations": {"port": "Application/Content/ContentLocalizationRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlContentLocalizationRepository.php"},
    "public_routes": {"port": "Application/Routing/PublicRouteRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlPublicRouteRepository.php"},
    "redirects": {"port": "Application/Routing/RedirectRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlRedirectRepository.php"},
    "tombstones": {"port": "Application/Routing/TombstoneRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlTombstoneRepository.php"},
    "seo_metadata": {"port": "Application/Seo/SeoMetadataRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlSeoMetadataRepository.php"},
    "seo_issues": {"port": "Application/Seo/SeoAuditIssueRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlSeoAuditIssueRepository.php"},
    "taxonomy_assignments": {"port": "Application/Taxonomy/TaxonomyAssignmentRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlTaxonomyAssignmentRepository.php"},
    "transaction_manager": {"port": "Application/Support/TransactionManager.php", "adapter": "Infrastructure/Persistence/Sql/SqlTransactionManager.php"},
    "outbox_events": {"port": "Application/Outbox/OutboxEventRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlOutboxEventRepository.php"},
    "projections": {"port": "Application/Projection/ProjectionRepository.php", "adapter": "Infrastructure/Persistence/Sql/SqlProjectionRepository.php"},
}
APPLICATION_USE_CASES = {
    "save_content_draft": {
        "class": "Application/Content/SaveContentDraft.php",
        "result": "Application/Content/SaveContentDraftResult.php",
        "steps": [
            "load_content_type",
            "validate_payload_against_schema",
            "normalize_values",
            "create_or_update_entry",
            "create_working_revision",
            "save_localization",
            "return_application_result"
        ],
        "depends_on_ports": [
            "content_types",
            "content_entries",
            "content_revisions",
            "content_localizations",
            "transaction_manager"
        ]
    },
    "publish_content_entry": {
        "class": "Application/Content/PublishContentEntry.php",
        "result": "Application/Content/PublishContentEntryResult.php",
        "steps": [
            "assert_entry_can_be_published",
            "assert_publisher_has_permission_upstream",
            "select_current_working_revision",
            "mark_previous_published_revision_superseded",
            "mark_revision_published",
            "set_entry_published_state",
            "emit_content_entry_published_event",
            "return_projection_hints"
        ],
        "depends_on_ports": [
            "content_entries",
            "content_revisions",
            "content_localizations",
            "transaction_manager",
            "outbox_events"
        ],
        "emits": ["content.entry_published"],
        "projections_to_rebuild": ["content_entry", "taxonomy_routes"]
    },
    "rebuild_projections": {
        "class": "Service/ProjectionService.php",
        "steps": [
            "list_content_entry_ids",
            "clear_projection_tables",
            "rebuild_entry_projections",
            "replace_taxonomy_routes",
            "emit_projection_rebuilt_event"
        ],
        "depends_on_ports": [
            "projections",
            "outbox_events"
        ],
        "emits": ["projection.rebuilt"]
    }
}



def connect(path: Path) -> sqlite3.Connection:
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    return con


def decode_json(value):
    if value is None or str(value).strip() == '':
        return {}
    try:
        decoded = json.loads(value)
        return decoded if isinstance(decoded, (dict, list)) else {}
    except json.JSONDecodeError:
        return {}


def export_core_contract(con: sqlite3.Connection) -> dict:
    content_types = []
    for content_type in con.execute('SELECT * FROM content_types ORDER BY type_key'):
        ct = dict(content_type)
        fields = []
        for field in con.execute('''
            SELECT f.*, fg.group_key, COALESCE(fg.tab_key, 'content') AS tab_key
            FROM fields f
            LEFT JOIN field_groups fg ON fg.id = f.group_id
            WHERE f.content_type_id = ?
            ORDER BY COALESCE(fg.sort_order, 0), f.sort_order, f.field_key
        ''', (ct['id'],)):
            item = dict(field)
            item['options'] = decode_json(item.pop('options_json', None))
            item['validation'] = decode_json(item.pop('validation_json', None))
            fields.append(item)
        groups = [dict(row) for row in con.execute('SELECT * FROM field_groups WHERE content_type_id = ? ORDER BY sort_order, group_key', (ct['id'],))]
        tabs = []
        for tab_key in sorted({g.get('tab_key') or 'content' for g in groups} | {f.get('tab_key') or 'content' for f in fields}):
            tabs.append({
                'tab_key': tab_key,
                'label': {'content': 'Contenu', 'seo': 'SEO', 'settings': 'Réglages'}.get(tab_key, tab_key.replace('_', ' ').title()),
                'field_keys': [f['field_key'] for f in fields if (f.get('tab_key') or 'content') == tab_key],
            })
        content_types.append({
            'type_key': ct['type_key'],
            'name': ct['name'],
            'singular_label': ct.get('singular_label'),
            'plural_label': ct.get('plural_label'),
            'capabilities': {
                'localizations': bool(ct['has_localizations']),
                'revisions': bool(ct['has_revisions']),
                'workflow': bool(ct['has_workflow']),
                'permalink': bool(ct['has_permalink']),
                'layout': bool(ct['has_layout']),
                'taxonomies': bool(ct['has_taxonomies']),
                'seo': bool(ct['has_seo']),
            },
            'template_binding': {
                'default_template': ct.get('frontend_template'),
                'resolver_class': ct.get('frontend_resolver'),
            },
            'editor_tabs': tabs,
            'groups': groups,
            'fields': fields,
        })

    return {
        'content_type_schemas': content_types,
        'workflow_states': ['draft', 'review', 'published', 'archived', 'scheduled'],
        'field_types': ['text', 'textarea', 'richtext', 'number', 'boolean', 'date', 'datetime', 'json', 'media', 'relation', 'select', 'multiselect', 'slug'],
        'storage_modes': {'content_types': ['hybrid', 'single_table', 'json'], 'fields': ['value_table', 'json']},
        'media_types': ['image', 'document', 'binary'],
        'language_modes': ['shared', 'localized'],
        'seo_severities': ['low', 'medium', 'high', 'critical'],
        'routing_contracts': {
            'route_statuses': ['active', 'inactive', 'archived'],
            'route_types': ['content', 'taxonomy', 'system'],
            'redirect_http_codes': [301, 302, 307, 308],
            'public_route_resource_type': 'content_entry',
        },
        'repository_ports': REPOSITORY_PORTS,
        'application_use_cases': APPLICATION_USE_CASES,
    }


for db_path in DB_DIR.glob("*.sqlite"):
    con = connect(db_path)
    rows = con.execute("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type, name").fetchall()
    target = OUT / f"{db_path.stem}_schema_export.sql"
    target.write_text("\n\n".join(r[0] for r in rows if r[0]), encoding="utf-8")
    print(f"Exported {target.name}")

    if db_path.stem == 'core':
        contract = export_core_contract(con)
        json_target = OUT / 'core_content_type_schemas.json'
        json_target.write_text(json.dumps(contract['content_type_schemas'], ensure_ascii=False, indent=2), encoding='utf-8')
        print(f"Exported {json_target.name}")

        contract_target = OUT / 'core_application_contracts.json'
        contract_target.write_text(json.dumps(contract, ensure_ascii=False, indent=2), encoding='utf-8')
        print(f"Exported {contract_target.name}")

    con.close()
