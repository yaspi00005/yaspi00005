<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928003548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Barème du bonus fidélité modifiable (paliers en base, valeurs d\'origine reprises)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE palier_bonus (id INT AUTO_INCREMENT NOT NULL, seuil INT NOT NULL, montant INT NOT NULL, UNIQUE INDEX UNIQ_B24080FAD6A93D15 (seuil), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        // Barème d'origine (BonusFidelite::BAREME_INITIAL), modifiable ensuite dans l'administration.
        $this->addSql('INSERT INTO palier_bonus (seuil, montant) VALUES (150000, 15000), (140000, 12000), (130000, 11000), (120000, 10000), (110000, 9000), (100000, 8000), (90000, 7000), (80000, 6000), (70000, 5000), (60000, 4000), (50000, 3000), (40000, 2000), (30000, 1500), (20000, 1000)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE palier_bonus');
    }
}
