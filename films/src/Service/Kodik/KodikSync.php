<?php

namespace App\Service\Kodik;

use App\Entity\Episode;
use App\Entity\Title;
use App\Entity\Translation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Импорт тайтлов из Kodik API в локальную БД.
 */
final class KodikSync
{
    public function __construct(
        private readonly KodikClient $client,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Импорт поисковой выдачи (по названию и/или фильтрам).
     *
     * @return array{imported: int, skipped: int} сколько импортировано/уже было
     */
    public function importSearch(array $filters, bool $withEpisodes = true): array
    {
        $data = $this->client->search($filters);
        $imported = 0;
        $skipped = 0;

        foreach ($data['results'] ?? [] as $raw) {
            if ($this->importTitle($raw, $withEpisodes)) {
                ++$imported;
            } else {
                ++$skipped;
            }
        }

        $this->em->flush();

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Импорт одного тайтла по kodik id.
     */
    public function importById(string $kodikId, bool $withEpisodes = true): Title
    {
        $raw = $this->client->info($kodikId);
        if (!isset($raw['id'])) {
            // часть инстансов отдаёт info без id — складываем в results
            $raw['id'] = $kodikId;
        }

        $title = $this->applyRaw($raw);
        if ($withEpisodes && $title->isSerial()) {
            $this->syncEpisodes($title);
        }

        $this->em->flush();

        return $title;
    }

    /**
     * Обновление списков озвучек и серий для сериала.
     *
     * @return int количество серий
     */
    public function syncEpisodes(Title $title): int
    {
        if (!$title->isSerial()) {
            return 0;
        }

        $count = 0;
        try {
            $seasons = $this->client->seasons($title->getKodikId());
        } catch (KodikApiException) {
            return 0;
        }

        foreach ($seasons as $seasonRaw) {
            $seasonNumber = (int) ($seasonRaw['season'] ?? $seasonRaw['number'] ?? $seasonRaw['id'] ?? 0);
            if ($seasonNumber <= 0) {
                continue;
            }

            foreach ($title->getTranslations() as $translation) {
                try {
                    $episodes = $this->client->episodes(
                        $title->getKodikId(),
                        $seasonNumber,
                        $translation->getKodikTranslationId()
                    );
                } catch (KodikApiException) {
                    continue;
                }

                foreach ($episodes as $epRaw) {
                    $this->applyEpisode($translation, $seasonNumber, $epRaw);
                    ++$count;
                }
            }
        }

        $this->em->flush();

        return $count;
    }

    /**
     * Применяет сырой ответ API к сущности Title (upsert по kodik_id).
     */
    private function applyRaw(array $raw): Title
    {
        $kodikId = (string) ($raw['id'] ?? '');
        if ('' === $kodikId) {
            throw new KodikApiException('Kodik API: в ответе нет id тайтла');
        }

        $title = $this->em->getRepository(Title::class)->findOneBy(['kodikId' => $kodikId]);
        $isNew = null === $title;
        if ($isNew) {
            $title = (new Title())->setKodikId($kodikId);
            $this->em->persist($title);
        }

        $title
            ->setType((string) ($raw['type'] ?? 'movie'))
            ->setTitle((string) ($raw['title'] ?? $raw['original_title'] ?? $kodikId))
            ->setOriginalTitle($raw['original_title'] ?? null)
            ->setYear(isset($raw['releaseYear']) ? (int) $raw['releaseYear'] : null)
            ->setPoster($raw['poster'] ?? $raw['poster_min'] ?? null)
            ->setDescription($raw['description'] ?? $raw['descriptions'] ?? null)
            ->setCountry(is_array($raw['country'] ?? null) ? implode(', ', $raw['country']) : ($raw['country'] ?? null))
            ->setDuration(isset($raw['duration']) && is_scalar($raw['duration']) ? (string) $raw['duration'] : null)
            ->setAgeRestriction(isset($raw['ageRestriction']) ? (string) $raw['ageRestriction'] : null)
            ->setPremiere(is_scalar($raw['premiere'] ?? null) ? (string) $raw['premiere'] : null)
            ->setPlayerLink($raw['link'] ?? null)
            ->setKinopoiskId(isset($raw['kinopoisk_id']) ? (string) $raw['kinopoisk_id'] : null)
            ->setImdbId(isset($raw['imdb_id']) ? (string) $raw['imdb_id'] : null)
            ->setLastSyncedAt(new \DateTimeImmutable());

        // жанры: [{title:..}, ..] или ["Комедия", ..]
        $genres = [];
        foreach (($raw['genres'] ?? []) as $g) {
            if (is_array($g)) {
                $genres[] = (string) ($g['title'] ?? $g['name'] ?? '');
            } else {
                $genres[] = (string) $g;
            }
        }
        $title->setGenres(array_values(array_filter($genres)));

        // рейтинг: либо scalar, либо массив
        $title
            ->setRatingKp($this->scalarRating($raw['rating_kp'] ?? $raw['kinopoisk_rating'] ?? $raw['kp_rating'] ?? null))
            ->setRatingImdb($this->scalarRating($raw['rating_imdb'] ?? $raw['imdb_rating'] ?? null));

        // озвучки
        if (isset($raw['translations']) && is_array($raw['translations'])) {
            $priority = 100;
            foreach ($raw['translations'] as $tr) {
                $trId = (string) ($tr['id'] ?? '');
                if ('' === $trId) {
                    continue;
                }
                $translation = null;
                foreach ($title->getTranslations() as $existing) {
                    if ($existing->getKodikTranslationId() === $trId) {
                        $translation = $existing;
                        break;
                    }
                }
                if (null === $translation) {
                    $translation = new Translation();
                    $title->addTranslation($translation);
                }
                $translation
                    ->setKodikTranslationId($trId)
                    ->setName((string) ($tr['title'] ?? $trId))
                    ->setTechTitle($tr['tech_title'] ?? $tr['techTitle'] ?? null)
                    ->setType($tr['type'] ?? null)
                    ->setIsVoice((bool) ($tr['is_voice'] ?? $tr['isVoice'] ?? true))
                    ->setPriority($priority);
                $priority -= 10;
            }
        }

        if ($isNew) {
            $this->em->flush();
        }

        return $title;
    }

    private function applyEpisode(Translation $translation, int $season, array $raw): void
    {
        $number = (int) ($raw['number'] ?? $raw['episode'] ?? 0);
        if ($number <= 0) {
            return;
        }

        $episode = null;
        foreach ($translation->getEpisodes() as $existing) {
            if ($existing->getSeason() === $season && $existing->getEpisodeNumber() === $number) {
                $episode = $existing;
                break;
            }
        }
        if (null === $episode) {
            $episode = new Episode();
            $translation->addEpisode($episode);
        }

        $episode
            ->setSeason($season)
            ->setEpisodeNumber($number)
            ->setName(is_scalar($raw['name'] ?? null) ? (string) $raw['name'] : null)
            ->setLink($raw['link'] ?? null)
            ->setKodikEpisodeId(isset($raw['id']) ? (string) $raw['id'] : null);
    }

    private function scalarRating(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? reset($value);
        }
        if (null === $value || false === $value || '' === $value) {
            return null;
        }

        return substr((string) $value, 0, 8);
    }
}
