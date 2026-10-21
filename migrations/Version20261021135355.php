<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021135355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE telegram_login (code VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, telegram_id BIGINT DEFAULT NULL, telegram_name VARCHAR(100) DEFAULT NULL, confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, requested_from VARCHAR(200) NOT NULL, link_to_id UUID DEFAULT NULL, PRIMARY KEY (code))');
        $this->addSql('CREATE INDEX IDX_B7C3C8529F8E16BB ON telegram_login (link_to_id)');
        $this->addSql('ALTER TABLE telegram_login ADD CONSTRAINT FK_B7C3C8529F8E16BB FOREIGN KEY (link_to_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE telegram_login DROP CONSTRAINT FK_B7C3C8529F8E16BB');
        $this->addSql('DROP TABLE telegram_login');
    }
}
