#!/usr/bin/env python3
"""
Test de charge concurrente reproductible pour webeLi.

Ce script est volontairement distinct de test_perf.py :
- test_perf.py mesure la latence faible concurrence et compare local/distant ;
- test_load.py mesure le comportement sous utilisateurs virtuels concurrents.

Le script produit un dossier de preuve complet : résultats bruts, résumés par
palier, résumés par route, environnement, profil de données local éventuel,
verdict de portée, copie du script exécuté et manifeste SHA-256.

Dépendance :
    python -m pip install requests

Exemples :
    python test_load.py --profile smoke --target local --yes
    python test_load.py --profile baseline --target remote --mode public --yes
    python test_load.py --profile baseline --base-url http://127.0.0.1:8080/mod/ --mode all --yes

IMPORTANT : ce programme génère une charge réelle. Ne l'exécutez que sur une
infrastructure que vous êtes explicitement autorisé à tester.
"""

from __future__ import annotations

import argparse
import csv
import getpass
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
import threading
import time
import uuid
from collections import Counter, defaultdict
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from html.parser import HTMLParser
from http.cookies import SimpleCookie
from pathlib import Path
from typing import Any
from urllib.parse import parse_qsl, urlencode, urljoin, urlparse, urlunparse

import requests


PROGRAM_VERSION = "3.0.0"
USER_AGENT = f"webeLi-load-evidence/{PROGRAM_VERSION} (+authorized-load-test)"
DEFAULT_REMOTE_URL = "https://webe.li/mod/"
DEFAULT_LOCAL_URL = "http://127.0.0.1:8080/mod/"
DEFAULT_CREDENTIALS_FILENAME = "users.csv"
DEFAULT_TOKENS_FILENAME = "token.csv"
SENSITIVE_HEADERS = {"set-cookie", "cookie", "authorization", "proxy-authorization"}

PUBLIC_ROUTES = [
    {"area": "public", "name": "home", "method": "GET", "path": "", "auth_policy": "anonymous"},
    {"area": "public", "name": "articles", "method": "GET", "path": "articles", "auth_policy": "anonymous"},
    {"area": "public", "name": "search", "method": "GET", "path": "search?q=cms", "auth_policy": "anonymous"},
    {"area": "public", "name": "sitemap", "method": "GET", "path": "sitemap.xml", "auth_policy": "anonymous"},
    {"area": "public", "name": "robots", "method": "GET", "path": "robots.txt", "auth_policy": "anonymous"},
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
    {"area": "admin-read", "name": "admin-app", "method": "GET", "path": "admin/app", "auth_policy": "admin-session"},
    {"area": "admin-read", "name": "admin-context", "method": "GET", "path": "admin/api/context", "auth_policy": "admin-session"},
    {"area": "admin-read", "name": "admin-entries", "method": "GET", "path": "admin/api/entries", "auth_policy": "admin-session"},
    {"area": "admin-read", "name": "admin-media", "method": "GET", "path": "admin/api/media", "auth_policy": "admin-session"},
]

DATASET_TABLES = {
    "core.sqlite": {
        "sites": "sites",
        "languages": "languages",
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

PROFILE_DEFAULTS = {
    "smoke": {
        "users": 2,
        "ramp": "1,2",
        "duration": 15.0,
        "think_min": 0.5,
        "think_max": 1.5,
        "description": "Validation rapide non agressive.",
    },
    "baseline": {
        "users": 25,
        "ramp": "1,5,10,25",
        "duration": 60.0,
        "think_min": 0.5,
        "think_max": 1.5,
        "description": "Baseline prudente pour qualifier une instance.",
    },
    "stress": {
        "users": 100,
        "ramp": "1,5,10,25,50,100",
        "duration": 45.0,
        "think_min": 0.2,
        "think_max": 1.0,
        "description": "Recherche du palier de dégradation ; à réserver à staging ou local.",
    },
    "spike": {
        "users": 50,
        "ramp": "1,50",
        "duration": 30.0,
        "think_min": 0.1,
        "think_max": 0.5,
        "description": "Montée brutale pour observer les protections et timeouts.",
    },
    "soak": {
        "users": 10,
        "ramp": "10",
        "duration": 900.0,
        "think_min": 1.0,
        "think_max": 3.0,
        "description": "Tenue dans le temps ; durée longue.",
    },
}


@dataclass
class TargetConfig:
    name: str
    base_url: str
    headers: dict[str, str]
    bearer_token: str | None = None
    bearer_fingerprint: str | None = None


@dataclass
class RouteConfig:
    area: str
    name: str
    method: str
    path: str
    auth_policy: str = "anonymous"  # anonymous | bearer | admin-session


@dataclass
class RequestResult:
    evidence_id: str
    target: str
    scenario: str
    stage: int
    configured_users: int
    vu_id: int
    request_no: int
    route_name: str
    auth_policy: str
    bearer_used: bool
    method: str
    requested_url: str
    final_url: str | None
    started_at_utc: str
    ended_at_utc: str
    elapsed_ms: float
    status: int | None
    ok: bool
    bytes_received: int
    response_sha256: str | None
    redirect_count: int
    server_date: str | None
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


class EvidenceSession:
    """Session HTTP avec cookies manuels pour les cookies Secure en local HTTP.

    Les secrets sont utilisés pour les requêtes mais ne sont jamais écrits dans les preuves.
    """

    def __init__(self, headers: dict[str, str] | None = None, bearer_token: str | None = None):
        self.session = requests.Session()
        self.headers = {
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Cache-Control": "no-cache",
        }
        if headers:
            self.headers.update(headers)
        self.bearer_token = normalize_bearer_token(bearer_token)
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

    def request(
        self,
        method: str,
        url: str,
        *,
        timeout: float,
        allow_redirects: bool = True,
        api: bool = False,
        bearer: bool = False,
        stream: bool = False,
        **kwargs: Any,
    ) -> requests.Response:
        headers = dict(self.headers)
        headers.update(kwargs.pop("headers", {}) or {})
        if api:
            headers["Accept"] = "application/json"
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
            stream=stream,
            **kwargs,
        )
        self._remember_cookies(response)
        for item in response.history:
            self._remember_cookies(item)
        return response


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="microseconds")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def normalize_base_url(value: str) -> str:
    return value.rstrip("/") + "/"


def normalize_bearer_token(value: str | None) -> str | None:
    if value is None:
        return None
    token = value.strip()
    if not token:
        return None
    if token.lower().startswith("bearer "):
        token = token.split(None, 1)[1].strip()
    return token or None


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


def safe_headers(headers: requests.structures.CaseInsensitiveDict[str]) -> dict[str, str]:
    return {key: value for key, value in headers.items() if key.lower() not in SENSITIVE_HEADERS}


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


