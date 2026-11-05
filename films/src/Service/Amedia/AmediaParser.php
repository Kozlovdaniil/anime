<?php

namespace App\Service\Amedia;

/**
 * Разбор HTML страниц сайта amd.online (Animedia, DLE).
 *
 * Извлекает:
 *  - элементы каталога/поиска (ссылка, название, постер, рейтинг);
 *  - полную карточку тайтла: метаданные, доступные плееры (amedia/cdn/kodik),
 *    список серий с iframe-ссылками и озвучки Kodik (кнопки .amd-kodik-voice).
 *
 * Класс чистый (без сети) — удобно тестировать на сохранённом HTML.
 */
final class AmediaParser
{
    /**
     * Разбор страницы каталога/поиска.
     *
     * @return list<array{url: string, title: string, poster: ?string, rating: ?string, episodesHint: ?string}>
     */
    public function parseListing(string $html, string $baseUrl): array
    {
        $xp = $this->xpath($html);
        if (null === $xp) {
            return [];
        }

        return $this->listingItems($xp, $baseUrl);
    }

    /**
     * Страница каталога/поиска вместе с пагинацией (один разбор HTML).
     *
     * @return array{
     *     items: list<array{url: string, title: string, poster: ?string, rating: ?string, episodesHint: ?string}>,
     *     pagination: array{current: int, total: int, next: ?string, hasNext: bool}
     * }
     */
    public function parseListingPage(string $html, string $baseUrl): array
    {
        $xp = $this->xpath($html);
        if (null === $xp) {
            return ['items' => [], 'pagination' => $this->emptyPagination()];
        }

        return [
            'items' => $this->listingItems($xp, $baseUrl),
            'pagination' => $this->pagination($xp, $baseUrl),
        ];
    }

    /**
     * Пагинация списка: блок #pagination (DLE-тема amd.online).
     *
     * Номера страниц — прямые <a>/<span> внутри .pagination__pages,
     * текущая страница отмечена <span>, «вперёд» — ссылка в .pagination__pages-btn.
     *
     * @return array{current: int, total: int, next: ?string, hasNext: bool}
     */
    public function parsePagination(string $html, string $baseUrl): array
    {
        $xp = $this->xpath($html);

        return null === $xp ? $this->emptyPagination() : $this->pagination($xp, $baseUrl);
    }

    /* ---------------- каталог/поиск ---------------- */

