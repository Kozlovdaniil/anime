#!/usr/bin/env bash
#
# Разовая проверка блока «Вышло продолжение» — уведомление о новом сезоне
# произведения, у которого досмотрен предыдущий сезон.
#
# Скрипт сам подбирает подходящую пару сезонов (в произведении, где у вас
# ВООБЩЕ нет прогресса, чтобы не задеть реальные данные), временно готовит
# данные, показывает результат и в конце ГАРАНТИРОВАННО откатывает всё назад
# (в т.ч. при Ctrl+C или ошибке).
#
#   docker/test-new-seasons.sh                     # подготовить, показать, откатить
#   KEEP=1 docker/test-new-seasons.sh              # оставить данные, чтобы посмотреть в браузере
#   BASE_URL=http://192.168.1.10:8080 docker/test-new-seasons.sh
#
# После KEEP=1 откатить вручную (команда печатается в конце) или просто
# запустить скрипт ещё раз без KEEP.
set -euo pipefail

export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

PROJECT_DIR="${PROJECT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$PROJECT_DIR"

COMPOSE="${COMPOSE:-docker compose}"
DB_USER="${POSTGRES_USER:-cinema}"
DB_NAME="${POSTGRES_DB:-cinema}"
BASE_URL="${BASE_URL:-http://localhost:8080}"

psql() { $COMPOSE exec -T database psql -U "$DB_USER" -d "$DB_NAME" -A -t "$@"; }

# --- 1. Подбираем пару: сезон A (с вышедшими сериями) и следующий за ним B. ---
PAIR="$(psql -F'|' -c "
WITH rel AS (
    SELECT DISTINCT t.franchise, t.id AS title_id,
           t.season_number AS s, COALESCE(t.season_part, 1) AS p
    FROM titles t
    JOIN translations tr ON tr.title_id = t.id
    JOIN episodes ep ON ep.translation_id = tr.id AND ep.season = t.season_number
    WHERE t.franchise IS NOT NULL AND t.season_number IS NOT NULL
      AND (ep.link IS NULL OR ep.link NOT LIKE '%do=nz%')
),
clean AS (
    SELECT DISTINCT franchise FROM titles
    WHERE franchise IS NOT NULL
      AND franchise NOT IN (
          SELECT t2.franchise FROM titles t2
          JOIN translations tr2 ON tr2.title_id = t2.id
          WHERE t2.franchise IS NOT NULL AND tr2.last_episode IS NOT NULL
      )
),
ranked AS (
    SELECT r.*, row_number() OVER (
        PARTITION BY r.franchise ORDER BY r.s, r.p, r.title_id
    ) AS rn
    FROM rel r JOIN clean c ON c.franchise = r.franchise
)
SELECT a.franchise, a.title_id, a.s, a.p, b.title_id, b.s, b.p
FROM (SELECT * FROM ranked WHERE rn = 1) a
JOIN (SELECT * FROM ranked WHERE rn = 2) b ON b.franchise = a.franchise
WHERE (b.s, b.p) > (a.s, a.p)
ORDER BY a.title_id
LIMIT 1;")"

if [ -z "$PAIR" ]; then
    echo "!! Не нашлось подходящей пары сезонов (нужно произведение с >=2 сезонами,"
    echo "   у которых есть вышедшие серии). Сначала прогоните amd:sync."
    exit 1
fi
IFS='|' read -r FRANCHISE A_ID A_S A_P B_ID B_S B_P <<< "$PAIR"

# --- 2. Озвучка сезона A без прогресса, её последняя вышедшая серия сезона A. ---
READ="$(psql -F'|' -c "
SELECT tr.id, MAX(ep.episode_number)
FROM translations tr
JOIN episodes ep ON ep.translation_id = tr.id
WHERE tr.title_id = $A_ID
  AND ep.season = $A_S
  AND tr.last_episode IS NULL
  AND (ep.link IS NULL OR ep.link NOT LIKE '%do=nz%')
GROUP BY tr.id
ORDER BY tr.id
LIMIT 1;")"
if [ -z "$READ" ]; then
    echo ">> не удалось подобрать озвучку для сезона — выходим" >&2
    exit 1
fi
IFS='|' read -r TR_ID MAX_E <<< "$READ"

A_TITLE="$(psql -c "SELECT title FROM titles WHERE id = $A_ID;")"
B_TITLE="$(psql -c "SELECT title FROM titles WHERE id = $B_ID;")"
B_SEEN="$(psql -c "SELECT COALESCE(to_char(first_seen_at, 'YYYY-MM-DD HH24:MI:SS'), '') FROM titles WHERE id = $B_ID;")"

