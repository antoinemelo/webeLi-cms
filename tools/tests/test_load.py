#!/usr/bin/env python3
"""
Test de montée en charge reproductible et documenté pour webeLi.

Objectifs :
- choisir interactivement le nombre maximal d'utilisateurs virtuels ;
- générer une montée progressive jusqu'à ce nombre ;
- attribuer une session HTTP indépendante à chaque utilisateur virtuel ;
- authentifier chaque utilisateur admin avec une session indépendante ;
- accepter plusieurs comptes de test depuis un fichier CSV ;
- distinguer PASS, FAIL, INCONCLUSIVE et SKIPPED ;
- conserver les mesures brutes et les paramètres exacts de l'exécution ;
- produire un dossier de preuve contrôlable par empreintes SHA-256.

Dépendance :
    python -m pip install requests

Exemple interactif :
    python load_test.py

Exemple automatisé :
    python load_test.py --users 20 --duration 30 --mode public --yes

IMPORTANT : n'exécutez ce programme que sur une infrastructure que vous êtes
explicitement autorisé à tester.
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
import re
import socket
import ssl
import statistics
import subprocess
import sys
import threading
import time
import uuid
from collections import Counter, defaultdict
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.parse import urljoin, urlparse

import requests


PROGRAM_VERSION = "2.2.0"
USER_AGENT = f"webeLi-load-evidence/{PROGRAM_VERSION} (+authorized-load-test)"
DEFAULT_BASE_URL = "https://webe.li/mod/"
DEFAULT_PUBLIC_PATHS = ["", "articles", "sitemap.xml", "robots.txt"]
DEFAULT_ADMIN_PATHS = ["admin/app"]
SENSITIVE_HEADERS = {"set-cookie", "cookie", "authorization", "proxy-authorization"}


@dataclass
class RequestResult:
    evidence_id: str
    area: str
    stage: int
    configured_users: int
    vu_id: int
    request_no: int
    request_id: str
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
    server_date: str | None
    server_header: str | None
    request_trace_header: str | None
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


def normalize_base_url(value: str) -> str:
    return value.rstrip("/") + "/"


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


def build_ramp(max_users: int, explicit: str | None) -> list[int]:
    if explicit:
        values: list[int] = []
        for token in explicit.split(","):
            try:
                number = int(token.strip())
            except ValueError as exc:
                raise argparse.ArgumentTypeError("Les paliers doivent être des entiers.") from exc
            if number < 1 or number > max_users:
                raise argparse.ArgumentTypeError(
                    f"Chaque palier doit être compris entre 1 et {max_users}."
                )
            values.append(number)
        return sorted(set(values))

    candidates = [
        1,
        max(1, math.ceil(max_users * 0.25)),
        max(1, math.ceil(max_users * 0.50)),
        max(1, math.ceil(max_users * 0.75)),
        max_users,
    ]
    return sorted(set(candidates))


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


def build_form_payload(
    form: dict[str, Any],
    email: str | None,
    password: str | None,
) -> dict[str, str]:
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
        elif password is not None and (
            field_type == "password" or "password" in lower_name or "passwd" in lower_name
        ):
            payload[name] = password
            password_assigned = True
        elif email is not None and (
            field_type == "email"
            or "email" in lower_name
            or "login" in lower_name
            or "user" in lower_name
        ):
            payload[name] = email
            email_assigned = True

    if email is not None and not email_assigned:
        payload["email"] = email
    if password is not None and not password_assigned:
        payload["password"] = password
    return payload


def parse_retry_after(value: str | None) -> float | None:
    if not value:
        return None
    try:
        return max(0.0, float(value.strip()))
    except ValueError:
        return None


def request_with_429_retry(
    session: requests.Session,
    method: str,
    url: str,
    *,
    timeout: float,
    attempts: int = 5,
    **kwargs: Any,
) -> requests.Response:
    """Retry prudent pour l'initialisation, en respectant Retry-After."""
    last_response: requests.Response | None = None
    for attempt in range(1, attempts + 1):
        response = session.request(
            method,
            url,
            timeout=timeout,
            allow_redirects=True,
            **kwargs,
        )
        last_response = response
        if response.status_code != 429:
            return response

        retry_after = parse_retry_after(response.headers.get("Retry-After"))
        delay = retry_after if retry_after is not None else min(60.0, 2.0 ** attempt)
        if attempt < attempts:
            time.sleep(delay)

    assert last_response is not None
    return last_response

