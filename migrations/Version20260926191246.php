<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926191246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rappels des ramassages : abonnements navigateur (Web Push) et suivi des envois';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE push_abonnement (id INT AUTO_INCREMENT NOT NULL, endpoint LONGTEXT NOT NULL, empreinte VARCHAR(64) NOT NULL, cle_p256dh VARCHAR(255) NOT NULL, cle_auth VARCHAR(255) NOT NULL, navigateur VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, utilisateur_id INT NOT NULL, UNIQUE INDEX UNIQ_ABFD27F032AF9675 (empreinte), INDEX IDX_ABFD27F0FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE push_abonnement ADD CONSTRAINT FK_ABFD27F0FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ramassage ADD rappel_veille_envoye_le DATETIME DEFAULT NULL, ADD rappel_proche_envoye_le DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE push_abonnement DROP FOREIGN KEY FK_ABFD27F0FB88E14F');
        $this->addSql('DROP TABLE push_abonnement');
        $this->addSql('ALTER TABLE ramassage DROP rappel_veille_envoye_le, DROP rappel_proche_envoye_le');
    }
}
