<?php

namespace App\Service\Amedia;

use App\Entity\Episode;
use App\Entity\Title;
use App\Entity\Translation;
use App\Service\Kodik\KodikCoverage;
use App\Service\SeasonLinker;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Импорт тайтлов с amd.online (Amedia) в локальную БД.
 *
 * Политика «два режима, совмещаем»:
 *  - каталог один: амедия-тайтлы и kodik-тайтлы лежат в одной таблице;
 *  - при совпадении названия (и года, если он известен у обоих) амедия-данные
 *    прикрепляются к уже существующему тайтлу — без дублей в каталоге;
 *  - амедия-плеер получает наивысший приоритет, озвучки Kodik с amd.online
 *    и старые kodik-переводы остаются запасными вариантами.
 *
 * Повторный импорт уже существующего тайтла — аддитивный: добираем только
 * недостающие серии по каждой озвучке (новые озвучки — целиком), уже имеющиеся
 * серии и их ссылки не перезаписываем и ничего не удаляем. Единственное
 * исключение — заглушка «серия ещё не вышла» (ссылка do=nz или её отсутствие):
 * когда серия выходит и у неё появляется настоящая ссылка, мы её обновляем.
 * Так новые серии подхватываются без «перезаписи всего» и без пропусков (гэпов).
 */
final class AmediaSync
{
    /** Приоритет основного амедия-плеера (выше всех остальных). */
    private const PRIORITY_AMEDIA = 1000;

    /** Стартовый приоритет озвучек Kodik с amd.online. */
    private const PRIORITY_KODIK_BASE = 900;

    /** Предохранитель от бесконечного обхода, если последняя страница не помечена. */
    private const MAX_PAGES = 5000;

    /** Сколько новых серий добавил текущий импорт (для отчёта в консоли). */
    private int $addedEpisodes = 0;

    public function __construct(
        private readonly AmediaClient $client,
        private readonly EntityManagerInterface $em,
        private readonly SeasonLinker $seasonLinker,
        private readonly KodikCoverage $kodikCoverage,
    ) {
    }

    /**
     * Импорт из результатов поиска по сайту.
     *
     * @return array{imported: int, skipped: int, failed: int, episodes: int}
     */
    public function importSearch(string $query, int $limit = 20): array
    {
        $this->addedEpisodes = 0;
        $items = $this->client->fetchSearch($query);
        if ($limit > 0) {
            $items = \array_slice($items, 0, $limit);
        }

        $result = $this->importItems($items);
        $result['episodes'] = $this->addedEpisodes;

        return $result;
    }

    /**
     * Импорт каталога: страницы идут подряд, переход — по ссылке «вперёд» из
     * пагинации. $maxPages ограничивает число страниц (0 — без ограничения,
     * обход до последней страницы пагинации).
     *
     * По умолчанию — каталог по дате редактирования: свежеобновлённые тайтлы
     * (вышедшие серии) идут первыми, поэтому ограниченный импорт
     * (`--max-pages`) сначала забирает самое свежее.
     *
     * @param callable(int, array{imported: int, skipped: int, failed: int}, int): void|null $onPage
     *        вызывается ПОСЛЕ каждой страницы: (номер страницы, счётчики
     *        страницы, сколько новых серий добавила эта страница)
     *
     * @return array{imported: int, skipped: int, failed: int, episodes: int}
     */
    public function importCatalog(string $path = AmediaClient::CATALOG_PATH_BY_EDITDATE, int $maxPages = 0, bool $skipExisting = false, ?callable $onPage = null): array
    {
        $this->addedEpisodes = 0;
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        $limit = $maxPages > 0 ? $maxPages : self::MAX_PAGES;
        $page = $this->client->fetchListingPage($path, 1);

        for ($number = 1; ; ++$number) {
            $items = $page['items'];
            if (!$items) {
                break;
            }

            $episodesBefore = $this->addedEpisodes;
            $pageCounts = $this->importItems($items, $skipExisting);
            foreach ($pageCounts as $key => $value) {
                $counts[$key] += $value;
            }

            // Прогресс наружу — после каждой страницы, а не только в конце
            // обхода: на сотнях страниц иначе непонятно, идёт ли ещё процесс.
            if (null !== $onPage) {
                $onPage($number, $pageCounts, $this->addedEpisodes - $episodesBefore);
            }

            $next = $page['pagination']['next'] ?? null;
            if (null === $next || $number >= $limit) {
                break;
            }

            $page = $this->client->fetchPage($next);
        }

        $counts['episodes'] = $this->addedEpisodes;

        return $counts;
    }