def load_credentials_csv(path: Path) -> list[tuple[str, str]]:
    """Charge un CSV email,password sans conserver les secrets dans les rapports."""
    if not path.is_file():
        raise ValueError(f"Fichier d'identifiants introuvable : {path}")

    credentials: list[tuple[str, str]] = []
    with path.open("r", newline="", encoding="utf-8-sig") as handle:
        reader = csv.DictReader(handle)
        fieldnames = {name.strip().lower() for name in (reader.fieldnames or [])}
        if not {"email", "password"}.issubset(fieldnames):
            raise ValueError("Le CSV doit contenir les colonnes email et password.")

        for line_no, row in enumerate(reader, start=2):
            normalized = {
                (key or "").strip().lower(): (value or "").strip()
                for key, value in row.items()
            }
            email = normalized.get("email", "")
            password = normalized.get("password", "")
            if not email or not password:
                raise ValueError(
                    f"Identifiant incomplet à la ligne {line_no} du fichier CSV."
                )
            credentials.append((email, password))

    if not credentials:
        raise ValueError("Le fichier d'identifiants ne contient aucun compte.")
    return credentials

def authenticate_admin(
    session: requests.Session,
    base_url: str,
    email: str,
    password: str,
    timeout: float,
) -> tuple[bool, str]:
    login_url = urljoin(base_url, "admin/login")
    try:
        first = request_with_429_retry(session, "GET", login_url, timeout=timeout)
        first.raise_for_status()
    except requests.RequestException as exc:
        return False, f"Ouverture de la connexion impossible : {exc}"

    form = parse_form(first.text)
    if form is None:
        return False, "Aucun formulaire de connexion détecté."

    action = urljoin(first.url, form.get("action") or first.url)
    has_password = any(
        field.get("type", "").lower() == "password"
        or "password" in field.get("name", "").lower()
        for field in form.get("inputs", [])
    )

    try:
        response = request_with_429_retry(
            session,
            form.get("method", "post").upper(),
            action,
            data=build_form_payload(form, email, password if has_password else None),
            timeout=timeout,
        )
    except requests.RequestException as exc:
        return False, f"Première étape de connexion impossible : {exc}"

    if not has_password:
        second_form = parse_form(response.text)
        if second_form is not None:
            second_has_password = any(
                field.get("type", "").lower() == "password"
                or "password" in field.get("name", "").lower()
                for field in second_form.get("inputs", [])
            )
            if second_has_password:
                second_action = urljoin(
                    response.url, second_form.get("action") or response.url
                )
                try:
                    response = session.request(
                        second_form.get("method", "post").upper(),
                        second_action,
                        data=build_form_payload(second_form, email, password),
                        timeout=timeout,
                        allow_redirects=True,
                    )
                except requests.RequestException as exc:
                    return False, f"Deuxième étape de connexion impossible : {exc}"

    check_url = urljoin(base_url, "admin/app")
    try:
        check = session.get(check_url, timeout=timeout, allow_redirects=True)
    except requests.RequestException as exc:
        return False, f"Vérification de la session impossible : {exc}"

    final_path = urlparse(check.url).path.lower()
    body = check.text.lower()
    still_login = "/login" in final_path or (
        "connexion" in body and 'type="password"' in body
    )
    if check.status_code in {401, 403} or still_login:
        return False, "Identifiants refusés ou session non reconnue."
    return True, check.url


def make_session() -> requests.Session:
    session = requests.Session()
    session.headers.update(
        {
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Cache-Control": "no-cache",
        }
    )
    return session


def request_once(
    evidence_id: str,
    area: str,
    stage: int,
    configured_users: int,
    vu_id: int,
    request_no: int,
    session: requests.Session,
    url: str,
    timeout: float,
) -> RequestResult:
    request_id = str(uuid.uuid4())
    started_utc = utc_now()
    started_mono = time.perf_counter()

    try:
        response = session.get(url, timeout=timeout, allow_redirects=True)
        elapsed_ms = (time.perf_counter() - started_mono) * 1000
        ended_utc = utc_now()
        body = response.content
        trace_header = (
            response.headers.get("X-Request-ID")
            or response.headers.get("X-Correlation-ID")
            or response.headers.get("Traceparent")
            or response.headers.get("CF-Ray")
        )
        status = response.status_code
        return RequestResult(
            evidence_id=evidence_id,
            area=area,
            stage=stage,
            configured_users=configured_users,
            vu_id=vu_id,
            request_no=request_no,
            request_id=request_id,
            method="GET",
            requested_url=url,
            final_url=response.url,
            started_at_utc=started_utc,
            ended_at_utc=ended_utc,
            elapsed_ms=round(elapsed_ms, 3),
            status=status,
            ok=200 <= status < 400,
            bytes_received=len(body),
            response_sha256=sha256_bytes(body),
            server_date=response.headers.get("Date"),
            server_header=response.headers.get("Server"),
            request_trace_header=trace_header,
            error=None if 200 <= status < 400 else f"HTTP {status}",
        )
    except requests.RequestException as exc:
        elapsed_ms = (time.perf_counter() - started_mono) * 1000
        return RequestResult(
            evidence_id=evidence_id,
            area=area,
            stage=stage,
            configured_users=configured_users,
            vu_id=vu_id,
            request_no=request_no,
            request_id=request_id,
            method="GET",
            requested_url=url,
            final_url=None,
            started_at_utc=started_utc,
            ended_at_utc=utc_now(),
            elapsed_ms=round(elapsed_ms, 3),
            status=None,
            ok=False,
            bytes_received=0,
            response_sha256=None,
            server_date=None,
            server_header=None,
            request_trace_header=None,
            error=f"{type(exc).__name__}: {exc}",
        )


