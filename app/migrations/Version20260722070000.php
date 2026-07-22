<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cascata de encargos ao vivo sem snapshot (#9-T1, spec `cobranca-cascata-encargos-ao-vivo-sem-
 * snapshot.md` §3.1/§5): o NÍVEL 2 (o "meio") da cascata `Carteira → Objeto/Caso → Obrigação` passa a
 * ser o OBJETO, não mais o Caso. Adiciona as 10 colunas de override no `cobranca_objeto`, espelhando
 * 1:1 as já existentes em `cobranca_obrigacao` (mesmos nomes/tipos). Todas nullable — null continua
 * significando "herda a carteira".
 *
 * ADITIVA, sem backfill (spec §5): as colunas de config do `cobranca_caso` NÃO são tocadas/dropadas
 * aqui — ficam como coluna-sombra por 1 release (rollback seguro), e o `ResolvedorConfigEncargos`
 * (T1, mesma tarefa) já para de lê-las. A remoção delas é follow-up (fora de escopo, spec §9).
 */
final class Version20260722070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranca: override de encargos no objeto (nivel 2 da cascata ao vivo, #9-T1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_objeto ADD taxa_juros_mensal_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD regime_juros VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD taxa_multa_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD base_multa VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD taxa_correcao_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD base_correcao VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD taxa_honorarios_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD base_honorarios VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD carencia_honorarios_dias INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_objeto ADD tolerancia_juros_multa_dias INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_objeto DROP taxa_juros_mensal_bp');
        $this->addSql('ALTER TABLE cobranca_objeto DROP regime_juros');
        $this->addSql('ALTER TABLE cobranca_objeto DROP taxa_multa_bp');
        $this->addSql('ALTER TABLE cobranca_objeto DROP base_multa');
        $this->addSql('ALTER TABLE cobranca_objeto DROP taxa_correcao_bp');
        $this->addSql('ALTER TABLE cobranca_objeto DROP base_correcao');
        $this->addSql('ALTER TABLE cobranca_objeto DROP taxa_honorarios_bp');
        $this->addSql('ALTER TABLE cobranca_objeto DROP base_honorarios');
        $this->addSql('ALTER TABLE cobranca_objeto DROP carencia_honorarios_dias');
        $this->addSql('ALTER TABLE cobranca_objeto DROP tolerancia_juros_multa_dias');
    }
}
