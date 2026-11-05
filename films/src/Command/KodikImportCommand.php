<?php

namespace App\Command;

use App\Service\Kodik\KodikApiException;
use App\Service\Kodik\KodikClient;
use App\Service\Kodik\KodikSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'kodik:import',
    description: 'Импорт тайтлов из Kodik API в БД (парсинг)'
)]
class KodikImportCommand extends Command
{
    public function __construct(
        private readonly KodikClient $client,
        private readonly KodikSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::OPTIONAL, 'Название для поиска (например "Интерстеллар")')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Тип: movie, serial, cartoon, anime...')
            ->addOption('year', 'y', InputOption::VALUE_REQUIRED, 'Год выпуска')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Номер страницы выдачи', '1')
            ->addOption('no-episodes', null, InputOption::VALUE_NONE, 'Не грузить серии сериалов')
            ->setHelp(<<<EOF
Ищет тайтлы в Kodik API и сохраняет их вместе с озвучками в локальную БД.

  <info>php bin/console kodik:import "Интерстеллар"</info>
  <info>php bin/console kodik:import --type=serial --year=2023 --page=2</info>

Без аргументов импортирует первую страницу каталога.
EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->client->isConfigured()) {
            $io->warning('KODIK_API_TOKEN не задан — это заглушка. Пропишите токен в .env, чтобы парсер работал.');

            return Command::SUCCESS;
        }

        $filters = array_filter([
            'title' => $input->getArgument('query'),
            'type' => $input->getOption('type'),
            'year' => $input->getOption('year'),
            'page' => $input->getOption('page'),
        ]);

        $io->title('Импорт из Kodik API');
        $io->listing([json_encode($filters, JSON_UNESCAPED_UNICODE)]);

        try {
            $result = $this->sync->importSearch($filters, !$input->getOption('no-episodes'));
        } catch (KodikApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Готово: импортировано %d, уже были в базе %d.',
            $result['imported'],
            $result['skipped']
        ));

        return Command::SUCCESS;
    }
}
