<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registre des appels, camions et camion des ramassages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE camion (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(60) NOT NULL, immatriculation VARCHAR(20) DEFAULT NULL, volume VARCHAR(30) DEFAULT NULL, couleur VARCHAR(7) NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, chauffeur_id INT DEFAULT NULL, INDEX IDX_5DD566EC85C0B3BE (chauffeur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql("CREATE TABLE appel (id INT AUTO_INCREMENT NOT NULL, date_appel DATE NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) DEFAULT NULL, telephone VARCHAR(30) NOT NULL, code_postal VARCHAR(10) NOT NULL, ville VARCHAR(100) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, date_conteneur DATE DEFAULT NULL, estimation INT DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, confirme TINYINT NOT NULL, source VARCHAR(20) DEFAULT 'telephone' NOT NULL, created_at DATETIME NOT NULL, utilisateur_id INT DEFAULT NULL, client_id INT NOT NULL, expedition_id INT DEFAULT NULL, camion_id INT DEFAULT NULL, ramassage_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_130D3BDCFE2BAAF (ramassage_id), INDEX IDX_130D3BDBBC03B31 (date_appel), INDEX IDX_130D3BDFB88E14F (utilisateur_id), INDEX IDX_130D3BD19EB6921 (client_id), INDEX IDX_130D3BD576EF81E (expedition_id), INDEX IDX_130D3BD3A706D3 (camion_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE camion ADD CONSTRAINT FK_5DD566EC85C0B3BE FOREIGN KEY (chauffeur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE appel ADD CONSTRAINT FK_130D3BDFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE appel ADD CONSTRAINT FK_130D3BD19EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE appel ADD CONSTRAINT FK_130D3BD576EF81E FOREIGN KEY (expedition_id) REFERENCES expedition (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE appel ADD CONSTRAINT FK_130D3BD3A706D3 FOREIGN KEY (camion_id) REFERENCES camion (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE appel ADD CONSTRAINT FK_130D3BDCFE2BAAF FOREIGN KEY (ramassage_id) REFERENCES ramassage (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ramassage ADD camion_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ramassage ADD CONSTRAINT FK_945DF2CF3A706D3 FOREIGN KEY (camion_id) REFERENCES camion (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_945DF2CF3A706D3 ON ramassage (camion_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ramassage DROP FOREIGN KEY FK_945DF2CF3A706D3');
        $this->addSql('DROP INDEX IDX_945DF2CF3A706D3 ON ramassage');
        $this->addSql('ALTER TABLE ramassage DROP camion_id');
        $this->addSql('DROP TABLE appel');
        $this->addSql('DROP TABLE camion');
    }
}