def run_stage(
    evidence_id: str,
    area: str,
    stage_no: int,
    users: int,
    duration_s: float,
    urls: list[str],
    timeout: float,
    think_min_s: float,
    think_max_s: float,
    admin_credentials: list[tuple[str, str]] | None,
    allow_credential_reuse: bool,
    base_url: str,
) -> tuple[list[RequestResult], dict[str, Any]]:
    barrier = threading.Barrier(users + 1)
    start_event = threading.Event()
    stop_at = [0.0]
    results: list[RequestResult] = []
    result_lock = threading.Lock()
    auth_failures: list[str] = []
    initialized_vus: list[int] = []

    if area == "admin-read":
        if not admin_credentials:
            return [], {
                "stage": stage_no,
                "area": area,
                "users": users,
                "configured_duration_s": duration_s,
                "actual_duration_s": 0.0,
                "initialization_duration_s": 0.0,
                "stage_started_at_utc": utc_now(),
                "load_started_at_utc": None,
                "stage_ended_at_utc": utc_now(),
                "authentication_failures": ["Aucun identifiant administrateur disponible."],
                "initialized_virtual_users": 0,
                "execution_status": "INCONCLUSIVE",
                "status_reason": "Aucun identifiant administrateur disponible.",
            }
        if not allow_credential_reuse and len(admin_credentials) < users:
            return [], {
                "stage": stage_no,
                "area": area,
                "users": users,
                "configured_duration_s": duration_s,
                "actual_duration_s": 0.0,
                "initialization_duration_s": 0.0,
                "stage_started_at_utc": utc_now(),
                "load_started_at_utc": None,
                "stage_ended_at_utc": utc_now(),
                "authentication_failures": [
                    f"{users} comptes requis, {len(admin_credentials)} disponibles."
                ],
                "initialized_virtual_users": 0,
                "execution_status": "INCONCLUSIVE",
                "status_reason": (
                    f"Nombre insuffisant de comptes distincts : "
                    f"{len(admin_credentials)} disponible(s) pour {users} VU."
                ),
            }

    def credential_for(vu_id: int) -> tuple[str, str] | None:
        if area != "admin-read" or not admin_credentials:
            return None
        if allow_credential_reuse:
            return admin_credentials[(vu_id - 1) % len(admin_credentials)]
        return admin_credentials[vu_id - 1]

    def virtual_user(vu_id: int) -> None:
        session = make_session()
        initialized = True

        if area == "admin-read":
            credential = credential_for(vu_id)
            assert credential is not None
            email, password = credential
            ok, detail = authenticate_admin(session, base_url, email, password, timeout)
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
            url = urls[(vu_id + request_no - 2) % len(urls)]
            item = request_once(
                evidence_id=evidence_id,
                area=area,
                stage=stage_no,
                configured_users=users,
                vu_id=vu_id,
                request_no=request_no,
                session=session,
                url=url,
                timeout=timeout,
            )
            with result_lock:
                results.append(item)

            if think_max_s > 0:
                pause = random.uniform(think_min_s, think_max_s)
                remaining = stop_at[0] - time.perf_counter()
                if remaining > 0:
                    time.sleep(min(pause, remaining))

        session.close()

    threads = [
        threading.Thread(target=virtual_user, args=(vu_id,), daemon=True)
        for vu_id in range(1, users + 1)
    ]
    stage_started_utc = utc_now()
    stage_started_mono = time.perf_counter()

    for thread in threads:
        thread.start()

    try:
        barrier.wait(timeout=max(60.0, timeout * max(1, users)))
    except threading.BrokenBarrierError:
        auth_failures.append("Synchronisation des utilisateurs impossible.")

    initialization_duration = time.perf_counter() - stage_started_mono

    # Un palier admin n'est mesuré que si toutes les sessions demandées sont prêtes.
    if area == "admin-read" and len(initialized_vus) != users:
        stop_at[0] = time.perf_counter()
        start_event.set()
        for thread in threads:
            thread.join(timeout=2)
        reason = (
            f"{len(initialized_vus)} session(s) initialisée(s) sur {users}; "
            "palier non exécuté."
        )
        return [], {
            "stage": stage_no,
            "area": area,
            "users": users,
            "configured_duration_s": duration_s,
            "actual_duration_s": 0.0,
            "initialization_duration_s": round(initialization_duration, 6),
            "stage_started_at_utc": stage_started_utc,
            "load_started_at_utc": None,
            "stage_ended_at_utc": utc_now(),
            "authentication_failures": auth_failures,
            "initialized_virtual_users": len(initialized_vus),
            "execution_status": "INCONCLUSIVE",
            "status_reason": reason,
        }

    actual_load_start_utc = utc_now()
    actual_load_start_mono = time.perf_counter()
    stop_at[0] = actual_load_start_mono + duration_s
    start_event.set()

    for thread in threads:
        thread.join(timeout=duration_s + timeout + think_max_s + 5)

    actual_load_end_mono = time.perf_counter()
    stage_ended_utc = utc_now()
    actual_duration = max(0.001, actual_load_end_mono - actual_load_start_mono)

    stage_meta = {
        "stage": stage_no,
        "area": area,
        "users": users,
        "configured_duration_s": duration_s,
        "actual_duration_s": round(actual_duration, 6),
        "initialization_duration_s": round(initialization_duration, 6),
        "stage_started_at_utc": stage_started_utc,
        "load_started_at_utc": actual_load_start_utc,
        "stage_ended_at_utc": stage_ended_utc,
        "authentication_failures": auth_failures,
        "initialized_virtual_users": users if area == "admin-read" else users,
        "execution_status": "COMPLETED",
        "status_reason": None,
    }
    return results, stage_meta

