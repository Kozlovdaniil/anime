<?php

namespace App\Service\Kodik;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Клиент публичного API https://kodikapi.com
 *
 * Эндпоинты:
 *   /search    — поиск по названию/фильтрам (title, type, year, page, limit)
 *   /info      — полная информация по id
 *   /seasons   — список сезонов сериала
 *   /episodes  — серии сезона (id, season, translation_id)
 *
 * Все запросы идут через Symfony HttpClient с токеном из KODIK_API_TOKEN.
 */
final class KodikClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'KODIK_API_TOKEN')]
        private readonly string $token,
        #[Autowire(env: 'KODIK_API_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'KODIK_API_PAGE_SIZE')]
        private readonly int $pageSize = 50,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->token && 'CHANGE_ME' !== $this->token;
    }

    /**
     * Поиск. Допустимые фильтры: title, type (movie|cartoon|serial|anime...), year, page.
     *
     * @return array{total: int, results: list<array>}
     */
    public function search(array $filters = []): array
    {
        return $this->get('search', array_filter([
            'title' => $filters['title'] ?? null,
            'type' => $filters['type'] ?? null,
            'year' => $filters['year'] ?? null,
            'page' => $filters['page'] ?? null,
            'limit' => $filters['limit'] ?? $this->pageSize,
        ], static fn ($v) => null !== $v && '' !== $v));
    }

    /** Полная информация о тайтле по kodik id. */
    public function info(string $kodikId): array
    {
        $data = $this->get('info', ['id' => $kodikId]);

        return $data;
    }

    /**
     * Сезоны сериала.
     *
     * @return list<array> каждый элемент: id/season/number + название
     */
    public function seasons(string $kodikId): array
    {
        $data = $this->get('seasons', ['id' => $kodikId]);

        return $data['results'] ?? $data;
    }

    /**
     * Серии сезона.
     *
     * @return list<array> каждый элемент: id, number/episode, name, link
     */
    public function episodes(string $kodikId, int|string $season, ?string $translationId = null): array
    {
        $data = $this->get('episodes', array_filter([
            'id' => $kodikId,
            'season' => $season,
            'translation_id' => $translationId,
        ], static fn ($v) => null !== $v));

        return $data['results'] ?? $data;
    }

    private function get(string $path, array $query): array
    {
        if (!$this->isConfigured()) {
            throw new KodikApiException(
                'KODIK_API_TOKEN не задан. Получите токен на kodikapi.com и пропишите его в .env (или .env.local).'
            );
        }

        $response = $this->http->request('GET', rtrim($this->baseUrl, '/').'/'.$path, [
            'query' => array_merge(['token' => $this->token], $query),
            'timeout' => 20,
        ]);

        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $data = json_decode($content, true);

        if (!\is_array($data)) {
            throw new KodikApiException(sprintf('Kodik API: некорректный ответ (%d): %s', $status, mb_substr($content, 0, 200)));
        }
        if (isset($data['error'])) {
            throw new KodikApiException('Kodik API: '.(is_array($data['error']) ? json_encode($data['error'], JSON_UNESCAPED_UNICODE) : $data['error']));
        }
        if ($status >= 400) {
            throw new KodikApiException(sprintf('Kodik API: HTTP %d', $status));
        }

        return $data;
    }
}