    /**
     * Импорт одного тайтла по URL (или пути вида «5768-psajren.html»).
     */
    public function importUrl(string $url): Title
    {
        return $this->importParsed($this->client->fetchTitle($url));
    }

    /**
     * Ядро импорта: upsert по внешнему ключу + слияние с существующим тайтлом.
     *
     * @param array<string, mixed> $p результат AmediaParser::parseTitle()
     */
    public function importParsed(array $p): Title
    {
        $newsId = (int) $p['newsId'];
        $externalId = 'amd:'.$newsId;
        $repo = $this->em->getRepository(Title::class);

        $title = $repo->findOneBy(['kodikId' => $externalId]) ?? $this->findMergeTarget($p);
        $isNew = null === $title;

        if ($isNew) {
            $title = (new Title())
                ->setKodikId($externalId)
                ->setSource(Title::SOURCE_AMEDIA);
            $this->em->persist($title);
        }

        // Снимок ДО правок: по нему в конце видно, изменил ли обход что-то
        // реально (новые серии/озвучки, правки метаданных) или тайтл лишь
        // «просмотрели».
        $before = $this->contentSnapshot($title);

        // тип берём с сайта (фильм может быть разбит на части — это не делает его сериалом;
        // факт наличия серий учитывает Title::isSerial())
        $hasEpisodes = !empty($p['episodes']);
        $type = (string) ($p['type'] ?? ($hasEpisodes ? 'serial' : 'movie'));

        // метаданные: при слиянии не затираем существующие данные пустыми значениями
        $title->setType($type);
        $title->setTitle((string) $p['title']);
        if (!empty($p['originalTitle'])) {
            $title->setOriginalTitle($p['originalTitle']);
        }
        if (!empty($p['year'])) {
            $title->setYear((int) $p['year']);
        }
        if (!empty($p['poster'])) {
            $title->setPoster($p['poster']);
        }
        if (!empty($p['description'])) {
            $title->setDescription($p['description']);
        }
        if (!empty($p['genres'])) {
            $title->setGenres(array_values($p['genres']));
        }
        if (null === $title->getSourceUrl()) {
            $title->setSourceUrl($p['url']);
        }

        // Для уже существующего тайтла обе функции работают как «добавить
        // недостающее»: серии и озвучки только создаются, уже имеющиеся —
        // не перезаписываются, удаления нет.
        $this->applyAmediaTranslation($title, $p);

        $this->applyKodikVoices($title, $p);
        $this->fillPlayerLink($title, $p);

        // связка сезонов: ключ франшизы и номер сезона считаем из названия
        $this->seasonLinker->apply($title);

        // Дату обновления ставим, только если импорт действительно что-то
        // поменял. Иначе каждый обход каталога поднимал бы тайтл наверх главной
        // (сортировка по lastSyncedAt), хотя в нём ничего не изменилось.
        if ($isNew || $this->contentSnapshot($title) !== $before) {
            $title->setLastSyncedAt(new \DateTimeImmutable());
        }

        $this->em->flush();

        return $title;
    }

    /**
     * Снимок содержимого тайтла: метаданные + озвучки + серии (без прогресса
     * просмотра и без lastSyncedAt). Импорт сравнивает снимки до и после
     * правок и обновляет lastSyncedAt только при реальном отличии.
     *
     * @return list<mixed>
     */
    private function contentSnapshot(Title $title): array
    {
        $snapshot = [
            $title->getType(),
            $title->getTitle(),
            $title->getOriginalTitle(),
            $title->getYear(),
            $title->getPoster(),
            $title->getDescription(),
            $title->getGenres(),
            $title->getSourceUrl(),
            $title->getPlayerLink(),
            $title->getFranchise(),
            $title->getSeasonNumber(),
            $title->getSeasonPart(),
        ];

        foreach ($title->getTranslations() as $translation) {
            $snapshot[] = [
                $translation->getKodikTranslationId(),
                $translation->getName(),
                $translation->getTechTitle(),
                $translation->getType(),
                $translation->isVoice(),
                $translation->getPriority(),
                $translation->getLink(),
            ];

            foreach ($translation->getEpisodes() as $episode) {
                $snapshot[] = [
                    $episode->getSeason(),
                    $episode->getEpisodeNumber(),
                    $episode->getLink(),
                ];
            }
        }

        return $snapshot;
    }