def summarize_stage(
    items: list[RequestResult],
    stage_meta: dict[str, Any],
    max_error_rate_pct: float,
    max_p95_ms: float,
) -> dict[str, Any]:
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
            "http_429_rate_pct": 0.0,
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
            "top_errors": {},
        }

    all_latencies = [item.elapsed_ms for item in items]
    successes = [item for item in items if item.ok]
    failures = [item for item in items if not item.ok]
    success_latencies = [item.elapsed_ms for item in successes]
    failure_latencies = [item.elapsed_ms for item in failures]
    duration_s = max(0.001, float(stage_meta["actual_duration_s"]))
    status_counts = Counter(
        str(item.status) if item.status is not None else "network"
        for item in items
    )
    error_counts = Counter(item.error or "" for item in failures)
    unique_vus = len({item.vu_id for item in items})
    count = max(1, len(items))
    success_rate = 100 * len(successes) / count
    error_rate = 100 * len(failures) / count
    rate_429 = 100 * status_counts.get("429", 0) / count
    p95 = percentile(success_latencies, 0.95)
    status = "PASS"
    if error_rate > max_error_rate_pct or p95 > max_p95_ms:
        status = "FAIL"

    return {
        **stage_meta,
        "status": status,
        "requests": len(items),
        "active_virtual_users": unique_vus,
        "successes": len(successes),
        "errors": len(failures),
        "success_rate_pct": round(success_rate, 4),
        "error_rate_pct": round(error_rate, 4),
        "http_429_rate_pct": round(rate_429, 4),
        "throughput_total_rps": round(len(items) / duration_s, 4),
        "goodput_success_rps": round(len(successes) / duration_s, 4),
        "success_latency_avg_ms": round(statistics.fmean(success_latencies), 3)
        if success_latencies else 0.0,
        "success_latency_p50_ms": round(percentile(success_latencies, 0.50), 3),
        "success_latency_p90_ms": round(percentile(success_latencies, 0.90), 3),
        "success_latency_p95_ms": round(p95, 3),
        "success_latency_p99_ms": round(percentile(success_latencies, 0.99), 3),
        "success_latency_max_ms": round(max(success_latencies), 3)
        if success_latencies else 0.0,
        "all_latency_avg_ms": round(statistics.fmean(all_latencies), 3)
        if all_latencies else 0.0,
        "all_latency_p95_ms": round(percentile(all_latencies, 0.95), 3),
        "failure_latency_avg_ms": round(statistics.fmean(failure_latencies), 3)
        if failure_latencies else 0.0,
        "status_counts": dict(status_counts),
        "top_errors": dict(error_counts.most_common(10)),
    }

