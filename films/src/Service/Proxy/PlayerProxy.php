<?php

namespace App\Service\Proxy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Прокси страниц плееров через наш origin (маршрут /player/frame).
 *
 * Зачем: кросс-доменный iframe извне неуправляем — у него нельзя взять
 * время, перемотать на конкретную секунду, включить «во весь экран»
 * и вырезать рекламу внутри. Отдаём HTML плеера со своего сервера, при этом:
 *
 *   1) документ становится «нашим» → внутрь внедряется player-shim.js,
 *      и родительская страница напрямую управляет <video> плеера
 *      (время, перемотка, плей-пауза) и навешивает клавиши;
 *   2) рекламные скрипты/рамки/вставки вырезаются из HTML, а динамически
 *      добавленные рекламные блоки убирает player-shim.js по DOM-селекторам;
 *   3) вложенные iframe плеера (если хост проксируем) переписываются на
 *      наш же прокси (вложенность ограничена).
 *
 * Любая ошибка загрузки → контроллер делает 302 на оригинал,
 * т.е. поведение обычного iframe.
 */
final class PlayerProxy
{
    /** Хосты, которые проксируем (базовый домен, матчится и по поддоменам). */
    private const ALLOWED_HOSTS = [
        'aser.pro',
        'kodikplayer.com',
        'rutube.ru',
        'vkvideo.ru',
    ];

    /**
     * Дополнительные хосты, на которые разрешён 302-фолбэк
     * (не проксируем, но отдаём как обычный iframe).
     */
    private const REDIRECT_EXTRA_HOSTS = [
        'amd.online',
        'vk.com',
    ];

    /** Рекламные/трекинговые домены — их URL вырезаем из HTML. */
    private const AD_URL_RE = '/(doubleclick|googlesyndication|googleadservices|pagead2\.|adsterra|propellerads|popads|popcash|adnxs|criteo|taboola|outbrain|mgid|adfox|adlab|reklama|banman|adserver|adslot|adition|openx|rubiconproject|pubmatic|yieldmanager|yandex\.ru\/ads|adsdk|amedia\.online|kxcdn|serving-sys|buzzoola|ufouxbwn|snsv\.ru|adriver|vidoomy|mc\.yandex\.ru|metri[ck]a)/i';

    /** Рекламные inline-загрузчики (внутри <script> без src). */
    private const AD_INLINE_RE = '/(googlesyndication|adsbygoogle|adsterra|propellerads|popads\.|popcash|mgid\.com|metri[ck]a)/i';

    /**
     * Рекламные имена/адреса в атрибуте src даже без домена
     * (`<script src="/adsbygoogle.js">` на домене плеера).
     */
    private const AD_SCRIPT_SRC_RE = '/(adsbygoogle|advert|vast|adfox|serving-sys|buzzoola|snsv\.ru|ufouxbwn|popunder|\/ads\/|metri[ck]a)/i';

    /**
     * Поля JSON-ответов API плеера, которыми раздаётся реклама
     * (Kodik `POST /ftor`: `vast`, `reserve_vast`, `advert_script`).
     * Вырезаем их на сервере — плееру просто нечего проигрывать.
     */
    private const AD_RESPONSE_KEYS = ['vast', 'reserve_vast', 'advert_script', 'vast_url', 'ad_tag', 'adtag'];

