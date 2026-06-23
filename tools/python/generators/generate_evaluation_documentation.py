#!/usr/bin/env python3
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import re
import subprocess
from pathlib import Path
from typing import Any

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
OUT = ROOT / "docs" / "evaluation" / "machine-readable"
DATE = dt.date.today().isoformat()
ALLOWED_TEXT_SUFFIXES = {".php", ".sql", ".py", ".ts", ".vue", ".json", ".md", ".yml", ".yaml"}


def rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def stable_id(prefix: str, value: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-")
    return f"{prefix}-{slug}-{hashlib.sha1(value.encode()).hexdigest()[:10]}" if slug else f"{prefix}-{hashlib.sha1(value.encode()).hexdigest()[:12]}"


def dump(name: str, value: Any) -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / name).write_text(json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def git_value(*args: str) -> str | None:
    try:
        value = subprocess.check_output(["git", *args], cwd=ROOT, stderr=subprocess.DEVNULL, text=True).strip()
        return value or None
    except (OSError, subprocess.CalledProcessError):
        return None


def evidence_record(identifier: str, *, status: str, source: list[str], confidence: str = "medium", limitations: list[str] | None = None, **extra: Any) -> dict[str, Any]:
    return {
        "id": identifier,
        **extra,
        "status": status,
        "source": source,
        "evidence": source.copy(),
        "last_verified": DATE,
        "confidence": confidence,
        "limitations": limitations or [],
    }


def discover_routes() -> list[dict[str, Any]]:
    results: dict[tuple[str, str, str], dict[str, Any]] = {}
    route_files = sorted((ROOT / "backend" / "routes").glob("*.php"))
    route_files += sorted((ROOT / "backend" / "src" / "Modules").rglob("*Provider.php"))
    patterns = [
        re.compile(r"\[\s*['\"](GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD)['\"]\s*,\s*['\"]([^'\"]+)['\"]", re.I),
        re.compile(r"->(get|post|put|patch|delete|options|head)\(\s*['\"]([^'\"]+)['\"]", re.I),
    ]
    for path in route_files:
        text = path.read_text(encoding="utf-8", errors="ignore")
        for pattern in patterns:
            for match in pattern.finditer(text):
                method, uri = match.group(1).upper(), match.group(2)
                key = (method, uri, rel(path))
                audience = "public" if "/api/v1" in uri or uri.startswith("/api/v1") else "administrative-internal" if "/api" in uri else "web"
                results[key] = evidence_record(
                    stable_id("route", f"{method}-{uri}-{rel(path)}"),
                    status="internal" if audience == "administrative-internal" else "supported",
                    source=[rel(path)],
                    confidence="high",
                    limitations=["A route declaration does not prove successful end-to-end execution."],
                    method=method,
                    path=uri,
                    audience=audience,
                )
    return sorted(results.values(), key=lambda item: (item["path"], item["method"], item["source"][0]))


def discover_permissions() -> list[dict[str, Any]]:
    pattern = re.compile(r"['\"]([a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+)['\"]")
    accepted_suffixes = (".read", ".write", ".create", ".update", ".delete", ".publish", ".approve", ".manage", ".run", ".export", ".restore", ".admin", ".use", ".apply", ".suggest", ".view")
    found: dict[str, set[str]] = {}
    for base in (ROOT / "backend", ROOT / "database", ROOT / "frontend" / "admin-vue" / "src"):
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if not path.is_file() or path.suffix.lower() not in ALLOWED_TEXT_SUFFIXES:
                continue
            for value in pattern.findall(path.read_text(encoding="utf-8", errors="ignore")):
                if value.endswith(accepted_suffixes) or value.startswith(("content.", "media.", "seo.", "sites.", "iam.", "ai.", "modules.", "imports.", "exports.", "settings.", "forms.")):
                    found.setdefault(value, set()).add(rel(path))
    return [evidence_record(stable_id("permission", key), status="supported", source=sorted(paths), confidence="high", limitations=["The effective role assignment and site scope must be checked separately."], permission=key) for key, paths in sorted(found.items())]


def discover_databases() -> list[dict[str, Any]]:
    create_table = re.compile(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[\"`\[]?([A-Za-z0-9_]+)", re.I)
    create_view = re.compile(r"CREATE\s+(?:TEMP\s+)?VIEW\s+(?:IF\s+NOT\s+EXISTS\s+)?[\"`\[]?([A-Za-z0-9_]+)", re.I)
    records = []
    for path in sorted((ROOT / "database").rglob("*.sql")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        records.append(evidence_record(
            stable_id("database-source", rel(path)), status="supported", source=[rel(path)], confidence="high",
            limitations=["This describes the schema source, not the contents of a deployed database."],
            tables=sorted(set(create_table.findall(text))), views=sorted(set(create_view.findall(text))),
            kind="migration" if "/migrations/" in f"/{rel(path)}" else "seed" if "/seeds/" in f"/{rel(path)}" else "view" if "/views/" in f"/{rel(path)}" else "schema",
        ))
    return records


def discover_tests() -> list[dict[str, Any]]:
    records = []
    for path in sorted((ROOT / "tools" / "python" / "tests").glob("test_*.py")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        count = len(re.findall(r"^\s*def\s+test_", text, flags=re.M))
        records.append(evidence_record(stable_id("test-file", rel(path)), status="unverified", source=[rel(path)], confidence="high", limitations=["File presence is not proof that the tests were executed successfully."], name=path.stem, command="python3 tools/cms.py test", discovered_test_functions=count, execution_result=None))
    return records


def discover_validators() -> list[dict[str, Any]]:
    import sys
    if str(ROOT) not in sys.path: sys.path.insert(0, str(ROOT))
    from tools.python.validation.registry import VALIDATORS
    return [evidence_record(
        stable_id("validator", item.name),
        status="unverified",
        source=[item.module.replace(".", "/") + ".py"],
        confidence="high",
        limitations=["Validator presence is distinct from execution and coverage completeness."],
        name=item.name,
        command=f"python3 tools/cms.py validate --validator {item.name}",
        execution_result=None,
    ) for item in VALIDATORS]


def feature_records() -> list[dict[str, Any]]:
    definitions = [
        ("accounts-authentication", "IAM", "supported", ["backend/src/Application/Admin/AdminAuthController.php", "database/iam.sql"], "Administrative authentication is implemented; all deployment flows remain environment-dependent."),
        ("totp-two-factor", "IAM", "supported", ["database/migrations/iam/0004_totp_2fa.sql", "tools/python/validation/security/baseline.py"], "Recovery and all mail-dependent paths require scenario testing."),
        ("email-two-factor-passwordless", "IAM", "partial", ["tools/python/validation/security/baseline.py", "tools/python/validation/security/baseline.py"], "Depends on external mail configuration."),
        ("roles-permissions", "IAM", "supported", ["database/seeds/default/iam_default_seed.sql", "backend/src/Application/Iam/IamAdminRepository.php"], "Custom roles and site scope must be verified on the deployed data."),
        ("multisite-multilingual", "sites", "supported", ["database/schema/core.sql", "tools/python/validation/content/site_locale.py"], "Not every domain and language combination is covered."),
        ("content-types-blueprints-fields-blocks", "content-model", "supported", ["database/schema/core.sql", "backend/src/Application/Schema/NativeFieldBlueprintRegistry.php"], "Third-party extension compatibility is not fully qualified."),
        ("drafts-revisions-preview", "workflow", "supported", ["backend/src/Domain/Content/ContentRevision.php", "backend/src/Application/Api/Admin/PreviewApiController.php"], "Concurrent editing behavior requires targeted tests."),
        ("visual-editor-in-context", "editing", "partial", ["frontend/admin-vue/src/components/editor/VisualEditorShell.vue", "frontend/theme-default/assets/js/visual-editing-bridge.js", "backend/src/Application/Api/Admin/VisualEditingApiController.php", "backend/routes/api.php"], "The controlled visual editor is implemented and documented; a full browser E2E path must still verify selection, save, revision, publication, language, media and locks."),
        ("publication-projections", "workflow", "supported", ["backend/src/Application/Publication/PublishedProjectionPipeline.php", "tools/python/validation/content/contracts.py"], "High-concurrency publication is not benchmarked."),
        ("media-variants-local-storage", "media", "supported", ["backend/src/Application/Media/GenerateMediaVariants.php", "backend/src/Application/Media/Storage/LocalStorageDriver.php"], "Malware scanning is not demonstrated."),
        ("media-s3-storage", "media", "partial", ["backend/src/Application/Media/Storage/S3StorageDriver.php", "tools/python/validation/operations/media.py"], "External provider integration was not executed by this generator."),
        ("menus-taxonomies", "navigation", "supported", ["backend/src/Application/Api/Admin/MenuApiController.php", "backend/src/Application/Taxonomy/AssignTaxonomyTerms.php"], "End-user usability requires manual testing."),
        ("seo-runtime-audit", "seo", "supported", ["backend/src/Application/Seo/RunSeoAudit.php", "database/views/seo_views.sql"], "Internal SEO scores do not predict search rankings."),
        ("native-search", "search", "supported", ["backend/src/Application/Search/PublicSearchReadRepository.php", "tools/python/validation/database/projections.py"], "Distributed or semantic search is not demonstrated."),
        ("forms", "forms", "supported", ["database/modules/forms.sql", "tools/python/validation/content/contracts.py"], "External anti-spam and privacy operations require configuration."),
        ("cookies-consent", "consent", "supported", ["database/modules/cookies.sql", "tools/python/validation/content/contracts.py"], "No legal compliance certification is claimed."),
        ("editorial-import-export", "operations", "supported", ["backend/src/EditorialPackage/EditorialExportService.php", "tools/python/validation/operations/manifests.py"], "Cross-version interoperability must be tested."),
        ("static-export", "operations", "partial", ["tools/python/validation/operations/manifests.py", "tools/python/validation/operations/release_structure.py"], "Target hosting and full runtime parity require scenario testing."),
        ("ai-assistant", "ai", "experimental", ["database/modules/ai.sql", "backend/src/Application/Api/Admin/AiAssistantApiController.php"], "Provider availability, costs, data protection and output quality are external concerns."),
        ("public-api-v1", "headless", "supported", ["backend/src/Application/PublicApi/PublicApiKernel.php", "docs/public-api/"], "Long-term SLA and all client integrations are not demonstrated."),
        ("backup-restore", "operations", "supported", ["tools/python/commands/backup.py"], "A backup is not proven until restoration is tested on a copy."),
        ("release-packaging-deployment", "operations", "partial", ["tools/python/operations/deployment/d2_package_release.py", ".github/workflows/release.yml"], "Production infrastructure was not exercised by this generation."),
        ("centralized-observability", "operations", "not-supported", ["backend/src/Core/Logger.php"], "Local logging exists, but a native centralized metrics/tracing stack is not demonstrated."),
    ]
    records = []
    for name, domain, status, sources, limitation in definitions:
        existing = [source for source in sources if (ROOT / source).exists()]
        effective = status if len(existing) == len(sources) else "unknown"
        records.append(evidence_record(stable_id("feature", name), status=effective, source=existing, confidence="high" if effective != "unknown" else "low", limitations=[limitation] + ([] if effective != "unknown" else ["One or more expected evidence paths are missing."]), feature=name, domain=domain, audience="administrative-or-public", permission=None, interface=None, api=None, command=None, data=None, tests=[], validators=[]))
    return records


def architecture() -> dict[str, Any]:
    components = []
    for identifier, responsibility, source, status in [
        ("php-runtime", "HTTP runtime, routing, services and SSR", "backend/", "supported"),
        ("twig-rendering", "Public server-side templates", "frontend/theme-default/", "supported"),
        ("vue-typescript-backoffice", "Administrative SPA source", "frontend/admin-vue/src/", "supported"),
        ("compiled-admin-assets", "Deployable administrative assets", "admin-app/", "supported"),
        ("sqlite-schemas", "Persistent schemas and seeds", "database/", "supported"),
        ("python-tooling", "Local operations, validation and release", "tools/python/", "supported"),
    ]:
        source_exists = (ROOT / source).exists()
        components.append(evidence_record(stable_id("component", identifier), status=status if source_exists else "unknown", source=[source] if source_exists else [], confidence="high", limitations=[] if source_exists else ["Expected path is missing."], name=identifier, responsibility=responsibility))
    return {"id": "architecture-current", "status": "supported", "source": ["backend/", "frontend/", "database/", "tools/python/"], "evidence": ["backend/", "frontend/", "database/", "tools/python/"], "last_verified": DATE, "confidence": "high", "limitations": ["Static inventory does not measure runtime performance or operational resilience."], "components": components}


def release_contents() -> dict[str, Any]:
    sources = ["tools/python/operations/deployment/d2_package_release.py", "tools/python/operations/deployment/d4_verify_release_archive.py"]
    existing = [source for source in sources if (ROOT / source).exists()]
    return evidence_record("release-contents-current", status="partial", source=existing, confidence="medium", limitations=["The exact content must be checked against an archive produced by the current tree."], included_documentation=["docs/README.md", "docs/user-guide/", "docs/administration/", "docs/installation/", "docs/api/", "docs/reference/", "docs/public-api/", "docs/evaluation/"], excluded_runtime_data=["storage/database/*.sqlite", "secrets", "local environment files"], exact_archive_result=None)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Generate evidence-oriented CMS evaluation references.")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)

    routes = discover_routes()
    public_api = [{**item, "id": item["id"].replace("route-", "api-endpoint-", 1)} for item in routes if item["audience"] == "public"]
    dump("routes.json", routes)
    dump("api-endpoints.json", public_api)
    dump("permissions.json", discover_permissions())
    dump("databases.json", discover_databases())
    dump("tests.json", discover_tests())
    dump("validators.json", discover_validators())
    dump("features.json", feature_records())
    dump("architecture.json", architecture())
    dump("release-contents.json", release_contents())

    manifest = evidence_record(
        "cms-evaluation-manifest", status="supported", source=["backend/composer.json", "frontend/admin-vue/package.json", "database/", "tools/cms.py", "docs/evaluation/"], confidence="high",
        limitations=["Generated inventories prove source presence and structure, not complete end-to-end usability."],
        product_name="DEC CMS", generated_at=DATE,
        commit=git_value("rev-parse", "HEAD"), dirty=(git_value("status", "--porcelain") not in (None, "")),
        scope=["runtime", "backoffice", "public-api", "databases", "python-tooling", "documentation", "release"],
        technologies=["PHP 8.2+", "Twig 3", "Vue 3", "TypeScript", "Pinia", "Vite", "SQLite", "Python 3"],
        databases=["core", "iam", "modules"], modules=["forms", "cookies/consent", "ai", "analytics"],
        audiences=["editor", "publisher", "seo-manager", "administrator", "superadministrator", "installer", "operator", "api-integrator", "developer", "evaluator", "ai-evaluator"],
        commands=["python3 tools/cms.py rebuild", "python3 tools/cms.py test", "python3 tools/cms.py validate", "python3 tools/cms.py docs generate", "python3 tools/cms.py docs check", "python3 tools/cms.py docs evaluation-generate", "python3 tools/cms.py docs evaluation-check", "python3 tools/cms.py backup --help", "python3 tools/cms.py release --help"],
        evaluation_documents=sorted(rel(path) for path in (ROOT / "docs" / "evaluation").glob("*.md")),
        machine_readable_documents=sorted(path.name for path in OUT.glob("*.json")),
    )
    dump("cms-evaluation-manifest.json", manifest)

    result = {"ok": True, "generated": sorted(path.name for path in OUT.glob("*.json")), "counts": {"routes": len(routes), "public_api_endpoints": len(public_api)}}
    print(json.dumps(result, ensure_ascii=False) if args.json else f"Documentation d’évaluation générée: {len(result['generated'])} fichiers JSON")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