def collect_target_evidence(base_url: str, timeout: float) -> dict[str, Any]:
    parsed = urlparse(base_url)
    host = parsed.hostname or ""
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    evidence: dict[str, Any] = {
        "base_url": base_url,
        "scheme": parsed.scheme,
        "hostname": host,
        "port": port,
        "resolved_addresses": [],
        "tls": None,
        "probe": None,
    }

    try:
        addresses = sorted({item[4][0] for item in socket.getaddrinfo(host, port)})
        evidence["resolved_addresses"] = addresses
    except OSError as exc:
        evidence["dns_error"] = str(exc)

    if parsed.scheme == "https":
        try:
            context = ssl.create_default_context()
            with socket.create_connection((host, port), timeout=timeout) as raw:
                with context.wrap_socket(raw, server_hostname=host) as secure:
                    certificate = secure.getpeercert(binary_form=True)
                    cert_info = secure.getpeercert()
                    evidence["tls"] = {
                        "protocol": secure.version(),
                        "cipher": secure.cipher(),
                        "certificate_sha256": sha256_bytes(certificate),
                        "subject": cert_info.get("subject"),
                        "issuer": cert_info.get("issuer"),
                        "not_before": cert_info.get("notBefore"),
                        "not_after": cert_info.get("notAfter"),
                    }
        except (OSError, ssl.SSLError) as exc:
            evidence["tls_error"] = str(exc)

    try:
        session = make_session()
        response = session.get(base_url, timeout=timeout, allow_redirects=True)
        evidence["probe"] = {
            "checked_at_utc": utc_now(),
            "status": response.status_code,
            "final_url": response.url,
            "bytes": len(response.content),
            "body_sha256": sha256_bytes(response.content),
            "headers": {
                key: value
                for key, value in response.headers.items()
                if key.lower() not in SENSITIVE_HEADERS
            },
        }
        session.close()
    except requests.RequestException as exc:
        evidence["probe_error"] = str(exc)

    return evidence


def collect_environment() -> dict[str, Any]:
    script_path = Path(__file__).resolve()
    try:
        requests_version = requests.__version__
    except AttributeError:
        requests_version = "unknown"

    return {
        "program": "webeLi load evidence",
        "program_version": PROGRAM_VERSION,
        "script_path": str(script_path),
        "script_sha256": sha256_file(script_path),
        "python_version": sys.version,
        "python_executable": sys.executable,
        "requests_version": requests_version,
        "platform": platform.platform(),
        "machine": platform.machine(),
        "processor": platform.processor(),
        "hostname": socket.gethostname(),
        "cpu_count": os.cpu_count(),
        "timezone": str(datetime.now().astimezone().tzinfo),
    }