    /**
     * Основной плеер Amedia: одна «озвучка» с прямыми ссылками на серии.
     *
     * @param array<string, mixed> $p
     */
    private function applyAmediaTranslation(Title $title, array $p): void
    {
        $episodes = $p['episodes'] ?? [];
        $baseLink = $p['amediaLink'] ?? null;
        $players = $p['players'] ?? [];

        // Реальный amedia-плеер: вкладка в переключателе (data-player="amedia"),
        // iframe .frame_video_mod или хотя бы одна серия с настоящей ссылкой
        // (data-vlnk, обычно aser.pro). Блок серий есть и на Kodik-only страницах,
        // но там data-vlnk пустой — такие «серии» не делают тайтл amedia-тайтлом.
        $hasAmedia = null !== $baseLink
            || \in_array('amedia', $players, true)
            || [] !== array_filter($episodes, static fn (array $ep): bool => !empty($ep['link']));

        $translation = $this->findTranslation($title, 'amedia');

        if (!$hasAmedia) {
            // амедия-плеера на странице нет. Ничего не удаляем: ранее созданная
            // амедия-озвучка (если была) остаётся в базе как есть.
            return;
        }

        if (null === $translation) {
            $translation = (new Translation())->setKodikTranslationId('amedia');
            $title->addTranslation($translation);
            $this->em->persist($translation);
        }

        $translation
            ->setName('Amedia')
            ->setTechTitle('amedia')
            ->setPriority(self::PRIORITY_AMEDIA)
            ->setIsVoice(true);

        $firstEpisodeLink = null;
        $season = (int) ($p['season'] ?? 1);

        foreach ($episodes as $epRaw) {
            $number = (int) $epRaw['number'];
            if ($number <= 0) {
                continue;
            }
            $link = $epRaw['link'] ?? null;
            if (null !== $link && null === $firstEpisodeLink && !str_contains($link, 'do=nz')) {
                $firstEpisodeLink = $link;
            }
            $this->addEpisodeIfMissing($translation, $season, $number, $link);
        }

        // фильм: одна ссылка на плеер вместо серий
        $translation->setLink($baseLink ?? $firstEpisodeLink);
    }

    /**
     * Озвучки Kodik с amd.online (таб Kodik): каждая кнопка — отдельная озвучка.
     *
     * @param array<string, mixed> $p
     */
    private function applyKodikVoices(Title $title, array $p): void
    {
        $voices = $p['kodikVoices'] ?? [];

        if (!$voices && !empty($p['kodikLink'])) {
            // у фильма вкладка Kodik бывает без кнопок озвучек — один iframe на всех
            $voices = [[
                'id' => 'main',
                'name' => 'Kodik',
                'src' => $p['kodikLink'],
            ]];
        }

        if (!$voices) {
            return;
        }


        $episodes = $p['episodes'] ?? [];
        $season = (int) ($p['season'] ?? 1);
        $priority = self::PRIORITY_KODIK_BASE;

        foreach ($voices as $voice) {
            $externalId = 'kodik:'.$voice['id'];
            $src = $voice['src'];
           

            $translation = $this->findTranslation($title, $externalId);
            if (null === $translation) {
                // та же озвучка может уже прийти из Kodik API — не плодим дубль
                $translation = $this->findTranslationByName($title, $voice['name']);
            }

            if (null === $translation) {
                $translation = (new Translation())->setKodikTranslationId($externalId);
                $title->addTranslation($translation);
                $this->em->persist($translation);
                $translation->setName($voice['name'] ?: 'Kodik');
                $translation->setTechTitle('kodik');
                $translation->setIsVoice(true);
                $translation->setPriority($priority);
                $priority -= 10;
            }

            if (null === $translation->getLink()) {
                $translation->setLink($src);
            }

            // Точный набор серий у каждой Kodik-озвучки свой, поэтому он
            // запрашивается точечно у Kodik API (with_episodes=true) — только
            // для новой (пустой) озвучки. На странице amd в табе Kodik
            // перечислены озвучки одной серии, и раздавать их на все серии
            // нельзя. Если Kodik недоступен/токен мёртв — грубый набор из amd,
            // чтобы озвучка не осталась пустой и не выпала из переключателя.

            if (!$this->applyKodikCoverage($translation, $src)) {

                foreach ($episodes as $epRaw) {
                    $number = (int) $epRaw['number'];
                    if ($number <= 0) {
                        continue;
                    }
                    // ссылка вычисляется детерминированно из базовой ссылки озвучки;
                    // серия добавляется только если её ещё нет
                  
                  
                    $this->addEpisodeIfMissing(
                        $translation,
                        $season,
                        $number,
                        $this->withSeasonEpisode($src, $season, $number)
                    );
                }
            }
        }
    }

