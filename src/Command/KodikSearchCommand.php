<?php

namespace App\Command;

use App\Service\Kodik\KodikApiException;
use App\Service\Kodik\KodikClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'kodik:search',
    description: 'Быстрый поиск по Kodik API без сохранения в БД (отладка)'
)]
class KodikSearchCommand extends Command
{
    public function __construct(private readonly KodikClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Название')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Тип: movie, serial...')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Лимит', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $data = $this->client->search([
                'title' => $input->getArgument('query'),
                'type' => $input->getOption('type'),
                'limit' => $input->getOption('limit'),
            ]);
        } catch (KodikApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title(sprintf('Найдено: %s', $data['total'] ?? '?'));

        $rows = [];
        foreach ($data['results'] ?? [] as $r) {
            $rows[] = [
                $r['id'] ?? '?',
                $r['type'] ?? '?',
                mb_substr((string) ($r['title'] ?? ''), 0, 40),
                $r['releaseYear'] ?? '-',
                implode(', ', array_column($r['translations'] ?? [], 'title')) ?: '-',
            ];
        }
        $io->table(['ID', 'Type', 'Title', 'Year', 'Translations'], $rows);

        return Command::SUCCESS;
    }
}