def determine_verdict(
    summaries: list[dict[str, Any]],
    requested_areas: list[str],
) -> tuple[str, list[str]]:
    reasons: list[str] = []
    statuses = [row.get("status", "INCONCLUSIVE") for row in summaries]

    completed_areas = {row["area"] for row in summaries}
    for area in requested_areas:
        if area not in completed_areas:
            reasons.append(f"{area} : scénario SKIPPED, aucun palier enregistré.")

    for row in summaries:
        if row.get("status") == "FAIL":
            reasons.append(
                f"{row['area']} palier {row['users']} : critères d'acceptation dépassés."
            )
        elif row.get("status") == "INCONCLUSIVE":
            reasons.append(
                f"{row['area']} palier {row['users']} : "
                f"{row.get('status_reason') or 'initialisation incomplète'}"
            )

    if "FAIL" in statuses:
        return "FAIL", reasons
    if "INCONCLUSIVE" in statuses or any("SKIPPED" in reason for reason in reasons):
        return "INCONCLUSIVE", reasons
    if summaries:
        return "PASS", reasons
    return "SKIPPED", ["Aucun scénario n'a été exécuté."]

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
    stage_metadata: list[dict[str, Any]],
    results: list[RequestResult],
    summaries: list[dict[str, Any]],
    verdict: str,
    verdict_reasons: list[str],
) -> None:
    output_dir.mkdir(parents=True, exist_ok=False)

    script_copy = output_dir / "executed_script.py"
    script_copy.write_bytes(Path(__file__).resolve().read_bytes())

    files_json = {
        "metadata.json": metadata,
        "target_evidence.json": target_evidence,
        "environment.json": environment,
        "stages.json": stage_metadata,
        "summary.json": summaries,
        "verdict.json": {
            "verdict": verdict,
            "reasons": verdict_reasons,
            "criteria": metadata["acceptance_criteria"],
        },
        "results.json": {
            "evidence_id": metadata["evidence_id"],
            "results": [asdict(item) for item in results],
        },
    }
    for filename, payload in files_json.items():
        (output_dir / filename).write_text(
            json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )

    write_csv(output_dir / "results.csv", results)

    lines = [
        "# Preuve de mise en charge webeLi",
        "",
        f"- Identifiant de preuve : `{metadata['evidence_id']}`",
        f"- Début UTC : {metadata['started_at_utc']}",
        f"- Fin UTC : {metadata['ended_at_utc']}",
        f"- Cible : `{metadata['base_url']}`",
        f"- Mode : `{metadata['mode']}`",
        f"- Utilisateurs virtuels maximum : **{metadata['max_users']}**",
        f"- Paliers : `{', '.join(map(str, metadata['ramp']))}`",
        f"- Durée configurée par palier : {metadata['duration_s']} s",
        f"- Verdict selon critères déclarés : **{verdict}**",
        "",
        "## Résultats",
        "",
        "| Zone | Utilisateurs | Statut | Requêtes | Succès | 429 | req/s brut | req/s utile | p50 succès | p95 succès | p99 succès |",
        "|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for row in summaries:
        lines.append(
            f"| {row['area']} | {row['users']} | {row['status']} | "
            f"{row['requests']} | {row['success_rate_pct']} % | {row['http_429_rate_pct']} % | "
            f"{row['throughput_total_rps']} | {row['goodput_success_rps']} | "
            f"{row['success_latency_p50_ms']} ms | "
            f"{row['success_latency_p95_ms']} ms | "
            f"{row['success_latency_p99_ms']} ms |"
        )

    lines.extend(["", "## Critères d'acceptation déclarés", ""])
    lines.append(
        f"- Taux d'erreur maximal : {metadata['acceptance_criteria']['max_error_rate_pct']} %."
    )
    lines.append(
        f"- Latence p95 maximale : {metadata['acceptance_criteria']['max_p95_ms']} ms."
    )
    if verdict_reasons:
        lines.extend(["", "## Écarts constatés", ""])
        lines.extend(f"- {reason}" for reason in verdict_reasons)

    lines.extend(
        [
            "",
            "## Portée probatoire",
            "",
            "Le dossier conserve les événements bruts, les horodatages UTC, la configuration, "
            "l'environnement d'exécution, l'empreinte du script, l'identité TLS observée et les "
            "empreintes SHA-256 des fichiers produits. Il permet à un tiers de contrôler les "
            "calculs et de détecter une modification ultérieure des fichiers.",
            "",
            "Il ne constitue pas une certification par un organisme tiers et ne prouve pas à lui "
            "seul la cause interne d'un ralentissement. Les identifiants administrateur et les "
            "cookies ne sont jamais enregistrés.",
            "",
            "## Vérification",
            "",
            "Depuis le dossier de preuve :",
            "",
            "```bash",
            "sha256sum -c manifest.sha256",
            "```",
        ]
    )
    (output_dir / "evidence.md").write_text("\n".join(lines) + "\n", encoding="utf-8")

    manifest_targets = sorted(
        path for path in output_dir.iterdir() if path.is_file() and path.name != "manifest.sha256"
    )
    manifest_lines = [f"{sha256_file(path)}  {path.name}" for path in manifest_targets]
    (output_dir / "manifest.sha256").write_text(
        "\n".join(manifest_lines) + "\n", encoding="utf-8"
    )


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


def ask_int(label: str, default: int, minimum: int = 1) -> int:
    while True:
        entered = input(f"{label} [{default}] : ").strip()
        if not entered:
            return default
        try:
            number = int(entered)
        except ValueError:
            print("Veuillez saisir un nombre entier.")
            continue
        if number < minimum:
            print(f"La valeur minimale est {minimum}.")
            continue
        return number


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Test de montée en charge avec dossier de preuve SHA-256."
    )
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL)
    parser.add_argument("--users", type=positive_int, help="Nombre maximal d'utilisateurs virtuels.")
    parser.add_argument("--ramp", help="Paliers explicites, par exemple 1,5,10,20.")
    parser.add_argument("--duration", type=non_negative_float, help="Durée de charge par palier en secondes.")
    parser.add_argument("--mode", choices=["public", "admin", "both"], help="Scénario à exécuter.")
    parser.add_argument("--admin", action="store_true", help="Alias pour inclure le scénario admin.")
    parser.add_argument("--public-path", action="append", default=[])
    parser.add_argument("--admin-path", action="append", default=[])
    parser.add_argument(
        "--credentials-file",
        type=Path,
        help="CSV contenant email,password ; un compte distinct est utilisé par VU.",
    )
    parser.add_argument("--timeout", type=non_negative_float, default=15.0)
    parser.add_argument("--think-min", type=non_negative_float, default=0.05)
    parser.add_argument("--think-max", type=non_negative_float, default=0.20)
    parser.add_argument("--pause-between-stages", type=non_negative_float, default=2.0)
    parser.add_argument(
        "--pause-between-scenarios",
        type=non_negative_float,
        default=60.0,
        help="Pause entre public et admin, pour laisser expirer une limitation de débit.",
    )
    parser.add_argument(
        "--stop-on-429-rate",
        type=non_negative_float,
        default=5.0,
        help="Arrêter la montée d'un scénario si ce pourcentage de réponses 429 est atteint.",
    )
    parser.add_argument("--max-error-rate", type=non_negative_float, default=1.0)
    parser.add_argument("--max-p95", type=non_negative_float, default=1000.0)
    parser.add_argument("--output", help="Nom du dossier de preuve.")
    parser.add_argument("--yes", action="store_true", help="Confirme l'autorisation sans invite.")
    args = parser.parse_args()

    base_url = normalize_base_url(args.base_url)
    interactive = sys.stdin.isatty()

    max_users = args.users
    if max_users is None:
        if not interactive:
            parser.error("--users est obligatoire en mode non interactif.")
        max_users = ask_int("Nombre maximal d'utilisateurs virtuels", 20)

    duration_s = args.duration
    if duration_s is None:
        if not interactive:
            parser.error("--duration est obligatoire en mode non interactif.")
        duration_s = float(ask_int("Durée de chaque palier en secondes", 30))
    if duration_s <= 0:
        parser.error("La durée doit être supérieure à zéro.")

    mode = args.mode
    if mode is None:
        if args.admin:
            mode = "both"
        elif interactive:
            entered = input("Mode public, admin ou both [both] : ").strip().lower()
            mode = entered or "both"
            if mode not in {"public", "admin", "both"}:
                parser.error("Mode invalide.")
        else:
            mode = "public"

    if args.think_max < args.think_min:
        parser.error("--think-max doit être supérieur ou égal à --think-min.")

    ramp = build_ramp(max_users, args.ramp)
    public_urls = [urljoin(base_url, path) for path in DEFAULT_PUBLIC_PATHS + args.public_path]
    admin_urls = [urljoin(base_url, path) for path in DEFAULT_ADMIN_PATHS + args.admin_path]

    if not args.yes:
        print("\nCe test génère une charge réelle sur le serveur.")
        print(f"Cible : {base_url}")
        print(f"Mode : {mode}; paliers : {ramp}; durée : {duration_s} s par palier.")
        answer = input("Confirmez-vous être autorisé à exécuter ce test ? [oui/N] ").strip().lower()
        if answer not in {"oui", "o", "yes", "y"}:
            print("Test annulé.")
            return 2

    admin_credentials: list[tuple[str, str]] | None = None
    allow_credential_reuse = True
    credential_source = "none"
    if mode in {"admin", "both"}:
        if args.credentials_file is not None:
            try:
                admin_credentials = load_credentials_csv(args.credentials_file)
            except ValueError as exc:
                parser.error(str(exc))
            allow_credential_reuse = False
            credential_source = "csv-distinct-accounts"
            print(
                f"{len(admin_credentials)} compte(s) administrateur(s) "
                "chargé(s) depuis le fichier CSV."
            )
        else:
            email = input("Compte admin de test : ").strip()
            password = getpass.getpass("Mot de passe admin : ")
            admin_credentials = [(email, password)]
            allow_credential_reuse = True
            credential_source = "interactive-single-account-reused"

    evidence_id = str(uuid.uuid4())
    started_at = utc_now()
    output_name = args.output or f"webeLi-evidence-{datetime.now().strftime('%Y%m%d-%H%M%S')}-{evidence_id[:8]}"
    output_dir = Path(output_name).resolve()

    environment = collect_environment()
    target_evidence = collect_target_evidence(base_url, args.timeout)
    all_results: list[RequestResult] = []
    all_stage_meta: list[dict[str, Any]] = []
    summaries: list[dict[str, Any]] = []

    areas: list[tuple[str, list[str]]] = []
    if mode in {"public", "both"}:
        areas.append(("public", public_urls))
    if mode in {"admin", "both"}:
        areas.append(("admin-read", admin_urls))

    try:
        for area_index, (area, urls) in enumerate(areas):
            if area_index > 0 and args.pause_between_scenarios > 0:
                print(
                    f"\nPause de récupération avant le scénario {area} : "
                    f"{args.pause_between_scenarios:.0f} s"
                )
                time.sleep(args.pause_between_scenarios)

            print(f"\n=== Scénario {area} ===")
            for stage_no, users in enumerate(ramp, start=1):
                print(f"Palier {stage_no}/{len(ramp)} : {users} utilisateur(s), {duration_s:.0f} s")
                stage_results, stage_meta = run_stage(
                    evidence_id=evidence_id,
                    area=area,
                    stage_no=stage_no,
                    users=users,
                    duration_s=duration_s,
                    urls=urls,
                    timeout=args.timeout,
                    think_min_s=args.think_min,
                    think_max_s=args.think_max,
                    admin_credentials=admin_credentials,
                    allow_credential_reuse=allow_credential_reuse,
                    base_url=base_url,
                )
                summary = summarize_stage(
                    stage_results,
                    stage_meta,
                    args.max_error_rate,
                    args.max_p95,
                )
                all_results.extend(stage_results)
                all_stage_meta.append(stage_meta)
                summaries.append(summary)
                if summary["status"] == "INCONCLUSIVE":
                    print(
                        f"  INCONCLUSIVE — {summary.get('status_reason') or 'initialisation incomplète'}"
                    )
                else:
                    print(
                        f"  {summary['status']} — {summary['requests']} requêtes; "
                        f"brut {summary['throughput_total_rps']} req/s; "
                        f"utile {summary['goodput_success_rps']} req/s; "
                        f"p95 succès {summary['success_latency_p95_ms']} ms; "
                        f"succès {summary['success_rate_pct']} %; "
                        f"429 {summary['http_429_rate_pct']} %"
                    )
                if summary["status"] == "INCONCLUSIVE":
                    print("  ARRÊT DE CE SCÉNARIO : palier non exécutable.")
                    break

                if summary["http_429_rate_pct"] >= args.stop_on_429_rate:
                    print(
                        "  ARRÊT DU SCÉNARIO : seuil de réponses 429 atteint "
                        f"({summary['http_429_rate_pct']} % >= "
                        f"{args.stop_on_429_rate} %)."
                    )
                    break

                if args.pause_between_stages > 0:
                    time.sleep(args.pause_between_stages)
    except KeyboardInterrupt:
        print("\nTest interrompu par l'utilisateur ; résultats partiels conservés.", file=sys.stderr)
        summaries.append({
            "area": "internal",
            "stage": 0,
            "users": 0,
            "status": "INCONCLUSIVE",
            "status_reason": "Interruption manuelle.",
            "requests": 0,
            "success_rate_pct": 0.0,
            "http_429_rate_pct": 0.0,
            "throughput_total_rps": 0.0,
            "goodput_success_rps": 0.0,
            "success_latency_p50_ms": 0.0,
            "success_latency_p95_ms": 0.0,
            "success_latency_p99_ms": 0.0,
        })
    except Exception as exc:
        print(f"\nErreur inattendue : {type(exc).__name__}: {exc}", file=sys.stderr)
        all_stage_meta.append({
            "stage": None,
            "area": "internal",
            "users": 0,
            "execution_status": "INCONCLUSIVE",
            "status_reason": f"{type(exc).__name__}: {exc}",
            "stage_started_at_utc": utc_now(),
            "stage_ended_at_utc": utc_now(),
        })

    ended_at = utc_now()
    verdict, reasons = determine_verdict(
        summaries,
        [area for area, _urls in areas],
    )
    metadata = {
        "evidence_id": evidence_id,
        "started_at_utc": started_at,
        "ended_at_utc": ended_at,
        "base_url": base_url,
        "mode": mode,
        "max_users": max_users,
        "ramp": ramp,
        "duration_s": duration_s,
        "timeout_s": args.timeout,
        "think_time_s": {"min": args.think_min, "max": args.think_max},
        "pause_between_stages_s": args.pause_between_stages,
        "pause_between_scenarios_s": args.pause_between_scenarios,
        "stop_on_429_rate_pct": args.stop_on_429_rate,
        "public_urls": public_urls,
        "admin_urls": admin_urls if mode in {"admin", "both"} else [],
        "admin_sessions": "Une session HTTP indépendante par utilisateur virtuel; identifiants non enregistrés.",
        "admin_credential_source": credential_source,
        "admin_credential_count": len(admin_credentials or []),
        "command_line": [sys.executable, *sys.argv],
        "acceptance_criteria": {
            "max_error_rate_pct": args.max_error_rate,
            "max_p95_ms": args.max_p95,
        },
        "raw_request_count": len(all_results),
    }

    write_evidence_bundle(
        output_dir=output_dir,
        metadata=metadata,
        target_evidence=target_evidence,
        environment=environment,
        stage_metadata=all_stage_meta,
        results=all_results,
        summaries=summaries,
        verdict=verdict,
        verdict_reasons=reasons,
    )

    print(f"\nDossier de preuve : {output_dir}")
    print(f"Verdict : {verdict}")
    print("Vérification : sha256sum -c manifest.sha256")
    if verdict == "PASS":
        return 0
    if verdict == "FAIL":
        return 1
    if verdict == "INCONCLUSIVE":
        return 3
    return 4


if __name__ == "__main__":
    raise SystemExit(main())
