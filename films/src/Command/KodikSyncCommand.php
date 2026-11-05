<?php

namespace App\Command;

use App\Repository\TitleRepository;
use App\Service\Kodik\KodikApiException;
use App\Service\Kodik\KodikSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'kodik:sync',
    description: 'Обновление данных уже импортированных тайтлов (оззвучки, серии)'
)]
class KodikSyncCommand extends Command
{
    public function __construct(
        private readonly TitleRepository $titles,
        private readonly KodikSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kodik-id', null, InputOption::VALUE_REQUIRED, 'Обновить только один тайтл по kodik id')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Сколько тайтлов обновить', '50')
            ->setHelp(<<<EOF
Проходит по импортированным тайтлам и обновляет серии/озвучки.

  <info>php bin/console kodik:sync</info>
  <info>php bin/console kodik:sync --kodik-id=xxxxxx</info>
EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $qb = $this->titles->createQueryBuilder('t')->orderBy('t.lastSyncedAt', 'ASC');
        if ($id = $input->getOption('kodik-id')) {
            $qb->andWhere('t.kodikId = :id')->setParameter('id', $id);
        }
        $qb->setMaxResults(max(1, (int) $input->getOption('limit')));

        $titles = $qb->getQuery()->getResult();
        if (!$titles) {
            $io->warning('Нет тайтлов для синхронизации. Сначала выполните kodik:import.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Синхронизация %d тайтлов', \count($titles)));
        $totalEpisodes = 0;
        $errors = 0;

        foreach ($titles as $title) {
            try {
                $n = $this->sync->syncEpisodes($title);
                $totalEpisodes += $n;
                $io->text(sprintf('  %s — %d серий', $title->getTitle(), $n));
            } catch (KodikApiException $e) {
                ++$errors;
                $io->warning(sprintf('  %s: %s', $title->getTitle(), $e->getMessage()));
                break;
            }
        }

        $io->success(sprintf('Синхронизировано серий: %d. Ошибок: %d.', $totalEpisodes, $errors));

        return Command::SUCCESS;
    }
}
