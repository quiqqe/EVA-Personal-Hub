<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023115541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE security_event (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, type VARCHAR(30) NOT NULL, ip VARCHAR(45) NOT NULL, device VARCHAR(100) NOT NULL, detail VARCHAR(200) DEFAULT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D712E90D8B8E8428 ON security_event (created_at)');
        $this->addSql('CREATE INDEX IDX_D712E90DA76ED395 ON security_event (user_id)');
        $this->addSql('ALTER TABLE security_event ADD CONSTRAINT FK_D712E90DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE security_event DROP CONSTRAINT FK_D712E90DA76ED395');
        $this->addSql('DROP TABLE security_event');
    }
}
