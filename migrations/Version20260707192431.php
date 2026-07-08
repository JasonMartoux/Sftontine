<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707192431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SavingsCycle.ends_at devient nullable (cycles Ponctuels sans date de fin).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tontine_savings_cycle ALTER ends_at DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Rolling back requires no existing row to have a NULL ends_at (i.e. no Punctual
        // savings cycles created since this migration ran) — SET NOT NULL will fail otherwise.
        $this->addSql('ALTER TABLE tontine_savings_cycle ALTER ends_at SET NOT NULL');
    }
}
