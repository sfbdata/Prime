<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cobrança — identidade externa do acordo (tarefa #7-A, spec `cobranca-importar-linhas-acordo.md`
 * §4): `numero_externo` (número do acordo na origem TOPLIFE, dedup por carteira+número na
 * reimportação) e `numero_parcelas_total` (total esperado de parcelas, lido do "p/t" do relatório —
 * usado para indicar "faltam parcelas", §3.3). Ambas nullable; nulo para acordos manuais. ADITIVA,
 * sem backfill — acordos existentes nascem sem identidade externa.
 */
final class Version20260722060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranca: numero_externo e numero_parcelas_total no acordo (identidade de importacao)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_acordo ADD numero_externo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_acordo ADD numero_parcelas_total INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_cobranca_acordo_tenant_numero_externo ON cobranca_acordo (tenant_id, numero_externo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cobranca_acordo_tenant_numero_externo');
        $this->addSql('ALTER TABLE cobranca_acordo DROP numero_parcelas_total');
        $this->addSql('ALTER TABLE cobranca_acordo DROP numero_externo');
    }
}
