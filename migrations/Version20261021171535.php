<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021171535 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE money_settlement ADD payer_operation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE money_settlement ADD receiver_operation_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE money_settlement ADD CONSTRAINT FK_F22C50B13316E7A7 FOREIGN KEY (payer_operation_id) REFERENCES money_operation (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_settlement ADD CONSTRAINT FK_F22C50B1B7F7BF15 FOREIGN KEY (receiver_operation_id) REFERENCES money_operation (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F22C50B13316E7A7 ON money_settlement (payer_operation_id)');
        $this->addSql('CREATE INDEX IDX_F22C50B1B7F7BF15 ON money_settlement (receiver_operation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE money_settlement DROP CONSTRAINT FK_F22C50B13316E7A7');
        $this->addSql('ALTER TABLE money_settlement DROP CONSTRAINT FK_F22C50B1B7F7BF15');
        $this->addSql('DROP INDEX IDX_F22C50B13316E7A7');
        $this->addSql('DROP INDEX IDX_F22C50B1B7F7BF15');
        $this->addSql('ALTER TABLE money_settlement DROP payer_operation_id');
        $this->addSql('ALTER TABLE money_settlement DROP receiver_operation_id');
    }
}
