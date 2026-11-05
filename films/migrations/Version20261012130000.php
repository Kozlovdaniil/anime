<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Вышло продолжение»: у titles появляется first_seen_at — когда тайтл впервые
 * попал в каталог. По нему блок «Вышло продолжение» показывает только свежие
 * (за последние N дней) новые сезоны, а не старые, которые пользователь просто
 * не смотрел.
 *
 * Существующие тайтлы помечаем «давно известными» (год назад): иначе после
 * деплоя все давно вышедшие продолжения разом попали бы в блок. Настоящие новые
 * сезоны получат first_seen_at при первом импорте уже с этим кодом (см.
 * Title::__construct()).
 */
final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'titles.first_seen_at (когда тайтл впервые появился — для уведомления о новом сезоне)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles ADD first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE titles SET first_seen_at = NOW() - INTERVAL '365 days' WHERE first_seen_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE titles DROP first_seen_at');
    }
}
