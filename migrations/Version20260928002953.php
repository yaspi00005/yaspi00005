<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928002953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corbeille des colis (7 jours) et compteur des numéros de suivi';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE compteur_numero (prefixe VARCHAR(20) NOT NULL, dernier INT NOT NULL, PRIMARY KEY (prefixe)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE colis ADD supprime_le DATETIME DEFAULT NULL, ADD supprime_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE colis ADD CONSTRAINT FK_470BDFF9ACC02199 FOREIGN KEY (supprime_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_470BDFF9EAF26A2B ON colis (supprime_le)');
        $this->addSql('CREATE INDEX IDX_470BDFF9ACC02199 ON colis (supprime_par_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE compteur_numero');
        $this->addSql('ALTER TABLE colis DROP FOREIGN KEY FK_470BDFF9ACC02199');
        $this->addSql('DROP INDEX IDX_470BDFF9EAF26A2B ON colis');
        $this->addSql('DROP INDEX IDX_470BDFF9ACC02199 ON colis');
        $this->addSql('ALTER TABLE colis DROP supprime_le, DROP supprime_par_id');
    }
}
