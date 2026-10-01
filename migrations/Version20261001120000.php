<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fréquentation anonyme du site vitrine (compteurs par jour)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trafic_vitrine (id INT AUTO_INCREMENT NOT NULL, jour DATE NOT NULL, type VARCHAR(20) NOT NULL, cle VARCHAR(60) NOT NULL, nombre INT NOT NULL, UNIQUE INDEX UNIQ_TRAFIC_JOUR_TYPE_CLE (jour, type, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trafic_vitrine');
    }
}
