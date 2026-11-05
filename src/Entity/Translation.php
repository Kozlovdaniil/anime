<?php

namespace App\Entity;

use App\Repository\TranslationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Озвучка/перевод тайтла (у Kodik у каждого тайтла список переводов).
 */
#[ORM\Entity(repositoryClass: TranslationRepository::class)]
#[ORM\Table(name: 'translations')]
#[ORM\Index(columns: ['title_id', 'kodik_translation_id'], options: ['unique' => true])]
class Translation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Title $title = null;

    /** ID перевода в Kodik API. */
    #[ORM\Column(length: 64)]
    private string $kodikTranslationId = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    /** tech title перевода, например "lostfilm", "jaskier". */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $techTitle = null;

    /** voice | dub | subtitle ... */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $type = null;

    #[ORM\Column]
    private bool $isVoice = true;

    /** Приоритет для сортировки (чем больше — тем выше). */
    #[ORM\Column]
    private int $priority = 0;

    /** Базовая iframe-ссылка плеера этой озвучки (для фильмов и шаблона серий). */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $link = null;

    /**
     * Последняя просмотренная серия: сезон. Прогресс ведётся ОТДЕЛЬНО по каждой
     * озвучке (у разных озвучок/плееров могут отличаться нумерация и состав серий),
     * считается кумулятивно по последней серии.
     */
    #[ORM\Column(nullable: true)]
    private ?int $lastSeason = null;

    /** Последняя просмотренная серия: номер серии. */
    #[ORM\Column(nullable: true)]
    private ?int $lastEpisode = null;

    /** Когда досмотрели до последней серии этой озвучки. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastWatchedAt = null;

    /** @var Collection<int, Episode> */
    #[ORM\OneToMany(mappedBy: 'translation', targetEntity: Episode::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $episodes;

    public function __construct()
    {
        $this->episodes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?Title
    {
        return $this->title;
    }

    public function setTitle(?Title $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getKodikTranslationId(): string
    {
        return $this->kodikTranslationId;
    }

    public function setKodikTranslationId(string $kodikTranslationId): static
    {
        $this->kodikTranslationId = $kodikTranslationId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTechTitle(): ?string
    {
        return $this->techTitle;
    }

    public function setTechTitle(?string $techTitle): static
    {
        $this->techTitle = $techTitle;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isVoice(): bool
    {
        return $this->isVoice;
    }

    public function setIsVoice(bool $isVoice): static
    {
        $this->isVoice = $isVoice;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getLastSeason(): ?int
    {
        return $this->lastSeason;
    }

    public function getLastEpisode(): ?int
    {
        return $this->lastEpisode;
    }

    public function getLastWatchedAt(): ?\DateTimeImmutable
    {
        return $this->lastWatchedAt;
    }

    /** Есть ли прогресс просмотра этой озвучки (хотя бы одна серия отмечена). */
    public function hasProgress(): bool
    {
        return null !== $this->lastEpisode;
    }

    /**
     * Отметить серию этой озвучки как просмотренную. Храним только последнюю
     * серию: всё до неё включительно считается просмотренным, поэтому двигаем
     * указатель только вперёд (сравниваем пары «сезон, серия»).
     */
    public function markWatched(?int $season, ?int $episode): static
    {
        if (null === $episode || $episode < 1) {
            return $this;
        }
        $season = (null !== $season && $season >= 1) ? $season : 1;
        $lastSeason = $this->lastSeason ?? 1;
        $lastEpisode = $this->lastEpisode ?? 0;

        if ($season > $lastSeason || ($season === $lastSeason && $episode > $lastEpisode)) {
            $this->lastSeason = $season;
            $this->lastEpisode = $episode;
            $this->lastWatchedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    /** @return Collection<int, Episode> */
    public function getEpisodes(): Collection
    {
        return $this->episodes;
    }

    public function addEpisode(Episode $episode): static
    {
        if (!$this->episodes->contains($episode)) {
            $this->episodes->add($episode);
            $episode->setTranslation($this);
        }

        return $this;
    }

    public function removeEpisode(Episode $episode): static
    {
        if ($this->episodes->removeElement($episode)) {
            if ($episode->getTranslation() === $this) {
                $episode->setTranslation(null);
            }
        }

        return $this;
    }
}
