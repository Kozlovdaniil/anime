<?php

namespace App\Command;

use App\Service\Amedia\AmediaException;
use App\Service\Amedia\AmediaSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'amd:import',
    description: 'Импорт одного тайтла с amd.online по URL'
)]
class AmediaImportCommand extends Command
{
    public function __construct(
        private readonly AmediaSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'URL страницы тайтла (или путь вида 5768-psajren.html)')
            ->setHelp(<<<EOF
Загружает страницу тайтла amd.online и сохраняет метаданные, плееры и серии.

  <info>php bin/console amd:import https://amd.online/5768-psajren.html</info>
  <info>php bin/console amd:import 5768-psajren.html</info>

Если тайтл с таким же названием уже есть в базе (из Kodik) — данные
слиты в существующую карточку, амедия-плеер получает приоритет.
EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = (string) $input->getArgument('url');

        $io->title('Импорт тайтла: '.$url);

        try {
            $title = $this->sync->importUrl($url);
        } catch (AmediaException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $episodes = 0;
        foreach ($title->getTranslations() as $translation) {
            $episodes += $translation->getEpisodes()->count();
        }

        $io->success(sprintf(
            'Импортировано: %s (%s, %d озвучек, %d серий). URL: /title/%d',
            $title->getTitle(),
            $title->getType(),
            $title->getTranslations()->count(),
            $episodes,
            $title->getId()
        ));

        return Command::SUCCESS;
    }
}
