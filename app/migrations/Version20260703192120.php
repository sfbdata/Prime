<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703192120 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 3 captura total Datajud: movimentacoes com complementos tabelados e codigo do orgao';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movimentacao_processo ADD orgao_codigo VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE movimentacao_processo ADD complementos JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movimentacao_processo DROP orgao_codigo');
        $this->addSql('ALTER TABLE movimentacao_processo DROP complementos');
    }
}
