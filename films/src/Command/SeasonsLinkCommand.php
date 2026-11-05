<?php

namespace App\Command;

use App\Entity\Title;
use App\Service\SeasonLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Разовая (и повторяемая) простановка связи сезонов: проходит по всем тайтлам,
 * считает franchise + season_number из названия (App\Service\SeasonLinker).
 *
 * После этого связи сезонов строятся запросом, и карточка показывает ссылки
 * на другие сезоны. Далее эти же поля проставляет обычный amd:sync.
 */
#[AsCommand(
    name: 'app:seasons:link',
    description: 'Связать сезоны: проставить franchise и season_number всем тайтлам'
)]
class SeasonsLinkCommand extends Command
{
    private const BATCH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeasonLinker $linker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Связка сезонов: разбор названий и простановка franchise/season_number');

        $qb = $this->em->createQuery('SELECT t FROM '.Title::class.' t ORDER BY t.id ASC');

        $total = 0;
        $changed = 0;
        $offset = 0;

        while (true) {
            $qb->setFirstResult($offset)->setMaxResults(self::BATCH);
            /** @var list<Title> $titles */
            $titles = $qb->getResult();
            if (!$titles) {
                break;
            }

            foreach ($titles as $title) {
                $before = [$title->getFranchise(), $title->getSeasonNumber(), $title->getSeasonPart()];
                $this->linker->apply($title);
                if ([$title->getFranchise(), $title->getSeasonNumber(), $title->getSeasonPart()] !== $before) {
                    ++$changed;
                }
                ++$total;
            }

            $this->em->flush();
            $this->em->clear();
            $offset += self::BATCH;

            $io->write(sprintf("\r  обработано: %d", $total));
        }

        $groups = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ('
            .'SELECT franchise FROM titles WHERE franchise IS NOT NULL GROUP BY franchise HAVING COUNT(*) > 1'
            .') x'
        );

        $io->newLine(2);
        $io->success(sprintf(
            'Обработано тайтлов: %d, изменено: %d. Франшиз из 2+ сезонов: %d.',
            $total,
            $changed,
            $groups
        ));

        return Command::SUCCESS;
    }
}
