<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registre des appels : bouton « Traité » (date et auteur)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appel ADD traite_le DATETIME DEFAULT NULL, ADD traite_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE appel ADD CONSTRAINT FK_130D3BD167FABE8 FOREIGN KEY (traite_par_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_130D3BD167FABE8 ON appel (traite_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appel DROP FOREIGN KEY FK_130D3BD167FABE8');
        $this->addSql('DROP INDEX IDX_130D3BD167FABE8 ON appel');
        $this->addSql('ALTER TABLE appel DROP traite_le, DROP traite_par_id');
    }
}