    /**
     * Проставляет озвучке точное покрытие серий по данным Kodik API.
     *
     * @return bool true — покрытие получено и записано; false — Kodik не
     *              ответил или материал в ссылке не распознан (тогда нужен
     *              грубый фолбэк на серии со страницы amd)
     */
    private function applyKodikCoverage(Translation $translation, string $url): bool
    {
        // материал озвучки: .../serial/<id>/... или .../video/<id>/...
  
        if (!preg_match('#kodikplayer\.com/(serial|video)/(\d+)/#', $url, $m)) {
            return false;
        }
        $material = ('video' === $m[1] ? 'movie' : 'serial').'-'.$m[2];

        $coverage = $this->kodikCoverage->fetch($material);

        if (null === $coverage || [] === $coverage) {
            return false;
        }

        foreach ($coverage as $seasonNo => $eps) {
            foreach ($eps as $number => $_) {
                $this->addEpisodeIfMissing(
                    $translation,
                    (int) $seasonNo,
                    (int) $number,
                    $this->withSeasonEpisode($url, (int) $seasonNo, (int) $number)
                );
            }
        }

        return true;
    }

    /** Если у тайтла ещё нет ссылки на плеер — собираем из доступных данных. */
    private function fillPlayerLink(Title $title, array $p): void
    {
        if ($title->getPlayerLink()) {
            return;
        }

        if (!empty($p['amediaLink'])) {
            $title->setPlayerLink($p['amediaLink']);

            return;
        }

        foreach ($p['episodes'] ?? [] as $epRaw) {
            $link = $epRaw['link'] ?? null;
            if ($link && !str_contains($link, 'do=nz')) {
                $title->setPlayerLink($link);

                return;
            }
        }

        if (!empty($p['kodikLink'])) {
            $title->setPlayerLink($p['kodikLink']);

            return;
        }

        foreach ($p['kodikVoices'] ?? [] as $voice) {
            if (!empty($voice['src'])) {
                $title->setPlayerLink($voice['src']);

                return;
            }
        }
    }

    /**
     * Ищем существующий тайтл с тем же названием — чтобы слить режимы, а не дублировать.
     */
    private function findMergeTarget(array $p): ?Title
    {
        $name = mb_strtolower(trim((string) $p['title']));
        if ('' === $name) {
            return null;
        }

        /** @var list<Title> $candidates */
        $candidates = $this->em->createQuery(
            'SELECT t FROM '.Title::class.' t WHERE LOWER(t.title) = :name'
        )->setParameter('name', $name)->getResult();

        $year = isset($p['year']) && $p['year'] ? (int) $p['year'] : null;

        $fallback = null;
        foreach ($candidates as $candidate) {
            if (null !== $year && null !== $candidate->getYear() && $candidate->getYear() !== $year) {
                continue; // разные годы — считаем разными тайтлами
            }
            if ($candidate->isFromAmedia()) {
                return $candidate; // амедия-строка уже есть — обновляем её
            }
            $fallback ??= $candidate;
        }

        return $fallback;
    }

    private function findTranslation(Title $title, string $externalId): ?Translation
    {
        foreach ($title->getTranslations() as $translation) {
            if ($translation->getKodikTranslationId() === $externalId) {
                return $translation;
            }
        }

        return null;
    }

