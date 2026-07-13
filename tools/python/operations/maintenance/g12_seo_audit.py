#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import argparse
import json
import re
import sqlite3
import sys
from typing import Any

BASE = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
CORE_PATH = BASE / 'storage' / 'database' / 'core.sqlite'


def connect(path: Path) -> sqlite3.Connection:
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    return con


def valid_path(path: str | None) -> bool:
    if not path:
        return False
    return path == '/' or (path.startswith('/') and '//' not in path and ' ' not in path and not path.endswith('/') and path == path.lower())


def penalty(severity: str) -> int:
    return {'critical': 30, 'high': 20, 'medium': 10, 'low': 5}.get(severity, 5)


def page_status(score: int) -> str:
    if score >= 85:
        return 'excellent'
    if score >= 70:
        return 'good'
    if score >= 50:
        return 'warning'
    return 'critical'



def jsonld_types(json_ld: str) -> set[str]:
    if not json_ld.strip():
        return set()
    try:
        payload = json.loads(json_ld)
    except json.JSONDecodeError:
        return set()

    nodes: list[Any] = []
    if isinstance(payload, dict):
        if isinstance(payload.get('@graph'), list):
            nodes.extend(payload['@graph'])
        else:
            nodes.append(payload)
    elif isinstance(payload, list):
        nodes.extend(payload)

    types: set[str] = set()
    for node in nodes:
        if not isinstance(node, dict):
            continue
        value = node.get('@type')
        if isinstance(value, str):
            types.add(value)
        elif isinstance(value, list):
            types.update(str(item) for item in value if item)
    return types



def block_plain_text(blocks_json: str | None) -> str:
    if not blocks_json:
        return ''
    try:
        blocks = json.loads(blocks_json)
    except json.JSONDecodeError:
        return ''

    def collect(block_list: Any) -> list[str]:
        chunks: list[str] = []
        for block in block_list if isinstance(block_list, list) else []:
            if not isinstance(block, dict):
                continue
            data = block.get('data') if isinstance(block.get('data'), dict) else {}
            block_type = block.get('type')
            for key in ('eyebrow', 'title', 'subtitle', 'lead', 'text', 'markdown', 'html', 'caption', 'alt', 'image_alt', 'form_key', 'more_label'):
                value = data.get(key)
                if isinstance(value, str):
                    chunks.append(value)
            for key in ('items', 'buttons'):
                value = data.get(key)
                if isinstance(value, list):
                    for item in value:
                        if isinstance(item, dict):
                            for item_key in ('label', 'alt', 'caption', 'title'):
                                item_value = item.get(item_key)
                                if isinstance(item_value, str):
                                    chunks.append(item_value)
            if block_type == 'columns' and isinstance(data.get('columns'), list):
                for column in data['columns']:
                    if isinstance(column, dict):
                        chunks.extend(collect(column.get('blocks')))
        return chunks

    chunks = collect(blocks)
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', ' '.join(chunks))).strip()

def primary_url(con: sqlite3.Connection, site_id: int, path: str) -> str:
    row = con.execute(
        "SELECT scheme, host, base_path FROM site_domains WHERE site_id=? AND is_active=1 ORDER BY is_primary DESC, id LIMIT 1",
        (site_id,),
    ).fetchone()
    if not row:
        return path or '/'
    base = (row['base_path'] or '').strip('/')
    clean = '' if path == '/' else path.lstrip('/')
    suffix = '/'.join(part for part in [base, clean] if part)
    return f"{row['scheme']}://{row['host']}/" + (suffix if suffix else '')


