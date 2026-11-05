<?php

declare(strict_types=1);

/**
 * РАЗОВЫЙ СКРИПТ-ПРОВЕРКА покрытия озвучек Kodik.
 *
 * READ-ONLY: ничего не пишет ни в БД, ни в .env, ни в файлы.
 * Сравнивает серии, которые уже лежат в таблице episodes, с тем, что реально
 * отдаёт Kodik API для каждой озвучки (with_episodes=true).
 *
 * Запуск внутри app-контейнера:
 *   docker compose exec -T app php docker/check-voice-coverage.php
 *   docker compose exec -T app php docker/check-voice-coverage.php --titles=84,53,3496
 *   docker compose exec -T app php docker/check-voice-coverage.php --limit=15
 *   docker compose exec -T app php docker/check-voice-coverage.php --all --no-details
 *
 * По умолчанию берёт N тайтлов с наибольшим числом Kodik-озвучек.
 * Токен: сначала KODIK_API_TOKEN из окружения, иначе автоматически из
 * tokens.json репозитория anime_parsers_ru (как KodikParser::get_token()).
 */

use App\Entity\Translation;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// ------------------------------- аргументы ---------------------------------
$opts = getopt('', ['titles::', 'limit::', 'all', 'no-details', 'delay::']);
$onlyTitles = (isset($opts['titles']) && '' !== (string) $opts['titles'])
    ? array_values(array_filter(array_map('intval', explode(',', (string) $opts['titles']))))
    : [];
$limit      = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 8;
$all        = array_key_exists('all', $opts);
$noDetails  = array_key_exists('no-details', $opts);
$delayMs    = isset($opts['delay']) ? max(0, (int) $opts['delay']) : 150;

const API_BASE = 'https://kodik-api.com';
const TOKENS_URL = 'https://raw.githubusercontent.com/YaNesyTortiK/AnimeParsers/refs/heads/main/kdk_tokns/tokens.json';

// ---------------------------------------------------------------------------
// HTTP helpers (обычный file_get_contents: Symfony HttpClient тут избыточен)
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

function apiSearch(string $token, array $params): ?array
{
    $url = API_BASE.'/search?'.http_build_query(array_merge(['token' => $token], $params));
    [$status, $body] = httpGet($url);
    $json = json_decode($body, true);
    if (!is_array($json) || isset($json['error'])) {
        return null;
    }

    return $json;
}

// ---------------------------------------------------------------------------
// Токен: KODIK_API_TOKEN из env, иначе разбор tokens.json (как get_token())
// ---------------------------------------------------------------------------

function decodeToken(string $t): string
{
    $n = intdiv(strlen($t), 2);
    $p1 = base64_decode(strrev(substr($t, 0, $n))) ?: '';
    $p2 = base64_decode(strrev(substr($t, $n))) ?: '';

    return $p2.$p1;
}

