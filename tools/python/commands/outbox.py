from __future__ import annotations

import json
import sqlite3

from tools.python.cms.runtime import execute, resolve_php_binary


def configure(parser) -> None:
    sub = parser.add_subparsers(dest="outbox_action", required=True)
    sub.add_parser("stats", help="Afficher la santé et la profondeur de l’outbox.")
    run = sub.add_parser("run", help="Traiter un lot via le worker PHP outbox.")
    run.add_argument("--limit", type=int, default=25)
    show = sub.add_parser("show", help="Afficher un événement outbox.")
    show.add_argument("id", type=int)
    retry = sub.add_parser("retry", help="Relancer un événement failed/dead-letter/processed.")
    retry.add_argument("id", type=int)
    retry.add_argument("--reset-attempts", action="store_true")
    dead = sub.add_parser("dead-letter", help="Déplacer manuellement un événement en dead-letter.")
    dead.add_argument("id", type=int)
    dead.add_argument("--reason", default="manual dead-letter")
    restore = sub.add_parser("restore", help="Restaurer une dead-letter en pending.")
    restore.add_argument("id", type=int)
    archive = sub.add_parser("archive", help="Archiver les événements processed anciens.")
    archive.add_argument("--older-than-days", type=int, default=30)


def run(ctx, args) -> int:
    db_path = ctx.database_dir / "core.sqlite"
    if not db_path.is_file():
        raise FileNotFoundError(f"Base core introuvable: {db_path}")

    with sqlite3.connect(db_path) as connection:
        connection.row_factory = sqlite3.Row
        _ensure_outbox_schema(connection)

    if args.outbox_action == "run":
        ctx.require_native_database_dir("outbox run")
        php = resolve_php_binary()
        return execute(ctx, [php, str(ctx.root / "backend/bin/console"), "worker:outbox", str(max(1, args.limit))])

    with sqlite3.connect(db_path) as connection:
        connection.row_factory = sqlite3.Row
        if args.outbox_action == "stats":
            payload = _stats(connection)
        elif args.outbox_action == "show":
            payload = _require_event(connection, args.id)
        elif args.outbox_action == "retry":
            fields = "status='pending', available_at=CURRENT_TIMESTAMP, locked_until=NULL, lock_token=NULL, last_error=NULL, error_type=NULL, dead_lettered_at=NULL, updated_at=CURRENT_TIMESTAMP"
            if args.reset_attempts:
                fields += ", attempts=0"
            connection.execute(f"UPDATE outbox_events SET {fields} WHERE id=? AND status IN ('failed','dead_letter','processing','processed')", (args.id,))
            payload = _require_event(connection, args.id)
        elif args.outbox_action == "dead-letter":
            connection.execute(
                "UPDATE outbox_events SET status='dead_letter', last_error=?, error_type='manual', locked_until=NULL, lock_token=NULL, dead_lettered_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?",
                (str(args.reason)[:500], args.id),
            )
            payload = _require_event(connection, args.id)
        elif args.outbox_action == "restore":
            connection.execute(
                "UPDATE outbox_events SET status='pending', available_at=CURRENT_TIMESTAMP, locked_until=NULL, lock_token=NULL, last_error=NULL, error_type=NULL, dead_lettered_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='dead_letter'",
                (args.id,),
            )
            payload = _require_event(connection, args.id)
        elif args.outbox_action == "archive":
            older = max(1, int(args.older_than_days))
            cursor = connection.execute(
                "UPDATE outbox_events SET status='archived', archived_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE status='processed' AND processed_at IS NOT NULL AND processed_at < datetime('now', ?)",
                (f"-{older} days",),
            )
            payload = {"archived": cursor.rowcount, "older_than_days": older}
        else:
            raise ValueError(f"Action outbox inconnue: {args.outbox_action}")
        connection.commit()

    if ctx.json_output:
        print(json.dumps(payload, ensure_ascii=False, sort_keys=True))
    else:
        print(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


def _require_event(connection: sqlite3.Connection, event_id: int) -> dict[str, object]:
    row = connection.execute("SELECT * FROM outbox_events WHERE id=? LIMIT 1", (event_id,)).fetchone()
    if row is None:
        raise ValueError(f"Événement outbox introuvable: {event_id}")
    return dict(row)


def _ensure_outbox_schema(connection: sqlite3.Connection) -> None:
    columns = {row[1] for row in connection.execute("PRAGMA table_info(outbox_events)").fetchall()}
    required = {"event_id", "event_type", "correlation_id", "locked_until", "dead_lettered_at"}
    missing = sorted(required - columns)
    if missing:
        raise ValueError(
            "Schéma outbox M0 absent dans core.sqlite. "
            "Reconstruire l’instance from scratch avec `python3 tools/cms.py rebuild` avant d’utiliser `tools/cms.py outbox`. "
            f"Colonnes manquantes: {', '.join(missing)}"
        )


def _stats(connection: sqlite3.Connection) -> dict[str, object]:
    counts = {}
    for status in ("pending", "processing", "failed", "dead_letter", "processed", "archived"):
        counts[status] = int(connection.execute("SELECT COUNT(*) FROM outbox_events WHERE status=?", (status,)).fetchone()[0])
    oldest = connection.execute(
        "SELECT id, event_id, event_type, status, available_at, occurred_at FROM outbox_events WHERE status IN ('pending','failed','dead_letter') ORDER BY occurred_at ASC, id ASC LIMIT 1"
    ).fetchone()
    worker = connection.execute("SELECT * FROM system_jobs WHERE job_key='outbox.worker' LIMIT 1").fetchone()
    provider_error = connection.execute(
        "SELECT id, event_topic, status, http_status, last_error, last_attempt_at FROM webhook_deliveries WHERE last_error IS NOT NULL AND last_error != '' ORDER BY last_attempt_at DESC, id DESC LIMIT 1"
    ).fetchone()
    return {
        "counts": counts,
        "depth": counts["pending"] + counts["failed"],
        "failed": counts["failed"],
        "dead_letter": counts["dead_letter"],
        "oldest_open_event": dict(oldest) if oldest else None,
        "last_worker": dict(worker) if worker else None,
        "last_provider_error": dict(provider_error) if provider_error else None,
    }
