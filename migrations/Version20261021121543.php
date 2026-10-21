<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021121543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split modes and several payers on group expenses';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE money_operation ADD split_mode VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE money_operation ADD account_amount BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE money_split ADD paid BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE money_split ADD weight DOUBLE PRECISION DEFAULT NULL');
        // existing split expenses: equal split, the single payer paid the whole amount
        $this->addSql("UPDATE money_operation SET split_mode = 'equal' WHERE paid_by_id IS NOT NULL");
        $this->addSql('UPDATE money_split s SET paid = o.amount FROM money_operation o WHERE s.operation_id = o.id AND s.user_id = o.paid_by_id');
        $this->addSql("INSERT INTO money_split (id, operation_id, user_id, amount, paid)
            SELECT gen_random_uuid(), o.id, o.paid_by_id, 0, o.amount FROM money_operation o
            WHERE o.paid_by_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM money_split s WHERE s.operation_id = o.id AND s.user_id = o.paid_by_id)");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE money_operation DROP split_mode');
        $this->addSql('ALTER TABLE money_operation DROP account_amount');
        $this->addSql('ALTER TABLE money_split DROP paid');
        $this->addSql('ALTER TABLE money_split DROP weight');
    }
}
