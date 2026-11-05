#!/usr/bin/env bash
#
# Регулярный парсинг обновлений amd.online: 10 страниц каталога, отсортированного
# по дате редактирования (свежие вышедшие серии — первыми). Запускается из cron
# дважды в день (см. docker/crontab).
#
#   docker/sync-updates.sh              # 10 страниц
#   PAGES=25 docker/sync-updates.sh     # другое число страниц
#
# Параллельный запуск исключается через flock, вывод копится в var/log/amd-sync.log.
set -euo pipefail

export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$PROJECT_DIR"

COMPOSE="${COMPOSE:-docker compose}"
PAGES="${PAGES:-10}"
LOG="${SYNC_LOG:-$PROJECT_DIR/var/log/amd-sync.log}"
LOCK="$PROJECT_DIR/var/log/amd-sync.lock"

mkdir -p "$(dirname "$LOG")"

# Не запускаем второй раз, пока идёт предыдущий.
exec 9>"$LOCK"
if ! flock -n 9; then
    echo "$(date '+%F %T') предыдущий amd:sync ещё выполняется — пропуск" >> "$LOG"
    exit 0
fi

{
    echo "===== $(date '+%F %T') amd:sync --max-pages=${PAGES} ====="
    $COMPOSE exec -T app php bin/console --no-debug amd:sync --max-pages="$PAGES"
    echo "===== $(date '+%F %T') завершено ====="
    echo
} >> "$LOG" 2>&1
