from __future__ import annotations
from tools.python.validation.checks import *
from tools.python.validation.model import ValidationReport

VALIDATOR_ID='BLUEPRINT_SCHEMA'
DOMAIN='content'
MODES=("fast","full")

NATIVE_CONTENT_BLUEPRINTS = {"page", "article", "page_archive", "article_archive"}
NATIVE_EDITOR_FIELD_HANDLES = {
    "blocks",
    "hero_title", "hero_text", "hero_image", "hero_cta_label", "hero_cta_url",
    "seo_title", "seo_description", "robots", "og_image", "og_title", "og_description", "canonical_url",
}


def _field_handle(field: object) -> str:
    return str(field.get("field_key") or field.get("handle") or field.get("key") or "").strip() if isinstance(field, dict) else ""


def _validate_native_content_blueprints(r: ValidationReport, data: dict) -> None:
    content_blueprints = data.get("content_blueprints", {})
    if not isinstance(content_blueprints, dict):
        return
    for blueprint_key in sorted(NATIVE_CONTENT_BLUEPRINTS):
        definition = content_blueprints.get(blueprint_key)
        if not isinstance(definition, dict):
            continue
        declared_fields = definition.get("fields", []) if isinstance(definition.get("fields"), list) else []
        declared_handles = {_field_handle(field) for field in declared_fields}
        forbidden_fields = sorted(handle for handle in declared_handles if handle in NATIVE_EDITOR_FIELD_HANDLES)
        r.checked()
        if forbidden_fields:
            r.add(
                "BPT-010",
                "Blueprint natif avec champs dupliquant les éditeurs natifs blocs/hero/SEO",
                path="database/seeds/native_blueprints.json",
                identifier=blueprint_key,
                details=", ".join(forbidden_fields),
            )
        for tab in definition.get("editor_tabs", []) if isinstance(definition.get("editor_tabs"), list) else []:
            if not isinstance(tab, dict):
                continue
            tab_fields = {str(field).strip() for field in tab.get("fields", []) if str(field).strip()}
            forbidden_tab_fields = sorted(field for field in tab_fields if field in NATIVE_EDITOR_FIELD_HANDLES)
            r.checked()
            if forbidden_tab_fields:
                r.add(
                    "BPT-011",
                    "Onglet natif référençant des champs gérés par les éditeurs blocs/hero/SEO natifs",
                    path="database/seeds/native_blueprints.json",
                    identifier=f"{blueprint_key}.{tab.get('key') or tab.get('label') or 'tab'}",
                    details=", ".join(forbidden_tab_fields),
                )


def validate(mode: str="fast") -> ValidationReport:
    r=ValidationReport(VALIDATOR_ID,DOMAIN,mode)
    data=load_json(r,"database/seeds/native_blueprints.json","BPT-001");
    if isinstance(data,list):
        seen=set()
        for item in data:
            r.checked(); key=str(item.get("key") or item.get("slug") or item.get("name") or "") if isinstance(item,dict) else ""
            if not key: r.add("BPT-001","Blueprint sans identifiant",path="database/seeds/native_blueprints.json")
            elif key in seen: r.add("BPT-002","Identifiant de blueprint dupliqué",path="database/seeds/native_blueprints.json",identifier=key)
            seen.add(key)
    elif isinstance(data,dict):
        _validate_native_content_blueprints(r, data)
    elif data is not None and not isinstance(data,dict): r.add("BPT-001","Racine de blueprints invalide",path="database/seeds/native_blueprints.json")
    return r
