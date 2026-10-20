<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registre des appels : montant payé et ordre de passage du camion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appel ADD montant_paye INT DEFAULT NULL, ADD ordre_tournee INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appel DROP montant_paye, DROP ordre_tournee');
    }
}
