<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021113257 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE groups (id UUID NOT NULL, invite_token VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(100) NOT NULL, currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F06D39705242FFC4 ON groups (invite_token)');
        $this->addSql('CREATE TABLE membership (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, role VARCHAR(10) NOT NULL, group_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_86FFD285FE54D947A76ED395 ON membership (group_id, user_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285FE54D947 ON membership (group_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285A76ED395 ON membership (user_id)');
        $this->addSql('CREATE TABLE money_account (id UUID NOT NULL, name VARCHAR(100) NOT NULL, type VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, initial_balance BIGINT NOT NULL, archived BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, group_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_195D60EFA76ED395 ON money_account (user_id)');
        $this->addSql('CREATE INDEX IDX_195D60EFFE54D947 ON money_account (group_id)');
        $this->addSql('CREATE TABLE money_budget (id UUID NOT NULL, amount BIGINT NOT NULL, category_id UUID NOT NULL, user_id UUID DEFAULT NULL, group_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_736C7C6212469DE2 ON money_budget (category_id)');
        $this->addSql('CREATE INDEX IDX_736C7C62A76ED395 ON money_budget (user_id)');
        $this->addSql('CREATE INDEX IDX_736C7C62FE54D947 ON money_budget (group_id)');
        $this->addSql('CREATE TABLE money_category (id UUID NOT NULL, archived BOOLEAN NOT NULL, kind VARCHAR(10) NOT NULL, name VARCHAR(100) NOT NULL, icon VARCHAR(16) NOT NULL, user_id UUID DEFAULT NULL, group_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E726EAEFA76ED395 ON money_category (user_id)');
        $this->addSql('CREATE INDEX IDX_E726EAEFFE54D947 ON money_category (group_id)');
        $this->addSql('CREATE TABLE money_goal (id UUID NOT NULL, deadline DATE DEFAULT NULL, name VARCHAR(100) NOT NULL, target BIGINT NOT NULL, account_id UUID NOT NULL, user_id UUID DEFAULT NULL, group_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_135048299B6B5FBA ON money_goal (account_id)');
        $this->addSql('CREATE INDEX IDX_13504829A76ED395 ON money_goal (user_id)');
        $this->addSql('CREATE INDEX IDX_13504829FE54D947 ON money_goal (group_id)');
        $this->addSql('CREATE TABLE money_operation (id UUID NOT NULL, type VARCHAR(10) NOT NULL, date DATE NOT NULL, amount BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, to_amount BIGINT DEFAULT NULL, note VARCHAR(255) NOT NULL, import_hash VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, account_id UUID DEFAULT NULL, to_account_id UUID DEFAULT NULL, category_id UUID DEFAULT NULL, group_id UUID DEFAULT NULL, paid_by_id UUID DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C5B6C1513C948C38 ON money_operation (import_hash)');
        $this->addSql('CREATE INDEX IDX_C5B6C1519B6B5FBAAA9E377A ON money_operation (account_id, date)');
        $this->addSql('CREATE INDEX IDX_C5B6C151BC58BDC7AA9E377A ON money_operation (to_account_id, date)');
        $this->addSql('CREATE INDEX IDX_C5B6C151FE54D947AA9E377A ON money_operation (group_id, date)');
        $this->addSql('CREATE INDEX IDX_C5B6C1519B6B5FBA ON money_operation (account_id)');
        $this->addSql('CREATE INDEX IDX_C5B6C151BC58BDC7 ON money_operation (to_account_id)');
        $this->addSql('CREATE INDEX IDX_C5B6C15112469DE2 ON money_operation (category_id)');
        $this->addSql('CREATE INDEX IDX_C5B6C151FE54D947 ON money_operation (group_id)');
        $this->addSql('CREATE INDEX IDX_C5B6C1517F9BC654 ON money_operation (paid_by_id)');
        $this->addSql('CREATE INDEX IDX_C5B6C151B03A8386 ON money_operation (created_by_id)');
        $this->addSql('CREATE TABLE money_recurring (id UUID NOT NULL, type VARCHAR(10) NOT NULL, amount BIGINT NOT NULL, to_amount BIGINT DEFAULT NULL, note VARCHAR(255) NOT NULL, period VARCHAR(10) NOT NULL, start_date DATE NOT NULL, count INT NOT NULL, next_date DATE NOT NULL, active BOOLEAN NOT NULL, account_id UUID NOT NULL, to_account_id UUID DEFAULT NULL, category_id UUID DEFAULT NULL, created_by_id UUID DEFAULT NULL, user_id UUID DEFAULT NULL, group_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B05B65D1940E81A0 ON money_recurring (next_date)');
        $this->addSql('CREATE INDEX IDX_B05B65D19B6B5FBA ON money_recurring (account_id)');
        $this->addSql('CREATE INDEX IDX_B05B65D1BC58BDC7 ON money_recurring (to_account_id)');
        $this->addSql('CREATE INDEX IDX_B05B65D112469DE2 ON money_recurring (category_id)');
        $this->addSql('CREATE INDEX IDX_B05B65D1B03A8386 ON money_recurring (created_by_id)');
        $this->addSql('CREATE INDEX IDX_B05B65D1A76ED395 ON money_recurring (user_id)');
        $this->addSql('CREATE INDEX IDX_B05B65D1FE54D947 ON money_recurring (group_id)');
        $this->addSql('CREATE TABLE money_settlement (id UUID NOT NULL, date DATE NOT NULL, amount BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, group_id UUID NOT NULL, from_id UUID NOT NULL, to_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F22C50B1FE54D947 ON money_settlement (group_id)');
        $this->addSql('CREATE INDEX IDX_F22C50B178CED90B ON money_settlement (from_id)');
        $this->addSql('CREATE INDEX IDX_F22C50B130354A65 ON money_settlement (to_id)');
        $this->addSql('CREATE TABLE money_split (id UUID NOT NULL, amount BIGINT NOT NULL, operation_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2F7E831144AC3583 ON money_split (operation_id)');
        $this->addSql('CREATE INDEX IDX_2F7E8311A76ED395 ON money_split (user_id)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, password VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(100) NOT NULL, currency VARCHAR(3) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_account ADD CONSTRAINT FK_195D60EFA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_account ADD CONSTRAINT FK_195D60EFFE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_budget ADD CONSTRAINT FK_736C7C6212469DE2 FOREIGN KEY (category_id) REFERENCES money_category (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_budget ADD CONSTRAINT FK_736C7C62A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_budget ADD CONSTRAINT FK_736C7C62FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_category ADD CONSTRAINT FK_E726EAEFA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_category ADD CONSTRAINT FK_E726EAEFFE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_goal ADD CONSTRAINT FK_135048299B6B5FBA FOREIGN KEY (account_id) REFERENCES money_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_goal ADD CONSTRAINT FK_13504829A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_goal ADD CONSTRAINT FK_13504829FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_operation ADD CONSTRAINT FK_C5B6C1519B6B5FBA FOREIGN KEY (account_id) REFERENCES money_account (id)');
        $this->addSql('ALTER TABLE money_operation ADD CONSTRAINT FK_C5B6C151BC58BDC7 FOREIGN KEY (to_account_id) REFERENCES money_account (id)');
        $this->addSql('ALTER TABLE money_operation ADD CONSTRAINT FK_C5B6C15112469DE2 FOREIGN KEY (category_id) REFERENCES money_category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_operation ADD CONSTRAINT FK_C5B6C151FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_operation ADD CONSTRAINT FK_C5B6C1517F9BC654 FOREIGN KEY (paid_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_operation ADD CONSTRAINT FK_C5B6C151B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_recurring ADD CONSTRAINT FK_B05B65D19B6B5FBA FOREIGN KEY (account_id) REFERENCES money_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_recurring ADD CONSTRAINT FK_B05B65D1BC58BDC7 FOREIGN KEY (to_account_id) REFERENCES money_account (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_recurring ADD CONSTRAINT FK_B05B65D112469DE2 FOREIGN KEY (category_id) REFERENCES money_category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_recurring ADD CONSTRAINT FK_B05B65D1B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_recurring ADD CONSTRAINT FK_B05B65D1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_recurring ADD CONSTRAINT FK_B05B65D1FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_settlement ADD CONSTRAINT FK_F22C50B1FE54D947 FOREIGN KEY (group_id) REFERENCES groups (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_settlement ADD CONSTRAINT FK_F22C50B178CED90B FOREIGN KEY (from_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_settlement ADD CONSTRAINT FK_F22C50B130354A65 FOREIGN KEY (to_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_split ADD CONSTRAINT FK_2F7E831144AC3583 FOREIGN KEY (operation_id) REFERENCES money_operation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE money_split ADD CONSTRAINT FK_2F7E8311A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD285FE54D947');
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD285A76ED395');
        $this->addSql('ALTER TABLE money_account DROP CONSTRAINT FK_195D60EFA76ED395');
        $this->addSql('ALTER TABLE money_account DROP CONSTRAINT FK_195D60EFFE54D947');
        $this->addSql('ALTER TABLE money_budget DROP CONSTRAINT FK_736C7C6212469DE2');
        $this->addSql('ALTER TABLE money_budget DROP CONSTRAINT FK_736C7C62A76ED395');
        $this->addSql('ALTER TABLE money_budget DROP CONSTRAINT FK_736C7C62FE54D947');
        $this->addSql('ALTER TABLE money_category DROP CONSTRAINT FK_E726EAEFA76ED395');
        $this->addSql('ALTER TABLE money_category DROP CONSTRAINT FK_E726EAEFFE54D947');
        $this->addSql('ALTER TABLE money_goal DROP CONSTRAINT FK_135048299B6B5FBA');
        $this->addSql('ALTER TABLE money_goal DROP CONSTRAINT FK_13504829A76ED395');
        $this->addSql('ALTER TABLE money_goal DROP CONSTRAINT FK_13504829FE54D947');
        $this->addSql('ALTER TABLE money_operation DROP CONSTRAINT FK_C5B6C1519B6B5FBA');
        $this->addSql('ALTER TABLE money_operation DROP CONSTRAINT FK_C5B6C151BC58BDC7');
        $this->addSql('ALTER TABLE money_operation DROP CONSTRAINT FK_C5B6C15112469DE2');
        $this->addSql('ALTER TABLE money_operation DROP CONSTRAINT FK_C5B6C151FE54D947');
        $this->addSql('ALTER TABLE money_operation DROP CONSTRAINT FK_C5B6C1517F9BC654');
        $this->addSql('ALTER TABLE money_operation DROP CONSTRAINT FK_C5B6C151B03A8386');
        $this->addSql('ALTER TABLE money_recurring DROP CONSTRAINT FK_B05B65D19B6B5FBA');
        $this->addSql('ALTER TABLE money_recurring DROP CONSTRAINT FK_B05B65D1BC58BDC7');
        $this->addSql('ALTER TABLE money_recurring DROP CONSTRAINT FK_B05B65D112469DE2');
        $this->addSql('ALTER TABLE money_recurring DROP CONSTRAINT FK_B05B65D1B03A8386');
        $this->addSql('ALTER TABLE money_recurring DROP CONSTRAINT FK_B05B65D1A76ED395');
        $this->addSql('ALTER TABLE money_recurring DROP CONSTRAINT FK_B05B65D1FE54D947');
        $this->addSql('ALTER TABLE money_settlement DROP CONSTRAINT FK_F22C50B1FE54D947');
        $this->addSql('ALTER TABLE money_settlement DROP CONSTRAINT FK_F22C50B178CED90B');
        $this->addSql('ALTER TABLE money_settlement DROP CONSTRAINT FK_F22C50B130354A65');
        $this->addSql('ALTER TABLE money_split DROP CONSTRAINT FK_2F7E831144AC3583');
        $this->addSql('ALTER TABLE money_split DROP CONSTRAINT FK_2F7E8311A76ED395');
        $this->addSql('DROP TABLE groups');
        $this->addSql('DROP TABLE membership');
        $this->addSql('DROP TABLE money_account');
        $this->addSql('DROP TABLE money_budget');
        $this->addSql('DROP TABLE money_category');
        $this->addSql('DROP TABLE money_goal');
        $this->addSql('DROP TABLE money_operation');
        $this->addSql('DROP TABLE money_recurring');
        $this->addSql('DROP TABLE money_settlement');
        $this->addSql('DROP TABLE money_split');
        $this->addSql('DROP TABLE users');
    }
}
