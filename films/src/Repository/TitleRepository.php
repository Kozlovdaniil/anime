<?php

namespace App\Repository;

use App\Entity\Episode;
use App\Entity\Title;
use App\Entity\Translation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Title>
 */
class TitleRepository extends ServiceEntityRepository
{
    /** Обычный каталог. */
    public const SCOPE_ALL = 'all';
    /** Раздел «Смотрю». */
    public const SCOPE_WATCHING = 'watching';
    /** «Просмотрено»: запускали хотя бы одну серию, но в «Смотрю» не добавляли. */
    public const SCOPE_WATCHED = 'watched';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Title::class);
    }

    /**
     * Каталог с опциональным поиском и фильтром по типу.
     *
     * @param string $scope SCOPE_ALL, SCOPE_WATCHING или SCOPE_WATCHED
     *
     * @return array{0: list<Title>, 1: int} [результаты, всего]
     */
    public function searchCatalog(?string $query, ?string $type, int $page, int $limit, string $scope = self::SCOPE_ALL, ?string $genre = null): array
    {
        $qb = $this->createQueryBuilder('t');

        if (self::SCOPE_WATCHING === $scope) {
            $qb->andWhere('t.watching = true')
                ->orderBy('t.watchingAt', 'DESC')
                ->addOrderBy('t.title', 'ASC');
        } elseif (self::SCOPE_WATCHED === $scope) {
            // «Просмотрено» — есть прогресс хотя бы по одной серии, но тайтл не
            // в «Смотрю» (добавленные остаются в своём разделе). EXISTS вместо
            // join: join по озвучкам размножил бы строки и сломал пагинацию.
            // Сортировка — по свежести просмотра (последняя просмотренная серия).
            $qb->andWhere('t.watching = false')
                ->andWhere('EXISTS (SELECT tr.id FROM '.Translation::class.' tr WHERE IDENTITY(tr.title) = t.id AND tr.lastEpisode IS NOT NULL)')
                ->addSelect('(SELECT MAX(wt.lastWatchedAt) FROM '.Translation::class.' wt WHERE IDENTITY(wt.title) = t.id) AS HIDDEN lastWatchedAt')
                ->orderBy('lastWatchedAt', 'DESC')
                ->addOrderBy('t.title', 'ASC');
        } else {
            $qb->orderBy('t.year', 'DESC')
                ->addOrderBy('t.lastSyncedAt', 'DESC')
                ->addOrderBy('t.title', 'ASC');
        }

        if ($query) {
            $qb->andWhere('(lower(t.title) LIKE lower(:q) OR lower(t.originalTitle) LIKE lower(:q))')
                ->setParameter('q', '%'.$query.'%');
        }
        if ($type) {
            $qb->andWhere('t.type = :type')->setParameter('type', $type);
        }
        if ($genre) {
            // JSONB_CONTAINS сравнивает без учёта регистра и пробелов.
            $qb->andWhere('JSONB_CONTAINS(t.genres, :g) = true')
                ->setParameter('g', $genre);
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(t.id)')
            ->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null);

        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);

        /** @var list<Title> $titles */
        $titles = $qb->getQuery()->getResult();

        // Каждой карточке нужно число серий («… сериал (15 серий)») — максимум по
        // всем плеерам. Считаем одной агрегатной выборкой на страницу: ленивая
        // загрузка обошлась бы ~140 запросами на страницу, а fetch-join в основном
        // запросе нельзя — LIMIT тогда применялся бы к строкам связей, и на
        // странице оставалось бы 3 тайтла вместо 25.
        if ($titles) {
            $counts = $this->maxEpisodeCounts(array_map(
                static fn (Title $title): int => $title->getId(),
                $titles
            ));
            foreach ($titles as $title) {
                $title->setEpisodeCountHint($counts[$title->getId()] ?? 0);
            }
        }

        return [$titles, (int) $countQb->getQuery()->getSingleScalarResult()];
    }

    /**
     * Максимум серий по всем озвучкам указанных тайтлов — одна агрегатная
     * выборка вместо загрузки самих Episode (на карточку нужно только число).
     *
     * @param list<int> $ids
     *
     * @return array<int, int> [id тайтла => максимум; 0, если серий нет]
     */
    public function maxEpisodeCounts(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $counts = array_fill_keys($ids, 0);
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT c.title_id AS title_id, MAX(c.cnt) AS cnt
             FROM (SELECT tr.title_id, tr.id, COUNT(*) AS cnt
                     FROM episodes e
                     JOIN translations tr ON tr.id = e.translation_id
                    WHERE tr.title_id IN (:ids)
                    GROUP BY tr.title_id, tr.id) c
             GROUP BY c.title_id",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        ) as $row) {
            $counts[(int) $row['title_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function countWatching(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.watching = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByKodikId(string $kodikId): ?Title
    {
        return $this->findOneBy(['kodikId' => $kodikId]);
    }

    /**
     * Сезоны одной франшизы (включая сам тайтл) по возрастанию: сначала первый
     * сезон, затем второй и т.д. Если тайтл один — пусто (связывать нечего).
     *
     * @return list<Title>
     */
    public function findSeasonGroup(Title $title): array
    {
        $franchise = $title->getFranchise();
        if (null === $franchise || '' === $franchise) {
            return [];
        }

        /** @var list<Title> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.franchise = :franchise')
            ->setParameter('franchise', $franchise)
            ->orderBy('t.seasonNumber', 'ASC')
            ->addOrderBy('t.seasonPart', 'ASC')
            ->addOrderBy('t.year', 'ASC')
            ->addOrderBy('t.title', 'ASC')
            ->getQuery()
            ->getResult();

        return count($rows) > 1 ? $rows : [];
    }

    /**
     * @return list<string>
     */
    public function findDistinctTypes(): array
    {
        return array_column(
            $this->createQueryBuilder('t')
                ->select('DISTINCT t.type')
                ->orderBy('t.type')
                ->getQuery()
                ->getArrayResult(),
            'type'
        );
    }

    /**
     * @return list<string>
     */
    public function findDistinctTypesWatching(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('DISTINCT t.type')
            ->andWhere('t.watching = true')
            ->orderBy('t.type')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'type');
    }

    /**
     * @return list<string>
     */
    public function findDistinctGenres(): array
    {
        $items = $this->createQueryBuilder('t')
            ->select('t.genres')
            ->where('t.genres IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $genres = [];
        foreach ($items as $row) {
            $list = $row['genres'] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $g) {
                if (!is_string($g) || $g === '') {
                    continue;
                }
                $key = mb_strtolower(trim($g));
                $genres[$key] = trim($g);
            }
        }

        sort($genres, SORT_LOCALE_STRING);

        return array_values($genres);
    }

    /**
     * @return list<string>
     */
    public function findDistinctGenresWatching(): array
    {
        $items = $this->createQueryBuilder('t')
            ->select('t.genres')
            ->andWhere('t.watching = true')
            ->andWhere('t.genres IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

        $genres = [];
        foreach ($items as $row) {
            $list = $row['genres'] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $g) {
                if (!is_string($g) || $g === '') {
                    continue;
                }
                $key = mb_strtolower(trim($g));
                $genres[$key] = trim($g);
            }
        }

        sort($genres, SORT_LOCALE_STRING);

        return array_values($genres);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Прогресс просмотра для набора тайтлов — по «текущей» озвучке тайтла:
     * той, где есть прогресс и самый свежий просмотр. Прогресс кумулятивный:
     * всё до последней просмотренной серии включительно считается просмотренным.
     *
     * Возвращает и первую непросмотренную серию (nextS/nextE) — из этих данных
     * строится блок «Новые серии» (серии после последней просмотренной).
     *
     * @param list<int> $titleIds
     *
     * @return array<int, array{name: string, translationId: int, total: int, watched: int,
     *                         lastS: int, lastE: int, nextS: int|null, nextE: int|null,
     *                         newCount: int, watchedAt: int}>
     */
    public function watchProgressFor(array $titleIds): array
    {
        if (!$titleIds) {
            return [];
        }

        $em = $this->getEntityManager();

        $qb = $em->createQueryBuilder();
        $rows = $qb
            ->select(
                'tr.id AS id',
                'IDENTITY(tr.title) AS titleId',
                'tr.name AS name',
                'tr.lastSeason AS lastSeason',
                'tr.lastEpisode AS lastEpisode',
                'tr.lastWatchedAt AS lastWatchedAt',
                'COUNT(ep.id) AS total'
            )
            ->from(Translation::class, 'tr')
            ->leftJoin('tr.episodes', 'ep')
            ->where($qb->expr()->in('tr.title', ':ids'))
            ->setParameter('ids', $titleIds)
            ->groupBy('tr.id')
            ->getQuery()
            ->getArrayResult();

        // по тайтлу — озвучка с прогрессом и самым свежим просмотром
        $chosen = [];
        foreach ($rows as $row) {
            if (null === $row['lastEpisode']) {
                continue;
            }
            $titleId = (int) $row['titleId'];
            $watchedAt = $row['lastWatchedAt'];
            $ts = $watchedAt instanceof \DateTimeInterface ? $watchedAt->getTimestamp() : 0;
            if (!isset($chosen[$titleId]) || $ts > $chosen[$titleId]['ts']) {
                $chosen[$titleId] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'lastSeason' => max(1, (int) ($row['lastSeason'] ?? 1)),
                    'lastEpisode' => (int) $row['lastEpisode'],
                    'total' => (int) $row['total'],
                    'ts' => $ts,
                ];
            }
        }
        if (!$chosen) {
            return [];
        }

        $out = [];
        $byTr = [];
        foreach ($chosen as $titleId => $c) {
            $out[$titleId] = [
                'name' => $c['name'],
                'translationId' => $c['id'],
                'total' => $c['total'],
                'watched' => 0,
                'lastS' => $c['lastSeason'],
                'lastE' => $c['lastEpisode'],
                'nextS' => null,
                'nextE' => null,
                'newCount' => 0,
                'watchedAt' => $c['ts'],
            ];
            $byTr[$c['id']] = $titleId;
        }

        $epQb = $em->createQueryBuilder();
        $eps = $epQb
            ->select('IDENTITY(ep.translation) AS trId', 'ep.season AS season', 'ep.episodeNumber AS e', 'ep.link AS link')
            ->from(Episode::class, 'ep')
            ->where($epQb->expr()->in('ep.translation', ':ids'))
            ->setParameter('ids', array_keys($byTr))
            ->getQuery()
            ->getArrayResult();

        foreach ($eps as $row) {
            $titleId = $byTr[(int) $row['trId']] ?? null;
            if (null === $titleId) {
                continue;
            }
            $c = $chosen[$titleId];
            $s = (int) $row['season'];
            $e = (int) $row['e'];
            if ($s < $c['lastSeason'] || ($s === $c['lastSeason'] && $e <= $c['lastEpisode'])) {
                $out[$titleId]['watched']++;
            } elseif (!self::isUnreleased($row['link'] ?? null)) {
                // Непросмотренные, но уже вышедшие серии. Заглушка «серия ещё не
                // вышла» (ссылка do=nz) в счёт не идёт — иначе «Новые серии»
                // показывают серию, которой на самом деле нет.
                $out[$titleId]['newCount']++;
                if (null === $out[$titleId]['nextS']
                    || $s < $out[$titleId]['nextS']
                    || ($s === $out[$titleId]['nextS'] && $e < $out[$titleId]['nextE'])) {
                    // первая непросмотренная вышедшая серия (минимальная после последней)
                    $out[$titleId]['nextS'] = $s;
                    $out[$titleId]['nextE'] = $e;
                }
            }
        }

        return $out;
    }

    /**
     * «Новые серии»: по всем тайтлам «Смотрю» — те, у которых в текущей
     * озвучке остались вышедшие непросмотренные серии. Сортировка — по
     * свежести просмотра (сначала то, что смотрели недавно).
     *
     * @return list<array{title: Title, name: string, translationId: int,
     *                    nextS: int, nextE: int, newCount: int}>
     */
    public function findNewEpisodes(int $limit): array
    {
        $progress = $this->watchProgressFor($this->watchingIds());

        $rows = [];
        foreach ($progress as $titleId => $p) {
            if (null === $p['nextS']) {
                continue; // всё просмотрено — новых серий нет
            }
            $rows[] = $p + ['id' => $titleId];
        }
        if (!$rows) {
            return [];
        }

        usort($rows, static fn (array $a, array $b): int => $b['watchedAt'] <=> $a['watchedAt']);
        $rows = \array_slice($rows, 0, $limit);

        // подтянуть сами тайтлы (постер/название)
        $byId = [];
        foreach ($this->findBy(['id' => array_column($rows, 'id')]) as $title) {
            $byId[$title->getId()] = $title;
        }

        $out = [];
        foreach ($rows as $r) {
            $title = $byId[$r['id']] ?? null;
            if (null === $title) {
                continue;
            }
            $out[] = [
                'title' => $title,
                'name' => $r['name'],
                'translationId' => $r['translationId'],
                'nextS' => (int) $r['nextS'],
                'nextE' => (int) $r['nextE'],
                'newCount' => (int) $r['newCount'],
            ];
        }

        return $out;
    }

    /**
     * «Вышло продолжение»: новые сезоны/части, появившиеся в каталоге после
     * того, как пользователь ДОСМОТРЕЛ предыдущий сезон того же произведения.
     *
     *  1) по тайтлам из «Смотрю» с прогрессом ищем досмотренные сезоны
     *     (последняя просмотренная серия >= последней ВЫШЕДШЕЙ серии сезона);
     *  2) по каждой франшизе берём самый дальний досмотренный сезон;
     *  3) продолжение — ближайший (по сезону и части) тайтл франшизы строго
     *     дальше досмотренного, с вышедшими сериями, ещё не начатый и
     *     появившийся за последние $withinDays дней.
     *
     * Тайтлы из «Просмотрено» (с прогрессом, но без флага «Смотрю») сигналом
     * не считаются: за ними не следим.
     *
     * @return list<array{title: Title, seasonNumber: int, seasonPart: int,
     *                    watchedSeason: int, watchedPart: int,
     *                    firstSeen: \DateTimeImmutable|null}>
     */
    public function findNewSeasons(int $withinDays = 30, int $limit = 12): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $progress = $conn->fetchAllAssociative(
            'SELECT t.id AS title_id, t.franchise, t.season_number, t.season_part,
                    tr.id AS tr_id, tr.last_season, tr.last_episode
             FROM translations tr
             JOIN titles t ON t.id = tr.title_id
             WHERE tr.last_episode IS NOT NULL AND t.franchise IS NOT NULL
               AND t.watching = true'
        );
        if (!$progress) {
            return [];
        }

        // Последняя вышедшая серия по (озвучка, сезон) — критерий «сезон досмотрен».
        $translationIds = array_values(array_unique(array_map(
            static fn (array $r): int => (int) $r['tr_id'],
            $progress
        )));
        $maxReleased = [];
        foreach ($conn->fetchAllAssociative(
            "SELECT translation_id, season, MAX(episode_number) AS max_e
             FROM episodes
             WHERE translation_id IN (:ids) AND (link IS NULL OR link NOT LIKE '%do=nz%')
             GROUP BY translation_id, season",
            ['ids' => $translationIds],
            ['ids' => ArrayParameterType::INTEGER]
        ) as $row) {
            $maxReleased[(int) $row['translation_id']][(int) $row['season']] = (int) $row['max_e'];
        }

        // Самый дальний досмотренный (сезон, часть) по каждой франшизе.
        $done = [];
        foreach ($progress as $row) {
            $season = max(1, (int) ($row['last_season'] ?? 1));
            $max = $maxReleased[(int) $row['tr_id']][$season] ?? null;
            if (null === $max || (int) $row['last_episode'] < $max) {
                continue; // сезон не досмотрен
            }

            $titleSeason = (int) $row['season_number'];
            $watchedSeason = max($titleSeason, $season);
            // Часть берём из тайтла, только если он и есть этот сезон.
            $watchedPart = $watchedSeason === $titleSeason
                ? max(1, (int) ($row['season_part'] ?? 1))
                : 1;

            $franchise = (string) $row['franchise'];
            if (!isset($done[$franchise])
                || $watchedSeason > $done[$franchise]['s']
                || ($watchedSeason === $done[$franchise]['s'] && $watchedPart > $done[$franchise]['p'])) {
                $done[$franchise] = ['s' => $watchedSeason, 'p' => $watchedPart];
            }
        }
        if (!$done) {
            return [];
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', abs($withinDays)));
        $candidates = $conn->fetchAllAssociative(
            "SELECT t.id, t.franchise, t.season_number, t.season_part, t.first_seen_at
             FROM titles t
             WHERE t.franchise IN (:franchises)
               AND t.first_seen_at >= :cutoff
               AND NOT EXISTS (
                   SELECT 1 FROM translations tr
                   WHERE tr.title_id = t.id AND tr.last_episode IS NOT NULL
               )
               AND EXISTS (
                   SELECT 1 FROM translations tr2
                   JOIN episodes ep ON ep.translation_id = tr2.id
                   WHERE tr2.title_id = t.id AND (ep.link IS NULL OR ep.link NOT LIKE '%do=nz%')
               )",
            ['franchises' => array_keys($done), 'cutoff' => $cutoff->format('Y-m-d H:i:s')],
            ['franchises' => ArrayParameterType::STRING]
        );

        // Ближайшее продолжение строго после досмотренного.
        $best = [];
        foreach ($candidates as $row) {
            $franchise = (string) $row['franchise'];
            $d = $done[$franchise];
            $season = (int) $row['season_number'];
            $part = max(1, (int) ($row['season_part'] ?? 1));
            if ($season < $d['s'] || ($season === $done[$franchise]['s'] && $part <= $done[$franchise]['p'])) {
                continue;
            }

            if (!isset($best[$franchise])
                || $season < $best[$franchise]['s']
                || ($season === $best[$franchise]['s'] && $part < $best[$franchise]['p'])) {
                $seen = $row['first_seen_at'];
                $best[$franchise] = [
                    'id' => (int) $row['id'],
                    's' => $season,
                    'p' => max(1, (int) ($row['season_part'] ?? 1)),
                    'seen' => \is_string($seen) && '' !== $seen ? new \DateTimeImmutable($seen) : null,
                    'watched' => $done[$franchise],
                ];
            }
        }
        if (!$best) {
            return [];
        }

        $byId = [];
        foreach ($this->findBy(['id' => array_column($best, 'id')]) as $title) {
            $byId[$title->getId()] = $title;
        }

        $out = [];
        foreach ($best as $candidate) {
            $title = $byId[$candidate['id']] ?? null;
            if (null === $title) {
                continue;
            }
            $out[] = [
                'title' => $title,
                'seasonNumber' => $candidate['s'],
                'seasonPart' => $candidate['p'],
                'watchedSeason' => $candidate['watched']['s'],
                'watchedPart' => $candidate['watched']['p'],
                'firstSeen' => $candidate['seen'],
            ];
        }

        usort($out, static fn (array $a, array $b): int
            => ($b['firstSeen']?->getTimestamp() ?? 0) <=> ($a['firstSeen']?->getTimestamp() ?? 0));

        return \array_slice($out, 0, $limit);
    }

    /**
     * Серия ещё не вышла? Amedia отдаёт на такие серии заглушку-ссылку с «do=nz».
     * Пустая/отсутствующая ссылка заглушкой не считается — это пробел парсинга,
     * а не невышедшая серия.
     */
    private static function isUnreleased(?string $link): bool
    {
        return null !== $link && str_contains($link, 'do=nz');
    }

    /**
     * ID всех тайтлов из списка «Смотрю».
     *
     * @return list<int>
     */
    public function watchingIds(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.watching = true')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }
}
