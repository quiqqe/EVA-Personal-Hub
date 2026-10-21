<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021164635 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE money_operation ADD orig_amount BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE money_operation ADD orig_currency VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE money_settlement ADD orig_amount BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE money_settlement ADD orig_currency VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE money_operation DROP orig_amount');
        $this->addSql('ALTER TABLE money_operation DROP orig_currency');
        $this->addSql('ALTER TABLE money_settlement DROP orig_amount');
        $this->addSql('ALTER TABLE money_settlement DROP orig_currency');
    }
}
