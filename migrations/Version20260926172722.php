<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926172722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article (id INT AUTO_INCREMENT NOT NULL, type_marchandise VARCHAR(100) NOT NULL, poids_volume NUMERIC(10, 2) NOT NULL, prix INT NOT NULL, observations LONGTEXT DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, colis_id INT NOT NULL, INDEX IDX_23A0E664D268D70 (colis_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, prenom VARCHAR(80) NOT NULL, telephone VARCHAR(30) NOT NULL, email VARCHAR(180) DEFAULT NULL, adresse LONGTEXT DEFAULT NULL, ville VARCHAR(100) DEFAULT NULL, code_postal VARCHAR(20) DEFAULT NULL, pays VARCHAR(60) NOT NULL, solde_bonus INT NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_C7440455450FF010 (telephone), INDEX IDX_C74404556C6E55B5A625945B (nom, prenom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE colis (id INT AUTO_INCREMENT NOT NULL, numero_suivi VARCHAR(30) NOT NULL, code_retrait VARCHAR(12) NOT NULL, token VARCHAR(64) NOT NULL, destinataire_nom VARCHAR(120) NOT NULL, destinataire_telephone VARCHAR(30) NOT NULL, type_expedition VARCHAR(20) NOT NULL, frais INT NOT NULL, remise INT NOT NULL, montant_paye INT NOT NULL, statut VARCHAR(20) NOT NULL, statut_paiement VARCHAR(20) NOT NULL, date_reception DATETIME NOT NULL, date_recuperation DATETIME DEFAULT NULL, recuperer_par VARCHAR(120) DEFAULT NULL, observations LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, client_id INT NOT NULL, expedition_id INT DEFAULT NULL, agent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_470BDFF981E3E1B2 (numero_suivi), UNIQUE INDEX UNIQ_470BDFF9E1232ECD (code_retrait), UNIQUE INDEX UNIQ_470BDFF95F37A13B (token), INDEX IDX_470BDFF9E564F0BF (statut), INDEX IDX_470BDFF9CEDAC6BF (statut_paiement), INDEX IDX_470BDFF96FA0B69B (date_reception), INDEX IDX_470BDFF919EB6921 (client_id), INDEX IDX_470BDFF9576EF81E (expedition_id), INDEX IDX_470BDFF93414710B (agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE expedition (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(30) NOT NULL, mode_transport VARCHAR(20) NOT NULL, destination VARCHAR(20) NOT NULL, date_depart DATE NOT NULL, date_arrivee_prevue DATE DEFAULT NULL, date_arrivee DATE DEFAULT NULL, statut VARCHAR(20) NOT NULL, reference VARCHAR(120) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_692907EF55AE19E (numero), INDEX IDX_692907EE564F0BF (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE historique_statut (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(20) NOT NULL, date DATETIME NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, colis_id INT NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_2C2650E34D268D70 (colis_id), INDEX IDX_2C2650E360BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_bonus (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, libelle VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, client_id INT NOT NULL, colis_id INT DEFAULT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_B54CE1F119EB6921 (client_id), INDEX IDX_B54CE1F14D268D70 (colis_id), INDEX IDX_B54CE1F160BB6FE6 (auteur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, canal VARCHAR(20) NOT NULL, destinataire VARCHAR(180) NOT NULL, message LONGTEXT NOT NULL, succes TINYINT NOT NULL, erreur VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, colis_id INT DEFAULT NULL, INDEX IDX_BF5476CA4D268D70 (colis_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE paiement (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, remise INT NOT NULL, mode VARCHAR(20) NOT NULL, date DATETIME NOT NULL, reference VARCHAR(120) DEFAULT NULL, commentaire VARCHAR(255) DEFAULT NULL, colis_id INT NOT NULL, caissier_id INT DEFAULT NULL, INDEX IDX_B1DC7A1EAA9E377A (date), INDEX IDX_B1DC7A1E4D268D70 (colis_id), INDEX IDX_B1DC7A1EB514973B (caissier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ramassage (id INT AUTO_INCREMENT NOT NULL, date_prevue DATETIME NOT NULL, adresse LONGTEXT DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, statut VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, client_id INT NOT NULL, agent_id INT DEFAULT NULL, INDEX IDX_945DF2CFEE522B99 (date_prevue), INDEX IDX_945DF2CF19EB6921 (client_id), INDEX IDX_945DF2CF3414710B (agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE type_marchandise (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, prix_unitaire INT DEFAULT NULL, actif TINYINT NOT NULL, UNIQUE INDEX UNIQ_92B1A64F6C6E55B5 (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(80) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, email VARCHAR(180) DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, pays VARCHAR(20) DEFAULT NULL, actif TINYINT NOT NULL, created_at DATETIME NOT NULL, derniere_connexion DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_1D1C63B3F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article ADD CONSTRAINT FK_23A0E664D268D70 FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE colis ADD CONSTRAINT FK_470BDFF919EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE colis ADD CONSTRAINT FK_470BDFF9576EF81E FOREIGN KEY (expedition_id) REFERENCES expedition (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE colis ADD CONSTRAINT FK_470BDFF93414710B FOREIGN KEY (agent_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE historique_statut ADD CONSTRAINT FK_2C2650E34D268D70 FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE historique_statut ADD CONSTRAINT FK_2C2650E360BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_bonus ADD CONSTRAINT FK_B54CE1F119EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mouvement_bonus ADD CONSTRAINT FK_B54CE1F14D268D70 FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_bonus ADD CONSTRAINT FK_B54CE1F160BB6FE6 FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA4D268D70 FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1E4D268D70 FOREIGN KEY (colis_id) REFERENCES colis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EB514973B FOREIGN KEY (caissier_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ramassage ADD CONSTRAINT FK_945DF2CF19EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ramassage ADD CONSTRAINT FK_945DF2CF3414710B FOREIGN KEY (agent_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E664D268D70');
        $this->addSql('ALTER TABLE colis DROP FOREIGN KEY FK_470BDFF919EB6921');
        $this->addSql('ALTER TABLE colis DROP FOREIGN KEY FK_470BDFF9576EF81E');
        $this->addSql('ALTER TABLE colis DROP FOREIGN KEY FK_470BDFF93414710B');
        $this->addSql('ALTER TABLE historique_statut DROP FOREIGN KEY FK_2C2650E34D268D70');
        $this->addSql('ALTER TABLE historique_statut DROP FOREIGN KEY FK_2C2650E360BB6FE6');
        $this->addSql('ALTER TABLE mouvement_bonus DROP FOREIGN KEY FK_B54CE1F119EB6921');
        $this->addSql('ALTER TABLE mouvement_bonus DROP FOREIGN KEY FK_B54CE1F14D268D70');
        $this->addSql('ALTER TABLE mouvement_bonus DROP FOREIGN KEY FK_B54CE1F160BB6FE6');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA4D268D70');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1E4D268D70');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EB514973B');
        $this->addSql('ALTER TABLE ramassage DROP FOREIGN KEY FK_945DF2CF19EB6921');
        $this->addSql('ALTER TABLE ramassage DROP FOREIGN KEY FK_945DF2CF3414710B');
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE colis');
        $this->addSql('DROP TABLE expedition');
        $this->addSql('DROP TABLE historique_statut');
        $this->addSql('DROP TABLE mouvement_bonus');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE paiement');
        $this->addSql('DROP TABLE ramassage');
        $this->addSql('DROP TABLE type_marchandise');
        $this->addSql('DROP TABLE utilisateur');
    }
}
