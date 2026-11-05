<?php

namespace App\Command;

use App\Entity\Episode;
use App\Entity\Title;
use App\Entity\Translation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Наполнение БД демо-данными для проверки интерфейса,
 * когда токен Kodik ещё не получен.
 */
#[AsCommand(
    name: 'app:seed-demo',
    description: 'Заполнить БД демо-данными (без обращения к Kodik API)'
)]
class SeedDemoCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge', 'p', InputOption::VALUE_NONE, 'Сначала удалить все тайтлы');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('purge')) {
            foreach ($this->em->getRepository(Title::class)->findAll() as $t) {
                $this->em->remove($t);
            }
            $this->em->flush();
            $io->note('Старые тайтлы удалены.');
        }

        $demo = [
            [
                'kodikId' => 'demo-movie-1',
                'type' => 'movie',
                'title' => 'Интерстеллар',
                'originalTitle' => 'Interstellar',
                'year' => 2014,
                'ratingKp' => '8.6',
                'ratingImdb' => '8.7',
                'country' => 'США, Великобритания',
                'genres' => ['Фантастика', 'Драма', 'Приключения'],
                'duration' => '2ч 49м',
                'ageRestriction' => '12+',
                'description' => 'Когда засуха, пыльные бури и вымирание растений приводят человечество к продовольственному кризису...',
                'poster' => null,
                'translations' => ['Лицензия', 'Дубляж (LostFilm)'],
            ],
            [
                'kodikId' => 'demo-serial-1',
                'type' => 'serial',
                'title' => 'Демо-Сериал: Орбита',
                'originalTitle' => 'Demo: Orbit',
                'year' => 2023,
                'ratingKp' => '7.9',
                'ratingImdb' => '8.1',
                'country' => 'Россия',
                'genres' => ['Детектив', 'Фантастика'],
                'duration' => '45м',
                'ageRestriction' => '16+',
                'description' => 'Демо-тайтл для проверки плеера: несколько озвучек, два сезона серий.',
                'poster' => null,
                'translations' => ['Дубляж', 'Оригинал + субтитры'],
                'seasons' => [1, 2],
                'episodesPerSeason' => 4,
            ],
        ];

        $created = 0;
        foreach ($demo as $raw) {
            $existing = $this->em->getRepository(Title::class)->findOneBy(['kodikId' => $raw['kodikId']]);
            if ($existing) {
                $io->text(sprintf('Уже есть: %s', $raw['title']));
                continue;
            }

            $title = (new Title())
                ->setKodikId($raw['kodikId'])
                ->setType($raw['type'])
                ->setTitle($raw['title'])
                ->setOriginalTitle($raw['originalTitle'])
                ->setYear($raw['year'])
                ->setRatingKp($raw['ratingKp'])
                ->setRatingImdb($raw['ratingImdb'])
                ->setCountry($raw['country'])
                ->setGenres($raw['genres'])
                ->setDuration($raw['duration'])
                ->setAgeRestriction($raw['ageRestriction'])
                ->setDescription($raw['description'])
                ->setPlayerLink('https://kodikplayer.com/seria/demo-link?fake=1')
                ->setLastSyncedAt(new \DateTimeImmutable());

            $priority = 100;
            foreach ($raw['translations'] as $name) {
                $tr = new Translation();
                $tr->setKodikTranslationId(md5($name))
                    ->setName($name)
                    ->setTechTitle(strtolower(preg_replace('/[^a-zа-я0-9]+/ui', '-', $name)))
                    ->setType('voice')
                    ->setIsVoice(true)
                    ->setPriority($priority);
                $priority -= 10;

                if (isset($raw['seasons'])) {
                    foreach ($raw['seasons'] as $season) {
                        for ($n = 1; $n <= $raw['episodesPerSeason']; ++$n) {
                            $ep = (new Episode())
                                ->setSeason($season)
                                ->setEpisodeNumber($n)
                                ->setName(sprintf('Серия %d', $n));
                            $tr->addEpisode($ep);
                        }
                    }
                }

                $title->addTranslation($tr);
            }

            $this->em->persist($title);
            ++$created;
        }

        $this->em->flush();
        $io->success(sprintf('Создано демо-тайтлов: %d.', $created));

        return Command::SUCCESS;
    }
}