    /**
     * @return list<array{url: string, title: string, poster: ?string, rating: ?string, episodesHint: ?string}>
     */
    private function listingItems(\DOMXPath $xp, string $baseUrl): array
    {
        // Главная: явный блок «Новые аниме» (poster + своя пагинация).
        // Каталог/поиск: результаты DLE живут в #dle-content; на главной — запасной вариант.
        $nodes = $xp->query(
            "//*[@data-home-block='new_anime']//div[contains(concat(' ', normalize-space(@class), ' '), ' poster ')]"
        );
        if (!$nodes || 0 === $nodes->length) {
            $nodes = $xp->query("//*[@id='dle-content']//div[contains(concat(' ', normalize-space(@class), ' '), ' poster ')]");
        }
        if (!$nodes || 0 === $nodes->length) {
            $nodes = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' poster ') and contains(@class, 'grid-item')]");
        }

        $items = [];
        /** @var \DOMNode $node */
        foreach ($nodes ?: [] as $node) {
            $link = $xp->query('./descendant::a[contains(@class, "poster__link")]/@href', $node)->item(0);
            $title = $xp->query('.//h3[contains(@class, "poster__title")]', $node)->item(0);
            if (null === $link || null === $title) {
                continue;
            }

            $poster = $xp->query('.//img/@src', $node)->item(0);
            $rating = $xp->query('.//*[contains(@class, "item__rating")]', $node)->item(0);
            $hint = $xp->query('.//*[contains(@class, "vysser")]', $node)->item(0);

            $url = $this->absolute(trim($link->nodeValue ?? ''), $baseUrl);
            $name = $this->clean($title->nodeValue ?? '');
            if ('' === $url || '' === $name) {
                continue;
            }

            $items[] = [
                'url' => $url,
                'title' => $name,
                'poster' => null !== $poster ? $this->absolute(trim($poster->nodeValue ?? ''), $baseUrl) : null,
                'rating' => null !== $rating ? $this->clean($rating->nodeValue) : null,
                'episodesHint' => null !== $hint ? $this->clean($hint->nodeValue) : null,
            ];
        }

        return $items;
    }

    /**
     * @return array{current: int, total: int, next: ?string, hasNext: bool}
     */
    private function pagination(\DOMXPath $xp, string $baseUrl): array
    {
        // Номерные ссылки/метка текущей страницы (без кнопок «вперёд/назад»).
        $numbers = $xp->query(
            "//*[@id='pagination']//*[contains(concat(' ', normalize-space(@class), ' '), ' pagination__pages ')"
            ." and not(contains(@class, 'pagination__pages-btn'))]/*[self::a or self::span]"
        );

        $current = 1;
        $total = 1;

        foreach ($numbers ?: [] as $node) {
            $text = $this->clean($node->nodeValue);
            if (!preg_match('/^\d+$/', $text)) {
                continue; // разделитель «...» (span.nav_ext)
            }

            $number = (int) $text;
            $total = max($total, $number);
            if ('span' === strtolower($node->nodeName)) {
                $current = $number; // текущая страница отмечена span
            }
        }

        // Кнопка «вперёд»: на последней странице вместо ссылки стоит span — это значит, next нет.
        $nextNode = $xp->query(
            "//*[@id='pagination']//*[contains(@class, 'pagination__pages-btn')]//a/@href"
        )->item(0);
        $href = null !== $nextNode ? trim($nextNode->nodeValue ?? '') : '';
        $next = '' !== $href ? $this->absolute($href, $baseUrl) : null;

        return [
            'current' => $current,
            'total' => max($current, $total),
            'next' => $next,
            'hasNext' => null !== $next,
        ];
    }

    /** @return array{current: int, total: int, next: ?string, hasNext: bool} */
    private function emptyPagination(): array
    {
        return ['current' => 1, 'total' => 1, 'next' => null, 'hasNext' => false];
    }

    /**
     * Разбор карточки тайтла.
     *
     * @return array{
     *     newsId: int, url: string, title: string, originalTitle: ?string, poster: ?string,
     *     description: ?string, genres: list<string>, year: ?int, type: string, season: int,
     *     episodeTotal: ?string, players: list<string>, episodes: list<array{number: int, link: ?string, url: ?string}>,
     *     amediaLink: ?string, kodikLink: ?string, kodikVoices: list<array{id: string, name: string, src: string}>
     * }
     */
    public function parseTitle(string $html, string $url): array
    {
        $xp = $this->xpath($html);
        if (null === $xp) {
            throw new AmediaException('Amedia: не удалось разобрать HTML страницы '.$url);
        }

        $baseUrl = $this->origin($url);

        $newsId = $this->newsId($xp, $html, $url);
        $episodes = $this->episodes($xp, $baseUrl);

        return [
            'newsId' => $newsId,
            'url' => $url,
            'title' => $this->title($xp, $html, $url),
            'originalTitle' => $this->firstText($xp, [
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' amd-sub ')]",
            ]),
            'poster' => $this->poster($xp, $html, $baseUrl),
            'description' => $this->firstText($xp, ['//*[@id="amd-desc"]']),
            'genres' => $this->genres($xp),
            'year' => $this->year($xp),
            'type' => $this->type($xp, [] !== $episodes),
            'season' => $this->season($xp, $html),
            'episodeTotal' => $this->episodeTotal($xp),
            'players' => $this->players($xp),
            'episodes' => $episodes,
            'amediaLink' => $this->firstAttr($xp, [
                "//iframe[contains(@class, 'frame_video_mod')]/@src",
            ], $baseUrl),
            'kodikLink' => $this->firstAttr($xp, [
                "//iframe[contains(@class, 'amd-kodik-iframe')]/@data-src",
                "//iframe[contains(@class, 'amd-kodik-iframe')]/@src",
            ], $baseUrl),
            'kodikVoices' => $this->kodikVoices($xp, $baseUrl),
        ];
    }

    /* ---------------- утилиты разбора ---------------- */

    private function xpath(string $html): ?\DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Принудительный UTF-8, подавляем ошибки malformed HTML
        $dom->loadHTML(
            "<?xml encoding=\"utf-8\" ?><meta charset=\"utf-8\" />".$html,
            \LIBXML_NONET | \LIBXML_NOWARNING | \LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (null === $dom->documentElement) {
            return null;
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('h', 'http://www.w3.org/1999/xhtml');

        return $xp;
    }

    /* ---------------- карточка тайтла ---------------- */

    private function newsId(\DOMXPath $xp, string $html, string $url): int
    {
        foreach ([
            "//*[@data-news-id]/@data-news-id",
            "//*[contains(@class, 'amd-player-note')]/@data-news-id",
        ] as $expr) {
            $value = $xp->query($expr)->item(0);
            if (null !== $value && preg_match('/\d+/', $value->nodeValue ?? '', $m)) {
                return (int) $m[0];
            }
        }

        // canonical или сам URL: /5768-psajren.html
        $canonical = $xp->query("//link[@rel='canonical']/@href")->item(0);
        $target = null !== $canonical ? ($canonical->nodeValue ?? '') : $url;
        if (preg_match('#/(\d+)-#', $target, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/"news_id"\s*:\s*(\d+)/', $html, $m)) {
            return (int) $m[1];
        }

        throw new AmediaException('Amedia: не удалось определить id тайтла на странице '.$url);
    }

    /** @return list<string> */
    private function players(\DOMXPath $xp): array
    {
        $players = [];
        $nodes = $xp->query("//div[contains(@class, 'tabs-block__select')]//button[@data-player]");
        foreach ($nodes ?: [] as $node) {
            $name = trim($node->getAttribute('data-player'));
            if ('' !== $name && !\in_array($name, $players, true)) {
                $players[] = $name;
            }
        }

        return $players;
    }

    /**
     * Список серий: первый блок .kontaiher (списки дублируются в каждом табе плеера).
     *
     * @return list<array{number: int, link: ?string, url: ?string}>
     */
    private function episodes(\DOMXPath $xp, string $baseUrl): array
    {
        $episodes = [];
        $nodes = $xp->query("(//div[@id='elementb'])[1]/a");
        if (!$nodes || 0 === $nodes->length) {
            $nodes = $xp->query("//a[contains(@class, 'nav_video_links')]");
        }

        foreach ($nodes ?: [] as $node) {
            $number = (int) $node->getAttribute('data-vid');
            if ($number <= 0 && preg_match('/\d+/', trim($node->nodeValue ?? ''), $m)) {
                $number = (int) $m[0];
            }
            if ($number <= 0) {
                continue;
            }

            $vlnk = trim($node->getAttribute('data-vlnk'));
            $href = trim($node->getAttribute('href'));

            $existing = $episodes[$number] ?? null;
            $link = '' !== $vlnk ? $this->absolute($vlnk, $baseUrl) : null;
            if (null === $existing || (null === $existing['link'] && null !== $link)) {
                $episodes[$number] = [
                    'number' => $number,
                    'link' => $link,
                    'url' => '' !== $href ? $this->absolute($href, $baseUrl) : null,
                ];
            }
        }

        ksort($episodes);

        return array_values($episodes);
    }

    /**
     * Озвучки Kodik (кнопки .amd-kodik-voice в табе Kodik).
     *
     * @return list<array{id: string, name: string, src: string}>
     */
    private function kodikVoices(\DOMXPath $xp, string $baseUrl): array
    {
        $voices = [];
        $nodes = $xp->query("//button[contains(@class, 'amd-kodik-voice')]");
        foreach ($nodes ?: [] as $node) {
            $id = trim($node->getAttribute('data-voice'));
            $src = trim($node->getAttribute('data-kodik-src'));
            if ('' === $src) {
                $src = trim($node->getAttribute('data-src'));
            }
            if ('' === $src) {
                continue;
            }
            if ('' === $id) {
                $id = md5($src);
            }
            if (isset($voices[$id])) {
                continue;
            }

            $voices[$id] = [
                'id' => $id,
                'name' => $this->clean($node->textContent),
                'src' => $this->absolute($src, $baseUrl),
            ];
        }

        return array_values($voices);
    }

    private function title(\DOMXPath $xp, string $html, string $url): string
    {
        $node = $xp->query("//div[contains(@class, 'amd-title')]//h1")->item(0)
            ?? $xp->query('//h1')->item(0);
        $title = $this->clean($node?->nodeValue ?? '');
        if ('' !== $title) {
            return $title;
        }

        if (preg_match('/<meta property="og:title" content="«([^»]+)»/u', $html, $m)) {
            return trim($m[1]);
        }

        throw new AmediaException('Amedia: не найдено название на странице '.$url);
    }

    private function poster(\DOMXPath $xp, string $html, string $baseUrl): ?string
    {
        $node = $xp->query(
            "//div[contains(@class, 'amd-poster')]//img[contains(@alt, 'постер')]/@src"
        )->item(0)
            ?? $xp->query("//meta[@property='og:image']/@content")->item(0);

        if (null !== $node && '' !== trim($node->nodeValue ?? '')) {
            return $this->absolute(trim($node->nodeValue), $baseUrl);
        }

        return null;
    }

    /** @return list<string> */
    private function genres(\DOMXPath $xp): array
    {
        $genres = [];
        $nodes = $xp->query("//div[contains(@class, 'amd-tags')]/a");
        foreach ($nodes ?: [] as $node) {
            $name = $this->clean($node->nodeValue);
            if ('' !== $name) {
                $genres[] = $name;
            }
        }

        return $genres;
    }

    private function year(\DOMXPath $xp): ?int
    {
        $node = $xp->query(
            "//div[contains(@class, 'amd-meta')]//a[contains(@href, '/anime/god/')]"
        )->item(0);
        if (null !== $node && preg_match('/\d{4}/', $node->nodeValue ?? '', $m)) {
            return (int) $m[0];
        }

        return null;
    }

    /** movie | serial | ova | ona | movie */
    private function type(\DOMXPath $xp, bool $hasEpisodes): string
    {
        $node = $xp->query(
            "//div[contains(@class, 'amd-meta')]//a[contains(@href, '/anime/tip/')]"
        )->item(0);

        $slug = '';
        if (null !== $node) {
            $href = rawurldecode($node->getAttribute('href'));
            if (preg_match('#/anime/tip/([^/]+)/?#', $href, $m)) {
                $slug = mb_strtolower(trim($m[1]));
            }
            if ('' === $slug) {
                $slug = mb_strtolower($this->clean($node->nodeValue));
            }
        }

        return match (true) {
            str_contains($slug, 'сериал') => 'serial',
            str_contains($slug, 'фильм') || str_contains($slug, 'movie') => 'movie',
            'ova' === $slug => 'ova',
            'ona' === $slug => 'ona',
            'спешл' === $slug || 'special' === $slug => 'ova',
            default => $hasEpisodes ? 'serial' : 'movie',
        };
    }

    private function season(\DOMXPath $xp, string $html): int
    {
        // <video-player season="3" ...> на странице тайтла
        $node = $xp->query('//video-player/@season')->item(0);
        if (null !== $node && (int) $node->nodeValue > 0) {
            return (int) $node->nodeValue;
        }

        // og:title: «...» (3 сезон) аниме смотреть онлайн
        if (preg_match('/\((\d+)\s*сезон\)/u', $html, $m)) {
            return (int) $m[1];
        }

        // season=N в ссылке Kodik
        if (preg_match('/[?&]season=(\d+)/', $html, $m)) {
            return (int) $m[1];
        }

        return 1;
    }

    private function episodeTotal(\DOMXPath $xp): ?string
    {
        $node = $xp->query("//div[contains(@class, 'amd-vser')]")->item(0);
        if (null === $node) {
            return null;
        }
        $text = $this->clean($node->nodeValue);

        return '' !== $text ? $text : null;
    }

    /* ---------------- утилиты ---------------- */

    private function firstText(\DOMXPath $xp, array $expressions): ?string
    {
        foreach ($expressions as $expr) {
            $node = $xp->query($expr)->item(0);
            $text = $this->clean($node?->nodeValue ?? '');
            if ('' !== $text) {
                return $text;
            }
        }

        return null;
    }

    private function firstAttr(\DOMXPath $xp, array $expressions, string $baseUrl): ?string
    {
        foreach ($expressions as $expr) {
            $node = $xp->query($expr)->item(0);
            $value = trim($node?->nodeValue ?? '');
            if ('' !== $value) {
                return $this->absolute($value, $baseUrl);
            }
        }

        return null;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return (($parts['scheme'] ?? 'https').'://').($parts['host'] ?? 'amd.online');
    }

    /** Делает ссылку абсолютной (поддерживает //host/, /path, относительные). */
    private function absolute(string $url, string $baseUrl): string
    {
        if ('' === $url) {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return rtrim($baseUrl, '/').'/'.ltrim($url, '/');
    }

    private function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
