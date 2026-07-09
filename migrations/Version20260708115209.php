<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260708115209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coffre de groupe (Safe) : tontine_group.safe_address + table safe_transaction.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tontine_group ADD safe_address VARCHAR(42) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE safe_transaction (
                id SERIAL NOT NULL,
                group_id INT NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                tx_hash VARCHAR(66) NOT NULL,
                safe_tx_hash VARCHAR(66) DEFAULT NULL,
                safe_address VARCHAR(42) DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_safe_transaction_tx_hash ON safe_transaction (tx_hash)');
        $this->addSql('CREATE INDEX idx_safe_transaction_status ON safe_transaction (status)');
        $this->addSql('CREATE INDEX idx_safe_transaction_group_purpose ON safe_transaction (group_id, purpose)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE safe_transaction');
        $this->addSql('ALTER TABLE tontine_group DROP safe_address');
    }
}