function ensureToken(): string
{
    $env = $_ENV['KODIK_API_TOKEN'] ?? $_SERVER['KODIK_API_TOKEN'] ?? '';
    if ('' !== $env && 'CHANGE_ME' !== $env) {
        fwrite(STDERR, "Токен: из KODIK_API_TOKEN\n");

        return $env;
    }

    fwrite(STDERR, "Токен: тяну tokens.json...\n");
    [$status, $body] = httpGet(TOKENS_URL);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        fwrite(STDERR, "Не удалось получить tokens.json (status={$status}, len=".strlen($body).")\n");
        exit(2);
    }

    foreach (['stable', 'unstable', 'legacy'] as $group) {
        foreach ((array) ($json[$group] ?? []) as $i => $item) {
            $t = decodeToken((string) ($item['tokn'] ?? ''));
            if ('' === $t) {
                continue;
            }
            $r = apiSearch($t, ['title' => 'Наруто', 'limit' => 1]);
            if (null !== $r && isset($r['total'])) {
                fwrite(STDERR, "Токен: рабочий из {$group}[{$i}]\n");

                return $t;
            }
        }
    }

    fwrite(STDERR, "Рабочих токенов в tokens.json не нашлось\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Компактный вывод множеств «сезон => серии»
// ---------------------------------------------------------------------------

function ranges(array $nums): string
{
    $nums = array_values(array_unique(array_map('intval', $nums)));
    sort($nums);
    $out = [];
    $start = null;
    $prev = null;
    foreach ($nums as $n) {
        if (null === $start) {
            $start = $prev = $n;
            continue;
        }
        if ($n === $prev + 1) {
            $prev = $n;
            continue;
        }
        $out[] = $start === $prev ? (string) $start : $start.'-'.$prev;
        $start = $prev = $n;
    }
    if (null !== $start) {
        $out[] = $start === $prev ? (string) $start : $start.'-'.$prev;
    }

    return implode(',', $out);
}

/**
 * @param array<int,array<int,true>> $bySeason
 */
function fmtCoverage(array $bySeason): string
{
    if (!$bySeason) {
        return '—';
    }
    ksort($bySeason);
    $parts = [];
    foreach ($bySeason as $s => $eps) {
        $parts[] = 's'.$s.': '.ranges(array_keys($eps));
    }

    return implode('  ', $parts);
}

/**
 * Разница множеств по сезонам.
 *
 * @param array<int,array<int,true>> $a
 * @param array<int,array<int,true>> $b
 *
 * @return array<int,array<int,true>> элементы a, которых нет в b
 */
function setDiff(array $a, array $b): array
{
    $out = [];
    foreach ($a as $s => $eps) {
        foreach ($eps as $e => $_) {
            if (!isset($b[$s][$e])) {
                $out[$s][$e] = true;
            }
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Boot Symfony
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

$token = ensureToken();

// ---------------------------------------------------------------------------
// Выборка тайтлов
// ---------------------------------------------------------------------------

/** @var list<int> $titleIds */
$titleIds = [];
if ($onlyTitles) {
    $titleIds = $onlyTitles;
} else {
    $sql = "SELECT t.id
              FROM translations tr
              JOIN titles t ON t.id = tr.title_id
             WHERE tr.tech_title = 'kodik' AND tr.link LIKE '%kodikplayer%'
             GROUP BY t.id
             ORDER BY count(*) DESC, t.id";
    if (!$all) {
        $sql .= ' LIMIT '.$limit;
    }
    $titleIds = array_map('intval', $conn->fetchFirstColumn($sql));
}

fwrite(STDERR, 'Тайтлов к проверке: '.count($titleIds)."\n\n");

$totVoices = 0;
$totOk = 0;
$totBad = 0;
$totErr = 0;

foreach ($titleIds as $ti => $titleId) {
    $title = $em->find(App\Entity\Title::class, $titleId);
    if (null === $title) {
        echo "T{$titleId}: не найден в БД\n";
        continue;
    }

    $translations = $em->createQuery(
        'SELECT tr FROM App\Entity\Translation tr
          WHERE tr.title = :t AND tr.techTitle = :tech AND tr.link LIKE :lk
          ORDER BY tr.priority DESC, tr.id'
    )
        ->setParameter('t', $title)
        ->setParameter('tech', 'kodik')
        ->setParameter('lk', '%kodikplayer%')
        ->getResult();

    $header = sprintf('T%d «%s» (s%s) — Kodik-озвучек: %d', $titleId, $title->getTitle(), $title->getSeasonNumber() ?? '?', count($translations));
    echo $header."\n";

    foreach ($translations as $tr) {
        ++$totVoices;
        $link = (string) $tr->getLink();
        if (!preg_match('#kodikplayer\.com/(serial|video)/(\d+)/#', $link, $m)) {
            echo "   ? {$tr->getName()}: не разобрал material из link\n";
            ++$totErr;
            continue;
        }
        $kind = 'serial' === $m[1] ? 'serial' : 'movie';
        $material = $kind.'-'.$m[2];

        $item = null;
        for ($attempt = 0; $attempt < 2 && null === $item; ++$attempt) {
            $r = apiSearch($token, ['id' => $material, 'with_episodes' => 'true', 'limit' => 1]);
            $item = $r['results'][0] ?? null;
            if (null === $item && 1 === $attempt) {
                break;
            }
        }
        if (null === $item) {
            echo "   ! {$tr->getName()} [{$material}]: Kodik ничего не вернул\n";
            ++$totErr;
            continue;
        }

        // Покрытие по версии Kodik
        $kMap = [];
        foreach ((array) ($item['seasons'] ?? []) as $s => $season) {
            $s = (int) $s;
            foreach ((array) ($season['episodes'] ?? []) as $e => $_) {
                $kMap[$s][(int) $e] = true;
            }
        }

        // Покрытие по версии БД
        $dbMap = [];
        foreach ($tr->getEpisodes() as $ep) {
            $dbMap[$ep->getSeason()][$ep->getEpisodeNumber()] = true;
        }

        $extra = setDiff($dbMap, $kMap);    // лишние в БД
        $missing = setDiff($kMap, $dbMap);  // не хватает в БД

        $same = !$extra && !$missing;
        $mark = $same ? '✓' : '✗';
        $same ? ++$totOk : ++$totBad;

        if ($noDetails && !$all) {
            // всё равно печатаем, детализация мелкая
        }

        $line = sprintf(
            '   %s %-24s [%s] kodik %s | db %s',
            $mark,
            mb_substr($tr->getName(), 0, 24),
            $material,
            fmtCoverage($kMap),
            fmtCoverage($dbMap)
        );
        echo $line."\n";
        if (!$same) {
            if ($extra) {
                echo '        лишние в БД: '.fmtCoverage($extra)."\n";
            }
            if ($missing) {
                echo '        не хватает:  '.fmtCoverage($missing)."\n";
            }
        }

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    $em->clear();
    if ($all && 0 === ($ti % 100)) {
        fwrite(STDERR, sprintf("[%d/%d] проверено озвучек: %d, расхождений: %d\n", $ti + 1, count($titleIds), $totVoices, $totBad));
    }
    echo "\n";
}

echo "=== Итого ===\n";
printf("Озвучек проверено: %d\n", $totVoices);
printf("Совпало:           %d\n", $totOk);
printf("Расхождений:       %d\n", $totBad);
printf("Ошибок:            %d\n", $totErr);
