<?php

namespace App\Command;

use App\Entity\Title;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Удаление тайтлов из локальной БД.
 *
 * Озвучки и серии удаляются каскадно (ON DELETE CASCADE в схеме), поэтому
 * обходимся одним массовым DELETE без загрузки сущностей в память.
 */
#[AsCommand(
    name: 'app:titles:clear',
    description: 'Удалить тайтлы из БД (со всеми озвучками и сериями)'
)]
class TitleClearCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Удалить без подтверждения')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Удалить только один источник: kodik | amedia')
            ->setHelp(<<<EOF
Удаляет тайтлы из локальной БД вместе с их озвучками и сериями.

  <info>php bin/console app:titles:clear</info>            # спросит подтверждение
  <info>php bin/console app:titles:clear --force</info>    # удалить всё без вопроса
  <info>php bin/console app:titles:clear --source=amedia -f</info> # только импорт с amd.online
EOF);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $input->getOption('source');
        if (null !== $source && !\in_array($source, [Title::SOURCE_KODIK, Title::SOURCE_AMEDIA], true)) {
            $io->error(sprintf('Неизвестный источник "%s" (ожидается kodik или amedia).', $source));

            return Command::INVALID;
        }

        $repo = $this->em->getRepository(Title::class);
        $total = $repo->count(null !== $source ? ['source' => $source] : []);

        if (0 === $total) {
            $io->success('В базе нет тайтлов для удаления.');

            return Command::SUCCESS;
        }

        $scope = null !== $source ? sprintf('источника "%s"', $source) : 'всех источников';
        if (!$input->getOption('force')
            && !$io->confirm(sprintf('Удалить %d тайтл(ов) (%s)? Озвучки и серии удалятся каскадно.', $total, $scope), false)
        ) {
            $io->note('Отменено.');

            return Command::SUCCESS;
        }

        $qb = $this->em->createQueryBuilder()->delete(Title::class, 't');
        if (null !== $source) {
            $qb->where('t.source = :source')->setParameter('source', $source);
        }

        $deleted = $qb->getQuery()->execute();

        $io->success(sprintf('Удалено тайтлов: %d.', $deleted));

        return Command::SUCCESS;
    }
}
