<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021193408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Personal accounts shared with groups';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE money_account_shared (account_id UUID NOT NULL, group_id UUID NOT NULL, PRIMARY KEY (account_id, group_id))');
        $this->addSql('CREATE INDEX IDX_FA31D9F39B6B5FBA ON money_account_shared (account_id)');
        $this->addSql('CREATE INDEX IDX_FA31D9F3FE54D947 ON money_account_shared (group_id)');
        $this->addSql('ALTER TABLE money_account_shared ADD CONSTRAINT FK_FA31D9F39B6B5FBA FOREIGN KEY (account_id) REFERENCES money_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE money_account_shared ADD CONSTRAINT FK_FA31D9F3FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE');
        // personal accounts already used for a group's operations start out visible to that group
        $this->addSql('INSERT INTO money_account_shared (account_id, group_id)
            SELECT DISTINCT a.id, o.group_id FROM money_operation o JOIN money_account a ON a.id = o.account_id
            WHERE o.group_id IS NOT NULL AND a.user_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE money_account_shared DROP CONSTRAINT FK_FA31D9F39B6B5FBA');
        $this->addSql('ALTER TABLE money_account_shared DROP CONSTRAINT FK_FA31D9F3FE54D947');
        $this->addSql('DROP TABLE money_account_shared');
    }
}
