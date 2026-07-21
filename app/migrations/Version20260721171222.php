<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721171222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranca: coluna taxa_honorarios_bp na obrigacao (override por-obrigacao, nivel 3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD taxa_honorarios_bp INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP taxa_honorarios_bp');
    }
}
