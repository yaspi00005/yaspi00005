<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registre des appels : le paiement se note à la main sur la feuille du camion (colonne montant_paye retirée)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appel DROP montant_paye');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appel ADD montant_paye INT DEFAULT NULL');
    }
}
