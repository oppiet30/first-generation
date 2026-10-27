#!/usr/bin/env bash
# Resume a world until its saved current_day reaches the requested target.
# Example: ./run-simulation.sh 1 100000
set -euo pipefail

cd "$(dirname "$0")"

WORLD_ID="${1:-1}"
TARGET="${2:-100000}"
BATCH=10000

if [[ ! "$WORLD_ID" =~ ^[1-9][0-9]*$ || ! "$TARGET" =~ ^[1-9][0-9]*$ ]]; then
    echo "Usage: $0 [world-id: positive integer] [target-day: positive integer]" >&2
    exit 2
fi

# Prevent multiple copies of this runner from competing for the same world.
# bin/simulate.php should independently lock each world (see PR #12).
exec 9>"${TMPDIR:-/tmp}/first-generation-runner-world-${WORLD_ID}.lock"
if ! flock -n 9; then
    echo "A batch runner for world ${WORLD_ID} is already active." >&2
    exit 1
fi

while true; do
    CURRENT=$(mariadb --batch --skip-column-names first_generation \
        -e "SELECT current_day FROM worlds WHERE id = ${WORLD_ID}" )
    if [[ ! "$CURRENT" =~ ^[0-9]+$ ]]; then
        echo "Could not read current_day for world ${WORLD_ID}." >&2
        exit 1
    fi

    if (( CURRENT >= TARGET )); then
        echo "World ${WORLD_ID} reached day ${CURRENT}; target ${TARGET}."
        exit 0
    fi

    REMAINING=$(( TARGET - CURRENT ))
    DAYS=$(( REMAINING < BATCH ? REMAINING : BATCH ))
    echo "World ${WORLD_ID}: day ${CURRENT}; advancing up to ${DAYS} days toward ${TARGET}."
    php bin/simulate.php "$WORLD_ID" "$DAYS"

    NEXT=$(mariadb --batch --skip-column-names first_generation \
        -e "SELECT current_day FROM worlds WHERE id = ${WORLD_ID}" )
    if [[ ! "$NEXT" =~ ^[0-9]+$ ]] || (( NEXT <= CURRENT )); then
        echo "Simulation made no progress; stopping to avoid an endless loop." >&2
        exit 1
    fi
done