def load_credentials_csv(path: Path) -> list[tuple[str, str]]:
    if not path.is_file():
        raise ValueError(f"Fichier d'identifiants introuvable : {path}")
    email_keys = {"email", "username", "login", "user", "nom", "name"}
    password_keys = {"password", "mot_de_passe", "motdepasse", "pass"}
    credentials: list[tuple[str, str]] = []
    with path.open("r", newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        fieldnames = {(name or "").strip().lower() for name in (reader.fieldnames or [])}
        email_col = next((name for name in fieldnames if name in email_keys), None)
        password_col = next((name for name in fieldnames if name in password_keys), None)
        if not email_col or not password_col:
            raise ValueError("Le CSV doit contenir une colonne email/login et une colonne password.")
        for line_no, row in enumerate(reader, start=2):
            normalized = {(key or "").strip().lower(): (value or "").strip() for key, value in row.items()}
            email = normalized.get(email_col, "")
            password = normalized.get(password_col, "")
            if not email or not password:
                raise ValueError(f"Identifiant incomplet à la ligne {line_no} du fichier CSV.")
            credentials.append((email, password))
    if not credentials:
        raise ValueError("Le fichier d'identifiants ne contient aucun compte.")
    return credentials


def load_tokens_csv(path: Path, base_url: str, target_name: str) -> dict[str, Any]:
    result: dict[str, Any] = {
        "path": str(path),
        "loaded": False,
        "token_available": False,
        "token": None,
        "token_fingerprint": None,
        "matched_by": None,
        "rows": 0,
    }
    if not path.is_file():
        return result
    normalized_base = normalize_base_url(base_url)
    base_host = urlparse(normalized_base).hostname or ""
    with path.open("r", newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        result["loaded"] = True
        for row in reader:
            result["rows"] += 1
            normalized = {(key or "").strip().lower(): (value or "").strip() for key, value in row.items()}
            token = normalize_bearer_token(normalized.get("token") or normalized.get("bearer") or normalized.get("api_token"))
            if not token:
                continue
            candidates = [
                normalized.get("url", ""),
                normalized.get("base_url", ""),
                normalized.get("target", ""),
                normalized.get("cible", ""),
                normalized.get("nom", ""),
                normalized.get("name", ""),
                normalized.get("env", ""),
            ]
            for candidate in candidates:
                value = candidate.strip()
                if not value:
                    continue
                match = False
                if value in {target_name, target_name.lower()}:
                    match = True
                elif value.startswith("http") and normalize_base_url(value) == normalized_base:
                    match = True
                elif value.startswith("http") and (urlparse(value).hostname or "") == base_host:
                    match = True
                if match:
                    result.update({
                        "token_available": True,
                        "token": token,
                        "token_fingerprint": sha256_text(token)[:12],
                        "matched_by": value,
                    })
                    return result
    return result


def resolve_auto_file(explicit: Path | None, filename: str) -> Path | None:
    if explicit is not None:
        return explicit
    candidates = [Path.cwd() / filename, Path(__file__).resolve().parent / filename]
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    return None


def build_ramp(max_users: int, explicit: str | None) -> list[int]:
    if explicit:
        values: list[int] = []
        for token in explicit.split(","):
            try:
                number = int(token.strip())
            except ValueError as exc:
                raise argparse.ArgumentTypeError("Les paliers doivent être des entiers.") from exc
            if number < 1:
                raise argparse.ArgumentTypeError("Chaque palier doit être supérieur ou égal à 1.")
            if number > max_users:
                raise argparse.ArgumentTypeError(f"Chaque palier doit être <= --users ({max_users}).")
            values.append(number)
        return sorted(set(values))
    candidates = [1, max(1, math.ceil(max_users * 0.25)), max(1, math.ceil(max_users * 0.50)), max(1, math.ceil(max_users * 0.75)), max_users]
    return sorted(set(candidates))


def route_configs(area: str) -> list[RouteConfig]:
    if area == "public":
        return [RouteConfig(**item) for item in PUBLIC_ROUTES]
    if area == "api":
        return [RouteConfig(**item) for item in API_ROUTES]
    if area == "admin-read":
        return [RouteConfig(**item) for item in ADMIN_ROUTES]
    raise ValueError(f"Zone inconnue : {area}")


def routes_for_mode(mode: str) -> list[str]:
    if mode == "public":
        return ["public"]
    if mode == "api":
        return ["api"]
    if mode == "admin":
        return ["admin-read"]
    if mode == "all":
        return ["public", "api", "admin-read"]
    raise ValueError(f"Mode inconnu : {mode}")


def build_target(args: argparse.Namespace) -> tuple[TargetConfig, dict[str, Any]]:
    if args.base_url:
        base_url = args.base_url
        target_name = args.target or "custom"
    elif args.target == "local":
        base_url = DEFAULT_LOCAL_URL
        target_name = "local"
    elif args.target == "remote":
        base_url = DEFAULT_REMOTE_URL
        target_name = "remote"
    else:
        base_url = DEFAULT_LOCAL_URL
        target_name = "local"
    base_url = normalize_base_url(base_url)

    headers: dict[str, str] = {}
    if args.host_header:
        headers["Host"] = args.host_header
    if args.forwarded_proto:
        headers["X-Forwarded-Proto"] = args.forwarded_proto
    if args.auto_local_canonical_headers:
        parsed = urlparse(base_url)
        host = parsed.hostname or ""
        if host in {"127.0.0.1", "localhost", "::1"}:
            canonical = urlparse(args.canonical_url or DEFAULT_REMOTE_URL)
            headers.setdefault("Host", canonical.hostname or "")
            headers.setdefault("X-Forwarded-Proto", canonical.scheme or "https")

    token_info = {"loaded": False, "token_available": False, "token_fingerprint": None}
    tokens_file = resolve_auto_file(args.tokens_file, DEFAULT_TOKENS_FILENAME)
    bearer_token = normalize_bearer_token(args.bearer_token)
    bearer_fingerprint = sha256_text(bearer_token)[:12] if bearer_token else None
    if not bearer_token and tokens_file is not None:
        token_info = load_tokens_csv(tokens_file, base_url, target_name)
        bearer_token = token_info.get("token")
        bearer_fingerprint = token_info.get("token_fingerprint")
    elif bearer_token:
        token_info = {
            "loaded": bool(tokens_file and tokens_file.is_file()),
            "token_available": True,
            "token_fingerprint": bearer_fingerprint,
            "matched_by": "--bearer-token",
            "rows": None,
        }

    target = TargetConfig(
        name=target_name,
        base_url=base_url,
        headers=headers,
        bearer_token=bearer_token,
        bearer_fingerprint=bearer_fingerprint,
    )
    return target, {key: value for key, value in token_info.items() if key != "token"}


def collect_target_evidence(target: TargetConfig, timeout: float) -> dict[str, Any]:
    parsed = urlparse(target.base_url)
    host = parsed.hostname or ""
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    evidence: dict[str, Any] = {
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
        evidence["resolved_addresses"] = sorted({item[4][0] for item in socket.getaddrinfo(host, port)})
    except OSError as exc:
        evidence["dns_error"] = str(exc)
    if parsed.scheme == "https":
        try:
            context = ssl.create_default_context()
            with socket.create_connection((host, port), timeout=timeout) as raw:
                with context.wrap_socket(raw, server_hostname=host) as secure:
                    certificate = secure.getpeercert(binary_form=True)
                    info = secure.getpeercert()
                    evidence["tls"] = {
                        "protocol": secure.version(),
                        "cipher": secure.cipher(),
                        "certificate_sha256": sha256_bytes(certificate),
                        "subject": info.get("subject"),
                        "issuer": info.get("issuer"),
                        "not_before": info.get("notBefore"),
                        "not_after": info.get("notAfter"),
                    }
        except (OSError, ssl.SSLError) as exc:
            evidence["tls_error"] = str(exc)
    session = EvidenceSession(headers=target.headers)
    try:
        response = session.request("GET", target.base_url, timeout=timeout, allow_redirects=True)
        evidence["probe"] = {
            "checked_at_utc": utc_now(),
            "status": response.status_code,
            "final_url": response.url,
            "bytes": len(response.content),
            "body_sha256": sha256_bytes(response.content),
            "headers": safe_headers(response.headers),
        }
    except requests.RequestException as exc:
        evidence["probe_error"] = str(exc)
    finally:
        session.close()
    return evidence


def bearer_preflight(target: TargetConfig, timeout: float, path: str) -> dict[str, Any]:
    out: dict[str, Any] = {
        "token_available": bool(target.bearer_token),
        "token_fingerprint": target.bearer_fingerprint,
        "path": path,
        "requested_url": urljoin(target.base_url, path),
        "status": "SKIPPED",
        "http_status": None,
        "final_url": None,
        "redirect_count": None,
        "www_authenticate": None,
        "content_type": None,
        "request_trace_header": None,
        "body_sha256": None,
        "error": None,
    }
    if not target.bearer_token:
        out["status"] = "MISSING_TOKEN"
        return out
    session = EvidenceSession(headers=target.headers, bearer_token=target.bearer_token)
    try:
        response = session.request("GET", out["requested_url"], timeout=timeout, allow_redirects=True, api=True, bearer=True)
        body = response.content
        trace = response.headers.get("X-Request-ID") or response.headers.get("X-Correlation-ID") or response.headers.get("Traceparent") or response.headers.get("CF-Ray")
        out.update({
            "status": "OK" if 200 <= response.status_code < 400 else "AUTH_FAILED" if response.status_code in {401, 403} else "HTTP_ERROR",
            "http_status": response.status_code,
            "final_url": response.url,
            "redirect_count": len(response.history),
            "www_authenticate": response.headers.get("WWW-Authenticate"),
            "content_type": response.headers.get("Content-Type"),
            "request_trace_header": trace,
            "body_sha256": sha256_bytes(body),
        })
    except requests.RequestException as exc:
        out.update({"status": "NETWORK_ERROR", "error": f"{type(exc).__name__}: {exc}"})
    finally:
        session.close()
    return out


def request_once(
    evidence_id: str,
    target: TargetConfig,
    scenario: str,
    stage: int,
    configured_users: int,
    vu_id: int,
    request_no: int,
    session: EvidenceSession,
    route: RouteConfig,
    timeout: float,
    cache_bust: bool,
) -> RequestResult:
    url = route_url(target.base_url, route)
    if cache_bust:
        url = add_query(url, {"_load": f"{evidence_id[:8]}-{stage}-{vu_id}-{request_no}"})
    started_utc = utc_now()
    started_mono = time.perf_counter()
    response: requests.Response | None = None
    error_text: str | None = None
    bearer_used = route.auth_policy == "bearer" and bool(target.bearer_token)
    try:
        response = session.request(
            route.method,
            url,
            timeout=timeout,
            allow_redirects=True,
            api=route.area == "api",
            bearer=route.auth_policy == "bearer",
            stream=True,
        )
        body = response.content
        elapsed_ms = (time.perf_counter() - started_mono) * 1000
        status = response.status_code
        ok = 200 <= status < 400
        trace = response.headers.get("X-Request-ID") or response.headers.get("X-Correlation-ID") or response.headers.get("Traceparent") or response.headers.get("CF-Ray")
        return RequestResult(
            evidence_id=evidence_id,
            target=target.name,
            scenario=scenario,
            stage=stage,
            configured_users=configured_users,
            vu_id=vu_id,
            request_no=request_no,
            route_name=route.name,
            auth_policy=route.auth_policy,
            bearer_used=bearer_used,
            method=route.method,
            requested_url=url,
            final_url=response.url,
            started_at_utc=started_utc,
            ended_at_utc=utc_now(),
            elapsed_ms=round(elapsed_ms, 3),
            status=status,
            ok=ok,
            bytes_received=len(body),
            response_sha256=sha256_bytes(body),
            redirect_count=len(response.history),
            server_date=response.headers.get("Date"),
            server_header=response.headers.get("Server"),
            request_trace_header=trace,
            error_category=classify_status(status, None),
            error=None if ok else f"HTTP {status}",
        )
    except requests.RequestException as exc:
        elapsed_ms = (time.perf_counter() - started_mono) * 1000
        error_text = f"{type(exc).__name__}: {exc}"
        return RequestResult(
            evidence_id=evidence_id,
            target=target.name,
            scenario=scenario,
            stage=stage,
            configured_users=configured_users,
            vu_id=vu_id,
            request_no=request_no,
            route_name=route.name,
            auth_policy=route.auth_policy,
            bearer_used=bearer_used,
            method=route.method,
            requested_url=url,
            final_url=None,
            started_at_utc=started_utc,
            ended_at_utc=utc_now(),
            elapsed_ms=round(elapsed_ms, 3),
            status=None,
            ok=False,
            bytes_received=0,
            response_sha256=None,
            redirect_count=0,
            server_date=None,
            server_header=None,
            request_trace_header=None,
            error_category=classify_status(None, error_text),
            error=error_text,
        )
    finally:
        if response is not None:
            response.close()


def run_stage(
    evidence_id: str,
    target: TargetConfig,
    scenario: str,
    stage_no: int,
    users: int,
    duration_s: float,
    routes: list[RouteConfig],
    timeout: float,
    think_min_s: float,
    think_max_s: float,
    credentials: list[tuple[str, str]] | None,
    allow_credential_reuse: bool,
    cache_bust: bool,
) -> tuple[list[RequestResult], dict[str, Any]]:
    barrier = threading.Barrier(users + 1)
    start_event = threading.Event()
    stop_at = [0.0]
    results: list[RequestResult] = []
    result_lock = threading.Lock()
    auth_failures: list[str] = []
    initialized_vus: list[int] = []

    if scenario == "admin-read":
        if not credentials:
            return [], inconclusive_stage_meta(stage_no, scenario, users, duration_s, "Aucun identifiant administrateur disponible.")
        if not allow_credential_reuse and len(credentials) < users:
            return [], inconclusive_stage_meta(stage_no, scenario, users, duration_s, f"Nombre insuffisant de comptes distincts : {len(credentials)} disponible(s) pour {users} VU.")

    def credential_for(vu_id: int) -> tuple[str, str] | None:
        if scenario != "admin-read" or not credentials:
            return None
        if allow_credential_reuse:
            return credentials[(vu_id - 1) % len(credentials)]
        return credentials[vu_id - 1]

    def virtual_user(vu_id: int) -> None:
        session = EvidenceSession(headers=target.headers, bearer_token=target.bearer_token)
        initialized = True
        if scenario == "admin-read":
            credential = credential_for(vu_id)
            assert credential is not None
            ok, detail = authenticate_admin(target, session, credential[0], credential[1], timeout)
            initialized = ok
            with result_lock:
                if ok:
                    initialized_vus.append(vu_id)
                else:
                    auth_failures.append(f"VU {vu_id}: {detail}")
        try:
            barrier.wait(timeout=max(60.0, timeout * max(1, users)))
        except threading.BrokenBarrierError:
            session.close()
            return
        start_event.wait()
        if not initialized or stop_at[0] <= time.perf_counter():
            session.close()
            return
        request_no = 0
        while time.perf_counter() < stop_at[0]:
            request_no += 1
            route = routes[(vu_id + request_no - 2) % len(routes)]
            item = request_once(
                evidence_id=evidence_id,
                target=target,
                scenario=scenario,
                stage=stage_no,
                configured_users=users,
                vu_id=vu_id,
                request_no=request_no,
                session=session,
                route=route,
                timeout=timeout,
                cache_bust=cache_bust,
            )
            with result_lock:
                results.append(item)
            if think_max_s > 0:
                pause = random.uniform(think_min_s, think_max_s)
                remaining = stop_at[0] - time.perf_counter()
                if remaining > 0:
                    time.sleep(min(pause, remaining))
        session.close()

    threads = [threading.Thread(target=virtual_user, args=(vu_id,), daemon=True) for vu_id in range(1, users + 1)]
    stage_started_utc = utc_now()
    stage_started_mono = time.perf_counter()
    for thread in threads:
        thread.start()
    try:
        barrier.wait(timeout=max(60.0, timeout * max(1, users)))
    except threading.BrokenBarrierError:
        auth_failures.append("Synchronisation des utilisateurs impossible.")
    initialization_duration = time.perf_counter() - stage_started_mono

    if scenario == "admin-read" and len(initialized_vus) != users:
        stop_at[0] = time.perf_counter()
        start_event.set()
        for thread in threads:
            thread.join(timeout=2)
        reason = f"{len(initialized_vus)} session(s) initialisée(s) sur {users}; palier non exécuté."
        meta = inconclusive_stage_meta(stage_no, scenario, users, duration_s, reason)
        meta["authentication_failures"] = auth_failures
        meta["initialized_virtual_users"] = len(initialized_vus)
        meta["initialization_duration_s"] = round(initialization_duration, 6)
        meta["stage_started_at_utc"] = stage_started_utc
        meta["stage_ended_at_utc"] = utc_now()
        return [], meta

    actual_load_start_utc = utc_now()
    actual_load_start_mono = time.perf_counter()
    stop_at[0] = actual_load_start_mono + duration_s
    start_event.set()
    for thread in threads:
        thread.join(timeout=duration_s + timeout + think_max_s + 5)
    actual_load_end_mono = time.perf_counter()
    actual_duration = max(0.001, actual_load_end_mono - actual_load_start_mono)
    return results, {
        "stage": stage_no,
        "scenario": scenario,
        "users": users,
        "configured_duration_s": duration_s,
        "actual_duration_s": round(actual_duration, 6),
        "initialization_duration_s": round(initialization_duration, 6),
        "stage_started_at_utc": stage_started_utc,
        "load_started_at_utc": actual_load_start_utc,
        "stage_ended_at_utc": utc_now(),
        "authentication_failures": auth_failures,
        "initialized_virtual_users": users if scenario != "admin-read" else len(initialized_vus),
        "execution_status": "COMPLETED",
        "status_reason": None,
        "routes": [asdict(route) for route in routes],
    }


def inconclusive_stage_meta(stage: int, scenario: str, users: int, duration_s: float, reason: str) -> dict[str, Any]:
    return {
        "stage": stage,
        "scenario": scenario,
        "users": users,
        "configured_duration_s": duration_s,
        "actual_duration_s": 0.0,
        "initialization_duration_s": 0.0,
        "stage_started_at_utc": utc_now(),
        "load_started_at_utc": None,
        "stage_ended_at_utc": utc_now(),
        "authentication_failures": [reason],
        "initialized_virtual_users": 0,
        "execution_status": "INCONCLUSIVE",
        "status_reason": reason,
        "routes": [],
    }


def threshold_for_scenario(scenario: str, args: argparse.Namespace) -> float:
    if scenario == "public":
        return args.max_p95_public
    if scenario == "api":
        return args.max_p95_api
    if scenario == "admin-read":
        return args.max_p95_admin
    return args.max_p95_public


def summarize_stage(items: list[RequestResult], stage_meta: dict[str, Any], args: argparse.Namespace) -> dict[str, Any]:
    scenario = stage_meta.get("scenario", "unknown")
    max_p95_ms = threshold_for_scenario(scenario, args)
    if stage_meta.get("execution_status") == "INCONCLUSIVE":
        return {
            **stage_meta,
            "status": "INCONCLUSIVE",
            "requests": 0,
            "active_virtual_users": 0,
            "successes": 0,
            "errors": 0,
            "success_rate_pct": 0.0,
            "error_rate_pct": 0.0,
            "http_429_count": 0,
            "http_429_rate_pct": 0.0,
            "http_5xx_count": 0,
            "network_error_count": 0,
            "throughput_total_rps": 0.0,
            "goodput_success_rps": 0.0,
            "success_latency_avg_ms": 0.0,
            "success_latency_p50_ms": 0.0,
            "success_latency_p90_ms": 0.0,
            "success_latency_p95_ms": 0.0,
            "success_latency_p99_ms": 0.0,
            "success_latency_max_ms": 0.0,
            "all_latency_avg_ms": 0.0,
            "all_latency_p95_ms": 0.0,
            "failure_latency_avg_ms": 0.0,
            "status_counts": {},
            "error_category_counts": {},
            "top_errors": {},
            "threshold_p95_ms": max_p95_ms,
        }
    all_latencies = [item.elapsed_ms for item in items]
    successes = [item for item in items if item.ok]
    failures = [item for item in items if not item.ok]
    success_latencies = [item.elapsed_ms for item in successes]
    failure_latencies = [item.elapsed_ms for item in failures]
    duration_s = max(0.001, float(stage_meta["actual_duration_s"]))
    status_counts = Counter(str(item.status) if item.status is not None else "network" for item in items)
    category_counts = Counter(item.error_category or "" for item in failures)
    error_counts = Counter(item.error or "" for item in failures)
    unique_vus = len({item.vu_id for item in items})
    count = len(items)
    if count == 0:
        return {**stage_meta, "status": "INCONCLUSIVE", "status_reason": "Aucune requête mesurée.", "requests": 0, "threshold_p95_ms": max_p95_ms}
    success_rate = 100 * len(successes) / count
    error_rate = 100 * len(failures) / count
    rate_429 = 100 * status_counts.get("429", 0) / count
    http_5xx_count = sum(1 for item in items if item.status is not None and item.status >= 500)
    network_error_count = sum(1 for item in items if item.status is None)
    p95 = percentile(success_latencies, 0.95)
    status = "PASS"
    reasons: list[str] = []
    if args.fail_on_5xx and http_5xx_count > 0:
        status = "FAIL"
        reasons.append(f"{http_5xx_count} réponse(s) 5xx")
    if network_error_count > 0:
        status = "FAIL"
        reasons.append(f"{network_error_count} erreur(s) réseau")
    if error_rate > args.max_error_rate:
        status = "FAIL"
        reasons.append(f"taux d'erreur {error_rate:.2f}% > {args.max_error_rate}%")
    if rate_429 >= args.max_429_rate:
        status = "FAIL"
        reasons.append(f"taux 429 {rate_429:.2f}% >= {args.max_429_rate}%")
    elif rate_429 > 0 and status != "FAIL":
        status = "WARN"
        reasons.append(f"{rate_429:.2f}% de 429 : protection de trafic observée")
    if p95 > max_p95_ms:
        if status != "FAIL":
            status = "FAIL"
        reasons.append(f"p95 {p95:.1f} ms > seuil {max_p95_ms:.1f} ms")
    return {
        **stage_meta,
        "status": status,
        "status_reasons": reasons,
        "requests": count,
        "active_virtual_users": unique_vus,
        "successes": len(successes),
        "errors": len(failures),
        "success_rate_pct": round(success_rate, 4),
        "error_rate_pct": round(error_rate, 4),
        "http_429_count": status_counts.get("429", 0),
        "http_429_rate_pct": round(rate_429, 4),
        "http_5xx_count": http_5xx_count,
        "network_error_count": network_error_count,
        "throughput_total_rps": round(count / duration_s, 4),
        "goodput_success_rps": round(len(successes) / duration_s, 4),
        "success_latency_avg_ms": round(statistics.fmean(success_latencies), 3) if success_latencies else 0.0,
        "success_latency_p50_ms": round(percentile(success_latencies, 0.50), 3),
        "success_latency_p90_ms": round(percentile(success_latencies, 0.90), 3),
        "success_latency_p95_ms": round(p95, 3),
        "success_latency_p99_ms": round(percentile(success_latencies, 0.99), 3),
        "success_latency_max_ms": round(max(success_latencies), 3) if success_latencies else 0.0,
        "all_latency_avg_ms": round(statistics.fmean(all_latencies), 3) if all_latencies else 0.0,
        "all_latency_p95_ms": round(percentile(all_latencies, 0.95), 3),
        "failure_latency_avg_ms": round(statistics.fmean(failure_latencies), 3) if failure_latencies else 0.0,
        "status_counts": dict(status_counts),
        "error_category_counts": dict(category_counts),
        "top_errors": dict(error_counts.most_common(10)),
        "threshold_p95_ms": max_p95_ms,
    }


def summarize_routes(items: list[RequestResult]) -> list[dict[str, Any]]:
    grouped: dict[tuple[str, int, str], list[RequestResult]] = defaultdict(list)
    for item in items:
        grouped[(item.scenario, item.stage, item.route_name)].append(item)
    rows: list[dict[str, Any]] = []
    for (scenario, stage, route_name), values in sorted(grouped.items()):
        successes = [item for item in values if item.ok]
        failures = [item for item in values if not item.ok]
        latencies = [item.elapsed_ms for item in successes]
        count = len(values)
        status_counts = Counter(str(item.status) if item.status is not None else "network" for item in values)
        rows.append({
            "scenario": scenario,
            "stage": stage,
            "route_name": route_name,
            "auth_policy": values[0].auth_policy if values else None,
            "requests": count,
            "successes": len(successes),
            "errors": len(failures),
            "success_rate_pct": round(100 * len(successes) / count, 4) if count else 0.0,
            "p50_ms": round(percentile(latencies, 0.50), 3),
            "p90_ms": round(percentile(latencies, 0.90), 3),
            "p95_ms": round(percentile(latencies, 0.95), 3),
            "p99_ms": round(percentile(latencies, 0.99), 3),
            "max_ms": round(max(latencies), 3) if latencies else 0.0,
            "status_counts": dict(status_counts),
        })
    return rows


def find_db_root(explicit: Path | None) -> Path | None:
    candidates: list[Path] = []
    if explicit is not None:
        candidates.append(explicit)
    cwd = Path.cwd()
    script_dir = Path(__file__).resolve().parent
    for root in {cwd, script_dir, cwd.parent, script_dir.parent}:
        candidates.extend([
            root / "storage" / "database",
            root / "storage" / "sqlite",
            root / "database",
            root / "mod" / "storage" / "database",
        ])
    for candidate in candidates:
        if candidate.is_dir() and any((candidate / name).is_file() for name in DATASET_TABLES):
            return candidate
    return None


def sqlite_count(path: Path, table: str) -> int | None:
    uri = f"file:{path.as_posix()}?mode=ro"
    try:
        with sqlite3.connect(uri, uri=True, timeout=2.0) as conn:
            row = conn.execute("SELECT name FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone()
            if row is None:
                return None
            value = conn.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
            return int(value)
    except sqlite3.Error:
        return None


def classify_dataset(counts: dict[str, int], total_size: int) -> str:
    entries = counts.get("core.content_entries", 0)
    snapshots = counts.get("core.public_content_snapshots", 0)
    search_docs = counts.get("core.search_documents", 0)
    media = counts.get("core.media_assets", 0)
    revisions = counts.get("core.revisions", 0)
    routes = counts.get("core.routes", 0)
    if max(entries, snapshots, search_docs, media, revisions, routes, total_size) == 0:
        return "unknown"
    score = 0
    if entries >= 10000 or snapshots >= 25000 or media >= 20000 or revisions >= 100000 or total_size >= 2_000_000_000:
        score = max(score, 4)
    elif entries >= 2500 or snapshots >= 7500 or media >= 5000 or revisions >= 25000 or total_size >= 500_000_000:
        score = max(score, 3)
    elif entries >= 250 or snapshots >= 750 or media >= 500 or revisions >= 2500 or total_size >= 50_000_000:
        score = max(score, 2)
    else:
        score = max(score, 1)
    return {1: "small", 2: "medium", 3: "large", 4: "very_large"}[score]


def collect_dataset_profile(db_root: Path | None) -> dict[str, Any]:
    if db_root is None:
        return {"status": "not_found", "profile": "unknown", "reason": "Aucun dossier de bases SQLite détecté."}
    counts: dict[str, int] = {}
    files: dict[str, Any] = {}
    total_size = 0
    for filename, tables in DATASET_TABLES.items():
        path = db_root / filename
        if not path.is_file():
            files[filename] = {"present": False}
            continue
        size = path.stat().st_size
        total_size += size
        file_info: dict[str, Any] = {"present": True, "size_bytes": size, "sha256": sha256_file(path), "tables": {}}
        domain = filename.replace(".sqlite", "")
        for logical, table in tables.items():
            count = sqlite_count(path, table)
            if count is not None:
                key = f"{domain}.{logical}"
                counts[key] = count
                file_info["tables"][logical] = count
        files[filename] = file_info
    return {
        "status": "measured",
        "db_root": str(db_root),
        "profile": classify_dataset(counts, total_size),
        "total_size_bytes": total_size,
        "counts": counts,
        "files": files,
        "limits": [
            "Mesure locale read-only.",
            "Le profil de données qualifie le volume testé, pas une extrapolation vers un volume supérieur.",
        ],
    }


def collect_environment() -> dict[str, Any]:
    script_path = Path(__file__).resolve()
    return {
        "program": "webeLi concurrent load evidence",
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


def determine_verdict(summaries: list[dict[str, Any]], requested_scenarios: list[str], skipped: list[dict[str, Any]]) -> tuple[str, list[str]]:
    reasons: list[str] = []
    statuses = [row.get("status", "INCONCLUSIVE") for row in summaries]
    completed = {row.get("scenario") for row in summaries}
    for scenario in requested_scenarios:
        if scenario not in completed:
            reasons.append(f"{scenario} : aucun palier exécuté.")
    for item in skipped:
        reasons.append(f"{item.get('scenario')} : {item.get('reason')}")
    for row in summaries:
        if row.get("status") == "FAIL":
            reasons.append(f"{row['scenario']} palier {row['users']} : {'; '.join(row.get('status_reasons') or ['critères dépassés'])}")
        elif row.get("status") == "WARN":
            reasons.append(f"{row['scenario']} palier {row['users']} : {'; '.join(row.get('status_reasons') or ['avertissement'])}")
        elif row.get("status") == "INCONCLUSIVE":
            reasons.append(f"{row['scenario']} palier {row['users']} : {row.get('status_reason') or 'initialisation incomplète'}")
    if "FAIL" in statuses:
        return "FAIL", reasons
    if "INCONCLUSIVE" in statuses or skipped or any("aucun palier" in reason for reason in reasons):
        return "INCONCLUSIVE", reasons
    if "WARN" in statuses:
        return "WARN", reasons
    if summaries:
        return "PASS", reasons
    return "SKIPPED", ["Aucun scénario n'a été exécuté."]


def build_scope_verdict(
    summaries: list[dict[str, Any]],
    requested_scenarios: list[str],
    skipped: list[dict[str, Any]],
    dataset_profile: dict[str, Any],
) -> dict[str, Any]:
    by_scenario: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in summaries:
        by_scenario[row.get("scenario", "unknown")].append(row)
    scope: dict[str, Any] = {}
    labels = {"public": "Charge concurrente publique SSR", "api": "Charge concurrente API lecture", "admin-read": "Charge concurrente admin lecture"}
    for scenario, label in labels.items():
        rows = by_scenario.get(scenario, [])
        if scenario not in requested_scenarios:
            status = "not_requested"
            conclusion = "Non demandé dans ce run."
        elif not rows:
            status = "inconclusive"
            conclusion = "Aucun palier exécuté."
        elif any(row.get("status") == "FAIL" for row in rows):
            status = "failed"
            last_ok = max((row["users"] for row in rows if row.get("status") in {"PASS", "WARN"}), default=0)
            conclusion = f"Dégradation ou échec observé ; dernier palier exploitable : {last_ok} VU."
        elif any(row.get("status") == "INCONCLUSIVE" for row in rows):
            status = "inconclusive"
            conclusion = "Initialisation ou couverture incomplète."
        elif any(row.get("status") == "WARN" for row in rows):
            status = "demonstrated_with_warnings"
            max_users = max(row["users"] for row in rows)
            conclusion = f"Démontré jusqu'à {max_users} VU avec avertissement(s)."
        else:
            status = "demonstrated"
            max_users = max(row["users"] for row in rows)
            conclusion = f"Démontré jusqu'à {max_users} VU selon les critères déclarés."
        scope[scenario] = {"label": label, "status": status, "conclusion": conclusion}
    profile = dataset_profile.get("profile", "unknown")
    scope["dataset"] = {
        "label": "Volume de données",
        "status": "measured" if dataset_profile.get("status") == "measured" else "not_measured",
        "profile": profile,
        "conclusion": f"Charge mesurée sur profil {profile}; pas d'extrapolation automatique vers un profil supérieur.",
    }
    scope["writes"] = {"label": "Écriture / publication", "status": "not_tested", "conclusion": "Non testé par défaut : ce script reste en lecture."}
    scope["long_duration"] = {"label": "Tenue longue durée", "status": "profile_dependent", "conclusion": "Démontrée uniquement avec le profil soak ou une durée longue explicite."}
    return {"scope": scope, "skipped": skipped}


def write_csv(path: Path, rows: list[RequestResult]) -> None:
    if not rows:
        path.write_text("", encoding="utf-8")
        return
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(asdict(rows[0]).keys()))
        writer.writeheader()
        for row in rows:
            writer.writerow(asdict(row))


def write_evidence_bundle(
    output_dir: Path,
    metadata: dict[str, Any],
    target_evidence: dict[str, Any],
    environment: dict[str, Any],
    dataset_profile: dict[str, Any],
    stages: list[dict[str, Any]],
    results: list[RequestResult],
    summaries: list[dict[str, Any]],
    route_summaries: list[dict[str, Any]],
    verdict: str,
    verdict_reasons: list[str],
    scope_verdict: dict[str, Any],
) -> None:
    output_dir.mkdir(parents=True, exist_ok=False)
    (output_dir / "executed_script.py").write_bytes(Path(__file__).resolve().read_bytes())
    payloads = {
        "metadata.json": metadata,
        "target_evidence.json": target_evidence,
        "environment.json": environment,
        "dataset_profile.json": dataset_profile,
        "stages.json": {"stages": stages},
        "summary.json": {"summaries": summaries},
        "route_summary.json": {"routes": route_summaries},
        "verdict.json": {"verdict": verdict, "reasons": verdict_reasons, "criteria": metadata["acceptance_criteria"]},
        "scope_verdict.json": scope_verdict,
        "results.json": {"evidence_id": metadata["evidence_id"], "results": [asdict(item) for item in results]},
    }
    for filename, payload in payloads.items():
        (output_dir / filename).write_text(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    write_csv(output_dir / "results.csv", results)

    lines = [
        "# Preuve de charge concurrente webeLi",
        "",
        f"- Identifiant de preuve : `{metadata['evidence_id']}`",
        f"- Début UTC : {metadata['started_at_utc']}",
        f"- Fin UTC : {metadata['ended_at_utc']}",
        f"- Cible : `{metadata['base_url']}`",
        f"- Mode : `{metadata['mode']}`",
        f"- Profil : `{metadata['profile']}`",
        f"- Paliers : `{', '.join(map(str, metadata['ramp']))}`",
        f"- Durée configurée par palier : {metadata['duration_s']} s",
        f"- Verdict selon critères déclarés : **{verdict}**",
        "",
        "## Lecture rapide",
        "",
    ]
    for key, item in scope_verdict.get("scope", {}).items():
        lines.append(f"- **{item['label']}** : {item['status']} — {item['conclusion']}")
    if verdict_reasons:
        lines.extend(["", "## Écarts, avertissements ou limites", ""])
        lines.extend(f"- {reason}" for reason in verdict_reasons)

    lines.extend([
        "",
        "## Résultats par palier",
        "",
        "| Scénario | VU | Statut | Requêtes | Succès | 429 | 5xx | req/s brut | req/s utile | p50 | p95 | p99 |",
        "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|",
    ])
    for row in summaries:
        lines.append(
            f"| {row.get('scenario')} | {row.get('users')} | {row.get('status')} | "
            f"{row.get('requests', 0)} | {row.get('success_rate_pct', 0)} % | "
            f"{row.get('http_429_rate_pct', 0)} % | {row.get('http_5xx_count', 0)} | "
            f"{row.get('throughput_total_rps', 0)} | {row.get('goodput_success_rps', 0)} | "
            f"{row.get('success_latency_p50_ms', 0)} ms | {row.get('success_latency_p95_ms', 0)} ms | {row.get('success_latency_p99_ms', 0)} ms |"
        )

    slow_routes = sorted(route_summaries, key=lambda row: row.get("p95_ms", 0), reverse=True)[:10]
    lines.extend(["", "## Routes les plus lentes par p95", ""])
    for row in slow_routes:
        lines.append(
            f"- {row['scenario']} palier {row['stage']} / {row['route_name']} : "
            f"p95 {row['p95_ms']} ms, succès {row['success_rate_pct']} %, requêtes {row['requests']}."
        )

    lines.extend(["", "## Préflight Bearer", ""])
    preflight = metadata.get("bearer_preflight", {})
    lines.append(f"- Token disponible : {preflight.get('token_available')}")
    lines.append(f"- Empreinte token : {preflight.get('token_fingerprint') or 'n/a'}")
    lines.append(f"- Statut : {preflight.get('status')}")
    lines.append(f"- HTTP : {preflight.get('http_status')}")
    lines.append(f"- URL finale : `{preflight.get('final_url') or preflight.get('requested_url')}`")

    lines.extend(["", "## Profil de données", ""])
    if dataset_profile.get("status") == "measured":
        lines.append(f"- Profil : **{dataset_profile.get('profile')}**")
        lines.append(f"- Taille totale : {dataset_profile.get('total_size_bytes')} octets")
        key_counts = ["core.content_entries", "core.public_content_snapshots", "core.routes", "core.search_documents", "core.media_assets", "core.revisions"]
        for key in key_counts:
            if key in dataset_profile.get("counts", {}):
                lines.append(f"- {key} : {dataset_profile['counts'][key]}")
    else:
        lines.append(f"- Profil : unknown — {dataset_profile.get('reason', 'non mesuré')}")

    lines.extend([
        "",
        "## Critères d'acceptation déclarés",
        "",
        f"- Taux d'erreur maximal : {metadata['acceptance_criteria']['max_error_rate_pct']} %.",
        f"- Taux 429 maximal avant échec : {metadata['acceptance_criteria']['max_429_rate_pct']} %.",
        f"- p95 public maximal : {metadata['acceptance_criteria']['max_p95_public_ms']} ms.",
        f"- p95 API maximal : {metadata['acceptance_criteria']['max_p95_api_ms']} ms.",
        f"- p95 admin maximal : {metadata['acceptance_criteria']['max_p95_admin_ms']} ms.",
        "",
        "## Portée probatoire",
        "",
        "Ce script qualifie une charge concurrente de lecture sur la cible indiquée. Il ne teste pas les écritures, la publication, ni la cause interne d'un ralentissement. Une réponse 429 indique une protection de trafic ou une limite d'infrastructure ; elle ne prouve pas à elle seule une saturation du CMS.",
        "",
        "Les identifiants, cookies et tokens ne sont pas enregistrés. Les fichiers produits peuvent être vérifiés par SHA-256.",
        "",
        "## Vérification",
        "",
        "```bash",
        "sha256sum -c manifest.sha256",
        "```",
    ])
    (output_dir / "load_report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")

    manifest_targets = sorted(path for path in output_dir.iterdir() if path.is_file() and path.name != "manifest.sha256")
    manifest_lines = [f"{sha256_file(path)}  {path.name}" for path in manifest_targets]
    (output_dir / "manifest.sha256").write_text("\n".join(manifest_lines) + "\n", encoding="utf-8")


def positive_int(value: str) -> int:
    try:
        number = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("Valeur entière attendue.") from exc
    if number < 1:
        raise argparse.ArgumentTypeError("La valeur doit être supérieure ou égale à 1.")
    return number


def non_negative_float(value: str) -> float:
    try:
        number = float(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("Valeur numérique attendue.") from exc
    if number < 0:
        raise argparse.ArgumentTypeError("La valeur ne peut pas être négative.")
    return number


def main() -> int:
    parser = argparse.ArgumentParser(description="Test de charge concurrente avec dossier de preuve SHA-256.")
    parser.add_argument("--profile", choices=sorted(PROFILE_DEFAULTS), default="smoke", help="Profil de charge prédéfini.")
    parser.add_argument("--target", choices=["local", "remote", "custom"], default="local", help="Cible logique ; local par défaut pour éviter une charge distante involontaire.")
    parser.add_argument("--base-url", help="URL de base explicite ; surcharge --target.")
    parser.add_argument("--canonical-url", default=DEFAULT_REMOTE_URL, help="URL canonique utilisée pour Host/X-Forwarded-Proto en local.")
    parser.add_argument("--users", type=positive_int, help="Nombre maximal d'utilisateurs virtuels.")
    parser.add_argument("--ramp", help="Paliers explicites, par exemple 1,5,10,25.")
    parser.add_argument("--duration", type=non_negative_float, help="Durée de charge par palier en secondes.")
    parser.add_argument("--mode", choices=["public", "api", "admin", "all"], default="public", help="Scénario à exécuter.")
    parser.add_argument("--credentials-file", type=Path, help="CSV admin ; détecte users.csv si absent.")
    parser.add_argument("--tokens-file", type=Path, help="CSV token ; détecte token.csv si absent.")
    parser.add_argument("--bearer-token", help="Token Bearer explicite ; jamais écrit dans les preuves.")
    parser.add_argument("--api-bearer-preflight-path", default="api/v1/search?q=cms")
    parser.add_argument("--db-root", type=Path, help="Dossier SQLite local pour mesurer le profil de données.")
    parser.add_argument("--timeout", type=non_negative_float, default=15.0)
    parser.add_argument("--think-min", type=non_negative_float, help="Pause minimale entre requêtes par VU.")
    parser.add_argument("--think-max", type=non_negative_float, help="Pause maximale entre requêtes par VU.")
    parser.add_argument("--pause-between-stages", type=non_negative_float, default=5.0)
    parser.add_argument("--pause-between-scenarios", type=non_negative_float, default=30.0)
    parser.add_argument("--max-error-rate", type=non_negative_float, default=1.0)
    parser.add_argument("--max-429-rate", type=non_negative_float, default=5.0)
    parser.add_argument("--max-p95-public", type=non_negative_float, default=1000.0)
    parser.add_argument("--max-p95-api", type=non_negative_float, default=1200.0)
    parser.add_argument("--max-p95-admin", type=non_negative_float, default=1500.0)
    parser.add_argument("--fail-on-5xx", action="store_true", default=True)
    parser.add_argument("--allow-credential-reuse", action="store_true", help="Réutilise les comptes admin si le CSV contient moins de comptes que de VU.")
    parser.add_argument("--cache-bust", action="store_true", help="Ajoute un paramètre _load pour limiter les effets de cache.")
    parser.add_argument("--host-header", help="En-tête Host explicite.")
    parser.add_argument("--forwarded-proto", help="En-tête X-Forwarded-Proto explicite.")
    parser.add_argument("--no-auto-local-canonical-headers", dest="auto_local_canonical_headers", action="store_false")
    parser.set_defaults(auto_local_canonical_headers=True)
    parser.add_argument("--output", type=Path, help="Dossier de preuve à créer.")
    parser.add_argument("--yes", action="store_true", help="Confirme l'autorisation sans invite interactive.")
    args = parser.parse_args()

    defaults = PROFILE_DEFAULTS[args.profile]
    max_users = args.users or int(defaults["users"])
    ramp_text = args.ramp or str(defaults["ramp"])
    duration_s = args.duration if args.duration is not None else float(defaults["duration"])
    think_min = args.think_min if args.think_min is not None else float(defaults["think_min"])
    think_max = args.think_max if args.think_max is not None else float(defaults["think_max"])
    if duration_s <= 0:
        parser.error("La durée doit être supérieure à zéro.")
    if think_max < think_min:
        parser.error("--think-max doit être supérieur ou égal à --think-min.")
    ramp = build_ramp(max_users, ramp_text)

    target, token_info = build_target(args)
    credentials_file = resolve_auto_file(args.credentials_file, DEFAULT_CREDENTIALS_FILENAME)
    credentials: list[tuple[str, str]] | None = None
    credential_source = "none"
    if args.mode in {"admin", "all"}:
        if credentials_file is not None:
            try:
                credentials = load_credentials_csv(credentials_file)
            except ValueError as exc:
                parser.error(str(exc))
            credential_source = "csv-distinct-accounts" if not args.allow_credential_reuse else "csv-reused-accounts"
        elif sys.stdin.isatty():
            email = input("Compte admin de test : ").strip()
            password = getpass.getpass("Mot de passe admin : ")
            credentials = [(email, password)]
            args.allow_credential_reuse = True
            credential_source = "interactive-single-account-reused"

    if not args.yes:
        print("\nCe test génère une charge réelle sur le serveur.")
        print(f"Cible : {target.base_url}")
        print(f"Mode : {args.mode}; profil : {args.profile}; paliers : {ramp}; durée : {duration_s} s par palier.")
        print(f"Description profil : {defaults['description']}")
        answer = input("Confirmez-vous être autorisé à exécuter ce test ? [oui/N] ").strip().lower()
        if answer not in {"oui", "o", "yes", "y"}:
            print("Test annulé.")
            return 2

    evidence_id = str(uuid.uuid4())
    started_at = utc_now()
    output_dir = args.output or Path(f"load-test-{datetime.now().strftime('%Y%m%d-%H%M%S')}-{evidence_id[:8]}").resolve()
    environment = collect_environment()
    target_evidence = collect_target_evidence(target, args.timeout)
    bearer_info = bearer_preflight(target, args.timeout, args.api_bearer_preflight_path)
    dataset_profile = collect_dataset_profile(find_db_root(args.db_root))

    requested_scenarios = routes_for_mode(args.mode)
    all_results: list[RequestResult] = []
    all_stage_meta: list[dict[str, Any]] = []
    summaries: list[dict[str, Any]] = []
    skipped: list[dict[str, Any]] = []

    try:
        for scenario_index, scenario in enumerate(requested_scenarios):
            routes = route_configs(scenario)
            if scenario == "api":
                bearer_routes = [route for route in routes if route.auth_policy == "bearer"]
                if bearer_routes and bearer_info.get("status") != "OK":
                    skipped.append({
                        "scenario": "api-bearer",
                        "reason": f"Routes Bearer non chargées : préflight {bearer_info.get('status')} HTTP {bearer_info.get('http_status')}",
                        "routes": [asdict(route) for route in bearer_routes],
                    })
                    routes = [route for route in routes if route.auth_policy != "bearer"]
                if not routes:
                    skipped.append({"scenario": "api", "reason": "Aucune route API mesurable."})
                    continue
            if scenario == "admin-read" and not credentials:
                skipped.append({"scenario": "admin-read", "reason": "Aucun users.csv ou identifiant admin disponible."})
                continue
            if scenario_index > 0 and args.pause_between_scenarios > 0:
                print(f"\nPause avant {scenario} : {args.pause_between_scenarios:.0f} s")
                time.sleep(args.pause_between_scenarios)
            print(f"\n=== Scénario {scenario} ===")
            for stage_no, users in enumerate(ramp, start=1):
                print(f"Palier {stage_no}/{len(ramp)} : {users} VU, {duration_s:.0f} s")
                stage_results, stage_meta = run_stage(
                    evidence_id=evidence_id,
                    target=target,
                    scenario=scenario,
                    stage_no=stage_no,
                    users=users,
                    duration_s=duration_s,
                    routes=routes,
                    timeout=args.timeout,
                    think_min_s=think_min,
                    think_max_s=think_max,
                    credentials=credentials,
                    allow_credential_reuse=args.allow_credential_reuse,
                    cache_bust=args.cache_bust,
                )
                summary = summarize_stage(stage_results, stage_meta, args)
                all_results.extend(stage_results)
                all_stage_meta.append(stage_meta)
                summaries.append(summary)
                if summary.get("status") == "INCONCLUSIVE":
                    print(f"  INCONCLUSIVE — {summary.get('status_reason') or 'initialisation incomplète'}")
                    break
                print(
                    f"  {summary['status']} — {summary['requests']} requêtes; "
                    f"brut {summary['throughput_total_rps']} req/s; utile {summary['goodput_success_rps']} req/s; "
                    f"p95 {summary['success_latency_p95_ms']} ms; succès {summary['success_rate_pct']} %; 429 {summary['http_429_rate_pct']} %"
                )
                if summary.get("http_429_rate_pct", 0.0) >= args.max_429_rate:
                    print("  ARRÊT DU SCÉNARIO : seuil 429 atteint.")
                    break
                if args.pause_between_stages > 0:
                    time.sleep(args.pause_between_stages)
    except KeyboardInterrupt:
        print("\nTest interrompu ; résultats partiels conservés.", file=sys.stderr)
        skipped.append({"scenario": "internal", "reason": "Interruption manuelle."})
    except Exception as exc:
        print(f"\nErreur inattendue : {type(exc).__name__}: {exc}", file=sys.stderr)
        skipped.append({"scenario": "internal", "reason": f"{type(exc).__name__}: {exc}"})

    ended_at = utc_now()
    route_summaries = summarize_routes(all_results)
    verdict, reasons = determine_verdict(summaries, requested_scenarios, skipped)
    metadata = {
        "evidence_id": evidence_id,
        "program_version": PROGRAM_VERSION,
        "started_at_utc": started_at,
        "ended_at_utc": ended_at,
        "target": target.name,
        "base_url": target.base_url,
        "headers_used": {key: value for key, value in target.headers.items() if key.lower() not in SENSITIVE_HEADERS},
        "mode": args.mode,
        "profile": args.profile,
        "profile_description": defaults["description"],
        "max_users": max_users,
        "ramp": ramp,
        "duration_s": duration_s,
        "timeout_s": args.timeout,
        "think_time_s": {"min": think_min, "max": think_max},
        "pause_between_stages_s": args.pause_between_stages,
        "pause_between_scenarios_s": args.pause_between_scenarios,
        "cache_bust": args.cache_bust,
        "credential_source": credential_source,
        "credential_count": len(credentials or []),
        "allow_credential_reuse": args.allow_credential_reuse,
        "token_info": token_info,
        "bearer_preflight": bearer_info,
        "command_line": [sys.executable, *sys.argv],
        "acceptance_criteria": {
            "max_error_rate_pct": args.max_error_rate,
            "max_429_rate_pct": args.max_429_rate,
            "max_p95_public_ms": args.max_p95_public,
            "max_p95_api_ms": args.max_p95_api,
            "max_p95_admin_ms": args.max_p95_admin,
            "fail_on_5xx": args.fail_on_5xx,
        },
        "raw_request_count": len(all_results),
    }
    scope_verdict = build_scope_verdict(summaries, requested_scenarios, skipped, dataset_profile)
    write_evidence_bundle(
        output_dir=output_dir,
        metadata=metadata,
        target_evidence=target_evidence,
        environment=environment,
        dataset_profile=dataset_profile,
        stages=all_stage_meta,
        results=all_results,
        summaries=summaries,
        route_summaries=route_summaries,
        verdict=verdict,
        verdict_reasons=reasons,
        scope_verdict=scope_verdict,
    )
    print(f"\nDossier de preuve : {output_dir}")
    print(f"Verdict : {verdict}")
    print("Vérification : sha256sum -c manifest.sha256")
    if verdict == "PASS":
        return 0
    if verdict in {"WARN", "INCONCLUSIVE"}:
        return 3
    if verdict == "FAIL":
        return 1
    return 4


if __name__ == "__main__":
    raise SystemExit(main())
