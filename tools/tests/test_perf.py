#!/usr/bin/env python3
"""
Benchmark comparatif local/distant pour webeLi.

Ce script est volontairement distinct de test_load.py :
- test_perf.py mesure des temps de réponse répétés à faible concurrence ;
- test_load.py produit une montée en charge avec utilisateurs virtuels.

Objectif : comparer une cible distante, par exemple https://webe.li/mod/ , avec
une cible locale, par exemple http://127.0.0.1:8080/mod/ , en séparant public,
API et administration. Par défaut, le script tente un run complet en lecture
(public + API + admin si un CSV d'identifiants est fourni ou détecté), mesure le
profil de données local si les bases SQLite sont accessibles, et produit un
dossier de preuve vérifiable.

Dépendance :
    python -m pip install requests

Exemple :
    python test_perf.py --iterations 30 --warmup 5 --yes

Avec administration et API protégée :
    python test_perf.py --credentials-file users.csv --tokens-file token.csv --yes

IMPORTANT : n'exécutez ce programme que sur une infrastructure que vous êtes
autorisé à tester. Par défaut, le script reste à faible concurrence, ne modifie
pas les données et ne constitue pas un test de charge.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import math
import os
import platform
import random
import socket
import sqlite3
import ssl
import statistics
import sys
import time
import uuid
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from html.parser import HTMLParser
from http.cookies import SimpleCookie
from pathlib import Path
from typing import Any
from urllib.parse import parse_qsl, urlencode, urljoin, urlparse, urlunparse

import requests


PROGRAM_VERSION = "1.3.0"
USER_AGENT = f"webeLi-perf-compare/{PROGRAM_VERSION} (+authorized-performance-benchmark)"
DEFAULT_REMOTE_URL = "https://webe.li/mod/"
DEFAULT_LOCAL_URL = "http://127.0.0.1:8080/mod/"
DEFAULT_CREDENTIALS_FILENAME = "users.csv"
DEFAULT_TOKENS_FILENAME = "token.csv"
SENSITIVE_HEADERS = {"set-cookie", "cookie", "authorization", "proxy-authorization"}

PUBLIC_ROUTES = [
    {"area": "public", "name": "home", "method": "GET", "path": ""},
    {"area": "public", "name": "articles", "method": "GET", "path": "articles"},
    {"area": "public", "name": "search", "method": "GET", "path": "search?q=cms"},
    {"area": "public", "name": "sitemap", "method": "GET", "path": "sitemap.xml"},
    {"area": "public", "name": "robots", "method": "GET", "path": "robots.txt"},
]

API_ROUTES = [
    {"area": "api", "name": "health", "method": "GET", "path": "api/v1/health", "auth_policy": "anonymous"},
    {"area": "api", "name": "cookies-config", "method": "GET", "path": "api/v1/cookies/config", "auth_policy": "anonymous"},
    {"area": "api", "name": "form-contact", "method": "GET", "path": "api/v1/forms/contact", "auth_policy": "anonymous"},
    {"area": "api", "name": "media", "method": "GET", "path": "api/v1/media", "auth_policy": "anonymous"},
    {"area": "api", "name": "languages", "method": "GET", "path": "api/v1/languages", "auth_policy": "bearer"},
    {"area": "api", "name": "menus", "method": "GET", "path": "api/v1/menus", "auth_policy": "bearer"},
    {"area": "api", "name": "content", "method": "GET", "path": "api/v1/content", "auth_policy": "bearer"},
    {"area": "api", "name": "search", "method": "GET", "path": "api/v1/search?q=cms", "auth_policy": "bearer"},
]

ADMIN_ROUTES = [
    {"area": "admin", "name": "login-page", "method": "GET", "path": "admin/login", "requires_auth": False},
    {"area": "admin", "name": "admin-app", "method": "GET", "path": "admin/app", "requires_auth": True},
    {"area": "admin", "name": "admin-context", "method": "GET", "path": "admin/api/context", "requires_auth": True},
    {"area": "admin", "name": "admin-entries", "method": "GET", "path": "admin/api/entries", "requires_auth": True},
    {"area": "admin", "name": "admin-media", "method": "GET", "path": "admin/api/media", "requires_auth": True},
]

DATASET_TABLES = {
    "core.sqlite": {
        "sites": "sites",
        "languages": "languages",
        "content_types": "content_types",
        "content_entries": "content_entries",
        "content_entry_localizations": "content_entry_localizations",
        "public_content_snapshots": "public_content_snapshots",
        "search_documents": "search_documents",
        "routes": "routes",
        "seo_metadata": "seo_metadata",
        "media_assets": "media_assets",
        "media_asset_variants": "media_asset_variants",
        "menus": "menus",
        "menu_items": "menu_items",
        "revisions": "revisions",
        "redirects": "redirects",
    },
    "iam.sqlite": {
        "iam_users": "iam_users",
        "iam_roles": "iam_roles",
        "iam_user_roles": "iam_user_roles",
        "api_tokens": "api_tokens",
        "iam_sessions": "iam_sessions",
    },
    "forms.sqlite": {
        "forms": "forms",
        "form_fields": "form_fields",
        "form_submissions": "form_submissions",
    },
    "cookies.sqlite": {
        "cookie_categories": "cookie_categories",
        "cookie_services": "cookie_services",
        "cookie_consent_logs": "cookie_consent_logs",
    },
    "ai.sqlite": {
        "ai_tasks": "ai_tasks",
        "ai_suggestions": "ai_suggestions",
        "ai_usage_events": "ai_usage_events",
    },
}


@dataclass
class TargetConfig:
    name: str
    base_url: str
    headers: dict[str, str]


@dataclass
class RouteConfig:
    area: str
    name: str
    method: str
    path: str
    requires_auth: bool = False
    auth_policy: str = "anonymous"  # anonymous | bearer | admin-session


@dataclass
class RequestMeasure:
    evidence_id: str
    target: str
    area: str
    route_name: str
    auth_policy: str
    bearer_used: bool
    iteration: int
    warmup: bool
    method: str
    requested_url: str
    final_url: str | None
    started_at_utc: str
    ended_at_utc: str
    total_ms: float
    ttfb_ms: float
    status: int | None
    ok: bool
    bytes_received: int
    response_sha256: str | None
    redirect_count: int
    content_type: str | None
    server_header: str | None
    request_trace_header: str | None
    error_category: str | None
    error: str | None


class FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict[str, Any]] = []
        self._current: dict[str, Any] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        data = {key.lower(): (value or "") for key, value in attrs}
        if tag.lower() == "form":
            self._current = {
                "action": data.get("action", ""),
                "method": data.get("method", "post").lower(),
                "inputs": [],
            }
            self.forms.append(self._current)
        elif tag.lower() == "input" and self._current is not None:
            self._current["inputs"].append(data)


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="microseconds")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def secret_fingerprint(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()[:12]


def normalize_token_value(value: str) -> str:
    token = value.strip()
    if token.lower().startswith("bearer "):
        token = token.split(None, 1)[1].strip()
    return token


def normalize_base_url(value: str) -> str:
    return value.rstrip("/") + "/"


def normalize_url_for_match(value: str) -> str:
    parsed = urlparse(normalize_base_url(value.strip()))
    scheme = parsed.scheme.lower()
    hostname = (parsed.hostname or "").lower()
    port = parsed.port
    netloc = hostname
    if port and not ((scheme == "http" and port == 80) or (scheme == "https" and port == 443)):
        netloc = f"{hostname}:{port}"
    return urlunparse((scheme, netloc, parsed.path.rstrip("/") + "/", "", "", ""))


def add_query(url: str, params: dict[str, str]) -> str:
    parsed = urlparse(url)
    current = dict(parse_qsl(parsed.query, keep_blank_values=True))
    current.update(params)
    return urlunparse(parsed._replace(query=urlencode(current)))


def route_url(base_url: str, route: RouteConfig) -> str:
    return urljoin(base_url, route.path)


def percentile(values: list[float], fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    rank = (len(ordered) - 1) * fraction
    low = math.floor(rank)
    high = math.ceil(rank)
    if low == high:
        return ordered[low]
    return ordered[low] + (ordered[high] - ordered[low]) * (rank - low)


def classify_status(status: int | None, error: str | None) -> str | None:
    if error:
        return "network"
    if status is None:
        return "network"
    if status == 429:
        return "traffic_protection_429"
    if 500 <= status:
        return "application_5xx"
    if status in {401, 403}:
        return "access_401_403"
    if status == 404:
        return "not_found_404"
    if 400 <= status:
        return "client_4xx"
    if 300 <= status:
        return "redirect_3xx"
    return None


def parse_form(html: str) -> dict[str, Any] | None:
    parser = FormParser()
    parser.feed(html)
    if not parser.forms:
        return None
    for form in parser.forms:
        types = {item.get("type", "text").lower() for item in form["inputs"]}
        names = {item.get("name", "").lower() for item in form["inputs"]}
        if "password" in types or any("email" in name or "login" in name for name in names):
            return form
    return parser.forms[0]


def build_form_payload(form: dict[str, Any], email: str | None, password: str | None) -> dict[str, str]:
    payload: dict[str, str] = {}
    email_assigned = False
    password_assigned = False
    for field in form.get("inputs", []):
        name = field.get("name", "")
        if not name:
            continue
        field_type = field.get("type", "text").lower()
        lower_name = name.lower()
        value = field.get("value", "")
        if field_type in {"hidden", "submit"}:
            payload[name] = value
        elif password is not None and (field_type == "password" or "password" in lower_name or "passwd" in lower_name):
            payload[name] = password
            password_assigned = True
        elif email is not None and (field_type == "email" or "email" in lower_name or "login" in lower_name or "user" in lower_name):
            payload[name] = email
            email_assigned = True
    if email is not None and not email_assigned:
        payload["email"] = email
    if password is not None and not password_assigned:
        payload["password"] = password
    return payload


class EvidenceSession:
    """Session HTTP qui évite de stocker les secrets dans les preuves."""

    def __init__(self, headers: dict[str, str] | None = None, bearer_token: str | None = None):
        self.session = requests.Session()
        self.headers = {
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Cache-Control": "no-cache",
        }
        if headers:
            self.headers.update(headers)
        self.bearer_token = bearer_token
        self.cookies: dict[str, str] = {}

    def close(self) -> None:
        self.session.close()

    def _cookie_header(self) -> str | None:
        if not self.cookies:
            return None
        return "; ".join(f"{key}={value}" for key, value in sorted(self.cookies.items()))

    def _remember_cookies(self, response: requests.Response) -> None:
        raw = response.headers.get("Set-Cookie")
        if not raw:
            return
        cookie = SimpleCookie()
        try:
            cookie.load(raw)
        except Exception:
            return
        for key, morsel in cookie.items():
            if morsel.value:
                self.cookies[key] = morsel.value

    def request(self, method: str, url: str, *, timeout: float, allow_redirects: bool = True, bearer: bool = False, **kwargs: Any) -> requests.Response:
        headers = dict(self.headers)
        headers.update(kwargs.pop("headers", {}) or {})
        cookie_header = self._cookie_header()
        if cookie_header:
            headers["Cookie"] = cookie_header
        if bearer and self.bearer_token:
            headers["Authorization"] = f"Bearer {self.bearer_token}"
        response = self.session.request(
            method,
            url,
            timeout=timeout,
            allow_redirects=allow_redirects,
            headers=headers,
            **kwargs,
        )
        self._remember_cookies(response)
        for item in response.history:
            self._remember_cookies(item)
        return response


def measure_once(
    evidence_id: str,
    target: TargetConfig,
    route: RouteConfig,
    iteration: int,
    warmup: bool,
    session: EvidenceSession,
    timeout: float,
    cache_bust: bool,
) -> RequestMeasure:
    url = route_url(target.base_url, route)
    if cache_bust:
        url = add_query(url, {"_perf": f"{evidence_id[:8]}-{iteration}-{target.name}-{route.name}"})
    started_at = utc_now()
    started = time.perf_counter()
    response: requests.Response | None = None
    bearer_required = route.auth_policy == "bearer"
    bearer_used = bearer_required and bool(session.bearer_token)
    try:
        response = session.request(
            route.method,
            url,
            timeout=timeout,
            allow_redirects=True,
            bearer=bearer_required,
            stream=True,
        )
        ttfb_ms = (time.perf_counter() - started) * 1000
        body = response.content
        total_ms = (time.perf_counter() - started) * 1000
        status = response.status_code
        ok = 200 <= status < 400
        trace_header = (
            response.headers.get("X-Request-ID")
            or response.headers.get("X-Correlation-ID")
            or response.headers.get("Traceparent")
            or response.headers.get("CF-Ray")
        )
        return RequestMeasure(
            evidence_id=evidence_id,
            target=target.name,
            area=route.area,
            route_name=route.name,
            auth_policy=route.auth_policy,
            bearer_used=bearer_used,
            iteration=iteration,
            warmup=warmup,
            method=route.method,
            requested_url=url,
            final_url=response.url,
            started_at_utc=started_at,
            ended_at_utc=utc_now(),
            total_ms=round(total_ms, 3),
            ttfb_ms=round(ttfb_ms, 3),
            status=status,
            ok=ok,
            bytes_received=len(body),
            response_sha256=sha256_bytes(body),
            redirect_count=len(response.history),
            content_type=response.headers.get("Content-Type"),
            server_header=response.headers.get("Server"),
            request_trace_header=trace_header,
            error_category=classify_status(status, None),
            error=None if ok else f"HTTP {status}",
        )
    except requests.RequestException as exc:
        total_ms = (time.perf_counter() - started) * 1000
        error_text = f"{type(exc).__name__}: {exc}"
        return RequestMeasure(
            evidence_id=evidence_id,
            target=target.name,
            area=route.area,
            route_name=route.name,
            auth_policy=route.auth_policy,
            bearer_used=bearer_used,
            iteration=iteration,
            warmup=warmup,
            method=route.method,
            requested_url=url,
            final_url=None,
            started_at_utc=started_at,
            ended_at_utc=utc_now(),
            total_ms=round(total_ms, 3),
            ttfb_ms=round(total_ms, 3),
            status=None,
            ok=False,
            bytes_received=0,
            response_sha256=None,
            redirect_count=0,
            content_type=None,
            server_header=None,
            request_trace_header=None,
            error_category=classify_status(None, error_text),
            error=error_text,
        )
    finally:
        if response is not None:
            response.close()


def first_present(row: dict[str, str], names: list[str]) -> str:
    for name in names:
        value = row.get(name, "")
        if value:
            return value
    return ""


def credential_fingerprint(login: str) -> str:
    return secret_fingerprint(login)


def load_credentials_csv(path: Path) -> list[tuple[str, str]]:
    if not path.is_file():
        raise ValueError(f"Fichier d'identifiants introuvable : {path}")
    credentials: list[tuple[str, str]] = []
    with path.open("r", newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        raw_fieldnames = reader.fieldnames or []
        fieldnames = {name.strip().lower() for name in raw_fieldnames}
        login_names = ["email", "username", "login", "user", "nom", "name"]
        password_names = ["password", "mot_de_passe", "motdepasse", "pass"]
        if not any(name in fieldnames for name in login_names) or not any(name in fieldnames for name in password_names):
            raise ValueError(
                "Le CSV doit contenir une colonne identifiant "
                "(email, username, login, user, nom ou name) et une colonne mot de passe "
                "(password, mot_de_passe, motdepasse ou pass)."
            )
        for line_no, row in enumerate(reader, start=2):
            normalized = {(key or "").strip().lower(): (value or "").strip() for key, value in row.items()}
            login = first_present(normalized, login_names)
            password = first_present(normalized, password_names)
            if not login or not password:
                raise ValueError(f"Identifiant incomplet à la ligne {line_no} du fichier CSV.")
            credentials.append((login, password))
    if not credentials:
        raise ValueError("Le fichier d'identifiants ne contient aucun compte complet.")
    return credentials


def resolve_credentials_file(value: Path | None) -> tuple[Path | None, str]:
    if value is not None:
        return value, "explicit"
    candidate = Path(__file__).resolve().parent / DEFAULT_CREDENTIALS_FILENAME
    if candidate.is_file():
        return candidate, "auto"
    return None, "none"


def resolve_tokens_file(value: Path | None) -> tuple[Path | None, str]:
    if value is not None:
        return value, "explicit"
    candidate = Path(__file__).resolve().parent / DEFAULT_TOKENS_FILENAME
    if candidate.is_file():
        return candidate, "auto"
    return None, "none"


def load_tokens_csv(path: Path, targets: list[TargetConfig]) -> dict[str, str]:
    if not path.is_file():
        raise ValueError(f"Fichier de tokens introuvable : {path}")
    target_urls = {normalize_url_for_match(target.base_url): target.name for target in targets}
    tokens: dict[str, str] = {}
    unmatched_rows: list[int] = []
    with path.open("r", newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        raw_fieldnames = reader.fieldnames or []
        fieldnames = {name.strip().lower() for name in raw_fieldnames}
        if "token" not in fieldnames:
            raise ValueError("Le CSV de tokens doit contenir une colonne token.")
        for line_no, row in enumerate(reader, start=2):
            normalized = {(key or "").strip().lower(): (value or "").strip() for key, value in row.items()}
            token = normalize_token_value(normalized.get("token", ""))
            if not token:
                continue
            raw_url = first_present(normalized, ["url", "base_url", "target", "cible"])
            raw_name = first_present(normalized, ["nom", "name", "target_name", "cible_nom", "environment", "env"]).lower()
            target_name: str | None = None
            if raw_url:
                target_name = target_urls.get(normalize_url_for_match(raw_url))
            if target_name is None and raw_name:
                if raw_name in {"local", "localhost", "127.0.0.1"}:
                    target_name = "local"
                elif raw_name in {"remote", "distant", "prod", "production", "webe.li"}:
                    target_name = "remote"
            if target_name:
                tokens[target_name] = token
            elif len(targets) == 1:
                tokens[targets[0].name] = token
            else:
                unmatched_rows.append(line_no)
    return tokens


def authenticate_admin(target: TargetConfig, session: EvidenceSession, email: str, password: str, timeout: float) -> tuple[bool, str]:
    login_url = urljoin(target.base_url, "admin/login")
    try:
        first = session.request("GET", login_url, timeout=timeout, allow_redirects=True)
    except requests.RequestException as exc:
        return False, f"Ouverture du login impossible : {exc}"
    if first.status_code >= 500:
        return False, f"Login page HTTP {first.status_code}"

    form = parse_form(first.text)
    if form is None:
        return False, "Aucun formulaire de connexion détecté."

    action = urljoin(first.url, form.get("action") or first.url)
    has_password = any(
        field.get("type", "").lower() == "password" or "password" in field.get("name", "").lower()
        for field in form.get("inputs", [])
    )

    try:
        response = session.request(
            form.get("method", "post").upper(),
            action,
            data=build_form_payload(form, email, password if has_password else None),
            timeout=timeout,
            allow_redirects=False,
        )
    except requests.RequestException as exc:
        return False, f"Première étape impossible : {exc}"

    if 300 <= response.status_code < 400 and response.headers.get("Location"):
        try:
            response = session.request(
                "GET",
                urljoin(action, response.headers["Location"]),
                timeout=timeout,
                allow_redirects=False,
            )
        except requests.RequestException as exc:
            return False, f"Redirection après login impossible : {exc}"

    if not has_password:
        second_form = parse_form(response.text)
        if second_form is not None:
            second_has_password = any(
                field.get("type", "").lower() == "password" or "password" in field.get("name", "").lower()
                for field in second_form.get("inputs", [])
            )
            if second_has_password:
                second_action = urljoin(response.url, second_form.get("action") or response.url)
                try:
                    response = session.request(
                        second_form.get("method", "post").upper(),
                        second_action,
                        data=build_form_payload(second_form, email, password),
                        timeout=timeout,
                        allow_redirects=False,
                    )
                except requests.RequestException as exc:
                    return False, f"Deuxième étape impossible : {exc}"

    check_url = urljoin(target.base_url, "admin/app")
    try:
        check = session.request("GET", check_url, timeout=timeout, allow_redirects=False)
    except requests.RequestException as exc:
        return False, f"Vérification session impossible : {exc}"

    body = check.text.lower()
    location = check.headers.get("Location", "").lower()
    if check.status_code in {401, 403}:
        return False, f"Session refusée HTTP {check.status_code}"
    if "admin/login" in location or ('type="password"' in body and "connexion" in body):
        return False, "Identifiants refusés ou session non reconnue."
    return True, check.url


def safe_headers(headers: requests.structures.CaseInsensitiveDict[str]) -> dict[str, str]:
    return {key: value for key, value in headers.items() if key.lower() not in SENSITIVE_HEADERS}


def collect_target_probe(target: TargetConfig, timeout: float) -> dict[str, Any]:
    parsed = urlparse(target.base_url)
    host = parsed.hostname or ""
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    out: dict[str, Any] = {
        "name": target.name,
        "base_url": target.base_url,
        "scheme": parsed.scheme,
        "hostname": host,
        "port": port,
        "headers_used": {key: value for key, value in target.headers.items() if key.lower() not in SENSITIVE_HEADERS},
        "resolved_addresses": [],
        "tls": None,
        "probe": None,
    }
    try:
        out["resolved_addresses"] = sorted({item[4][0] for item in socket.getaddrinfo(host, port)})
    except OSError as exc:
        out["dns_error"] = str(exc)
    if parsed.scheme == "https":
        try:
            context = ssl.create_default_context()
            with socket.create_connection((host, port), timeout=timeout) as raw:
                with context.wrap_socket(raw, server_hostname=host) as secure:
                    cert = secure.getpeercert(binary_form=True)
                    info = secure.getpeercert()
                    out["tls"] = {
                        "protocol": secure.version(),
                        "cipher": secure.cipher(),
                        "certificate_sha256": sha256_bytes(cert),
                        "subject": info.get("subject"),
                        "issuer": info.get("issuer"),
                        "not_before": info.get("notBefore"),
                        "not_after": info.get("notAfter"),
                    }
        except (OSError, ssl.SSLError) as exc:
            out["tls_error"] = str(exc)
    session = EvidenceSession(headers=target.headers)
    try:
        response = session.request("GET", target.base_url, timeout=timeout, allow_redirects=True)
        out["probe"] = {
            "checked_at_utc": utc_now(),
            "status": response.status_code,
            "final_url": response.url,
            "bytes": len(response.content),
            "body_sha256": sha256_bytes(response.content),
            "headers": safe_headers(response.headers),
        }
    except requests.RequestException as exc:
        out["probe_error"] = str(exc)
    finally:
        session.close()
    return out


def collect_bearer_preflight(target: TargetConfig, token: str | None, path: str, timeout: float) -> dict[str, Any]:
    if not token:
        return {
            "target": target.name,
            "status": "no_token",
            "ok": False,
            "reason": "aucun token disponible pour cette cible",
            "path": path,
        }
    session = EvidenceSession(headers=target.headers, bearer_token=token)
    url = urljoin(target.base_url, path)
    started_at = utc_now()
    try:
        response = session.request("GET", url, timeout=timeout, allow_redirects=True, bearer=True)
        http_status = response.status_code
        if 200 <= http_status < 400:
            status = "ok"
            reason = "token accepté sur le préflight Bearer"
            ok = True
        elif http_status in {401, 403}:
            status = "auth_failed"
            reason = "token refusé sur le préflight Bearer"
            ok = False
        else:
            status = "http_error"
            reason = f"préflight Bearer HTTP {http_status}"
            ok = False
        return {
            "target": target.name,
            "status": status,
            "ok": ok,
            "reason": reason,
            "path": path,
            "requested_url": url,
            "final_url": response.url,
            "checked_at_utc": started_at,
            "http_status": http_status,
            "content_type": response.headers.get("Content-Type"),
            "request_trace_header": response.headers.get("X-Request-ID") or response.headers.get("X-Correlation-ID") or response.headers.get("Traceparent") or response.headers.get("CF-Ray"),
        }
    except requests.RequestException as exc:
        return {
            "target": target.name,
            "status": "network_error",
            "ok": False,
            "reason": f"préflight Bearer impossible : {type(exc).__name__}",
            "path": path,
            "requested_url": url,
            "checked_at_utc": started_at,
            "error": str(exc),
        }
    finally:
        session.close()


def collect_environment() -> dict[str, Any]:
    script_path = Path(__file__).resolve()
    return {
        "program": "webeLi performance comparison",
        "program_version": PROGRAM_VERSION,
        "script_path": str(script_path),
        "script_sha256": sha256_file(script_path),
        "python_version": sys.version,
        "python_executable": sys.executable,
        "requests_version": getattr(requests, "__version__", "unknown"),
        "platform": platform.platform(),
        "machine": platform.machine(),
        "processor": platform.processor(),
        "hostname": socket.gethostname(),
        "cpu_count": os.cpu_count(),
        "timezone": str(datetime.now().astimezone().tzinfo),
    }


def sqlite_count(con: sqlite3.Connection, table: str) -> int | None:
    exists = con.execute(
        "SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name = ? LIMIT 1",
        (table,),
    ).fetchone()
    if not exists:
        return None
    return int(con.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0])


def find_default_db_root(explicit: Path | None) -> tuple[Path | None, str]:
    if explicit is not None:
        return explicit, "explicit"
    roots: list[Path] = []
    for anchor in [Path(__file__).resolve().parent, Path.cwd().resolve()]:
        for base in [anchor, *anchor.parents[:5]]:
            roots.extend([
                base / "storage" / "database",
                base / "mod" / "storage" / "database",
                base / ".." / "storage" / "database",
            ])
    seen: set[Path] = set()
    for candidate in roots:
        resolved = candidate.resolve()
        if resolved in seen:
            continue
        seen.add(resolved)
        if (resolved / "core.sqlite").is_file() or any(resolved.glob("*.sqlite")):
            return resolved, "auto"
    return None, "none"


def classify_dataset_profile(counts: dict[str, int], total_bytes: int) -> str:
    entries = counts.get("core.content_entries", 0)
    snapshots = counts.get("core.public_content_snapshots", 0)
    search_docs = counts.get("core.search_documents", 0)
    media = counts.get("core.media_assets", 0)
    revisions = counts.get("core.revisions", 0)
    routes = counts.get("core.routes", 0)
    if max(entries, snapshots, search_docs, media, revisions, routes) == 0:
        return "unknown"
    if entries >= 10_000 or snapshots >= 20_000 or search_docs >= 30_000 or media >= 20_000 or revisions >= 50_000 or routes >= 20_000 or total_bytes >= 2_000_000_000:
        return "very_large"
    if entries >= 1_000 or snapshots >= 2_000 or search_docs >= 3_000 or media >= 2_000 or revisions >= 5_000 or routes >= 2_000 or total_bytes >= 250_000_000:
        return "large"
    if entries >= 100 or snapshots >= 200 or search_docs >= 300 or media >= 250 or revisions >= 500 or routes >= 250 or total_bytes >= 25_000_000:
        return "medium"
    return "small"


def collect_dataset_profile(db_root: Path | None, source: str, enabled: bool) -> dict[str, Any]:
    if not enabled:
        return {"status": "disabled", "method": "sqlite_readonly", "reason": "désactivé par --no-measure-dataset"}
    if db_root is None:
        return {
            "status": "not_measured",
            "method": "sqlite_readonly",
            "source": source,
            "reason": "aucun répertoire de bases SQLite détecté ; utiliser --db-root si nécessaire",
        }
    if not db_root.exists():
        return {
            "status": "not_measured",
            "method": "sqlite_readonly",
            "source": source,
            "db_root": str(db_root),
            "reason": "répertoire de bases SQLite introuvable",
        }

    counts: dict[str, int] = {}
    files: list[dict[str, Any]] = []
    errors: list[str] = []
    total_bytes = 0
    for db_name, table_map in DATASET_TABLES.items():
        db_path = db_root / db_name
        if not db_path.is_file():
            files.append({"name": db_name, "present": False})
            continue
        size = db_path.stat().st_size
        total_bytes += size
        db_key = db_name.replace(".sqlite", "")
        file_info: dict[str, Any] = {"name": db_name, "present": True, "size_bytes": size, "sha256": sha256_file(db_path)}
        try:
            uri = f"file:{db_path.as_posix()}?mode=ro"
            con = sqlite3.connect(uri, uri=True)
            try:
                con.execute("PRAGMA query_only = ON")
                table_counts: dict[str, int] = {}
                for label, table in table_map.items():
                    value = sqlite_count(con, table)
                    if value is not None:
                        counts[f"{db_key}.{label}"] = value
                        table_counts[label] = value
                file_info["counts"] = table_counts
            finally:
                con.close()
        except sqlite3.Error as exc:
            file_info["error"] = str(exc)
            errors.append(f"{db_name}: {exc}")
        files.append(file_info)

    profile = classify_dataset_profile(counts, total_bytes)
    return {
        "status": "measured" if counts else "not_measured",
        "method": "sqlite_readonly",
        "source": source,
        "db_root": str(db_root),
        "profile": profile,
        "counts": counts,
        "database_files": files,
        "total_database_bytes": total_bytes,
        "errors": errors,
        "thresholds": {
            "small": "moins de 100 contenus, 250 médias, 250 routes, 500 révisions et <25 MB",
            "medium": "à partir de 100 contenus ou 250 médias ou 250 routes ou 500 révisions ou 25 MB",
            "large": "à partir de 1'000 contenus ou 2'000 médias/routes ou 5'000 révisions ou 250 MB",
            "very_large": "à partir de 10'000 contenus ou 20'000 médias/routes ou 50'000 révisions ou 2 GB",
        },
    }


def summarize(values: list[RequestMeasure]) -> dict[str, Any]:
    measured = [item for item in values if not item.warmup]
    totals = [item.total_ms for item in measured]
    ttfb = [item.ttfb_ms for item in measured]
    ok = [item for item in measured if item.ok]
    status_counts: dict[str, int] = {}
    category_counts: dict[str, int] = {}
    auth_policy_counts: dict[str, int] = {}
    external_redirect_count = 0
    redirected_to: dict[str, int] = {}
    for item in measured:
        status_key = str(item.status) if item.status is not None else "network"
        status_counts[status_key] = status_counts.get(status_key, 0) + 1
        auth_policy_counts[item.auth_policy] = auth_policy_counts.get(item.auth_policy, 0) + 1
        if item.error_category:
            category_counts[item.error_category] = category_counts.get(item.error_category, 0) + 1
        if item.final_url:
            requested_host = urlparse(item.requested_url).netloc
            final_host = urlparse(item.final_url).netloc
            if final_host and requested_host and final_host != requested_host:
                external_redirect_count += 1
                redirected_to[final_host] = redirected_to.get(final_host, 0) + 1
    count = len(measured)
    return {
        "count": count,
        "success_count": len(ok),
        "error_count": count - len(ok),
        "success_rate_pct": round(100 * len(ok) / count, 4) if count else 0.0,
        "http_401_403_count": sum(1 for item in measured if item.status in {401, 403}),
        "http_404_count": sum(1 for item in measured if item.status == 404),
        "http_5xx_count": sum(1 for item in measured if item.status is not None and item.status >= 500),
        "http_429_count": sum(1 for item in measured if item.status == 429),
        "network_error_count": sum(1 for item in measured if item.status is None),
        "bearer_used_count": sum(1 for item in measured if item.bearer_used),
        "external_redirect_count": external_redirect_count,
        "redirected_to": redirected_to,
        "total_avg_ms": round(statistics.fmean(totals), 3) if totals else 0.0,
        "total_stdev_ms": round(statistics.pstdev(totals), 3) if len(totals) > 1 else 0.0,
        "total_min_ms": round(min(totals), 3) if totals else 0.0,
        "total_p50_ms": round(percentile(totals, 0.50), 3),
        "total_p90_ms": round(percentile(totals, 0.90), 3),
        "total_p95_ms": round(percentile(totals, 0.95), 3),
        "total_p99_ms": round(percentile(totals, 0.99), 3),
        "total_max_ms": round(max(totals), 3) if totals else 0.0,
        "ttfb_p50_ms": round(percentile(ttfb, 0.50), 3),
        "ttfb_p95_ms": round(percentile(ttfb, 0.95), 3),
        "bytes_median": round(percentile([item.bytes_received for item in measured], 0.50), 3) if measured else 0.0,
        "status_counts": status_counts,
        "error_category_counts": category_counts,
        "auth_policy_counts": auth_policy_counts,
    }


def route_from_map(route_map: dict[tuple[str, str], RouteConfig], area: str, route_name: str) -> RouteConfig:
    return route_map.get((area, route_name), RouteConfig(area=area, name=route_name, method="GET", path=""))


def status_for_pair(
    local: dict[str, Any] | None,
    remote: dict[str, Any] | None,
    route: RouteConfig,
    args: argparse.Namespace,
    bearer_preflights: dict[str, dict[str, Any]] | None = None,
) -> tuple[str, list[str], list[str]]:
    reasons: list[str] = []
    observations: list[str] = []
    status = "PASS"

    for name, row in {"local": local, "remote": remote}.items():
        if row is None or row.get("count", 0) == 0:
            return "INCONCLUSIVE", [f"{name}: aucun échantillon mesuré"], observations

        count = int(row.get("count", 0))
        success_count = int(row.get("success_count", 0))
        status_counts = row.get("status_counts", {}) or {}
        bearer_used = int(row.get("bearer_used_count", 0)) > 0
        access_denied_count = int(row.get("http_401_403_count", 0))

        if row.get("external_redirect_count", 0) > 0:
            status = "INCONCLUSIVE" if status != "FAIL" else status
            reasons.append(f"{name}: {row['external_redirect_count']} redirection(s) externe(s), cible non comparable")

        if row.get("network_error_count", 0) == count:
            status = "INCONCLUSIVE" if status != "FAIL" else status
            reasons.append(f"{name}: aucune réponse HTTP, erreurs réseau uniquement")
            continue

        if row.get("http_5xx_count", 0) > 0 and args.fail_on_5xx:
            status = "FAIL"
            reasons.append(f"{name}: {row['http_5xx_count']} réponse(s) 5xx")

        if success_count == 0:
            if access_denied_count == count:
                if route.auth_policy == "bearer":
                    preflight = (bearer_preflights or {}).get(name, {})
                    preflight_status = preflight.get("status")
                    if not bearer_used:
                        status = "INCONCLUSIVE" if status != "FAIL" else status
                        reasons.append(f"{name}: endpoint Bearer non testé, token absent")
                    elif preflight_status == "auth_failed":
                        status = "INCONCLUSIVE" if status != "FAIL" else status
                        reasons.append(f"{name}: préflight Bearer refusé, API protégée non mesurable avec ce token")
                    elif preflight_status and preflight_status != "ok":
                        status = "INCONCLUSIVE" if status != "FAIL" else status
                        reasons.append(f"{name}: préflight Bearer non concluant ({preflight_status})")
                    else:
                        status = "FAIL"
                        reasons.append(f"{name}: token Bearer accepté au préflight mais accès refusé 401/403 sur cette route")
                elif route.requires_auth:
                    status = "INCONCLUSIVE" if status != "FAIL" else status
                    reasons.append(f"{name}: session admin absente ou refusée")
                else:
                    status = "FAIL"
                    reasons.append(f"{name}: route anonyme attendue mais accès refusé 401/403")
            elif row.get("http_5xx_count", 0) > 0:
                status = "FAIL"
                reasons.append(f"{name}: 0 % de succès avec erreur serveur")
            elif row.get("http_404_count", 0) == count:
                status = "FAIL"
                reasons.append(f"{name}: route introuvable 404")
            else:
                status = "FAIL" if status != "INCONCLUSIVE" else status
                reasons.append(f"{name}: 0 % de succès, statuts {status_counts}")

        elif success_count < count and status == "PASS":
            status = "WARN"
            reasons.append(f"{name}: succès partiel {row.get('success_rate_pct')} %")

        if row.get("http_429_count", 0) > 0:
            if status not in {"FAIL", "INCONCLUSIVE"}:
                status = "WARN"
            reasons.append(f"{name}: {row['http_429_count']} réponse(s) 429, protection de trafic probable")

    if local and remote and local.get("total_p95_ms", 0) > 0:
        ratio = remote.get("total_p95_ms", 0) / local["total_p95_ms"]
        if ratio > args.warn_ratio:
            message = f"ratio p95 distant/local {ratio:.2f} > {args.warn_ratio}"
            if args.strict_ratio_warnings and status == "PASS":
                status = "WARN"
                reasons.append(message)
            else:
                observations.append(message)

    thresholds = {
        ("public", "local"): args.max_p95_public_local,
        ("public", "remote"): args.max_p95_public_remote,
        ("api", "local"): args.max_p95_api_local,
        ("api", "remote"): args.max_p95_api_remote,
        ("admin", "local"): args.max_p95_admin_local,
        ("admin", "remote"): args.max_p95_admin_remote,
    }
    for target_name, row in {"local": local, "remote": remote}.items():
        threshold = thresholds.get((route.area, target_name))
        if row and row.get("success_count", 0) > 0 and threshold and row.get("total_p95_ms", 0) > threshold:
            if status == "PASS":
                status = "WARN"
            reasons.append(f"{target_name}: p95 {row['total_p95_ms']} ms > seuil {threshold} ms")
    return status, reasons, observations


def build_summaries(
    results: list[RequestMeasure],
    routes: list[RouteConfig],
    args: argparse.Namespace,
    bearer_preflights: dict[str, dict[str, Any]] | None = None,
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    grouped: dict[tuple[str, str, str], list[RequestMeasure]] = {}
    for item in results:
        if item.warmup:
            continue
        grouped.setdefault((item.area, item.route_name, item.target), []).append(item)
    rows: list[dict[str, Any]] = []
    for (area, route_name, target), items in sorted(grouped.items()):
        route = RouteConfig(area=area, name=route_name, method=items[0].method, path="", auth_policy=items[0].auth_policy)
        rows.append({"area": area, "route_name": route_name, "target": target, "auth_policy": route.auth_policy, **summarize(items)})

    route_map = {(route.area, route.name): route for route in routes}
    by_pair: dict[tuple[str, str], dict[str, dict[str, Any]]] = {}
    for row in rows:
        by_pair.setdefault((row["area"], row["route_name"]), {})[row["target"]] = row
    comparisons: list[dict[str, Any]] = []
    for (area, route_name), pair in sorted(by_pair.items()):
        route = route_from_map(route_map, area, route_name)
        local = pair.get("local")
        remote = pair.get("remote")
        status, reasons, observations = status_for_pair(local, remote, route, args, bearer_preflights)
        local_p95 = local.get("total_p95_ms", 0.0) if local else 0.0
        remote_p95 = remote.get("total_p95_ms", 0.0) if remote else 0.0
        local_avg = local.get("total_avg_ms", 0.0) if local else 0.0
        remote_avg = remote.get("total_avg_ms", 0.0) if remote else 0.0
        comparisons.append({
            "area": area,
            "route_name": route_name,
            "auth_policy": route.auth_policy,
            "status": status,
            "reasons": reasons,
            "observations": observations,
            "local_p95_ms": local_p95,
            "remote_p95_ms": remote_p95,
            "delta_p95_ms": round(remote_p95 - local_p95, 3),
            "ratio_p95_remote_over_local": round(remote_p95 / local_p95, 4) if local_p95 > 0 else None,
            "local_avg_ms": local_avg,
            "remote_avg_ms": remote_avg,
            "delta_avg_ms": round(remote_avg - local_avg, 3),
            "ratio_avg_remote_over_local": round(remote_avg / local_avg, 4) if local_avg > 0 else None,
            "local_success_rate_pct": local.get("success_rate_pct", 0.0) if local else 0.0,
            "remote_success_rate_pct": remote.get("success_rate_pct", 0.0) if remote else 0.0,
            "local_status_counts": local.get("status_counts", {}) if local else {},
            "remote_status_counts": remote.get("status_counts", {}) if remote else {},
            "local_bearer_used": bool(local.get("bearer_used_count", 0)) if local else False,
            "remote_bearer_used": bool(remote.get("bearer_used_count", 0)) if remote else False,
        })
    return rows, comparisons


def write_csv(path: Path, rows: list[RequestMeasure]) -> None:
    if not rows:
        path.write_text("", encoding="utf-8")
        return
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(asdict(rows[0]).keys()))
        writer.writeheader()
        for row in rows:
            writer.writerow(asdict(row))


def area_scope_status(area: str, comparisons: list[dict[str, Any]], required_routes: int) -> dict[str, Any]:
    rows = [row for row in comparisons if row.get("area") == area]
    if required_routes == 0:
        return {"status": "non_testee", "label": "non testée", "routes": 0, "reason": "aucune route demandée"}
    if not rows:
        return {"status": "non_testee", "label": "non testée", "routes": 0, "reason": "aucune mesure produite"}
    statuses = {row.get("status") for row in rows}
    if "FAIL" in statuses:
        return {"status": "echec", "label": "échec", "routes": len(rows), "reason": "au moins une route est en FAIL"}
    if "INCONCLUSIVE" in statuses:
        return {"status": "non_concluante", "label": "non concluante", "routes": len(rows), "reason": "au moins une route est non comparable ou non authentifiée"}
    if "WARN" in statuses:
        return {"status": "partiellement_demontree", "label": "partiellement démontrée", "routes": len(rows), "reason": "au moins une route est en WARN"}
    if len(rows) < required_routes:
        return {
            "status": "partiellement_demontree",
            "label": "partiellement démontrée",
            "routes": len(rows),
            "reason": f"{len(rows)} route(s) comparée(s) sur {required_routes} attendue(s)",
        }
    return {"status": "entierement_demontree", "label": "entièrement démontrée", "routes": len(rows), "reason": "toutes les routes attendues sont en PASS"}


def dataset_scope_status(dataset_profile: dict[str, Any], tested_statuses: list[str]) -> dict[str, Any]:
    if dataset_profile.get("status") == "disabled":
        return {"status": "non_testee", "label": "non testée", "reason": dataset_profile.get("reason", "")}
    if dataset_profile.get("status") != "measured":
        return {
            "status": "non_qualifiee",
            "label": "non qualifiée",
            "reason": dataset_profile.get("reason", "profil de données non mesuré"),
        }
    profile = dataset_profile.get("profile", "unknown")
    if profile in {"large", "very_large"}:
        if "echec" in tested_statuses:
            status = "echec"
            label = "mesurée, mais performance en échec"
        elif "non_concluante" in tested_statuses:
            status = "non_concluante"
            label = "mesurée, lecture non concluante"
        elif "partiellement_demontree" in tested_statuses:
            status = "partiellement_demontree"
            label = "partiellement démontrée sur base volumineuse"
        else:
            status = "entierement_demontree"
            label = "démontrée pour les lectures testées sur base volumineuse"
        reason = f"profil {profile} mesuré par lecture SQLite read-only"
    else:
        status = "mesuree_non_volumineuse"
        label = f"profil {profile} mesuré"
        reason = "la performance est démontrée sur le profil courant, sans extrapolation automatique vers une base volumineuse"
    return {
        "status": status,
        "label": label,
        "reason": reason,
        "profile": profile,
        "method": dataset_profile.get("method"),
        "db_root": dataset_profile.get("db_root"),
    }


def build_scope_verdict(
    metadata: dict[str, Any],
    routes: list[RouteConfig],
    comparisons: list[dict[str, Any]],
    dataset_profile: dict[str, Any],
) -> dict[str, Any]:
    requested_by_area = {
        "public": len([route for route in routes if route.area == "public"]),
        "api": len([route for route in routes if route.area == "api"]),
        "admin": len([route for route in routes if route.area == "admin" and route.requires_auth]),
    }
    admin_auth = metadata.get("admin_auth_status") or {}
    credentials_available = bool(metadata.get("credentials_file_used"))
    admin_auth_ok = bool(admin_auth.get("local", {}).get("ok")) and bool(admin_auth.get("remote", {}).get("ok"))
    areas = {
        "performance_publique_ssr": area_scope_status("public", comparisons, requested_by_area["public"]),
        "api_lecture": area_scope_status("api", comparisons, requested_by_area["api"]),
        "admin_lecture": area_scope_status("admin", comparisons, requested_by_area["admin"]),
        "charge_concurrente": {
            "status": "non_testee",
            "label": "non testée par ce script",
            "reason": "utiliser test_load.py pour la montée en charge avec utilisateurs virtuels",
        },
        "ecriture_publication": {
            "status": "non_testee",
            "label": "non testée par défaut",
            "reason": "ce benchmark reste non destructif et ne publie pas de contenu",
        },
    }
    if requested_by_area["admin"] and not credentials_available:
        areas["admin_lecture"] = {
            "status": "non_testee",
            "label": "non testée",
            "routes": 0,
            "reason": "fournir un CSV admin avec --credentials-file ou placer users.csv à côté du script",
        }
    elif requested_by_area["admin"] and credentials_available and not admin_auth_ok:
        areas["admin_lecture"] = {
            "status": "non_concluante",
            "label": "non concluante",
            "routes": 0,
            "reason": "authentification admin impossible sur local et/ou distant",
        }
    tested_keys = ("performance_publique_ssr", "api_lecture", "admin_lecture")
    tested_statuses = [areas[key]["status"] for key in tested_keys]
    areas["volume_donnees"] = dataset_scope_status(dataset_profile, tested_statuses)

    if "echec" in tested_statuses:
        overall = "FAIL"
    elif "non_concluante" in tested_statuses:
        overall = "INCONCLUSIVE"
    elif "partiellement_demontree" in tested_statuses:
        overall = "WARN"
    else:
        overall = "PASS"
    return {
        "overall_status": overall,
        "areas": areas,
        "dataset_profile": dataset_profile,
        "notes": [
            "Ce verdict porte uniquement sur un benchmark à faible concurrence.",
            "Le profil de données est mesuré par lecture SQLite locale read-only quand les bases sont disponibles.",
            "Les secrets admin, cookies et jetons Authorization ne sont pas écrits dans les preuves.",
        ],
    }


def write_outputs(
    output_dir: Path,
    metadata: dict[str, Any],
    environment: dict[str, Any],
    targets: list[dict[str, Any]],
    routes: list[dict[str, Any]],
    results: list[RequestMeasure],
    summaries: list[dict[str, Any]],
    comparisons: list[dict[str, Any]],
    scope_verdict: dict[str, Any],
    dataset_profile: dict[str, Any],
) -> None:
    output_dir.mkdir(parents=True, exist_ok=False)
    script_copy = output_dir / "executed_script.py"
    script_copy.write_bytes(Path(__file__).resolve().read_bytes())

    payloads = {
        "metadata.json": metadata,
        "environment.json": environment,
        "targets.json": {"targets": targets},
        "routes.json": {"routes": routes},
        "raw_results.json": {"results": [asdict(item) for item in results]},
        "summary.json": {"summaries": summaries},
        "comparison.json": {"comparisons": comparisons},
        "dataset_profile.json": dataset_profile,
        "scope_verdict.json": scope_verdict,
    }
    for filename, payload in payloads.items():
        (output_dir / filename).write_text(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    write_csv(output_dir / "raw_results.csv", results)

    lines = [
        "# Benchmark comparatif local/distant webeLi",
        "",
        f"- Identifiant de preuve : `{metadata['evidence_id']}`",
        f"- Début UTC : {metadata['started_at_utc']}",
        f"- Fin UTC : {metadata['ended_at_utc']}",
        f"- Cible distante : `{metadata['remote_url']}`",
        f"- Cible locale : `{metadata['local_url']}`",
        f"- Mode : `{metadata['mode']}`",
        f"- Warmup : {metadata['warmup']} requête(s) par route/cible",
        f"- Itérations mesurées : {metadata['iterations']} par route/cible",
        "",
        "## Lecture rapide",
        "",
        "Ce script mesure des temps répétés à faible concurrence. Il ne remplace pas un test de charge. La cible locale isole davantage le moteur CMS/PHP/SQLite ; la cible distante mesure l'expérience hébergée avec réseau, TLS, proxy et éventuelles protections de trafic.",
        "",
        "## Portée de la démonstration",
        "",
    ]
    labels = {
        "performance_publique_ssr": "Performance publique SSR",
        "api_lecture": "API lecture",
        "admin_lecture": "Admin lecture",
        "volume_donnees": "Volume de données",
        "charge_concurrente": "Charge concurrente",
        "ecriture_publication": "Écriture / publication",
    }
    for key, label in labels.items():
        item = scope_verdict.get("areas", {}).get(key, {})
        lines.append(f"- {label} : **{item.get('label', 'non évaluée')}** — {item.get('reason', '')}")

    if dataset_profile.get("status") == "measured":
        counts = dataset_profile.get("counts", {})
        lines.extend([
            "",
            "## Profil de données mesuré",
            "",
            f"- Méthode : `{dataset_profile.get('method')}`",
            f"- Répertoire DB : `{dataset_profile.get('db_root')}`",
            f"- Profil : **{dataset_profile.get('profile')}**",
            f"- Taille totale DB : {dataset_profile.get('total_database_bytes')} octets",
            "",
            "| Indicateur | Valeur |",
            "|---|---:|",
        ])
        for key in sorted(counts):
            lines.append(f"| {key} | {counts[key]} |")

    lines.extend([
        "",
        "## Comparaison par route",
        "",
        "| Zone | Route | Auth | Statut | Local p95 | Distant p95 | Ratio p95 | Local succès | Distant succès | Remarques |",
        "|---|---|---|---|---:|---:|---:|---:|---:|---|",
    ])
    for row in comparisons:
        ratio = row["ratio_p95_remote_over_local"]
        ratio_text = "n/a" if ratio is None else f"{ratio:.2f}x"
        remarks = "; ".join(row.get("reasons") or [])
        lines.append(
            f"| {row['area']} | {row['route_name']} | {row.get('auth_policy', '')} | {row['status']} | "
            f"{row['local_p95_ms']} ms | {row['remote_p95_ms']} ms | {ratio_text} | "
            f"{row['local_success_rate_pct']} % | {row['remote_success_rate_pct']} % | {remarks} |"
        )

    non_blocking_observations = [
        row for row in comparisons
        if row.get("observations") and not metadata.get("thresholds", {}).get("strict_ratio_warnings")
    ]
    if non_blocking_observations:
        lines.extend(["", "## Observations non bloquantes", ""])
        lines.append("Les observations ci-dessous ne changent pas le statut si les seuils absolus et les statuts HTTP sont bons.")
        for row in non_blocking_observations:
            lines.append(f"- {row['area']} / {row['route_name']} : " + "; ".join(row.get("observations") or []))

    slowest = sorted(comparisons, key=lambda row: row.get("remote_p95_ms", 0), reverse=True)[:10]
    lines.extend(["", "## Routes distantes les plus lentes", ""])
    for row in slowest:
        lines.append(f"- {row['area']} / {row['route_name']} : p95 distant {row['remote_p95_ms']} ms, p95 local {row['local_p95_ms']} ms, statut {row['status']}.")

    failures = [row for row in comparisons if row["status"] == "FAIL"]
    warnings = [row for row in comparisons if row["status"] == "WARN"]
    inconclusive = [row for row in comparisons if row["status"] == "INCONCLUSIVE"]
    lines.extend(["", "## Verdict synthétique", ""])
    if failures:
        lines.append(f"- FAIL : {len(failures)} route(s) avec erreur applicative ou critère bloquant.")
    if warnings:
        lines.append(f"- WARN : {len(warnings)} route(s) avec seuil, 429 ou autre point à surveiller.")
    if inconclusive:
        lines.append(f"- INCONCLUSIVE : {len(inconclusive)} route(s) non comparables ou insuffisamment mesurées.")
    if not failures and not warnings and not inconclusive:
        lines.append("- PASS : aucune anomalie selon les seuils fournis.")

    lines.extend([
        "",
        "## Vérification",
        "",
        "```bash",
        "sha256sum -c manifest.sha256",
        "```",
        "",
        "Les mots de passe, cookies et jetons Authorization ne sont pas enregistrés dans les fichiers de preuve.",
    ])
    (output_dir / "comparison.md").write_text("\n".join(lines) + "\n", encoding="utf-8")

    manifest_targets = sorted(path for path in output_dir.iterdir() if path.is_file() and path.name != "manifest.sha256")
    manifest_lines = [f"{sha256_file(path)}  {path.name}" for path in manifest_targets]
    (output_dir / "manifest.sha256").write_text("\n".join(manifest_lines) + "\n", encoding="utf-8")


def routes_for_mode(mode: str, routes_file: Path | None) -> list[RouteConfig]:
    routes: list[dict[str, Any]] = []
    if mode in {"public", "all"}:
        routes.extend(PUBLIC_ROUTES)
    if mode in {"api", "all"}:
        routes.extend(API_ROUTES)
    if mode in {"admin", "all"}:
        routes.extend(ADMIN_ROUTES)
    if routes_file:
        data = json.loads(routes_file.read_text(encoding="utf-8"))
        extra = data.get("routes", data if isinstance(data, list) else [])
        if not isinstance(extra, list):
            raise ValueError("Le fichier de routes doit contenir une liste ou un objet {routes: [...] }.")
        routes.extend(extra)
    return [RouteConfig(**item) for item in routes]


def build_targets(args: argparse.Namespace) -> list[TargetConfig]:
    remote = TargetConfig("remote", normalize_base_url(args.remote_url), {})
    local_headers: dict[str, str] = {}
    if args.local_host_header:
        local_headers["Host"] = args.local_host_header
    if args.local_forwarded_proto:
        local_headers["X-Forwarded-Proto"] = args.local_forwarded_proto
    if args.auto_local_canonical_headers:
        local_host = urlparse(args.local_url).hostname or ""
        remote_parsed = urlparse(args.remote_url)
        if local_host in {"127.0.0.1", "localhost", "::1"}:
            local_headers.setdefault("Host", remote_parsed.hostname or "")
            local_headers.setdefault("X-Forwarded-Proto", remote_parsed.scheme or "https")
    local = TargetConfig("local", normalize_base_url(args.local_url), local_headers)
    return [local, remote]


def positive_int(value: str) -> int:
    try:
        number = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("Entier attendu.") from exc
    if number < 0:
        raise argparse.ArgumentTypeError("La valeur ne peut pas être négative.")
    return number


def non_negative_float(value: str) -> float:
    try:
        number = float(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("Nombre attendu.") from exc
    if number < 0:
        raise argparse.ArgumentTypeError("La valeur ne peut pas être négative.")
    return number


def main() -> int:
    parser = argparse.ArgumentParser(description="Benchmark comparatif local/distant pour webeLi.")
    parser.add_argument("--remote-url", default=DEFAULT_REMOTE_URL)
    parser.add_argument("--local-url", default=DEFAULT_LOCAL_URL)
    parser.add_argument("--mode", choices=["public", "api", "admin", "all"], default="all")
    parser.add_argument("--iterations", type=positive_int, default=30)
    parser.add_argument("--warmup", type=positive_int, default=5)
    parser.add_argument("--timeout", type=non_negative_float, default=15.0)
    parser.add_argument("--pause", type=non_negative_float, default=0.15, help="Pause entre deux requêtes mesurées.")
    parser.add_argument("--jitter", type=non_negative_float, default=0.05, help="Jitter ajouté à --pause.")
    parser.add_argument("--credentials-file", type=Path, help="CSV admin. Colonnes acceptées : email/username/login/nom + password/mot_de_passe.")
    parser.add_argument("--tokens-file", type=Path, help="CSV de tokens API. Colonnes attendues : url, token. Détecte token.csv par défaut.")
    parser.add_argument("--bearer-token", help="Jeton Bearer commun pour endpoints API protégés ; jamais écrit dans les preuves.")
    parser.add_argument("--routes-file", type=Path, help="JSON optionnel contenant des routes supplémentaires.")
    parser.add_argument("--api-bearer-preflight-path", default="api/v1/search?q=cms", help="Endpoint utilisé une fois par cible pour vérifier que le token Bearer est accepté.")
    parser.add_argument("--output", type=Path, help="Dossier de preuve à créer.")
    parser.add_argument("--yes", action="store_true", help="Confirme l'autorisation sans invite interactive.")
    parser.add_argument("--cache-bust", action="store_true", help="Ajoute un paramètre _perf pour limiter les effets de cache.")
    parser.add_argument("--local-host-header", help="En-tête Host à utiliser pour la cible locale.")
    parser.add_argument("--local-forwarded-proto", help="En-tête X-Forwarded-Proto à utiliser pour la cible locale.")
    parser.add_argument("--no-auto-local-canonical-headers", dest="auto_local_canonical_headers", action="store_false", help="Désactive Host/X-Forwarded-Proto automatiques pour 127.0.0.1.")
    parser.set_defaults(auto_local_canonical_headers=True)
    parser.add_argument("--db-root", type=Path, help="Répertoire des bases SQLite locales, par exemple ../storage/database. Détecté automatiquement si possible.")
    parser.add_argument("--no-measure-dataset", dest="measure_dataset", action="store_false", help="Désactive la mesure read-only du profil de données local.")
    parser.set_defaults(measure_dataset=True)
    parser.add_argument("--fail-on-5xx", action="store_true", default=True)
    parser.add_argument("--warn-ratio", type=non_negative_float, default=5.0)
    parser.add_argument("--strict-ratio-warnings", action="store_true", help="Transforme un ratio distant/local élevé en WARN. Par défaut, le ratio est seulement une observation si les seuils absolus passent.")
    parser.add_argument("--dataset-label", default="current", help="Libellé libre du jeu de données mesuré, par exemple demo, small, medium, large.")
    parser.add_argument("--max-p95-public-local", type=non_negative_float, default=300.0)
    parser.add_argument("--max-p95-public-remote", type=non_negative_float, default=900.0)
    parser.add_argument("--max-p95-api-local", type=non_negative_float, default=300.0)
    parser.add_argument("--max-p95-api-remote", type=non_negative_float, default=700.0)
    parser.add_argument("--max-p95-admin-local", type=non_negative_float, default=500.0)
    parser.add_argument("--max-p95-admin-remote", type=non_negative_float, default=1200.0)
    args = parser.parse_args()

    if args.iterations < 1:
        parser.error("--iterations doit être supérieur ou égal à 1.")
    if args.timeout <= 0:
        parser.error("--timeout doit être supérieur à 0.")
    if not args.yes:
        print("Ce benchmark effectue des requêtes réelles à faible concurrence.")
        print(f"Cible locale : {args.local_url}")
        print(f"Cible distante : {args.remote_url}")
        answer = input("Confirmez-vous être autorisé à exécuter ce benchmark ? [oui/N] ").strip().lower()
        if answer not in {"o", "oui", "y", "yes"}:
            print("Benchmark annulé.")
            return 2

    try:
        routes = routes_for_mode(args.mode, args.routes_file)
    except (OSError, json.JSONDecodeError, TypeError, ValueError) as exc:
        parser.error(f"Routes invalides : {exc}")

    credentials_file, credentials_source = resolve_credentials_file(args.credentials_file)
    credentials: list[tuple[str, str]] = []
    if args.mode in {"admin", "all"}:
        if credentials_file is None:
            print(
                "Mode admin inclus sans CSV : les routes admin authentifiées seront marquées non testées. "
                "Fournir --credentials-file ou placer users.csv à côté de test_perf.py.",
                file=sys.stderr,
            )
        else:
            try:
                credentials = load_credentials_csv(credentials_file)
            except ValueError as exc:
                parser.error(str(exc))

    evidence_id = str(uuid.uuid4())
    started_at = utc_now()
    output_dir = args.output or Path(f"performance-compare-{datetime.now().strftime('%Y%m%d-%H%M%S')}-{evidence_id[:8]}").resolve()
    targets = build_targets(args)

    tokens_file, tokens_source = resolve_tokens_file(args.tokens_file)
    target_tokens: dict[str, str] = {}
    if args.bearer_token:
        target_tokens = {target.name: args.bearer_token for target in targets}
        tokens_source = "explicit_bearer_token"
    elif tokens_file is not None:
        try:
            target_tokens = load_tokens_csv(tokens_file, targets)
        except ValueError as exc:
            parser.error(str(exc))

    bearer_preflights = {
        target.name: collect_bearer_preflight(
            target,
            target_tokens.get(target.name),
            args.api_bearer_preflight_path,
            args.timeout,
        )
        for target in targets
    }

    db_root, db_root_source = find_default_db_root(args.db_root)
    dataset_profile = collect_dataset_profile(db_root, db_root_source, args.measure_dataset)
    dataset_profile["label"] = args.dataset_label

    environment = collect_environment()
    target_probes = [collect_target_probe(target, args.timeout) for target in targets]

    sessions: dict[str, EvidenceSession] = {
        target.name: EvidenceSession(headers=target.headers, bearer_token=target_tokens.get(target.name))
        for target in targets
    }
    auth_status: dict[str, Any] = {}
    if credentials:
        for target in targets:
            attempts: list[dict[str, Any]] = []
            target_ok = False
            target_detail = "no credential accepted"
            for index, (login, password) in enumerate(credentials, start=1):
                ok, detail = authenticate_admin(target, sessions[target.name], login, password, args.timeout)
                attempts.append({
                    "index": index,
                    "login_fingerprint": credential_fingerprint(login),
                    "ok": ok,
                    "detail": "authenticated" if ok else detail,
                })
                if ok:
                    target_ok = True
                    target_detail = "authenticated"
                    break
            auth_status[target.name] = {
                "ok": target_ok,
                "detail": target_detail,
                "credential_count": len(credentials),
                "attempted_count": len(attempts),
                "attempts": attempts,
            }

    results: list[RequestMeasure] = []
    total_rounds = args.warmup + args.iterations
    measurable_routes = []
    for route in routes:
        if route.requires_auth and not credentials:
            continue
        measurable_routes.append(route)

    for round_no in range(1, total_rounds + 1):
        warmup = round_no <= args.warmup
        ordered_routes = list(measurable_routes)
        if round_no % 2 == 0:
            ordered_routes.reverse()
        for route in ordered_routes:
            ordered_targets = list(targets)
            if round_no % 2 == 0:
                ordered_targets.reverse()
            for target in ordered_targets:
                if route.requires_auth and credentials and not auth_status.get(target.name, {}).get("ok"):
                    continue
                item = measure_once(
                    evidence_id,
                    target,
                    route,
                    round_no - args.warmup if not warmup else round_no,
                    warmup,
                    sessions[target.name],
                    args.timeout,
                    args.cache_bust,
                )
                results.append(item)
                if args.pause or args.jitter:
                    time.sleep(args.pause + random.uniform(0.0, args.jitter))

    for session in sessions.values():
        session.close()

    summaries, comparisons = build_summaries(results, routes, args, bearer_preflights)
    ended_at = utc_now()
    metadata = {
        "evidence_id": evidence_id,
        "program_version": PROGRAM_VERSION,
        "started_at_utc": started_at,
        "ended_at_utc": ended_at,
        "remote_url": normalize_base_url(args.remote_url),
        "local_url": normalize_base_url(args.local_url),
        "mode": args.mode,
        "iterations": args.iterations,
        "warmup": args.warmup,
        "timeout_s": args.timeout,
        "pause_s": args.pause,
        "jitter_s": args.jitter,
        "cache_bust": args.cache_bust,
        "bearer_token_provided": bool(args.bearer_token),
        "tokens_file_provided": bool(args.tokens_file),
        "tokens_file_used": str(tokens_file) if tokens_file else None,
        "tokens_source": tokens_source,
        "api_bearer_preflight_path": args.api_bearer_preflight_path,
        "bearer_preflight": bearer_preflights,
        "target_token_status": {
            target.name: {
                "available": target.name in target_tokens,
                "fingerprint": secret_fingerprint(target_tokens[target.name]) if target.name in target_tokens else None,
                "preflight_status": bearer_preflights.get(target.name, {}).get("status"),
                "preflight_ok": bearer_preflights.get(target.name, {}).get("ok"),
            }
            for target in targets
        },
        "credentials_file_provided": bool(args.credentials_file),
        "credentials_file_used": str(credentials_file) if credentials_file else None,
        "credentials_source": credentials_source,
        "credential_count": len(credentials),
        "dataset_label": args.dataset_label,
        "dataset_status": dataset_profile.get("status"),
        "dataset_profile": dataset_profile.get("profile"),
        "dataset_db_root": dataset_profile.get("db_root"),
        "admin_auth_status": auth_status,
        "command_line": [sys.executable, *sys.argv],
        "thresholds": {
            "warn_ratio": args.warn_ratio,
            "strict_ratio_warnings": args.strict_ratio_warnings,
            "max_p95_public_local": args.max_p95_public_local,
            "max_p95_public_remote": args.max_p95_public_remote,
            "max_p95_api_local": args.max_p95_api_local,
            "max_p95_api_remote": args.max_p95_api_remote,
            "max_p95_admin_local": args.max_p95_admin_local,
            "max_p95_admin_remote": args.max_p95_admin_remote,
        },
    }
    scope_verdict = build_scope_verdict(metadata, routes, comparisons, dataset_profile)
    write_outputs(
        output_dir=output_dir,
        metadata=metadata,
        environment=environment,
        targets=target_probes,
        routes=[asdict(route) for route in routes],
        results=results,
        summaries=summaries,
        comparisons=comparisons,
        scope_verdict=scope_verdict,
        dataset_profile=dataset_profile,
    )

    fail_count = sum(1 for row in comparisons if row["status"] == "FAIL")
    warn_count = sum(1 for row in comparisons if row["status"] == "WARN")
    inconclusive_count = sum(1 for row in comparisons if row["status"] == "INCONCLUSIVE")
    print(f"\nDossier de preuve : {output_dir}")
    print(f"Routes comparées : {len(comparisons)}")
    print(f"FAIL={fail_count} WARN={warn_count} INCONCLUSIVE={inconclusive_count}")
    print(f"Portée : {scope_verdict['overall_status']}")
    if dataset_profile.get("status") == "measured":
        print(f"Profil données : {dataset_profile.get('profile')} ({dataset_profile.get('total_database_bytes')} octets)")
    else:
        print(f"Profil données : {dataset_profile.get('status')} - {dataset_profile.get('reason', '')}")
    print("Vérification : sha256sum -c manifest.sha256")
    if fail_count or scope_verdict["overall_status"] == "FAIL":
        return 1
    if inconclusive_count or (credentials_file and scope_verdict["overall_status"] == "INCONCLUSIVE"):
        return 3
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
