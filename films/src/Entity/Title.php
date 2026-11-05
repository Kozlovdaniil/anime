<?php

namespace App\Entity;

use App\Repository\TitleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тайтл (фильм или сериал), импортированный из Kodik API или с amd.online (Amedia).
 */
#[ORM\Entity(repositoryClass: TitleRepository::class)]
#[ORM\Table(name: 'titles')]
#[ORM\Index(columns: ['title'])]
#[ORM\Index(columns: ['kodik_id'], options: ['unique' => true])]
#[ORM\Index(name: 'idx_titles_franchise', columns: ['franchise'])]
class Title
{
    public const SOURCE_KODIK = 'kodik';
    public const SOURCE_AMEDIA = 'amedia';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Внешний ID: kodik id из API, либо "amd:{newsId}" для amedia. */
    #[ORM\Column(length: 64)]
    private ?string $kodikId = null;

    /** Источник данных: kodik | amedia. */
    #[ORM\Column(length: 16, options: ['default' => 'kodik'])]
    private string $source = self::SOURCE_KODIK;

    /** URL страницы тайтла на сайте-источнике (для amedia — страница на amd.online). */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $sourceUrl = null;

    /** movie | serial | cartoon | anime | ova | ona ... */
    #[ORM\Column(length: 32)]
    private string $type = 'movie';

    #[ORM\Column(length: 512)]
    private string $title = '';

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $originalTitle = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $year = null;

    /** URL постера. */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $poster = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Рейтинг КП (строка вида "7.5"). */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $ratingKp = null;

    /** Рейтинг IMDB. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $ratingImdb = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $country = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $genres = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $duration = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $ageRestriction = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $premiere = null;

    /** iframe-ссылка на плеер Kodik (из поля link у API). */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $playerLink = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $kinopoiskId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $imdbId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** Тайтл добавлен в список «Смотрю». */
    #[ORM\Column(options: ['default' => false])]
    private bool $watching = false;

    /** Когда добавили в «Смотрю» (для сортировки списка). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $watchingAt = null;

    /**
     * Ключ франшизы — нормализованное название без номера сезона. Тайтлы с
     * одинаковым franchise считаются сезонами одного произведения (см.
     * App\Service\SeasonLinker). Уникален не сам ключ, а группа.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $franchise = null;

    /** Номер сезона внутри франшизы: 1 — базовый тайтл, 2 — продолжение и т.д. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $seasonNumber = null;

    /**
     * Номер части внутри сезона («Часть 2» → 2). Нужен, чтобы отличить части
     * одного сезона (у них совпадают и сезон, и год) и отсортировать их.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $seasonPart = null;

    /**
     * Когда тайтл впервые появился в каталоге (ставится один раз при импорте).
     * По нему блок «Вышло продолжение» показывает только свежие новые сезоны.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstSeenAt = null;

    /**
     * Подсказка для страницы каталога: максимум серий по всем озвучкам,
     * посчитанный одним агрегатным запросом (см. TitleRepository::searchCatalog).
     * В БД не маппится и живёт только внутри запроса — если подсказки нет,
     * getEpisodeCount() считает максимум по коллекциям самостоятельно.
     */
    private ?int $episodeCountHint = null;

    /** @var Collection<int, Translation> */
    #[ORM\OneToMany(mappedBy: 'title', targetEntity: Translation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->firstSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKodikId(): ?string
    {
        return $this->kodikId;
    }

