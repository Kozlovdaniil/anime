<?php

namespace App\Entity;

use App\Repository\EpisodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Серия сериала внутри конкретной озвучки.
 */
#[ORM\Entity(repositoryClass: EpisodeRepository::class)]
#[ORM\Table(name: 'episodes')]
#[ORM\Index(columns: ['translation_id', 'season', 'episode_number'], options: ['unique' => true])]
class Episode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'episodes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Translation $translation = null;

    #[ORM\Column]
    private int $season = 1;

    #[ORM\Column]
    private int $episodeNumber = 1;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $name = null;

    /** iframe-ссылка на серию у Kodik (если API её отдал). */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $link = null;

    /** ID серии в Kodik API. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $kodikEpisodeId = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTranslation(): ?Translation
    {
        return $this->translation;
    }

    public function setTranslation(?Translation $translation): static
    {
        $this->translation = $translation;

        return $this;
    }

    public function getSeason(): int
    {
        return $this->season;
    }

    public function setSeason(int $season): static
    {
        $this->season = $season;

        return $this;
    }

    public function getEpisodeNumber(): int
    {
        return $this->episodeNumber;
    }

    public function setEpisodeNumber(int $episodeNumber): static
    {
        $this->episodeNumber = $episodeNumber;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

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

    public function getKodikEpisodeId(): ?string
    {
        return $this->kodikEpisodeId;
    }

    public function setKodikEpisodeId(?string $kodikEpisodeId): static
    {
        $this->kodikEpisodeId = $kodikEpisodeId;

        return $this;
    }
}
