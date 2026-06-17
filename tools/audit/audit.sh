#!/usr/bin/env bash
set -euo pipefail

PROFILE="quick"
ENGINE="auto"
FORCE_BUILD=0
PULL=0
RESULTS_DIR=""
IMAGE="localhost/cms-audit:latest"

usage() {
    cat <<'EOF'
Usage: tools/audit/audit.sh [quick|full|release] [options]

Options:
  --engine auto|podman|docker  Moteur de conteneurs (défaut: auto)
  --build                      Reconstruit l'image avant l'audit
  --pull                       Actualise les images de base pendant le build
  --results-dir PATH           Répertoire de sortie des preuves
  -h, --help                   Affiche cette aide
EOF
}

if [[ $# -gt 0 && "$1" != --* ]]; then
    PROFILE="$1"
    shift
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --engine)
            ENGINE="${2:?Valeur manquante pour --engine}"
            shift 2
            ;;
        --build)
            FORCE_BUILD=1
            shift
            ;;
        --pull)
            PULL=1
            FORCE_BUILD=1
            shift
            ;;
        --results-dir)
            RESULTS_DIR="${2:?Valeur manquante pour --results-dir}"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "ERREUR: option inconnue: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

case "$PROFILE" in
    quick|full|release) ;;
    *) echo "ERREUR: profil invalide: $PROFILE" >&2; exit 2 ;;
esac

case "$ENGINE" in
    auto)
        if command -v podman >/dev/null 2>&1; then
            ENGINE="podman"
        elif command -v docker >/dev/null 2>&1; then
            ENGINE="docker"
        else
            echo "ERREUR: ni Podman ni Docker n'est disponible." >&2
            exit 2
        fi
        ;;
    podman|docker) ;;
    *) echo "ERREUR: moteur invalide: $ENGINE" >&2; exit 2 ;;
esac

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$TOOLS_DIR/../.." && pwd)"
RESULTS_DIR="${RESULTS_DIR:-$ROOT/storage/audit-results}"
mkdir -p "$RESULTS_DIR"

if [[ ! -f "$ROOT/Dockerfile.audit" ]]; then
    echo "ERREUR: Dockerfile.audit introuvable à la racine du CMS." >&2
    exit 2
fi
if [[ ! -f "$ROOT/tools/audit/run-audit.sh" ]]; then
    echo "ERREUR: tools/audit/run-audit.sh introuvable." >&2
    exit 2
fi

image_exists() {
    "$ENGINE" image inspect "$IMAGE" >/dev/null 2>&1
}

# Une release doit toujours documenter un environnement fraîchement reconstruit.
if [[ "$PROFILE" == "release" ]]; then
    FORCE_BUILD=1
fi

if [[ "$FORCE_BUILD" -eq 1 ]] || ! image_exists; then
    build_args=(build --tag "$IMAGE" --file "$ROOT/Dockerfile.audit")
    if [[ "$PULL" -eq 1 ]]; then
        build_args+=(--pull)
    fi
    build_args+=("$ROOT")
    echo "==> Construction de l'image d'audit ($ENGINE)"
    "$ENGINE" "${build_args[@]}"
fi

echo "==> Audit reproductible: $PROFILE"
echo "==> Résultats: $RESULTS_DIR"

run_args=(run --rm --name "cms-audit-${PROFILE}"
    --volume "$ROOT:/audit/source:ro"
    --volume "$RESULTS_DIR:/audit/results"
    --env "AUDIT_PROFILE=$PROFILE")

"$ENGINE" "${run_args[@]}" "$IMAGE"
