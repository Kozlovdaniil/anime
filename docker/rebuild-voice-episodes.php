<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ СКРИПТ ПЕРЕСБОРКИ серий озвучек Kodik.
 *
 * Приводит таблицу episodes к реальному покрытию каждой озвучки по данным
 * Kodik API (with_episodes=true): удаляет «лишние» серии (косяк старого
 * fan-out из AmediaSync) и добавляет недостающие. Сезон 0 (спешлы) не трогает
 * и не заводит.
 *
 * По умолчанию — DRY-RUN: только показывает, что будет изменено.
 * Чтобы реально записать, добавь --apply.
 *
 * Запуск внутри app-контейнера:
 *   docker compose exec -T app php docker/rebuild-voice-episodes.php
 *   docker compose exec -T app php docker/rebuild-voice-episodes.php --apply
 *   docker compose exec -T app php docker/rebuild-voice-episodes.php --apply --limit=100
 *   docker compose exec -T app php docker/rebuild-voice-episodes.php --apply --titles=84,3496
 *
 * Про мёртвый токен: если Kodik API перестал принимать токен и новый из
 * tokens.json получить не удалось — скрипт аккуратно останавливается, уже
 * сделанные озвучки сохранены, остальные остаются как есть. Ничего не падает.
 */

use App\Entity\Episode;
use App\Entity\Translation;
use App\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Аргументы
// ---------------------------------------------------------------------------
$opts = getopt('', ['titles::', 'limit::', 'delay::', 'apply']);
$onlyTitles = isset($opts['titles']) && '' !== $opts['titles']
    ? array_map('intval', explode(',', (string) $opts['titles']))
    : [];
$limit = isset($opts['limit']) ? max(0, (int) $opts['limit']) : 0; // 0 = все
$apply = array_key_exists('apply', $opts);
$delayMs = isset($opts['delay']) ? max(0, (int) $opts['delay']) : 120;

const API_BASE = 'https://kodik-api.com';
const TOKENS_URL = 'https://raw.githubusercontent.com/YaNesyTortiK/AnimeParsers/refs/heads/main/kdk_tokns/tokens.json';

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/** @return array{0:int,1:string} [status, body] */
function httpGet(string $url, int $timeout = 25, int $retries = 3): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $status = 0;
    $body = '';
    for ($attempt = 0; $attempt < $retries; ++$attempt) {
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        if (false !== $body && $status > 0) {
            break;
        }
        usleep(300000 * ($attempt + 1));
    }

    return [$status, (string) $body];
}

function decodeToken(string $t): string
{
    $n = intdiv(strlen($t), 2);
    $p1 = base64_decode(strrev(substr($t, 0, $n))) ?: '';
    $p2 = base64_decode(strrev(substr($t, $n))) ?: '';

    return $p2.$p1;
}

/** @return array<string,mixed>|null */
function apiGet(string $token, array $params): ?array
{
    $url = API_BASE.'/search?'.http_build_query(array_merge(['token' => $token], $params));
    [$status, $body] = httpGet($url);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return null;
    }

    return $json;
}

function isTokenError(?array $json): bool
{
    if (!is_array($json) || !isset($json['error'])) {
        return false;
    }

    return false !== mb_stripos((string) $json['error'], 'токен');
}

/**
 * Берём рабочий токен: сначала env, иначе перебор из tokens.json.
 * Возвращает null, если рабочего токена нет (тогда ничего не пишем).
 */
function obtainToken(): ?string
{
    $env = $_ENV['KODIK_API_TOKEN'] ?? $_SERVER['KODIK_API_TOKEN'] ?? '';
    if ('' !== $env && 'CHANGE_ME' !== $env) {
        $r = apiGet($env, ['title' => 'Наруто', 'limit' => 1]);
        if (is_array($r) && !isset($r['error'])) {
            return $env;
        }
        fwrite(STDERR, "KODIK_API_TOKEN из .env не работает, пробую tokens.json...\n");
    }

    [$status, $body] = httpGet(TOKENS_URL);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        fwrite(STDERR, "Не удалось получить tokens.json (status={$status}, len=".strlen($body).")\n");

        return null;
    }

    foreach (['stable', 'unstable', 'legacy'] as $group) {
        foreach ((array) ($json[$group] ?? []) as $i => $item) {
            $t = decodeToken((string) ($item['tokn'] ?? ''));
            if ('' === $t) {
                continue;
            }
            $r = apiGet($t, ['title' => 'Наруто', 'limit' => 1]);
            if (is_array($r) && !isset($r['error'])) {
                fwrite(STDERR, "Токен: рабочий из {$group}[{$i}]\n");

                return $t;
            }
        }
    }

    fwrite(STDERR, "Рабочих токенов в tokens.json нет\n");

    return null;
}