    private function findTranslationByName(Title $title, string $name): ?Translation
    {
        $name = mb_strtolower(trim($name));
        if ('' === $name) {
            return null;
        }

        foreach ($title->getTranslations() as $translation) {
            if ('amedia' === $translation->getKodikTranslationId()) {
                continue; // основной амедия-плеер не трогаем
            }
            if (mb_strtolower(trim($translation->getName())) === $name) {
                return $translation;
            }
        }

        return null;
    }

    private function findEpisode(Translation $translation, int $season, int $number): ?Episode
    {
        foreach ($translation->getEpisodes() as $episode) {
            if ($episode->getSeason() === $season && $episode->getEpisodeNumber() === $number) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * Добавляет серию, только если её ещё нет. Существующую серию не трогаем,
     * чтобы повторный импорт был чисто аддитивным (реальные ссылки не
     * перезаписываем). Исключение — заглушка «серия ещё не вышла» (do=nz или
     * отсутствие ссылки): как только у серии появляется настоящая ссылка,
     * обновляем её. Иначе заглушка «залипла» бы навсегда, и вышедшая серия
     * никогда не попала бы в «Новые серии».
     */
    private function addEpisodeIfMissing(Translation $translation, int $season, int $number, ?string $link): void
    {

        $existing = $this->findEpisode($translation, $season, $number);
    
        if (null !== $existing) {
            if (self::isPlaceholderLink($existing->getLink()) && !self::isPlaceholderLink($link)) {
                $existing->setLink($link);
            }

            return;
        }


        $episode = (new Episode())
            ->setSeason($season)
            ->setEpisodeNumber($number)
            ->setLink($link);
        $translation->addEpisode($episode);
        $this->em->persist($episode);
         $this->em->flush();
        ++$this->addedEpisodes;
    }

    /** Заглушка вместо серии: ссылки нет вовсе или amd отдал страницу «серия не вышла» (do=nz). */
    private static function isPlaceholderLink(?string $link): bool
    {
        return null === $link || '' === $link || str_contains($link, 'do=nz');
    }

    /** Подставляет season/episode в ссылку Kodik (или добавляет параметры). */
    private function withSeasonEpisode(string $url, int $season, int $episode): string
    {
        if (preg_match('/[?&]season=\d+/', $url)) {
            $url = preg_replace('/([?&]season=)\d+/', '${1}'.$season, $url, 1);
        } else {
            $url .= (str_contains($url, '?') ? '&' : '?').'season='.$season;
        }

        if (preg_match('/[?&]episode=\d+/', $url)) {
            $url = preg_replace('/([?&]episode=)\d+/', '${1}'.$episode, $url, 1);
        } else {
            $url .= (str_contains($url, '?') ? '&' : '?').'episode='.$episode;
        }

        return $url;
    }

    /**
     * @param list<array{url: string}> $items
     *
     * @return array{imported: int, skipped: int, failed: int}
     */
    private function importItems(array $items, bool $skipExisting = false): array
    {
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($items as $item) {
            $url = $item['url'];
            try {
                if ($skipExisting && $this->existsByUrl($url)) {
                    ++$counts['skipped'];
                } else {
                    $this->importParsed($this->client->fetchTitle($url));
                    $this->em->flush();
                    ++$counts['imported'];
                }
            } catch (AmediaException) {
                ++$counts['failed'];
            }

            // Память освобождаем после КАЖДОГО тайтла, а не в конце страницы:
            // граф Title↔Translation↔Episode держит циклические ссылки, и на
            // длинном тайтле (сотни серий) даже одна страница забивает identity
            // map так, что процесс падает по OOM. clear() сбрасывает identity
            // map, gc_collect_cycles() тут же собирает осиротевшие циклы.
            $this->em->clear();
            gc_collect_cycles();
        }

        return $counts;
    }

    private function existsByUrl(string $url): bool
    {
        $newsId = null;
        if (preg_match('#/(\d+)-#', $url, $m)) {
            $newsId = 'amd:'.$m[1];
        }
        if (null !== $newsId && $this->em->getRepository(Title::class)->findOneBy(['kodikId' => $newsId])) {
            return true;
        }

        return null !== $this->em->getRepository(Title::class)->findOneBy(['sourceUrl' => $url]);
    }
}
