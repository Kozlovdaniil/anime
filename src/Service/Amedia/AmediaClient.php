<?php

namespace App\Service\Amedia;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент сайта amd.online (Animedia Online, DataLife Engine).
 *
 * Сайт не имеет публичного API — разбираем HTML:
 *   /                          — каталог по дате добавления (пагинация /page/N/)
 *   /f/sort=editdate/order=desc/ — каталог по дате редактирования (DLE-фильтр)
 *   /anime/tip/{тип}/          — фильтр по типу (фильм, тв-сериал, ova, ona)
 *   /anime/god/{год}/          — фильтр по году
 *   /index.php?do=search&story= — поиск
 *   /{id}-{slug}.html          — карточка тайтла (плееры и серии внутри)
 *
 * Сайт отдаёт 404-страницу без браузерного User-Agent — всегда шлём свой.
 */
final class AmediaClient
{
    /**
     * Каталог целиком, отсортированный по дате редактирования (свежие апдейты
     * первыми). Путь DLE-фильтра amd.online: `sort=editdate` — «По дате
     * редактирования», `order=desc` — от новых к старым.
     *
     * Нужен синку: без сортировки сайт отдаёт каталог по дате добавления, и
     * только что вышедшие серии могли оказаться глубоко в пагинации (а при
     * ограничении `--max-pages` — вообще не попасть в импорт).
     */
    public const CATALOG_PATH_BY_EDITDATE = '/f/sort=editdate/order=desc/';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly AmediaParser $parser,
        #[Autowire(env: 'AMD_ONLINE_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'AMD_ONLINE_USER_AGENT')]
        private readonly string $userAgent,
        /** Пауза между запросами, мс, чтобы не нагружать сайт. */
        #[Autowire(env: 'AMD_ONLINE_DELAY_MS')]
        private readonly int $delayMs = 250,
    ) {
    }

    /**
     * Страница каталога (path вида '/', '/anime/tip/фильм/').
     *
     * @return list<array{url: string, title: string, poster: ?string, rating: ?string, episodesHint: ?string}>
     */
    public function fetchListing(string $path = '/', int $page = 1): array
    {
        return $this->fetchListingPage($path, $page)['items'];
    }

    /**
     * Страница каталога вместе с пагинацией (current/total/next).
     *
     * @return array{
     *     items: list<array{url: string, title: string, poster: ?string, rating: ?string, episodesHint: ?string}>,
     *     pagination: array{current: int, total: int, next: ?string, hasNext: bool}
     * }
     */
    public function fetchListingPage(string $path = '/', int $page = 1): array
    {
        return $this->fetchPage($this->catalogUrl($path, $page));
    }

    /**
     * Загружает произвольный URL списка (например, next-страницу пагинации) и разбирает его.
     *
     * @return array{
     *     items: list<array{url: string, title: string, poster: ?string, rating: ?string, episodesHint: ?string}>,
     *     pagination: array{current: int, total: int, next: ?string, hasNext: bool}
     * }
     */
    public function fetchPage(string $url): array
    {
        return $this->parser->parseListingPage($this->getHtml($url), $this->baseUrl);
    }

    /**
     * Поиск по сайту.
     *
     * @return list<array{url: string, title: string, poster: ?string, rating: ?string, episodesHint: ?string}>
     */
    public function fetchSearch(string $query): array
    {
        $url = $this->baseUrl.'/index.php?'.http_build_query([
            'do' => 'search',
            'subaction' => 'search',
            'story' => $query,
        ]);

        return $this->parser->parseListing($this->getHtml($url), $this->baseUrl);
    }

    /**
     * Карточка тайтла (метаданные, плееры, серии, озвучки Kodik).
     *
     * @return array<string, mixed> см. AmediaParser::parseTitle()
     */
    public function fetchTitle(string $url): array
    {
        $url = $this->normalizeTitleUrl($url);

        return $this->parser->parseTitle($this->getHtml($url), $url);
    }

    /**
     * Принимает полный URL, путь или «5768-psajren.html» и приводит к полному URL.
     */
    public function normalizeTitleUrl(string $urlOrPath): string
    {
        $urlOrPath = trim($urlOrPath);
        if (preg_match('#^https?://#i', $urlOrPath)) {
            return $urlOrPath;
        }

        return $this->baseUrl.'/'.ltrim($urlOrPath, '/');
    }

    private function catalogUrl(string $path, int $page): string
    {
        $path = '/'.ltrim($path, '/');

        return $page <= 1
            ? $this->baseUrl.$path
            : $this->baseUrl.rtrim($path, '/').'/page/'.$page.'/';
    }

    private function getHtml(string $url): string
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $response = $this->http->request('GET', $url, [
            'timeout' => 30,
            'max_redirects' => 5,
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
                'Referer' => $this->baseUrl.'/',
            ],
        ]);

        $status = $response->getStatusCode();
        $html = $response->getContent();

        if ($status >= 400) {
            throw new AmediaException(sprintf('Amedia: HTTP %d для %s', $status, $url));
        }
        if (false === stripos($html, '<html')) {
            throw new AmediaException(sprintf(
                'Amedia: неожиданный ответ (%d, %d байт) для %s',
                $status,
                \strlen($html),
                $url
            ));
        }

        return $html;
    }
}
