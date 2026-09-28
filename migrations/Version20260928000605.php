<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928000605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Charges des expéditions (calcul de la marge)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE depense_expedition (id INT AUTO_INCREMENT NOT NULL, categorie VARCHAR(20) NOT NULL, libelle VARCHAR(120) DEFAULT NULL, montant INT NOT NULL, date DATE NOT NULL, created_at DATETIME NOT NULL, expedition_id INT NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_5C3B23F9576EF81E (expedition_id), INDEX IDX_5C3B23F960BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE depense_expedition ADD CONSTRAINT FK_5C3B23F9576EF81E FOREIGN KEY (expedition_id) REFERENCES expedition (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE depense_expedition ADD CONSTRAINT FK_5C3B23F960BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE depense_expedition DROP FOREIGN KEY FK_5C3B23F9576EF81E');
        $this->addSql('ALTER TABLE depense_expedition DROP FOREIGN KEY FK_5C3B23F960BB6FE6');
        $this->addSql('DROP TABLE depense_expedition');
    }
}