def score_page(row: sqlite3.Row, global_failed: list[dict[str, Any]]) -> dict[str, Any]:
    title = (row['meta_title'] or row['title'] or '').strip()
    content_text = block_plain_text(row['blocks_json'])
    description = (row['meta_description'] or content_text[:220] or '').strip()
    words = len(re.findall(r"\w+", content_text, flags=re.UNICODE))
    robots = (row['meta_robots'] or 'index,follow').lower()
    json_ld = (row['json_ld'] or '').strip()
    canonical = (row['canonical_url'] or '').strip()

    checks: list[dict[str, Any]] = []
    checks.append({'code': 'meta.title.present', 'severity': 'high', 'passed': bool(title), 'recommendation': 'Ajouter un meta title unique et descriptif.'})
    checks.append({'code': 'meta.title.length', 'severity': 'medium', 'passed': 30 <= len(title) <= 60, 'recommendation': 'Viser 30 à 60 caractères.'})
    checks.append({'code': 'meta.description.present', 'severity': 'high', 'passed': bool(description), 'recommendation': 'Rédiger une meta description claire.'})
    checks.append({'code': 'meta.description.length', 'severity': 'medium', 'passed': 110 <= len(description) <= 160, 'recommendation': 'Viser 110 à 160 caractères.'})
    checks.append({'code': 'canonical.self', 'severity': 'high', 'passed': canonical in ('', row['route_path']), 'recommendation': 'La canonique doit pointer vers la page courante sauf consolidation volontaire.'})
    checks.append({'code': 'robots.indexable', 'severity': 'high', 'passed': 'noindex' not in robots, 'recommendation': 'Retirer noindex pour une page destinée à être trouvée.'})
    checks.append({'code': 'content.word_count', 'severity': 'medium', 'passed': words >= 250, 'recommendation': 'Ajouter un contenu principal plus explicatif.'})
    checks.append({'code': 'content.blocks', 'severity': 'low', 'passed': int(row['block_count'] or 0) > 0, 'recommendation': 'Composer la page avec au moins un bloc éditorial.'})
    json_valid = True
    if json_ld:
        try:
            json.loads(json_ld)
        except json.JSONDecodeError:
            json_valid = False
    types = jsonld_types(json_ld) if json_valid else set()
    page_types = {'WebPage', 'Article', 'SearchResultsPage', 'CollectionPage'}
    checks.append({'code': 'structured_data.valid', 'severity': 'medium', 'passed': json_valid, 'recommendation': 'Corriger le JSON-LD invalide.'})
    checks.append({'code': 'geo.structured_data.website', 'severity': 'medium', 'passed': 'WebSite' in types, 'recommendation': 'Publier un graphe JSON-LD avec un nœud WebSite et SearchAction.'})
    checks.append({'code': 'geo.structured_data.organization', 'severity': 'medium', 'passed': 'Organization' in types, 'recommendation': 'Publier un nœud Organization/Publisher relié au WebSite et à la page.'})
    checks.append({'code': 'geo.structured_data.page_entity', 'severity': 'medium', 'passed': bool(types & page_types), 'recommendation': 'Publier un nœud WebPage/Article/CollectionPage décrivant l’entité principale.'})
    checks.append({'code': 'geo.structured_data.breadcrumbs', 'severity': 'low', 'passed': 'BreadcrumbList' in types, 'recommendation': 'Publier un BreadcrumbList pour expliciter la position de la page.'})

    search = int(row['seo_score']) if row['seo_score'] is not None else 100
    technical = 100
    ai = 100
    recommendations: list[dict[str, str]] = []
    for check in checks:
        if check['passed']:
            continue
        p = penalty(check['severity'])
        code = check['code']
        if code.startswith(('meta.', 'canonical.', 'robots.')):
            search -= p
        if code.startswith(('content.', 'structured_data.')):
            ai -= p
        if code.startswith(('canonical.', 'structured_data.')):
            technical -= p
        recommendations.append({'scope': 'page', 'severity': check['severity'], 'code': code, 'text': check['recommendation']})

    for check in global_failed:
        p = max(2, penalty(check['severity']) // 2)
        technical -= p
        if check['code'].startswith(('domains.', 'i18n.')):
            search -= p
            ai -= 2
        recommendations.append({'scope': 'site', 'severity': check['severity'], 'code': check['code'], 'text': check.get('recommendation') or check.get('message') or ''})

    search = max(0, min(100, search))
    technical = max(0, min(100, technical))
    ai = max(0, min(100, ai))
    overall = round(search * 0.45 + technical * 0.25 + ai * 0.30)
    recommendations.sort(key=lambda item: penalty(item['severity']), reverse=True)
    return {
        'resource_id': row['resource_id'],
        'title': row['title'],
        'path': row['route_path'],
        'scores': {'overall': overall, 'search_engines': search, 'technical': technical, 'ai_readiness': ai},
        'status': page_status(overall),
        'recommendations': recommendations[:8],
        'serp_preview': {'title': title or 'Titre SEO manquant', 'url': '', 'description': description or 'Meta description manquante.'},
    }


def audit(con: sqlite3.Connection) -> dict[str, Any]:
    errors: list[str] = []
    warnings: list[str] = []
    technical_checks: list[dict[str, Any]] = []

    bad_routes = []
    for row in con.execute("SELECT id, full_path FROM routes WHERE status='active' ORDER BY id"):
        if not valid_path(row['full_path']):
            msg = f"routes.{row['id']}: chemin public non canonique ({row['full_path']})"
            errors.append(msg)
            bad_routes.append({'id': row['id'], 'full_path': row['full_path']})
    technical_checks.append({'code': 'routes.canonical_path_format', 'severity': 'critical', 'passed': not bad_routes, 'count': len(bad_routes), 'message': 'Routes actives non canoniques', 'recommendation': 'Normaliser les chemins publics.'})

    multi_canonical = list(con.execute("""
        SELECT site_id, language_code, resource_type, resource_id, COUNT(*) AS c
        FROM routes
        WHERE status='active' AND is_canonical=1
        GROUP BY site_id, language_code, resource_type, resource_id
        HAVING COUNT(*) > 1
    """))
    for row in multi_canonical:
        errors.append(f"routes: canonicals multiples site={row['site_id']} lang={row['language_code']} resource={row['resource_type']}#{row['resource_id']}")
    technical_checks.append({'code': 'routes.single_canonical_per_resource', 'severity': 'critical', 'passed': not multi_canonical, 'count': len(multi_canonical), 'message': 'Ressources avec plusieurs routes canoniques actives', 'recommendation': 'Conserver une seule canonique et rediriger les anciennes URL en 301.'})

    for row in con.execute("""
        SELECT pcs.id, pcs.resource_id, pcs.language_code, pcs.route_path
        FROM public_content_snapshots pcs
        LEFT JOIN routes r ON r.site_id=pcs.site_id
          AND r.language_code=pcs.language_code
          AND r.resource_type=pcs.resource_type
          AND r.resource_id=pcs.resource_id
          AND r.full_path=pcs.route_path
          AND r.status='active'
          AND r.source_published_revision_id=pcs.source_published_revision_id
          AND r.source_revision_checksum_sha256=pcs.source_revision_checksum_sha256
        LEFT JOIN seo_metadata sm ON sm.site_id=pcs.site_id
          AND sm.language_code=pcs.language_code
          AND sm.resource_type=pcs.resource_type
          AND sm.resource_id=pcs.resource_id
          AND sm.source_published_revision_id=pcs.source_published_revision_id
          AND sm.source_revision_checksum_sha256=pcs.source_revision_checksum_sha256
        WHERE pcs.resource_type='content_entry' AND (r.id IS NULL OR sm.id IS NULL)
    """):
        errors.append(f"public_content_snapshots.{row['id']}: snapshot publié sans routes/SEO atomiques ({row['resource_id']} {row['language_code']} {row['route_path']})")

    for row in con.execute("""
        SELECT pcs.id, pcs.resource_id, pcs.language_code, pcs.route_path,
               pcs.seo_json,
               sm.meta_title, sm.meta_description, sm.meta_robots, sm.canonical_url,
               sm.og_title, sm.og_description, sm.twitter_title, sm.twitter_description,
               sm.hreflang_code, sm.json_ld, sm.seo_score
        FROM public_content_snapshots pcs
        JOIN seo_metadata sm ON sm.site_id=pcs.site_id
          AND sm.language_code=pcs.language_code
          AND sm.resource_type=pcs.resource_type
          AND sm.resource_id=pcs.resource_id
          AND sm.source_published_revision_id=pcs.source_published_revision_id
          AND sm.source_revision_checksum_sha256=pcs.source_revision_checksum_sha256
        WHERE pcs.resource_type='content_entry'
    """):
        try:
            seo_json = json.loads(row['seo_json'] or '{}')
        except json.JSONDecodeError:
            errors.append(f"public_content_snapshots.{row['id']}: seo_json invalide")
            continue
        for key in ('meta_title', 'meta_description', 'meta_robots', 'canonical_url', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'hreflang_code', 'json_ld'):
            projected = '' if seo_json.get(key) is None else str(seo_json.get(key))
            stored = '' if row[key] is None else str(row[key])
            if projected != stored:
                errors.append(f"public_content_snapshots.{row['id']}: incohérence SEO {key} entre seo_json et seo_metadata")
                break
        if seo_json.get('seo_score') is not None and row['seo_score'] is not None and int(seo_json.get('seo_score')) != int(row['seo_score']):
            errors.append(f"public_content_snapshots.{row['id']}: incohérence seo_score entre seo_json et seo_metadata")


    missing_seo = list(con.execute("""
        SELECT r.id, r.site_id, r.language_code, r.resource_type, r.resource_id, r.full_path
        FROM routes r
        LEFT JOIN seo_metadata sm ON sm.site_id=r.site_id AND sm.resource_type=r.resource_type AND sm.resource_id=r.resource_id AND sm.language_code=r.language_code
        WHERE r.status='active' AND r.is_canonical=1 AND (sm.id IS NULL OR COALESCE(sm.meta_title,'')='' OR COALESCE(sm.meta_description,'')='')
        ORDER BY r.id
    """))
    for row in missing_seo:
        warnings.append(f"routes.{row['id']}: projection publiée sans SEO complet ({row['resource_type']}#{row['resource_id']} {row['language_code']} {row['full_path']})")
    technical_checks.append({'code': 'seo.metadata_complete_for_canonical_routes', 'severity': 'high', 'passed': not missing_seo, 'count': len(missing_seo), 'message': 'Routes canoniques publiées sans SEO complet', 'recommendation': 'Renseigner title et description SEO.'})

    domains = list(con.execute("SELECT site_id, scheme, host, base_path FROM site_domains WHERE is_active=1 ORDER BY site_id, is_primary DESC, id"))
    technical_checks.append({
        'code': 'geo.llms_txt_runtime',
        'severity': 'low',
        'passed': bool(domains),
        'count': len(domains),
        'message': 'Endpoint /llms.txt disponible au runtime public',
        'recommendation': 'Vérifier /llms.txt sur le domaine canonique après déploiement.',
    })

    invalid_json = []
    for row in con.execute("SELECT id, json_ld FROM seo_metadata WHERE json_ld IS NOT NULL AND trim(json_ld)<>'' ORDER BY id"):
        try:
            json.loads(row['json_ld'])
        except json.JSONDecodeError as exc:
            errors.append(f"seo_metadata.{row['id']}: JSON-LD invalide ({exc})")
            invalid_json.append({'id': row['id']})
    technical_checks.append({'code': 'seo.json_ld_valid', 'severity': 'high', 'passed': not invalid_json, 'count': len(invalid_json), 'message': 'JSON-LD invalide', 'recommendation': 'Corriger ou supprimer le JSON-LD invalide.'})

    noindex_docs = list(con.execute("""
        SELECT sd.id, sd.resource_type, sd.resource_id, sd.language_code
        FROM search_documents sd
        JOIN seo_metadata sm ON sm.site_id=sd.site_id AND sm.resource_type=sd.resource_type AND sm.resource_id=sd.resource_id AND sm.language_code=sd.language_code
        WHERE LOWER(COALESCE(sm.meta_robots,'index,follow')) LIKE '%noindex%'
    """))
    technical_checks.append({
        'code': 'search.noindex_absent_from_internal_index',
        'severity': 'medium',
        'passed': not noindex_docs,
        'count': len(noindex_docs),
        'message': 'Documents noindex présents dans l’index interne search_documents',
        'recommendation': 'Reconstruire les projections publiques afin de retirer ces contenus de l’index de recherche.'
    })

    for row in con.execute("""
        SELECT red.id, red.old_path
        FROM redirects red
        JOIN routes r ON r.site_id=red.site_id AND r.language_code=red.language_code AND r.full_path=red.old_path AND r.status='active'
        WHERE red.is_active=1
    """):
        errors.append(f"redirects.{row['id']}: redirection active en conflit avec une route active ({row['old_path']})")

    for row in con.execute("""
        SELECT t.id, t.old_path
        FROM tombstones t
        JOIN routes r ON r.site_id=t.site_id AND r.language_code=t.language_code AND r.full_path=t.old_path AND r.status='active'
        WHERE t.is_active=1
    """):
        errors.append(f"tombstones.{row['id']}: tombstone actif en conflit avec une route active ({row['old_path']})")

    failed = [check for check in technical_checks if not check['passed']]
    pages = []
    for row in con.execute("""
        SELECT pcs.*, sm.meta_title, sm.meta_description, sm.meta_robots, sm.canonical_url, sm.json_ld, sm.seo_score
        FROM public_content_snapshots pcs
        LEFT JOIN seo_metadata sm ON sm.site_id=pcs.site_id AND sm.language_code=pcs.language_code AND sm.resource_type=pcs.resource_type AND sm.resource_id=pcs.resource_id
        WHERE pcs.resource_type='content_entry'
        ORDER BY pcs.site_id, pcs.language_code, CASE pcs.route_path WHEN '/' THEN 0 ELSE 1 END, pcs.route_path
    """):
        item = score_page(row, failed)
        item['serp_preview']['url'] = primary_url(con, row['site_id'], row['route_path'])
        pages.append(item)

    return {
        'errors': errors,
        'warnings': warnings,
        'summary': {
            'pages_total': len(pages),
            'average_score': round(sum(page['scores']['overall'] for page in pages) / len(pages)) if pages else None,
            'technical_checks_failed': len(failed),
        },
        'technical_checks': technical_checks,
        'pages': pages,
    }


def audit_front(theme_path: Path) -> dict[str, list[str]]:
    errors: list[str] = []
    warnings: list[str] = []

    required_files = [
        'templates/layout.twig',
        'templates/partials/head.twig',
        'templates/partials/site-header.twig',
        'templates/partials/site-footer.twig',
        'templates/partials/breadcrumbs.twig',
        'templates/partials/content-blocks.twig',
        'templates/partials/content-block.twig',
        'templates/partials/social-share.twig',
        'templates/article.twig',
        'templates/page.twig',
        'templates/search.twig',
        'templates/taxonomy-archive.twig',
        'assets/css/app.css',
    ]
    for relative in required_files:
        if not (theme_path / relative).exists():
            errors.append(f"front: fichier requis manquant ({relative})")

    layout = (theme_path / 'templates/layout.twig').read_text(encoding='utf-8') if (theme_path / 'templates/layout.twig').exists() else ''
    if layout.count("include 'partials/") < 4:
        warnings.append('front: layout.twig devrait rester un shell lisible composé de partials')

    head = (theme_path / 'templates/partials/head.twig').read_text(encoding='utf-8') if (theme_path / 'templates/partials/head.twig').exists() else ''
    for token in ['rel="canonical"', 'apple-touch-icon', 'hreflang', 'og:title', 'twitter:card', 'application/ld+json']:
        if token not in head:
            errors.append(f"front: balise SEO attendue absente dans partials/head.twig ({token})")

    footer = (theme_path / 'templates/partials/site-footer.twig').read_text(encoding='utf-8') if (theme_path / 'templates/partials/site-footer.twig').exists() else ''
    legal_positions = [footer.find('/mentions-legales'), footer.find('/politique-confidentialite'), footer.find('/cookies')]
    if any(pos < 0 for pos in legal_positions) or legal_positions != sorted(legal_positions):
        errors.append('front: ordre attendu des liens légaux absent ou modifié')

    css = (theme_path / 'assets/css/app.css').read_text(encoding='utf-8') if (theme_path / 'assets/css/app.css').exists() else ''
    if 'content-block--' not in css and css.count('@import') < 4:
        warnings.append('front: CSS public non découpé en partials maintenables')

    return {'errors': errors, 'warnings': warnings}


def main() -> int:
    parser = argparse.ArgumentParser(description='Audit SEO local des projections publiques du CMS avec scoring SEO & IA.')
    parser.add_argument('--db', type=Path, default=CORE_PATH, help='Chemin vers core.sqlite')
    parser.add_argument('--json', action='store_true', help='Sortie JSON')
    parser.add_argument('--front', action='store_true', help='Ajoute un audit statique du front public Twig/CSS')
    parser.add_argument('--theme', type=Path, default=BASE / 'frontend' / 'theme-default', help='Chemin du thème public à auditer')
    parser.add_argument('--pages', action='store_true', help='Affiche le scoring détaillé des pages')
    args = parser.parse_args()

    if not args.db.exists():
        print(f"Base introuvable: {args.db}", file=sys.stderr)
        return 2

    report = audit(connect(args.db))
    if args.front:
        front_report = audit_front(args.theme)
        report['errors'].extend(front_report['errors'])
        report['warnings'].extend(front_report['warnings'])
    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
    else:
        print('[seo audit]')
        print(f"pages: {report['summary']['pages_total']} — score moyen: {report['summary']['average_score']}")
        for level in ('errors', 'warnings'):
            items = report[level]
            if not items:
                print(f'{level}: OK')
            else:
                for item in items:
                    print(f'{level[:-1].upper()} {item}')
        if args.pages:
            print('\n[pages]')
            for page in report['pages']:
                print(f"{page['scores']['overall']:>3} {page['status']:<9} {page['path']} — {page['title'] or 'Sans titre'}")
                for rec in page['recommendations'][:3]:
                    print(f"    - {rec['severity']}: {rec['text']}")

    return 1 if report['errors'] else 0


if __name__ == '__main__':
    raise SystemExit(main())
