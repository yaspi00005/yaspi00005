<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Position GPS des clients (latitude, longitude, origine, date) et photo du colis emballé.; /auto-generated, please modify/d
 */
final class Version20260926213745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Position GPS des clients et photo du colis';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client ADD latitude DOUBLE PRECISION DEFAULT NULL, ADD longitude DOUBLE PRECISION DEFAULT NULL, ADD position_source VARCHAR(10) DEFAULT NULL, ADD position_maj_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE colis ADD photo VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client DROP latitude, DROP longitude, DROP position_source, DROP position_maj_le');
        $this->addSql('ALTER TABLE colis DROP photo');
    }
}
