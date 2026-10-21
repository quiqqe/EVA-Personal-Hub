<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021122547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD telegram_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ALTER email DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9CC0B3066 ON users (telegram_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_1483A5E9CC0B3066');
        $this->addSql('ALTER TABLE users DROP telegram_id');
        $this->addSql('ALTER TABLE users ALTER email SET NOT NULL');
    }
}
