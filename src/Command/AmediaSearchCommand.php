<?php

namespace App\Command;

use App\Service\Amedia\AmediaException;
use App\Service\Amedia\AmediaSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'amd:search',
    description: 'Поиск по amd.online и импорт найденного'
)]
class AmediaSearchCommand extends Command
{
    public function __construct(
        private readonly AmediaSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Что искать (например "Интерстеллар")')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Максимум результатов для импорта', '20')
            ->setHelp(<<<EOF
Ищет тайтлы на amd.online и импортирует найденные карточки в БД.

  <info>php bin/console amd:search "Реинкарнация"</info>
  <info>php bin/console amd:search "Псайрен" --limit=5</info>
EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = (string) $input->getArgument('query');
        $limit = max(1, (int) $input->getOption('limit'));

        $io->title('Поиск на amd.online: '.$query);

        try {
            $items = $this->sync->importSearch($query, $limit);
        } catch (AmediaException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Готово: импортировано %d, пропущено %d, с ошибками %d.',
            $items['imported'],
            $items['skipped'],
            $items['failed']
        ));

        return Command::SUCCESS;
    }
}