# --- 2b. Откат: срабатывает всегда (EXIT), в т.ч. при прерывании. ---
CLEANED=0
cleanup() {
    [ "$CLEANED" = 1 ] && return 0
    CLEANED=1
    psql -q -c "UPDATE translations SET last_season = NULL, last_episode = NULL, last_watched_at = NULL WHERE id = $TR_ID;" >/dev/null 2>&1 || true
    if [ -n "$B_SEEN" ]; then
        psql -q -c "UPDATE titles SET first_seen_at = '$B_SEEN' WHERE id = $B_ID;" >/dev/null 2>&1 || true
    else
        psql -q -c "UPDATE titles SET first_seen_at = NULL WHERE id = $B_ID;" >/dev/null 2>&1 || true
    fi
    echo ">> откат выполнен (озвучка #$TR_ID, тайтл #$B_ID)"
}
trap cleanup EXIT

# --- 3. Готовим сценарий. ---
echo ">> произведение (franchise): $FRANCHISE"
echo ">> досматриваем  сезон $A_S: «$A_TITLE» (озвучка #$TR_ID, последняя серия S$A_S = $MAX_E)"
echo ">> делаем свежим сезон $B_S: «$B_TITLE» (как будто вышел только что)"
echo
psql -q -c "UPDATE translations SET last_season = $A_S, last_episode = $MAX_E, last_watched_at = NOW() WHERE id = $TR_ID;" >/dev/null
psql -q -c "UPDATE titles SET first_seen_at = NOW() WHERE id = $B_ID;" >/dev/null

# --- 4. Показываем, что отдаёт сайт. ---
show_block() {
    local url="$1" html
    echo "=== $url ==="
    html="$(curl -fsS "$url" 2>/dev/null || true)"
    if [ -z "$html" ] || ! grep -q 'new-seasons' <<< "$html"; then
        echo "  (блок «Вышло продолжение» не отрисован)"
        return
    fi
    if command -v python3 >/dev/null 2>&1; then
        printf '%s' "$html" | python3 -c '
import re, sys
h = sys.stdin.read()
m = re.search(r"<section class=\"new-episodes new-seasons\">(.*?)</section>", h, re.S)
if not m:
    print("  (блок не найден)")
    raise SystemExit
cards = re.findall(r"<a class=\"ne-item[^\"]*\"[^>]*>.*?</a>", m.group(1), re.S)
print(f"  карточек: {len(cards)}")
for c in cards:
    def pick(pat):
        mm = re.search(pat, c, re.S)
        return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", mm.group(1))).strip() if mm else ""
    name = pick(r"class=\"ne-name\">(.*?)</span>")
    meta = pick(r"class=\"ne-meta\">(.*?)</span>")
    nxt  = pick(r"class=\"ne-next\">(.*?)</span>")
    href = re.search(r"href=\"([^\"]+)\"", c)
    print("  • " + name + "  ->  " + (href.group(1) if href else ""))
    print(f"      {meta}  ·  {nxt}")
'
    else
        grep -o 'ne-name">[^<]*\|ne-meta">[^<]*\|ne-next">[^<]*' <<< "$html" \
            | sed 's/ne-name">/  • /; s/ne-meta">/      /; s/ne-next">/      /'
    fi
}

show_block "$BASE_URL/"
echo
show_block "$BASE_URL/watching"
echo

# --- 5. Ждём Enter (если терминал), чтобы успеть посмотреть в браузере. ---
if [ "${KEEP:-0}" = "1" ]; then
    CLEANED=1
    echo ">> KEEP=1: данные оставил как есть. Открой $BASE_URL и посмотри блок сверху."
    echo ">> Откатить вручную:"
    echo "   $COMPOSE exec -T database psql -U $DB_USER -d $DB_NAME -c \\"
    echo "     \"UPDATE translations SET last_season=NULL, last_episode=NULL, last_watched_at=NULL WHERE id=$TR_ID;\""
    if [ -n "$B_SEEN" ]; then
        echo "   $COMPOSE exec -T database psql -U $DB_USER -d $DB_NAME -c \\"
        echo "     \"UPDATE titles SET first_seen_at='$B_SEEN' WHERE id=$B_ID;\""
    fi
elif [ -t 0 ]; then
    read -r -p ">> Открой $BASE_URL, посмотри блок и нажми Enter для отката " _ || true
fi
