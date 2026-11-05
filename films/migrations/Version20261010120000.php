<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Просмотренные серии: последняя просмотренная серия у тайтла (в БД,
 * без localStorage). Подсветка «просмотрено» и счётчик «N из M» строятся
 * по последней серии (кумулятивно), поэтому храним только её.
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Добавить поля last_season/last_episode/last_watched_at к titles (прогресс просмотра)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles ADD last_season INT DEFAULT NULL');
        $this->addSql('ALTER TABLE titles ADD last_episode INT DEFAULT NULL');
        $this->addSql('ALTER TABLE titles ADD last_watched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles DROP last_watched_at');
        $this->addSql('ALTER TABLE titles DROP last_episode');
        $this->addSql('ALTER TABLE titles DROP last_season');
    }
}