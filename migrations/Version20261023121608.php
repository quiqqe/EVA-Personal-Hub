<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023121608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD role VARCHAR(20) DEFAULT NULL');
        // administrators keep their rights
        $this->addSql("UPDATE users SET role = 'admin' WHERE admin");
        $this->addSql('ALTER TABLE users DROP admin');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD admin BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('UPDATE users SET admin = role IS NOT NULL');
        $this->addSql('ALTER TABLE users DROP role');
    }
}