    /**
     * Селекторы рекламных контейнеров — внедряются в страницу как
     * window.__cinemaAdSelectors и вырезаются плеером player-shim.js
     * даже тогда, когда их добавляют скрипты плеера на лету.
     */
    public const AD_SELECTORS = [
        'ins.adsbygoogle',
        '.adsbygoogle',
        '[data-ad-client]',
        '[data-ad-slot]',
        '[id^="yandex_rtb"]',
        '[id^="yandex_ads"]',
        '[class*="reklama"]',
        '[id^="ad-"]',
        '[class^="ad-"]',
        '[class*=" ad-"]',
        '[data-popunder]',
        '.popunder',
        '[id^="banner_"]',
        '[class*="banner-"]',
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'AMD_ONLINE_USER_AGENT')]
        private readonly string $userAgent,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** Плеер этого URL можно проксировать? */
    public function isProxiable(string $url): bool
    {
        return $this->matchHost($url) !== null;
    }

    /** Допустим ли 302-фолбэк на этот URL (прокси или обычный iframe)? */
    public function isRedirectable(string $url): bool
    {
        if (null !== $this->matchHost($url)) {
            return true;
        }
        $host = $this->cleanHost($url);
        foreach (self::REDIRECT_EXTRA_HOSTS as $key) {
            if ($host === $key || str_ends_with($host, '.'.$key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Загрузка страницы плеера браузерным запросом (UA + Referer amd.online:
     * aser.pro отдаёт заглушку «Смотрите на сайте» при чужом Referer).
     *
     * @return array{0: string, 1: string} [HTML, финальный URL после редиректов]
     *
     * @throws PlayerProxyException
     */
    public function fetchHtml(string $url): array
    {
        // Сеть/DNS в контейнере бывает нестабильной (мгновенный сбой резолва),
        // а aser.pro иногда отвечает 429/5xx — повторяем несколько раз.
        $lastError = null;
        $status = 0;
        $html = '';
        $resp = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(700_000);
            }
            try {
                $resp = $this->http->request('GET', $url, [
                    'headers' => [
                        'User-Agent: '.$this->userAgent,
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: ru-RU,ru;q=0.9,en;q=0.8',
                        'Referer: https://amd.online/',
                    ],
                    'max_redirects' => 5,
                    'timeout' => 20,
                ]);
                $status = $resp->getStatusCode();
                $html = $resp->getContent(false);
            } catch (\Throwable $e) {
                $lastError = $e;
                // таймаут не повторяем (суммарно ждать 3×20с — слишком долго),
                // сетевые/DNS-сбои повторяем
                if (preg_match('/timed? ?out|timeout/i', $e->getMessage())) {
                    break;
                }
                continue;
            }

            if ($status >= 500 || $status === 429) {
                $lastError = new \RuntimeException('HTTP '.$status);
                $resp = null;
                continue;
            }

            $lastError = null;
            break;
        }

        if ($lastError !== null || $resp === null) {
            throw new PlayerProxyException('Не удалось загрузить '.$url.': '.$lastError?->getMessage());
        }

        if ($status < 200 || $status >= 300) {
            throw new PlayerProxyException('HTTP '.$status.' для '.$url);
        }
        if (stripos($html, '<html') === false && stripos($html, '<!doctype') === false) {
            throw new PlayerProxyException('Ответ не похож на HTML: '.$url);
        }
        if (strlen($html) < 800) {
            // заглушка «Смотрите на сайте»/анти-бот вместо страницы плеера
            throw new PlayerProxyException('Слишком маленький ответ ('.strlen($html).' байт): '
                .substr(strip_tags($html), 0, 80).' — '.$url);
        }
        if (strlen($html) > 5_000_000) {
            throw new PlayerProxyException('Слишком большой ответ: '.$url);
        }

        $info = $resp->getInfo();
        $finalUrl = $info['redirect_url'] ?? null;
        if (!\is_string($finalUrl) || '' === $finalUrl) {
            $finalUrl = $url;
        }

        return [$html, $finalUrl];
    }

    /**
     * Проксирование AJAX-запросов плеера к его собственному хосту.
     *
     * В обычном iframe документ плеера и его API — один origin, CORS не нужен.
     * У нас документ отдаётся с нашего origin, и такие запросы (например
     * POST /ftor у Kodik) становятся кросс-доменными, а CORS у API нет →
     * браузер режет ответ и видео не грузится. shim заворачивает их сюда,
     * а мы ходим к апстриму с нужным Referer.
     *
     * @param array<string, string> $headers заголовки исходного запроса (lowercase)
     *
     * @return array{0: int, 1: string, 2: string} [status, content-type, body]
     *
     * @throws PlayerProxyException
     */
    public function passthrough(string $url, string $method, array $headers, string $body): array
    {
        if (!$this->isProxiable($url)) {
            throw new PlayerProxyException('Passthrough: хост не разрешён — '.$url);
        }

        // Переносим заголовки исходного запроса (content-type, x-requested-with,
        // range и любые x-* плеера), кроме hop-by-hop и тех, что задаём сами.
        $skip = [
            'host', 'content-length', 'connection', 'accept-encoding',
            'referer', 'origin', 'cookie', 'user-agent',
        ];
        $forward = [
            'User-Agent' => $this->userAgent,
            'Accept' => $headers['accept'] ?? '*/*',
            'Referer' => 'https://amd.online/',
        ];
        foreach ($headers as $name => $value) {
            if ('' === $value || \in_array($name, $skip, true)) {
                continue;
            }
            $forward[$this->headerName($name)] = $value;
        }

        try {
            $resp = $this->http->request(strtoupper($method), $url, [
                'headers' => $forward,
                'body' => $body,
                'max_redirects' => 5,
                'timeout' => 30,
            ]);
            $status = $resp->getStatusCode();
            $respHeaders = $resp->getHeaders(false);
            $content = $resp->getContent(false);
        } catch (\Throwable $e) {
            throw new PlayerProxyException('Passthrough '.$url.': '.$e->getMessage(), 0, $e);
        }

        $contentType = $respHeaders['content-type'][0] ?? 'application/octet-stream';

        return [$status, $contentType, $this->stripApiAds($contentType, $content)];
    }

    /**
     * Вырезание рекламных полей из JSON-ответов API плеера.
     *
     * Kodik отдаёт рекламные ролики через `POST /ftor`: в ответе есть
     * `vast`/`reserve_vast` (VAST-теги ad-сетей) и `advert_script`. Если их
     * убрать, плееру нечего проигрывать — реклама не появляется вовсе,
     * в отличие от блокировки отдельных ad-URL уже в браузере.
     */
    private function stripApiAds(string $contentType, string $content): string
    {
        if ('' === $content || stripos($contentType, 'json') === false) {
            return $content;
        }

        try {
            $data = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $content;
        }

        if (!$this->stripAdKeys($data)) {
            return $content;
        }

        $json = json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $json ? $content : $json;
    }

    /** Рекурсивно удаляет рекламные ключи. Возвращает true, если что-то удалено. */
    private function stripAdKeys(mixed $node): bool
    {
        $changed = false;

        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                if (\in_array(strtolower($key), self::AD_RESPONSE_KEYS, true)) {
                    unset($node->$key);
                    $changed = true;
                    continue;
                }
                if (\is_array($value) || $value instanceof \stdClass) {
                    $changed = $this->stripAdKeys($value) || $changed;
                }
            }
        } elseif (\is_array($node)) {
            foreach ($node as $value) {
                if (\is_array($value) || $value instanceof \stdClass) {
                    $changed = $this->stripAdKeys($value) || $changed;
                }
            }
        }

        return $changed;
    }

    /** 'content-type' → 'Content-Type'. */
    private function headerName(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', $name)));
    }

    /**
     * Раскодирование HTML-сущности `&amp;` (и её вариантов) в URL.
     *
     * Ссылки, которые мы вытаскиваем из HTML регулярками (атрибуты src/href
     * вложенных iframe), приходят в исходном виде — с `&amp;`. Если передать их
     * дальше как есть, апстрим получает битый параметр (`&amp;episode=1`),
     * а его редиректы и `<base>` дальше распространяют `&amp;` по всем URL
     * плеера — «после перехода по ссылке URL с &amp;».
     */
    public static function decodeUrlEntities(string $url): string
    {
        return str_replace(['&amp;', '&AMP;', '&#38;', '&#x26;', '&#X26;'], '&', $url);
    }

    /**
     * Переписывание HTML: вырезаем рекламу, проксируем вложенные iframe,
     * внедряем player-shim.js + <base>.
     */
    public function rewrite(string $html, string $finalUrl, int $depth): string
    {
        $html = $this->stripAds($html);
        $html = $this->rewriteNestedFrames($html, $finalUrl, $depth);

        // Хост самого плеера — по нему shim заворачивает AJAX плеера к себе
        // на наш /player/api (у их API нет CORS, см. passthrough()).
        $playerHost = strtolower((string) parse_url($finalUrl, \PHP_URL_HOST));
        $playerHost = (string) preg_replace('/^www\./', '', $playerHost);

        // shim версионируется по mtime: HTML отдаём no-store, а сам скрипт
        // иначе залипает в кеше браузера и обновления не подхватываются.
        $shim = '/js/player-shim.js';
        $shimFile = $this->projectDir.'/public/js/player-shim.js';
        if (is_file($shimFile)) {
            $shim .= '?v='.filemtime($shimFile);
        }

        // Порядок важен: <script src="/js/…"> — ДО <base>, иначе относительный
        // URL уедет на домен плеера; <base> — в самом начале head, чтобы
        // контент плеера разрешился против его собственного адреса.
        $injection = '<script>window.__cinemaPlayerHost='.json_encode($playerHost).';</script>'
            .'<script src="'.htmlspecialchars($shim, \ENT_QUOTES).'"></script>'
            .'<script>window.__cinemaAdSelectors='
            .json_encode(self::AD_SELECTORS, \JSON_UNESCAPED_SLASHES|\JSON_UNESCAPED_UNICODE).';</script>'
            .'<base href="'.htmlspecialchars($finalUrl, \ENT_QUOTES).'">';

        if (preg_match('/<head\b[^>]*>/i', $html, $m, \PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + \strlen($m[0][0]);
        } elseif (preg_match('/<html\b[^>]*>/i', $html, $m, \PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + \strlen($m[0][0]);
        } else {
            return $injection.$html;
        }

        return substr($html, 0, $pos).$injection.substr($html, $pos);
    }

    /* ------------------------------------------------------------------ */

    /** Вырезание рекламных скриптов/рамок/вставок. */
    private function stripAds(string $html): string
    {
        // <script src="…реклама…"> или inline-загрузчик рекламы
        $html = preg_replace_callback('#<script\b([^>]*)>(.*?)</script>#is', function ($m) {
            if (preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#i', $m[1], $u)
                && (preg_match(self::AD_URL_RE, $u[2]) || preg_match(self::AD_SCRIPT_SRC_RE, $u[2]))) {
                return '';
            }
            if (preg_match(self::AD_INLINE_RE, $m[2])) {
                return '';
            }

            return $m[0];
        }, $html) ?? $html;

        // <link href="…реклама…">
        $html = preg_replace_callback('#<link\b[^>]*>#i', function ($m) {
            return preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#i', $m[0], $u) && preg_match(self::AD_URL_RE, $u[2])
                ? ''
                : $m[0];
        }, $html) ?? $html;

        // <iframe src="…реклама…">
        $html = preg_replace_callback('#<iframe\b([^>]*)>(.*?)</iframe>#is', function ($m) {
            return preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#i', $m[1], $u) && preg_match(self::AD_URL_RE, $u[2])
                ? ''
                : $m[0];
        }, $html) ?? $html;

        // <embed>/<object> с рекламными URL
        $html = preg_replace_callback('#<(?:embed|object)\b[^>]*>#i', function ($m) {
            return preg_match('#\b(?:src|data)\s*=\s*(["\'])(.*?)\1#i', $m[0], $u) && preg_match(self::AD_URL_RE, $u[2])
                ? ''
                : $m[0];
        }, $html) ?? $html;

        // рекламные вставки <ins>/<amp-ad>
        $html = preg_replace('#<(?:ins|amp-ad)\b.*?</(?:ins|amp-ad)>#is', '', $html) ?? $html;

        // <noscript> с рекламным/трекинговым URL (пиксель Метрики и т.п.)
        $html = preg_replace_callback('#<noscript\b[^>]*>.*?</noscript>#is', function ($m) {
            return preg_match(self::AD_URL_RE, $m[0]) ? '' : $m[0];
        }, $html) ?? $html;

        // Настройки рекламного модуля самого плеера (Kodik): выключаем.
        $html = preg_replace('/forceAdvertCount\s*=\s*true/i', 'forceAdvertCount = false', $html) ?? $html;

        return $html;
    }

    /** Вложенные iframe проксируемых хостов → наш прокси (с ограничением глубины). */
    private function rewriteNestedFrames(string $html, string $finalUrl, int $depth): string
    {
        if ($depth >= 2) {
            return $html;
        }

        return preg_replace_callback('#<iframe\b([^>]*)>#i', function ($m) use ($finalUrl, $depth) {
            if (!preg_match('#\bsrc\s*=\s*(["\'])(.*?)\1#i', $m[1], $u)) {
                return $m[0];
            }
            // src берём из сырого HTML регуляркой — в нём ещё HTML-сущности,
            // поэтому &amp; раскодируем до построения нашего прокси-URL.
            $src = $this->absoluteUrl(self::decodeUrlEntities($u[2]), $finalUrl);
            if (!$this->isProxiable($src)) {
                return $m[0];
            }
            $proxied = '/player/frame?url='.rawurlencode($src).'&d='.($depth + 1);

            return '<iframe'.preg_replace('#(\bsrc\s*=\s*)(["\']).*?\2#i', '$1"'.$proxied.'"', $m[1]).'>';
        }, $html) ?? $html;
    }

    private function absoluteUrl(string $src, string $base): string
    {
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }
        if (str_starts_with($src, '//')) {
            return 'https:'.$src;
        }
        $scheme = parse_url($base, \PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($base, \PHP_URL_HOST) ?: '';
        if ('' === $host) {
            return $src;
        }
        if (str_starts_with($src, '/')) {
            return $scheme.'://'.$host.$src;
        }
        $path = (string) (parse_url($base, \PHP_URL_PATH) ?: '/');
        $slash = strrpos($path, '/');
        $dir = false === $slash ? '/' : substr($path, 0, $slash + 1);

        return $scheme.'://'.$host.$dir.$src;
    }

    private function matchHost(string $url): ?string
    {
        $host = $this->cleanHost($url);
        if ('' === $host) {
            return null;
        }
        foreach (self::ALLOWED_HOSTS as $key) {
            if ($host === $key || str_ends_with($host, '.'.$key)) {
                return $key;
            }
        }

        return null;
    }

    private function cleanHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));

        return (string) preg_replace('/^www\./', '', $host);
    }
}
