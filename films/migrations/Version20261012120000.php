<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Связка сезонов одного произведения: у titles появляются franchise
 * (нормализованный ключ названия без номера сезона) и season_number. Тайтлы с
 * одинаковым franchise считаются сезонами — карточка показывает ссылки на них.
 */
final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'titles: franchise + season_number (связка сезонов) и индекс по franchise';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles ADD franchise VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE titles ADD season_number SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE titles ADD season_part SMALLINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_titles_franchise ON titles (franchise)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_titles_franchise');
        $this->addSql('ALTER TABLE titles DROP franchise');
        $this->addSql('ALTER TABLE titles DROP season_number');
        $this->addSql('ALTER TABLE titles DROP season_part');
    }
}
