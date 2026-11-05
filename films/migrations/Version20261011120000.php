<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Просмотренные серии — теперь ОТДЕЛЬНО по каждой озвучке (переводу), а не
 * общим полем у тайтла. У разных озвучек/плееров отличаются нумерация и состав
 * серий, поэтому прогресс (последняя просмотренная серия) переезжает с titles
 * на translations. Старый прогресс тайтла переносим на его самую приоритетную
 * озвучку — по ней обычно и смотрели (дефолтная озвучка в плеере).
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Прогресс просмотра по озвучкам: поля last_season/last_episode/last_watched_at у translations (и удаление их у titles)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE translations ADD last_season INT DEFAULT NULL');
        $this->addSql('ALTER TABLE translations ADD last_episode INT DEFAULT NULL');
        $this->addSql('ALTER TABLE translations ADD last_watched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Перенос старого прогресса тайтла на его самую приоритетную озвучку.
        $this->addSql(<<<'SQL'
UPDATE translations tr
SET last_season = t.last_season,
    last_episode = t.last_episode,
    last_watched_at = t.last_watched_at
FROM titles t
WHERE tr.title_id = t.id
  AND t.last_episode IS NOT NULL
  AND tr.id = (
      SELECT tr2.id FROM translations tr2
      WHERE tr2.title_id = t.id
      ORDER BY tr2.priority DESC, tr2.id ASC
      LIMIT 1
  )
SQL);

        $this->addSql('ALTER TABLE titles DROP last_watched_at');
        $this->addSql('ALTER TABLE titles DROP last_episode');
        $this->addSql('ALTER TABLE titles DROP last_season');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles ADD last_season INT DEFAULT NULL');
        $this->addSql('ALTER TABLE titles ADD last_episode INT DEFAULT NULL');
        $this->addSql('ALTER TABLE titles ADD last_watched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Обратно — прогресс самой «свежей» озвучки тайтла.
        $this->addSql(<<<'SQL'
UPDATE titles t
SET last_season = tr.last_season,
    last_episode = tr.last_episode,
    last_watched_at = tr.last_watched_at
FROM translations tr
WHERE tr.title_id = t.id
  AND tr.last_episode IS NOT NULL
  AND tr.last_watched_at = (
      SELECT MAX(tr2.last_watched_at) FROM translations tr2
      WHERE tr2.title_id = t.id AND tr2.last_episode IS NOT NULL
  )
SQL);
        $this->addSql('ALTER TABLE translations DROP last_watched_at');
        $this->addSql('ALTER TABLE translations DROP last_episode');
        $this->addSql('ALTER TABLE translations DROP last_season');
    }
}
