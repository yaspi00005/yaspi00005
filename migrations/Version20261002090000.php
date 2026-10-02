<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Poids des articles facultatif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article CHANGE poids_volume poids_volume NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE article SET poids_volume = 0 WHERE poids_volume IS NULL');
        $this->addSql('ALTER TABLE article CHANGE poids_volume poids_volume NUMERIC(10, 2) NOT NULL');
    }
}
