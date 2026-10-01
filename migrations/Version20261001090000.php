<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Annonce des expéditions sur le site vitrine (publication, texte, date limite de dépôt)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expedition ADD publiee_sur_site TINYINT DEFAULT 0 NOT NULL, ADD annonce VARCHAR(500) DEFAULT NULL, ADD date_limite_depot DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expedition DROP publiee_sur_site, DROP annonce, DROP date_limite_depot');
    }
}
