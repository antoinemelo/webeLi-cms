#!/usr/bin/env python3
"""Baseline M0 de performance locale.

Le contrôle vise un trafic modéré et quelques administrateurs. Il ne remplace
pas un banc de charge, mais donne une preuve reproductible que les parcours
transactionnels essentiels restent sous un seuil raisonnable sur la machine de
qualification.
"""
from __future__ import annotations

import argparse
import json
import os
import platform
import shutil
import socket
import sqlite3
import statistics
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Callable

ROOT = next(parent for parent in Path(__file__).resolve().parents if (parent / "tools" / "cms.py").is_file())
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from tools.python.lib.deploylib import sha256_file
from tools.python.operations.testing.run_playwright_e2e import (
    copy_isolated_instance,
    create_php_router,
    free_port,
    normalize_e2e_site,
    rebuild_databases,
    require_built_frontend,
    require_executable,
    wait_for_http,
)

DEFAULT_REPORT = ROOT / "storage/qualification/performance/latest.json"


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Capture une baseline M0 de performance.")
    parser.add_argument("--report", default=str(DEFAULT_REPORT), help="Rapport JSON à écrire.")
    parser.add_argument("--repeat", type=int, default=int(os.getenv("AMCMS_M0_PERF_REPEAT", "3")))
    parser.add_argument("--critical-ms", type=float, default=float(os.getenv("AMCMS_M0_PERF_CRITICAL_MS", "2000")))
    parser.add_argument("--base-url", default=os.getenv("AMCMS_M0_PERF_BASE_URL", "").strip(), help="URL externe à mesurer au lieu de créer une instance isolée.")
    parser.add_argument("--use-built-assets", action="store_true", help="Exige les assets admin-app déjà compilés dans l'instance isolée.")
    return parser.parse_args(argv)


