<?php

namespace App\Command;

use App\Service\Amedia\AmediaClient;
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
    name: 'amd:sync',
    description: 'Импорт каталога amd.online (Amedia) в БД'
)]
class AmediaSyncCommand extends Command
{
    public function __construct(
        private readonly AmediaSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Раздел каталога (по умолчанию — каталог по дате редактирования)', AmediaClient::CATALOG_PATH_BY_EDITDATE)
            ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Сколько страниц пагинации импортировать (0 — все)', '0')
            ->addOption('skip-existing', 's', InputOption::VALUE_NONE, 'Не перечитывать уже импортированные тайтлы')
            ->setHelp(<<<EOF
Импортирует каталог, отсортированный по дате редактирования
(/f/sort=editdate/order=desc/), и листает пагинацию до последней страницы —
сейчас до 417. Так свежие апдейты (вышедшие серии) обрабатываются первыми,
и ограниченный импорт забирает самое новое.

  <info>php bin/console amd:sync</info>                        # весь каталог, свежее первым
  <info>php bin/console amd:sync --max-pages=10</info>          # только первые 10 страниц
  <info>php bin/console amd:sync "/" -s</info>                  # блок «Новые аниме» (по дате добавления), только новые
  <info>php bin/console amd:sync "/anime/tip/фильм/" -s</info>  # другой раздел, только новые

Переход между страницами — по ссылке «вперёд» из блока пагинации,
поэтому обход останавливается на последней странице.

Без <comment>-s</comment> уже импортированные тайтлы перечитываются, но обновляются
аддитивно: доклеиваются только недостающие серии по каждой озвучке (новые
озвучки — целиком), уже имеющиеся серии и их ссылки не перезаписываются,
из базы ничего не удаляется. Отмечать «новое» не нужно: блок «Новые серии»
считает непросмотренное от вашего прогресса.

С <comment>-s</comment> тайтлы, которые уже есть в базе, пропускаются целиком.

На полном обходе (сотни страниц) запускайте с <comment>--no-debug</comment>, иначе
Doctrine-профайлер копит backtrace запросов и процесс падает по памяти:

  <info>php bin/console --no-debug amd:sync</info>
EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');
        $maxPages = max(0, (int) $input->getOption('max-pages'));

        $io->title(sprintf(
            'Импорт каталога amd.online: %s (страниц: %s)',
            $path,
            $maxPages > 0 ? (string) $maxPages : 'все'
        ));

        try {
            $result = $this->sync->importCatalog(
                $path,
                $maxPages,
                (bool) $input->getOption('skip-existing'),
                // прогресс после каждой страницы (а не только в конце обхода)
                static function (int $page, array $pageCounts, int $episodes) use ($io): void {
                    $io->writeln(sprintf(
                        '  Страница %d: импортировано %d, пропущено %d, с ошибками %d. Новых серий: %d.',
                        $page,
                        $pageCounts['imported'],
                        $pageCounts['skipped'],
                        $pageCounts['failed'],
                        $episodes
                    ));
                }
            );
        } catch (AmediaException $e) {
            $io->error($e->getTraceAsString());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Готово: импортировано %d, пропущено %d, с ошибками %d. Новых серий: %d.',
            $result['imported'],
            $result['skipped'],
            $result['failed'],
            $result['episodes'] ?? 0
        ));

        return Command::SUCCESS;
    }
}
