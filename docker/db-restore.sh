#!/usr/bin/env bash
#
# Импорт дампа в базу кинотеатра. База пересоздаётся «начисто» из дампа,
# поэтому это заодно и полный перенос данных на новый сервер (VPS).
#
#   docker/db-restore.sh backups/cinema-20261012-060000.dump
#
# ВНИМАНИЕ: текущее содержимое БД будет удалено.
set -euo pipefail

export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$PROJECT_DIR"

COMPOSE="${COMPOSE:-docker compose}"
DB_USER="${POSTGRES_USER:-cinema}"
DB_NAME="${POSTGRES_DB:-cinema}"

if [ $# -ne 1 ]; then
    echo "Использование: $0 <файл-дампа>" >&2
    exit 2
fi
DUMP="$1"
[ -f "$DUMP" ] || { echo "Файл не найден: $DUMP" >&2; exit 2; }

echo ">> БД ${DB_NAME} будет удалена и создана заново из ${DUMP}"
echo ">> Ctrl+C в течение 5 секунд, чтобы отменить"
sleep 5

echo ">> останавливаем app (чтобы никто не держал соединения с БД)"
$COMPOSE stop app >/dev/null 2>&1 || true

echo ">> пересоздаём базу"
$COMPOSE exec -T database dropdb   -U "$DB_USER" --if-exists --force "$DB_NAME"
$COMPOSE exec -T database createdb -U "$DB_USER" -O "$DB_USER" "$DB_NAME"

echo ">> pg_restore"
$COMPOSE exec -T database \
    pg_restore -U "$DB_USER" -d "$DB_NAME" --no-owner --no-privileges --exit-on-error < "$DUMP"

echo ">> поднимаем app"
$COMPOSE start app >/dev/null 2>&1 || true

echo ">> готово. Если код новее дампа — догоните схему:"
echo "   $COMPOSE exec app php bin/console doctrine:migrations:migrate --no-interaction"
