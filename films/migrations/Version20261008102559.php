<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008102559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE titles ADD source VARCHAR(16) DEFAULT \'kodik\' NOT NULL');
        $this->addSql('ALTER TABLE titles ADD source_url VARCHAR(1024) DEFAULT NULL');
        $this->addSql('ALTER TABLE translations ADD link VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE titles DROP source');
        $this->addSql('ALTER TABLE titles DROP source_url');
        $this->addSql('ALTER TABLE translations DROP link');
    }
}
