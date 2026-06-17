#!/usr/bin/env bash
set -u
set -o pipefail

SOURCE="${AUDIT_SOURCE:-/audit/source}"
WORK="${AUDIT_WORK:-/audit/work}"
RESULTS="${AUDIT_RESULTS:-/audit/results}"
STEP_TIMEOUT="${AUDIT_TIMEOUT:-900}"
PROFILE="${AUDIT_PROFILE:-full}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="$RESULTS/$STAMP"
PROJECT="$WORK/project"
SUMMARY="$RUN_DIR/summary.tsv"

mkdir -p "$RUN_DIR/logs" "$RUN_DIR/artifacts" "$WORK"
rm -rf "$PROJECT"
mkdir -p "$PROJECT"
cp -a "$SOURCE"/. "$PROJECT"/
rm -rf "$PROJECT/storage/audit-results" 2>/dev/null || true

case "$PROFILE" in
    quick|full|release) ;;
    *) echo "Profil d'audit invalide: $PROFILE" >&2; exit 2 ;;
esac

printf 'step\tstatus\texit_code\tduration_seconds\tlog\n' > "$SUMMARY"

run_step() {
    local name="$1"
    shift
    local slug
    slug="$(printf '%s' "$name" | tr ' /:' '___' | tr -cd '[:alnum:]_.-')"
    local log="$RUN_DIR/logs/${slug}.log"
    local started ended rc status
    started="$(date +%s)"
    {
        echo "COMMAND: $*"
        echo "STARTED_UTC: $(date -u +%FT%TZ)"
        echo
        timeout --preserve-status "$STEP_TIMEOUT" "$@"
    } >"$log" 2>&1
    rc=$?
    ended="$(date +%s)"
    if [ "$rc" -eq 0 ]; then status="PASS"; else status="FAIL"; fi
    printf '%s\t%s\t%s\t%s\t%s\n' "$name" "$status" "$rc" "$((ended-started))" "logs/${slug}.log" >> "$SUMMARY"
    return 0
}

run_shell_step() {
    local name="$1"
    local command="$2"
    run_step "$name" bash -lc "cd '$PROJECT' && $command"
}

# Environnement exact de l'audit
{
    echo "AUDIT_UTC=$STAMP"
    echo "AUDIT_PROFILE=$PROFILE"
    echo "SOURCE=$SOURCE"
    echo "PROJECT_COPY=$PROJECT"
    echo
    uname -a
    cat /etc/os-release
    php -v
    php --ri pdo_sqlite
    php -r 'print_r(PDO::getAvailableDrivers());'
    composer --version
    python3 --version
    node --version
    npm --version
    git --version
    sqlite3 --version
} > "$RUN_DIR/environment.txt" 2>&1

cd "$PROJECT"

run_shell_step "CLI help" "python3 tools/cms.py --help"
run_shell_step "Composer validate" "cd backend && composer validate --strict"

if [[ "$PROFILE" == "quick" ]]; then
    run_shell_step "Tests" "python3 tools/cms.py test"
    run_shell_step "Validators" "python3 tools/cms.py validate"
    run_shell_step "Check documentation" "python3 tools/cms.py docs check"
else
    run_shell_step "Composer install" "cd backend && composer install --no-interaction --prefer-dist"
    run_shell_step "Admin npm ci" "cd frontend/admin-vue && npm ci"
    run_shell_step "Admin build" "cd frontend/admin-vue && npm run build"
    run_shell_step "Rebuild databases" "python3 tools/cms.py rebuild"
    run_shell_step "Tests" "python3 tools/cms.py test"
    run_shell_step "Validators" "python3 tools/cms.py validate"
    run_shell_step "Generate documentation" "python3 tools/cms.py docs generate"
    run_shell_step "Check documentation" "python3 tools/cms.py docs check"
    run_shell_step "Generate evaluation documentation" "python3 tools/cms.py docs evaluation-generate"
    run_shell_step "Check evaluation documentation" "python3 tools/cms.py docs evaluation-check"

    run_shell_step "Database hashes before backup" "cd storage/database && find . -maxdepth 1 -type f \( -name '*.db' -o -name '*.sqlite' -o -name '*.sqlite3' \) -print0 | sort -z | xargs -0 -r sha256sum > '$RUN_DIR/artifacts/database-hashes-before.txt'"
    run_shell_step "Backup" "mkdir -p storage/backups && python3 tools/cms.py backup --output storage/backups/evaluation.zip"
    run_shell_step "Restore" "python3 tools/cms.py backup --restore storage/backups/evaluation.zip --yes"
    run_shell_step "Database hashes after restore" "cd storage/database && find . -maxdepth 1 -type f \( -name '*.db' -o -name '*.sqlite' -o -name '*.sqlite3' \) -print0 | sort -z | xargs -0 -r sha256sum > '$RUN_DIR/artifacts/database-hashes-after.txt'"
    run_shell_step "Compare restored database hashes" "diff -u '$RUN_DIR/artifacts/database-hashes-before.txt' '$RUN_DIR/artifacts/database-hashes-after.txt'"
    run_shell_step "Static export" "python3 tools/cms.py export"
fi

if [[ "$PROFILE" == "release" ]]; then
    run_shell_step "Release package and verification" "DEC_CMS_AUDIT_INTERNAL=1 python3 tools/cms.py release --package --verify-archive --include-vendor"
fi

# Collecte déterministe et compacte. Les archives, sauvegardes, bases SQLite,
# dépendances et anciens audits ne sont jamais recopiés dans la preuve courante.
run_step "Collect audit evidence" \
    python3 "$PROJECT/tools/python/operations/audit/a1_collect_evidence.py" \
    --project "$PROJECT" \
    --run-dir "$RUN_DIR"
run_step "Verify audit evidence" \
    python3 "$PROJECT/tools/python/operations/audit/a2_verify_evidence.py" \
    --run-dir "$RUN_DIR"

python3 - "$SUMMARY" "$RUN_DIR/summary.json" <<'PY'
import csv, json, sys
src, dst = sys.argv[1:]
with open(src, encoding="utf-8") as f:
    rows = list(csv.DictReader(f, delimiter="\t"))
for row in rows:
    row["exit_code"] = int(row["exit_code"])
    row["duration_seconds"] = int(row["duration_seconds"])
with open(dst, "w", encoding="utf-8") as f:
    json.dump({"steps": rows}, f, ensure_ascii=False, indent=2)
PY

cp "$SUMMARY" "$RUN_DIR/summary.txt"
(
    cd "$RESULTS"
    zip -qr "cms-audit-evidence-$STAMP.zip" "$STAMP"
)

PASS_COUNT="$(awk -F '\t' 'NR>1 && $2=="PASS" {n++} END{print n+0}' "$SUMMARY")"
FAIL_COUNT="$(awk -F '\t' 'NR>1 && $2=="FAIL" {n++} END{print n+0}' "$SUMMARY")"

echo "Audit terminé : $PASS_COUNT étape(s) réussie(s), $FAIL_COUNT en échec."
echo "Résultats : $RUN_DIR"
echo "Archive : $RESULTS/cms-audit-evidence-$STAMP.zip"

# L'audit retourne un échec global s'il existe au moins une étape en échec.
[ "$FAIL_COUNT" -eq 0 ]
