<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Список «Смотрю»: флаг и время добавления у тайтла.
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Добавить поля watching/watching_at к titles (раздел «Смотрю»)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles ADD watching BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE titles ADD watching_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles DROP watching');
        $this->addSql('ALTER TABLE titles DROP watching_at');
    }
}
