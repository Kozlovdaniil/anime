#!/usr/bin/env bash
#
# Экспорт базы кинотеатра в дамп (custom-формат pg_dump, сжатый).
#
#   docker/db-dump.sh                      # -> backups/cinema-<дата>.dump
#   OUT_DIR=/mnt/nfs/dumps docker/db-dump.sh
#
# Дамп кладётся на ХОСТ (stdout docker exec пишется в файл на хосте), поэтому
# его можно сразу увозить на другой сервер.
set -euo pipefail

export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$PROJECT_DIR"

COMPOSE="${COMPOSE:-docker compose}"
DB_USER="${POSTGRES_USER:-cinema}"
DB_NAME="${POSTGRES_DB:-cinema}"
OUT_DIR="${OUT_DIR:-$PROJECT_DIR/backups}"

mkdir -p "$OUT_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$OUT_DIR/${DB_NAME}-${STAMP}.dump"

echo ">> pg_dump ${DB_NAME} (user ${DB_USER}) -> ${OUT}"
$COMPOSE exec -T database \
    pg_dump -U "$DB_USER" -d "$DB_NAME" --format=custom --compress=9 > "$OUT"

# Проверяем, что прочитался как валидный custom-дамп.
$COMPOSE exec -T database pg_restore --list < "$OUT" > /dev/null

echo ">> готово: $(du -h "$OUT" | cut -f1)  $OUT"
