<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Administration du site : nouveautés, témoignages des clients, partenaires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE nouveaute (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(120) NOT NULL, texte VARCHAR(600) DEFAULT NULL, etiquette VARCHAR(20) NOT NULL, icone VARCHAR(40) NOT NULL, lien VARCHAR(255) DEFAULT NULL, date_fin DATE DEFAULT NULL, ordre INT NOT NULL, publiee TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE temoignage_client (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, texte VARCHAR(500) DEFAULT NULL, video VARCHAR(255) DEFAULT NULL, apercu VARCHAR(255) DEFAULT NULL, ordre INT NOT NULL, publie TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE partenaire (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, logo VARCHAR(255) DEFAULT NULL, url VARCHAR(255) DEFAULT NULL, ordre INT NOT NULL, publie TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE nouveaute');
        $this->addSql('DROP TABLE temoignage_client');
        $this->addSql('DROP TABLE partenaire');
    }
}
