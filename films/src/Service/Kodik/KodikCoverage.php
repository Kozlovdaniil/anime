<?php

namespace App\Service\Kodik;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Точное покрытие озвучки — какие серии у неё реально есть — по Kodik API.
 *
 * На странице тайтла amd в табе Kodik перечислены только озвучки, привязанные
 * к первой серии; раздавать их на все серии нельзя (у каждой озвучки свой
 * набор). Поэтому для новой озвучки спрашиваем /search?id=<material> с
 * with_episodes=true: Kodik отдаёт сезоны и серии материала, по ним и строим
 * эпизоды озвучки (season >= 1; спешлы/сезон 0 игнорируем).
 *
 * Kodik недоступен, токен мёртв, ответ мусорный — НЕ бросаем исключение,
 * возвращаем null. Вызывающий код в этом случае оставляет озвучку с грубым
 * (amd) набором серий.
 */
final class KodikCoverage
{
    /** Откуда брать свежий публичный токен, если .env-токен не работает. */
    private const TOKENS_URL = 'https://raw.githubusercontent.com/YaNesyTortiK/AnimeParsers/refs/heads/main/kdk_tokns/tokens.json';

    private ?string $token = null;

    /** Рабочий токен получить не удалось — за этот процесс больше не пытаемся. */
    private bool $tokenUnavailable = false;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'KODIK_API_TOKEN')]
        private readonly string $envToken = '',
        #[Autowire(env: 'KODIK_API_BASE_URL')]
        private readonly string $baseUrl = 'https://kodik-api.com',
    ) {
    }

    /**
     * Покрытие материала озвучки.
     *
     * @param string $material id материала в Kodik: "serial-123" / "movie-123"
     *
     * @return array<int, array<int, true>>|null [season => [episode => true]]; null — не смогли получить
     */
    public function fetch(string $material): ?array
    {
        $token = $this->resolveToken();
        if (null === $token) {
            return null;
        }

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $json = $this->apiGet($token, ['id' => $material, 'with_episodes' => 'true', 'limit' => 1]);
            if (null === $json) {
                return null; // сеть/мусор — не трогаем
            }
            if ($this->isTokenError($json)) {
                $this->token = null; // попробуем получить свежий токен
                $fresh = $this->resolveToken();
                if (null === $fresh || $fresh === $token) {
                    return null;
                }
                $token = $fresh;
                continue;
            }
            if (isset($json['error'])) {
                return null; // прочая ошибка API
            }

            $item = $json['results'][0] ?? null;
            if (!is_array($item)) {
                return null;
            }

            return $this->mapCoverage((array) ($item['seasons'] ?? []));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $seasons
     *
     * @return array<int, array<int, true>>
     */
    private function mapCoverage(array $seasons): array
    {
        $coverage = [];
        foreach ($seasons as $seasonNo => $season) {
            $seasonNo = (int) $seasonNo;
            if ($seasonNo < 1) {
                continue; // спешлы/сезон 0 не заводим
            }
            foreach ((array) ($season['episodes'] ?? []) as $episodeNo => $_) {
                $episodeNo = (int) $episodeNo;
                if ($episodeNo < 1) {
                    continue;
                }
                $coverage[$seasonNo][$episodeNo] = true;
            }
        }

        return $coverage;
    }

    private function resolveToken(): ?string
    {
        if (null !== $this->token) {
            return $this->token;
        }
        if ($this->tokenUnavailable) {
            return null;
        }

        $env = trim($this->envToken);
        if ('' !== $env && 'CHANGE_ME' !== $env && $this->tokenWorks($env)) {
            return $this->token = $env;
        }

        $fresh = $this->tokenFromTokensJson();
        if (null === $fresh) {
            $this->tokenUnavailable = true;
        }

        return $this->token = $fresh;
    }

    private function tokenFromTokensJson(): ?string
    {
        $body = $this->httpGet(self::TOKENS_URL);
        $data = null !== $body ? json_decode($body, true) : null;
        if (!is_array($data)) {
            return null;
        }

        foreach (['stable', 'unstable', 'legacy'] as $group) {
            foreach ((array) ($data[$group] ?? []) as $item) {
                $candidate = $this->decodeToken((string) ($item['tokn'] ?? ''));
                if ('' !== $candidate && $this->tokenWorks($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function tokenWorks(string $token): bool
    {
        $json = $this->apiGet($token, ['title' => 'Наруто', 'limit' => 1]);

        return is_array($json) && !isset($json['error']);
    }

    private function decodeToken(string $t): string
    {
        if ('' === $t) {
            return '';
        }
        $n = intdiv(strlen($t), 2);
        $p1 = base64_decode(strrev(substr($t, 0, $n))) ?: '';
        $p2 = base64_decode(strrev(substr($t, $n))) ?: '';

        return $p2.$p1;
    }

    private function isTokenError(?array $json): bool
    {
        if (!is_array($json) || !isset($json['error'])) {
            return false;
        }
        $error = $json['error'];
        if (is_array($error)) {
            $error = json_encode($error, JSON_UNESCAPED_UNICODE);
        }

        return false !== mb_stripos((string) $error, 'токен');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function apiGet(string $token, array $params): ?array
    {
        try {
            $response = $this->http->request('GET', rtrim($this->baseUrl, '/').'/search', [
                'query' => array_merge(['token' => $token], $params),
                'timeout' => 20,
            ]);
            $content = $response->getContent(false);
        } catch (\Throwable) {
            return null;
        }

        $json = json_decode($content, true);

        return is_array($json) ? $json : null;
    }

    private function httpGet(string $url): ?string
    {
        try {
            return $this->http->request('GET', $url, ['timeout' => 20])->getContent(false);
        } catch (\Throwable) {
            return null;
        }
    }
}
