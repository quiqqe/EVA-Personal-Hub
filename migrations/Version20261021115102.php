<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021115102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE groups ALTER currency TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE money_account ALTER currency TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE money_operation ALTER currency TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE money_settlement ALTER currency TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE users ALTER currency TYPE VARCHAR(10)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE groups ALTER currency TYPE VARCHAR(3)');
        $this->addSql('ALTER TABLE money_account ALTER currency TYPE VARCHAR(3)');
        $this->addSql('ALTER TABLE money_operation ALTER currency TYPE VARCHAR(3)');
        $this->addSql('ALTER TABLE money_settlement ALTER currency TYPE VARCHAR(3)');
        $this->addSql('ALTER TABLE users ALTER currency TYPE VARCHAR(3)');
    }
}