// ---------------------------------------------------------------------------
// Покрытие
// ---------------------------------------------------------------------------

/**
 * @return array<int,array<int,true>>|null [season => [episode => true]]; null — не удалось/ошибка
 */
function fetchCoverage(string $material, string &$token, bool &$tokenDead): ?array
{
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $json = apiGet($token, ['id' => $material, 'with_episodes' => 'true', 'limit' => 1]);
        if (null === $json) {
            return null; // сеть/мусор — озвучку не трогаем
        }
        if (isTokenError($json)) {
            fwrite(STDERR, "Токен отклонён Kodik, обновляю...\n");
            $fresh = obtainToken();
            if (null === $fresh) {
                $tokenDead = true;

                return null;
            }
            $token = $fresh;

            continue; // повторить с новым токеном
        }
        if (isset($json['error'])) {
            return null; // прочая ошибка API
        }

        $item = $json['results'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }

        $cov = [];
        foreach ((array) ($item['seasons'] ?? []) as $s => $season) {
            $s = (int) $s;
            if ($s < 1) {
                continue; // спешлы/сезон 0 игнорим
            }
            foreach ((array) ($season['episodes'] ?? []) as $e => $_) {
                $e = (int) $e;
                if ($e < 1) {
                    continue;
                }
                $cov[$s][$e] = true;
            }
        }

        return $cov;
    }

    return null;
}

