<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023121643 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_error (hash VARCHAR(32) NOT NULL, level VARCHAR(20) NOT NULL, message TEXT NOT NULL, place VARCHAR(255) NOT NULL, url VARCHAR(255) DEFAULT NULL, trace TEXT DEFAULT NULL, count INT NOT NULL, first_seen TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_seen TIMESTAMP(0) WITH TIME ZONE NOT NULL, resolved BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (hash))');
        $this->addSql('CREATE INDEX IDX_8B0E31BC3279CE44 ON app_error (last_seen)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_error');
    }
}