    public function setKodikId(string $kodikId): static
    {
        $this->kodikId = $kodikId;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function isFromAmedia(): bool
    {
        return self::SOURCE_AMEDIA === $this->source;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isSerial(): bool
    {
        if (\in_array($this->type, ['serial', 'anime_serial', 'ova', 'ona'], true)) {
            return true;
        }

        // OVA/ONA и тайтлы с нестандартным типом считаем сериалом, если есть серии
        foreach ($this->translations as $translation) {
            if (!$translation->getEpisodes()->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getOriginalTitle(): ?string
    {
        return $this->originalTitle;
    }

    public function setOriginalTitle(?string $originalTitle): static
    {
        $this->originalTitle = $originalTitle;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getPoster(): ?string
    {
        return $this->poster;
    }

    public function setPoster(?string $poster): static
    {
        $this->poster = $poster;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getRatingKp(): ?string
    {
        return $this->ratingKp;
    }

    public function setRatingKp(?string $ratingKp): static
    {
        $this->ratingKp = $ratingKp;

        return $this;
    }

    public function getRatingImdb(): ?string
    {
        return $this->ratingImdb;
    }

    public function setRatingImdb(?string $ratingImdb): static
    {
        $this->ratingImdb = $ratingImdb;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;

        return $this;
    }

    /** @return list<string> */
    public function getGenres(): ?array
    {
        return $this->genres;
    }

    /** @param list<string>|null $genres */
    public function setGenres(?array $genres): static
    {
        $this->genres = $genres;

        return $this;
    }

    public function getDuration(): ?string
    {
        return $this->duration;
    }

    public function setDuration(?string $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getAgeRestriction(): ?string
    {
        return $this->ageRestriction;
    }

    public function setAgeRestriction(?string $ageRestriction): static
    {
        $this->ageRestriction = $ageRestriction;

        return $this;
    }

    public function getPremiere(): ?string
    {
        return $this->premiere;
    }

    public function setPremiere(?string $premiere): static
    {
        $this->premiere = $premiere;

        return $this;
    }

    public function getPlayerLink(): ?string
    {
        return $this->playerLink;
    }

    public function setPlayerLink(?string $playerLink): static
    {
        $this->playerLink = $playerLink;

        return $this;
    }

    public function getKinopoiskId(): ?string
    {
        return $this->kinopoiskId;
    }

    public function setKinopoiskId(?string $kinopoiskId): static
    {
        $this->kinopoiskId = $kinopoiskId;

        return $this;
    }

    public function getImdbId(): ?string
    {
        return $this->imdbId;
    }

    public function setImdbId(?string $imdbId): static
    {
        $this->imdbId = $imdbId;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function isWatching(): bool
    {
        return $this->watching;
    }

    public function setWatching(bool $watching): static
    {
        $this->watching = $watching;
        $this->watchingAt = $watching ? new \DateTimeImmutable() : null;

        return $this;
    }

    public function getWatchingAt(): ?\DateTimeImmutable
    {
        return $this->watchingAt;
    }

    public function setWatchingAt(?\DateTimeImmutable $watchingAt): static
    {
        $this->watchingAt = $watchingAt;

        return $this;
    }

    public function getFranchise(): ?string
    {
        return $this->franchise;
    }

    public function setFranchise(?string $franchise): static
    {
        $this->franchise = $franchise;

        return $this;
    }

    public function getSeasonNumber(): ?int
    {
        return $this->seasonNumber;
    }

    public function setSeasonNumber(?int $seasonNumber): static
    {
        $this->seasonNumber = $seasonNumber;

        return $this;
    }

    public function getSeasonPart(): ?int
    {
        return $this->seasonPart;
    }

    public function setSeasonPart(?int $seasonPart): static
    {
        $this->seasonPart = $seasonPart;

        return $this;
    }

    public function getFirstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(?\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    /** @return Collection<int, Translation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    /**
     * Эталонное число серий тайтла — «из скольки» в подписи «переведено N из M».
     * Берём у Amedia-озвучки (amd знает полный состав серий), а если её нет —
     * максимум по всем озвучкам.
     */
    public function getEpisodeTotal(): int
    {
        $amedia = null;
        $max = 0;
        foreach ($this->translations as $translation) {
            $count = $translation->getEpisodes()->count();
            if ('amedia' === $translation->getTechTitle()) {
                $amedia = max($amedia ?? 0, $count);
            }
            if ($count > $max) {
                $max = $count;
            }
        }

        return $amedia ?? $max;
    }

    /**
     * Максимум серий по всем озвучкам — для подписи на карточке
     * («… сериал (15 серий)»).
     *
     * В отличие от getEpisodeTotal(), которое за эталон берёт amedia-озвучку (для
     * подписи «переведено N из M»), здесь берётся самый полный плеер: если в
     * amedia 12 серий, а в kodik 15 — подписываем 15.
     */
    public function getEpisodeCount(): int
    {
        if (null !== $this->episodeCountHint) {
            return $this->episodeCountHint;
        }

        $max = 0;
        foreach ($this->translations as $translation) {
            $max = max($max, $translation->getEpisodes()->count());
        }

        return $max;
    }

    /** @internal Заполняется TitleRepository::searchCatalog() одним запросом. */
    public function setEpisodeCountHint(int $count): static
    {
        $this->episodeCountHint = $count;

        return $this;
    }

    public function addTranslation(Translation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setTitle($this);
        }

        return $this;
    }

    public function removeTranslation(Translation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getTitle() === $this) {
                $translation->setTitle(null);
            }
        }

        return $this;
    }
}
