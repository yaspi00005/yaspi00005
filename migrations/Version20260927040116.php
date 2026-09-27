<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Journal d'activité : ajouts, modifications, suppressions et connexions, avec l'auteur.; /auto-generated, please modify/d
 */
final class Version20260927040116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal d\'activité (qui a fait quoi)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE journal (id INT AUTO_INCREMENT NOT NULL, date DATETIME NOT NULL, utilisateur_nom VARCHAR(120) NOT NULL, action VARCHAR(20) NOT NULL, entite VARCHAR(40) DEFAULT NULL, entite_id INT DEFAULT NULL, libelle VARCHAR(255) NOT NULL, changements JSON DEFAULT NULL, colis_id INT DEFAULT NULL, client_id INT DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, utilisateur_id INT DEFAULT NULL, INDEX IDX_C1A7E74DAA9E377A (date), INDEX IDX_C1A7E74D1A2918279BEA957A (entite, entite_id), INDEX IDX_C1A7E74D4D268D70 (colis_id), INDEX IDX_C1A7E74D19EB6921 (client_id), INDEX IDX_C1A7E74DFB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE journal ADD CONSTRAINT FK_C1A7E74DFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE journal DROP FOREIGN KEY FK_C1A7E74DFB88E14F');
        $this->addSql('DROP TABLE journal');
    }
}
