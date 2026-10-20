<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colis : plusieurs photos par colis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE photo_colis (id INT AUTO_INCREMENT NOT NULL, colis_id INT NOT NULL, fichier VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_FD5AD84E4D268D70 (colis_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE photo_colis ADD CONSTRAINT FK_FD5AD84E4D268D70 FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE photo_colis');
    }
}
