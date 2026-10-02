<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Administration du site : photos de la galerie « En images »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE photo_site (id INT AUTO_INCREMENT NOT NULL, photo VARCHAR(255) NOT NULL, legende VARCHAR(150) DEFAULT NULL, categorie VARCHAR(20) NOT NULL, ordre INT NOT NULL, publie TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE photo_site');
    }
}