def _json_body(payload: dict) -> bytes:
    return json.dumps({"data": payload}, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def _request(base_url: str, method: str, path: str, *, body: dict | None = None, headers: dict[str, str] | None = None) -> tuple[int, bytes, float]:
    data = _json_body(body or {}) if body is not None else None
    request_headers = {"Accept": "application/json", **(headers or {})}
    if data is not None:
        request_headers.setdefault("Content-Type", "application/json")
    request = urllib.request.Request(base_url.rstrip("/") + path, data=data, headers=request_headers, method=method)
    start = time.perf_counter()
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            return int(response.status), response.read(), (time.perf_counter() - start) * 1000
    except urllib.error.HTTPError as exc:
        return int(exc.code), exc.read(), (time.perf_counter() - start) * 1000


def _decode_json(raw: bytes) -> dict:
    try:
        data = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return {}
    return data if isinstance(data, dict) else {}


def _php_version() -> str:
    php = shutil.which("php")
    if not php:
        return "absent"
    proc = subprocess.run([php, "-r", "echo PHP_VERSION;"], text=True, capture_output=True, timeout=10)
    return proc.stdout.strip() if proc.returncode == 0 else "unknown"


def _git_commit(root: Path) -> str:
    proc = subprocess.run(["git", "rev-parse", "HEAD"], cwd=root, text=True, capture_output=True, timeout=10)
    return proc.stdout.strip() if proc.returncode == 0 else "unknown"


def _environment() -> dict[str, object]:
    return {
        "platform": platform.platform(),
        "machine": platform.machine(),
        "processor": platform.processor(),
        "cpu_count": os.cpu_count(),
        "python": platform.python_version(),
        "php": _php_version(),
        "commit": _git_commit(ROOT),
    }


def _percentile_95(values: list[float]) -> float:
    if len(values) == 1:
        return values[0]
    ordered = sorted(values)
    index = min(len(ordered) - 1, int(round((len(ordered) - 1) * 0.95)))
    return ordered[index]


def _measure(name: str, critical_ms: float, repeat: int, action: Callable[[], tuple[int, float, str]]) -> dict[str, object]:
    timings: list[float] = []
    statuses: list[int] = []
    detail = ""
    for _ in range(max(1, repeat)):
        status, duration_ms, detail = action()
        statuses.append(status)
        timings.append(duration_ms)
    p95 = _percentile_95(timings)
    failed = any(status >= 500 or status == 0 for status in statuses) or p95 > critical_ms
    return {
        "name": name,
        "status": "failed" if failed else "passed",
        "http_statuses": statuses,
        "samples_ms": [round(value, 2) for value in timings],
        "median_ms": round(statistics.median(timings), 2),
        "p95_ms": round(p95, 2),
        "critical_ms": critical_ms,
        "detail": detail,
    }


def _prepare_sale(instance: Path) -> int:
    sale_db = instance / "storage/database/sale.sqlite"
    business_db = instance / "storage/database/business.sqlite"
    with sqlite3.connect(sale_db) as connection:
        connection.execute("UPDATE sale_channels SET status='active', is_public=1 WHERE site_id=1 AND code='web-main'")
        connection.commit()
    with sqlite3.connect(business_db) as connection:
        row = connection.execute("SELECT id FROM business_product_variants WHERE sku='DEMO-GOURDE-BLEU' LIMIT 1").fetchone()
    if row is None:
        raise RuntimeError("Variant de démonstration DEMO-GOURDE-BLEU absent.")
    return int(row[0])


def _stock_probe(instance: Path, cart_token: str) -> tuple[int, float, str]:
    sale_db = instance / "storage/database/sale.sqlite"
    start = time.perf_counter()
    with sqlite3.connect(sale_db) as connection:
        row = connection.execute(
            """
            SELECT COUNT(*)
            FROM sale_stock_reservations sr
            JOIN sale_carts c ON c.id = sr.cart_id
            WHERE c.cart_token_hash = ?
            """,
            [__import__("hashlib").sha256(cart_token.encode("utf-8")).hexdigest()],
        ).fetchone()
    duration = (time.perf_counter() - start) * 1000
    count = int(row[0] if row else 0)
    return (200 if count > 0 else 500), duration, f"reservations={count}"


def _run_scenarios(base_url: str, instance: Path | None, *, variant_id: int | None, repeat: int, critical_ms: float) -> list[dict[str, object]]:
    created_cart: dict[str, str | int] = {"token": "", "line_id": 0}

    def storefront() -> tuple[int, float, str]:
        status, body, duration = _request(base_url, "GET", "/")
        return status, duration, f"bytes={len(body)}"

    def backoffice() -> tuple[int, float, str]:
        status, body, duration = _request(base_url, "GET", "/admin/login")
        return status, duration, f"bytes={len(body)}"

    def catalog() -> tuple[int, float, str]:
        status, body, duration = _request(base_url, "GET", "/api/v1/sale/channels/web-main/bootstrap?lang=fr")
        return status, duration, str(_decode_json(body).get("meta", {}).get("contract", ""))

    def cart_create() -> tuple[int, float, str]:
        status, body, duration = _request(base_url, "POST", "/api/v1/sale/channels/web-main/cart?lang=fr", body={})
        payload = _decode_json(body)
        token = str(payload.get("data", {}).get("cart", {}).get("token", ""))
        if token:
            created_cart["token"] = token
        return status, duration, f"token_len={len(token)}"

    def cart_add_line() -> tuple[int, float, str]:
        token = str(created_cart.get("token") or "")
        if not token:
            status, _duration, _detail = cart_create()
            if status >= 400:
                return status, _duration, "cart creation failed"
            token = str(created_cart.get("token") or "")
        status, body, duration = _request(
            base_url,
            "POST",
            f"/api/v1/sale/channels/web-main/cart/{urllib.parse.quote(token)}/lines",
            body={"business_variant_id": int(variant_id or 0), "quantity": 1},
            headers={"Idempotency-Key": "perf-line-" + os.urandom(6).hex()},
        )
        payload = _decode_json(body)
        line_id = int(payload.get("data", {}).get("line", {}).get("id") or 0)
        if line_id:
            created_cart["line_id"] = line_id
        return status, duration, f"line_id={line_id}"

    def cart_read() -> tuple[int, float, str]:
        token = str(created_cart.get("token") or "")
        if not token:
            status, _duration, _detail = cart_create()
            if status >= 400:
                return status, _duration, "cart creation failed"
            token = str(created_cart.get("token") or "")
        status, body, duration = _request(base_url, "GET", f"/api/v1/sale/channels/web-main/cart/{urllib.parse.quote(token)}?lang=fr")
        return status, duration, f"bytes={len(body)}"

    def checkout() -> tuple[int, float, str]:
        status, _duration, _detail = cart_add_line()
        if status >= 400:
            return status, _duration, "cart line failed"
        token = str(created_cart.get("token") or "")
        status, body, duration = _request(
            base_url,
            "POST",
            "/api/v1/sale/channels/web-main/checkout",
            body={
                "cart_token": token,
                "identity": {"email": "performance@example.test", "first_name": "Perf", "last_name": "Guest"},
                "billing_address": {"line1": "Rue du Test 1", "postal_code": "1000", "city": "Lausanne", "country_code": "CH"},
                "shipping_same_as_billing": True,
                "shipping_method": {"code": "standard"},
                "payment": {"code": "bank_transfer"},
                "terms_accepted": True,
                "marketing_consent": False,
            },
            headers={"Idempotency-Key": "perf-checkout-" + os.urandom(6).hex()},
        )
        order_id = _decode_json(body).get("data", {}).get("order", {}).get("id", 0)
        created_cart["token"] = ""
        created_cart["line_id"] = 0
        return status, duration, f"order_id={order_id}"

    scenarios: list[tuple[str, Callable[[], tuple[int, float, str]]]] = [
        ("storefront_ssr", storefront),
        ("backoffice_login", backoffice),
        ("api_catalogue", catalog),
        ("cart_create", cart_create),
        ("cart_add_line", cart_add_line),
        ("cart_read", cart_read),
        ("checkout_local_payment", checkout),
    ]
    if instance is not None:
        def stock() -> tuple[int, float, str]:
            token = str(created_cart.get("token") or "")
            if not token:
                status, _duration, _detail = cart_add_line()
                if status >= 400:
                    return status, _duration, "cart line failed"
                token = str(created_cart.get("token") or "")
            return _stock_probe(instance, token)

        scenarios.append(("stock_reservation", stock))

    return [_measure(name, critical_ms, repeat, action) for name, action in scenarios]


def _report_path(value: str) -> Path:
    path = Path(value).expanduser()
    return path if path.is_absolute() else ROOT / path


def _run_external(args: argparse.Namespace) -> tuple[list[dict[str, object]], dict[str, object]]:
    return _run_scenarios(args.base_url, None, variant_id=None, repeat=args.repeat, critical_ms=args.critical_ms), {
        "mode": "external",
        "base_url": args.base_url,
    }


def _run_isolated(args: argparse.Namespace) -> tuple[list[dict[str, object]], dict[str, object]]:
    require_executable("php")
    cms_port = free_port()
    base_url = f"http://127.0.0.1:{cms_port}"
    temporary = Path(tempfile.mkdtemp(prefix="amcms-perf-"))
    instance = temporary / "instance"
    php_log = temporary / "php-server.log"
    php_process: subprocess.Popen[bytes] | None = None
    log_handle = None
    try:
        copy_isolated_instance(instance)
        if args.use_built_assets:
            require_built_frontend(instance)
        rebuild_databases(instance)
        normalize_e2e_site(instance)
        variant_id = _prepare_sale(instance)
        router = create_php_router(instance)
        runtime_env = os.environ.copy()
        runtime_env.update({"APP_ENV": "test", "APP_DEBUG": "0", "APP_BASE_PATH": ""})
        log_handle = php_log.open("wb")
        php_process = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{cms_port}", router.name],
            cwd=instance,
            env=runtime_env,
            stdout=log_handle,
            stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        wait_for_http(base_url + "/admin/login", php_process)
        scenarios = _run_scenarios(base_url, instance, variant_id=variant_id, repeat=args.repeat, critical_ms=args.critical_ms)
        context = {
            "mode": "isolated",
            "base_url": base_url,
            "instance_root_hash": sha256_file(instance / "database/schema/core.sql") if (instance / "database/schema/core.sql").is_file() else None,
        }
        return scenarios, context
    finally:
        if php_process is not None:
            php_process.terminate()
            try:
                php_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                php_process.kill()
                php_process.wait(timeout=5)
        if log_handle is not None:
            log_handle.close()
        shutil.rmtree(temporary, ignore_errors=True)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    report_path = _report_path(args.report)
    report_path.parent.mkdir(parents=True, exist_ok=True)
    started = time.time()
    try:
        scenarios, context = _run_external(args) if args.base_url else _run_isolated(args)
        status = "failed" if any(item["status"] == "failed" for item in scenarios) else "passed"
        payload = {
            "schema_version": 1,
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "duration_ms": round((time.time() - started) * 1000, 2),
            "environment": _environment(),
            "thresholds": {
                "critical_ms": args.critical_ms,
                "repeat": max(1, args.repeat),
                "config": {
                    "AMCMS_M0_PERF_CRITICAL_MS": os.getenv("AMCMS_M0_PERF_CRITICAL_MS"),
                    "AMCMS_M0_PERF_REPEAT": os.getenv("AMCMS_M0_PERF_REPEAT"),
                },
            },
            "context": context,
            "scenarios": scenarios,
            "status": status,
        }
        report_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"status": status, "report": report_path.relative_to(ROOT).as_posix()}, ensure_ascii=False))
        return 0 if status == "passed" else 1
    except (OSError, RuntimeError, socket.error, subprocess.SubprocessError) as exc:
        payload = {
            "schema_version": 1,
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "environment": _environment(),
            "status": "skipped",
            "reason": str(exc),
        }
        report_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
