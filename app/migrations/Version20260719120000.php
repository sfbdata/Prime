<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encargos separados e configuráveis em cascata — F1 (motor + dados).
 *
 * POR QUÊ: hoje a obrigação guarda um `encargos_reconhecidos` único, que mistura juros e multa e é
 * ESTÁTICO. A contabilidade trabalha com as parcelas separadas (juros, multa, correção,
 * honorários), e juros crescem todo dia. Esta migração cria o lugar onde esses valores moram e a
 * configuração que os gera, em três níveis (Carteira → Objeto/Caso → Obrigação).
 *
 * ADITIVA E NÃO-BREAKING, de propósito, porque o módulo está EM PRODUÇÃO:
 *  - a coluna `encargos_reconhecidos` NÃO é removida (spec §10.3): fica um release inteiro como
 *    coluna-sombra, escrita pelos lifecycle callbacks da entidade, para que um rollback do deploy
 *    reencontre o saldo intacto. A remoção é assunto da migração seguinte;
 *  - os defaults da carteira são NEUTROS (taxa 0 — decisão D4): um sistema em produção não pode
 *    começar a gerar juros e multa sozinho porque alguém fez deploy. Encargo só passa a existir
 *    depois que o escritório configurar a carteira;
 *  - o backfill joga todo `encargos_reconhecidos` em `juros` (fallback da spec §10.2). Preserva o
 *    saldo EXATO ao centavo (invariante INV-E1: exigível = original + juros + multa + correção) ao
 *    custo de não saber, retroativamente, quanto daquilo era multa. O split correto virá da
 *    reimportação das planilhas, que já trazem as colunas separadas.
 *
 * Nada é congelado aqui: o congelamento é decisão do importador e da edição manual (F2/F5).
 *
 * O down() desfaz tudo: derruba as colunas novas nas três tabelas. O dado do backfill não se perde
 * no rollback justamente porque `encargos_reconhecidos` continua lá.
 */
final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encargos separados (juros/multa/correcao/honorarios) + config em cascata nos 3 niveis';
    }

    public function up(Schema $schema): void
    {
        // --- Nível 3: Obrigação — valores materializados + metadados de congelamento -----------
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD juros INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD multa INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD correcao INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD honorarios INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD encargos_congelados_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD encargos_atualizados_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // --- Nível 3: Obrigação — override da configuração (NULL = herda o Caso) --------------
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD taxa_juros_mensal_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD regime_juros VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD taxa_multa_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD base_multa VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD taxa_correcao_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD base_correcao VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD base_honorarios VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD carencia_honorarios_dias INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD tolerancia_juros_multa_dias INT DEFAULT NULL');

        // --- Nível 2: Caso — snapshot da configuração (NULL = herda a Carteira) ---------------
        $this->addSql('ALTER TABLE cobranca_caso ADD taxa_juros_mensal_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD regime_juros VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD taxa_multa_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD base_multa VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD taxa_correcao_bp INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD base_correcao VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD base_honorarios VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD carencia_honorarios_dias INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_caso ADD tolerancia_juros_multa_dias INT DEFAULT NULL');

        // --- Nível 1: Carteira — fundo da cascata, NOT NULL com default NEUTRO (D4) -----------
        $this->addSql('ALTER TABLE cobranca_carteira ADD taxa_juros_mensal_bp INT DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE cobranca_carteira ADD regime_juros VARCHAR(20) DEFAULT 'simples' NOT NULL");
        $this->addSql('ALTER TABLE cobranca_carteira ADD taxa_multa_bp INT DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE cobranca_carteira ADD base_multa VARCHAR(20) DEFAULT 'principal' NOT NULL");
        $this->addSql('ALTER TABLE cobranca_carteira ADD taxa_correcao_bp INT DEFAULT 0 NOT NULL');
        $this->addSql("ALTER TABLE cobranca_carteira ADD base_correcao VARCHAR(20) DEFAULT 'principal' NOT NULL");
        $this->addSql("ALTER TABLE cobranca_carteira ADD base_honorarios VARCHAR(20) DEFAULT 'composta' NOT NULL");
        // NULL aqui é significativo: cai para `tolerancia_atraso_dias` na resolução (decisão D3).
        $this->addSql('ALTER TABLE cobranca_carteira ADD carencia_honorarios_dias INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cobranca_carteira ADD tolerancia_juros_multa_dias INT DEFAULT 0 NOT NULL');

        // --- Backfill conservador: preserva o saldo ao centavo (INV-E1) -----------------------
        $this->addSql('UPDATE cobranca_obrigacao SET juros = encargos_reconhecidos WHERE encargos_reconhecidos <> 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_carteira DROP taxa_juros_mensal_bp');
        $this->addSql('ALTER TABLE cobranca_carteira DROP regime_juros');
        $this->addSql('ALTER TABLE cobranca_carteira DROP taxa_multa_bp');
        $this->addSql('ALTER TABLE cobranca_carteira DROP base_multa');
        $this->addSql('ALTER TABLE cobranca_carteira DROP taxa_correcao_bp');
        $this->addSql('ALTER TABLE cobranca_carteira DROP base_correcao');
        $this->addSql('ALTER TABLE cobranca_carteira DROP base_honorarios');
        $this->addSql('ALTER TABLE cobranca_carteira DROP carencia_honorarios_dias');
        $this->addSql('ALTER TABLE cobranca_carteira DROP tolerancia_juros_multa_dias');

        $this->addSql('ALTER TABLE cobranca_caso DROP taxa_juros_mensal_bp');
        $this->addSql('ALTER TABLE cobranca_caso DROP regime_juros');
        $this->addSql('ALTER TABLE cobranca_caso DROP taxa_multa_bp');
        $this->addSql('ALTER TABLE cobranca_caso DROP base_multa');
        $this->addSql('ALTER TABLE cobranca_caso DROP taxa_correcao_bp');
        $this->addSql('ALTER TABLE cobranca_caso DROP base_correcao');
        $this->addSql('ALTER TABLE cobranca_caso DROP base_honorarios');
        $this->addSql('ALTER TABLE cobranca_caso DROP carencia_honorarios_dias');
        $this->addSql('ALTER TABLE cobranca_caso DROP tolerancia_juros_multa_dias');

        $this->addSql('ALTER TABLE cobranca_obrigacao DROP taxa_juros_mensal_bp');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP regime_juros');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP taxa_multa_bp');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP base_multa');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP taxa_correcao_bp');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP base_correcao');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP base_honorarios');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP carencia_honorarios_dias');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP tolerancia_juros_multa_dias');

        $this->addSql('ALTER TABLE cobranca_obrigacao DROP juros');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP multa');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP correcao');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP honorarios');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP encargos_congelados_em');
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP encargos_atualizados_em');
    }
}