/** Подставляет season/episode в ссылку (как AmediaSync::withSeasonEpisode). */
function withSeasonEpisode(string $url, int $season, int $episode): string
{
    if (preg_match('#[?&]season=#', $url)) {
        $url = preg_replace('#([?&]season=)\d+#', '${1}'.$season, $url);
    } else {
        $url .= (str_contains($url, '?') ? '&' : '?').'season='.$season;
    }
    if (preg_match('#[?&]episode=#', $url)) {
        $url = preg_replace('#([?&]episode=)\d+#', '${1}'.$episode, $url);
    } else {
        $url .= (str_contains($url, '?') ? '&' : '?').'episode='.$episode;
    }

    return $url;
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
if (!isset($_SERVER['APP_ENV'])) {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'dev';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';

$kernel = new Kernel($_SERVER['APP_ENV'], false);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$conn = $container->get('doctrine')->getConnection();

$token = obtainToken();
if (null === $token) {
    fwrite(STDERR, "Нет рабочего токена Kodik — ничего не меняю, выхожу.\n");
    exit(0);
}

// ---------------------------------------------------------------------------
// Выборка озвучек
// ---------------------------------------------------------------------------
$where = ["tr.tech_title = 'kodik'", "tr.link LIKE '%kodikplayer%'", "tr.link LIKE '%/serial/%'"];
if ($onlyTitles) {
    $where[] = 'tr.title_id IN ('.implode(',', array_map('intval', $onlyTitles)).')';
}
$sql = 'SELECT tr.id FROM translations tr WHERE '.implode(' AND ', $where).' ORDER BY tr.id';
if ($limit > 0) {
    $sql .= ' LIMIT '.$limit;
}
$ids = array_map('intval', $conn->fetchFirstColumn($sql));

fwrite(STDERR, sprintf(
    "Режим: %s. Озвучек к обработке: %d\n\n",
    $apply ? 'ЗАПИСЬ (--apply)' : 'DRY-RUN (показать, что изменится)',
    count($ids)
));

$ok = 0;
$changed = 0;
$skipped = 0;
$errors = 0;
$removedTotal = 0;
$addedTotal = 0;
$tokenDead = false;

foreach ($ids as $i => $id) {
    if ($tokenDead) {
        break;
    }

    /** @var Translation|null $tr */
    $tr = $em->find(Translation::class, $id);
    if (null === $tr) {
        continue;
    }
    $link = (string) $tr->getLink();
    if (!preg_match('#kodikplayer\.com/(serial|video)/(\d+)/#', $link, $m)) {
        ++$errors;
        $em->clear();
        continue;
    }
    $material = ('video' === $m[1] ? 'movie' : 'serial').'-'.$m[2];

    $cov = fetchCoverage($material, $token, $tokenDead);
    if ($tokenDead) {
        fwrite(STDERR, "Токен мёртв и нового нет — останавливаюсь, остальное не трогаю.\n");
        break;
    }
    if (null === $cov || !$cov) {
        // Не смогли получить или Kodik не знает серий — существующие не удаляем.
        ++$skipped;
        $em->clear();
        continue;
    }

    // Текущее покрытие БД (сезон >= 1; сезон 0 не трогаем)
    $existing = [];
    foreach ($tr->getEpisodes() as $ep) {
        $s = $ep->getSeason();
        if ($s < 1) {
            continue;
        }
        $existing[$s.':'.$ep->getEpisodeNumber()] = $ep;
    }

    $toRemove = [];
    foreach ($existing as $key => $ep) {
        [$s, $e] = array_map('intval', explode(':', $key));
        if (!isset($cov[$s][$e])) {
            $toRemove[] = $ep;
        }
    }

    $toAdd = [];
    foreach ($cov as $s => $eps) {
        foreach ($eps as $e => $_) {
            if (!isset($existing[$s.':'.$e])) {
                $toAdd[] = [(int) $s, (int) $e];
            }
        }
    }

    $removedTotal += count($toRemove);
    $addedTotal += count($toAdd);

    if (!$toRemove && !$toAdd) {
        ++$ok;
        $em->clear();
        continue;
    }

    ++$changed;
    printf(
        "%s T%d «%s» / %s [%s]: −%d +%d\n",
        $apply ? '~' : '·',
        $tr->getTitle()?->getId(),
        mb_substr((string) $tr->getTitle()?->getTitle(), 0, 28),
        mb_substr($tr->getName(), 0, 22),
        $material,
        count($toRemove),
        count($toAdd)
    );

    if ($apply) {
        try {
            foreach ($toRemove as $ep) {
                $tr->removeEpisode($ep);
                $em->remove($ep);
            }
            foreach ($toAdd as [$s, $e]) {
                $ep = (new Episode())
                    ->setSeason($s)
                    ->setEpisodeNumber($e)
                    ->setLink(withSeasonEpisode($link, $s, $e));
                $tr->addEpisode($ep);
                $em->persist($ep);
            }
            $em->flush();
        } catch (\Throwable $ex) {
            ++$errors;
            fwrite(STDERR, "Ошибка на озвучке {$id}: {$ex->getMessage()} — оставляю как есть\n");
            $em->clear();
            continue;
        }
    }

    $em->clear();

    if (0 === ($i % 200)) {
        fwrite(STDERR, sprintf(
            "[%d/%d] без изменений: %d, изменено: %d, −%d/+%d серий\n",
            $i, count($ids), $ok, $changed, $removedTotal, $addedTotal
        ));
    }

    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
}

echo "\n=== Итого ===\n";
printf("Режим:            %s\n", $apply ? 'ЗАПИСЬ' : 'DRY-RUN');
printf("Обработано:       %d\n", $ok + $changed);
printf("Без изменений:    %d\n", $ok);
printf("Изменено озвучек: %d\n", $changed);
printf("Пропущено:        %d\n", $skipped);
printf("Ошибок:           %d\n", $errors);
printf("Серий удалено:    %d\n", $removedTotal);
printf("Серий добавлено:  %d\n", $addedTotal);
if ($tokenDead) {
    echo "\n!! Токен умер по ходу — часть озвучек не обработана, они остались как есть.\n";
}
if (!$apply) {
    echo "\nЭто был DRY-RUN. Для записи повтори с --apply.\n";
}
