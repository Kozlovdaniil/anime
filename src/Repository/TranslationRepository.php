<?php

namespace App\Repository;

use App\Entity\Title;
use App\Entity\Translation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Translation>
 */
class TranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Translation::class);
    }

    public function findOneByKodikIdForTitle(Title $title, string $kodikTranslationId): ?Translation
    {
        return $this->findOneBy([
            'title' => $title,
            'kodikTranslationId' => $kodikTranslationId,
        ]);
    }
}
