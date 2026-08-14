<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espelho da contabilidade — as colunas que os três layouts NOVOS exigem
 * (SPEC docs/specs/cobranca-espelho-quatro-relatorios.md §4.3).
 *
 * Até aqui o espelho só guardava o relatório de INADIMPLÊNCIA, e as colunas de
 * `cobranca_relatorio_linha` serviam ao layout dele. Os relatórios de acordos, receitas e dados
 * cadastrais trazem campos que não têm onde cair.
 *
 * 🔴 **Cada campo ganhou coluna própria — nenhum foi encaixado numa que já existia.** Guardar
 * `Valor recebido` dentro de `total` porque "cabe" produziria número errado com cara de certo, que é
 * o defeito que este módulo já levou duas vezes. Todas nascem `NULL`: a inadimplência não as tem, e
 * os lotes já carregados continuam válidos sem reprocessamento.
 *
 * ⚠️ **O `DROP INDEX uniq_cobranca_obrigacao_ref_competencia` que o `doctrine:schema:update
 * --dump-sql` propõe NÃO está aqui, e é de propósito.** Ele é divergência PRÉ-EXISTENTE, medida no
 * master antes desta frente: o índice é único e PARCIAL
 * (`NULLS NOT DISTINCT WHERE referencia_externa IS NOT NULL`), criado por SQL cru, e o Doctrine não
 * sabe representá-lo no mapeamento — então propõe apagá-lo em toda geração. Aceitar esse DROP não
 * quebra nada visível: **deixa entrar obrigação duplicada**. Ver o aviso na `Version20260710130000`.
 */
final class Version20260813210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espelho: colunas dos relatórios de acordos, receitas e cadastro';
    }

    public function up(Schema $schema): void
    {
        // Só os acordos têm uma aba por acordo; nos outros três fica NULL.
        $this->addSql('ALTER TABLE cobranca_relatorio_linha ADD aba VARCHAR(255) DEFAULT NULL');
        // Qual das duas tabelas da aba de acordo (contas_originais | parcelas) — gravado, não deduzido.
        $this->addSql('ALTER TABLE cobranca_relatorio_linha ADD tabela VARCHAR(30) DEFAULT NULL');
        // "5/40", como a planilha de acordos escreve.
        $this->addSql('ALTER TABLE cobranca_relatorio_linha ADD parcela VARCHAR(30) DEFAULT NULL');
        // "Liquidação" (acordos) ou "Recebimento" (receitas).
        $this->addSql('ALTER TABLE cobranca_relatorio_linha ADD liquidacao DATE DEFAULT NULL');
        // "Valor recebido" (receitas) ou "Valor liquidado" (acordos), em centavos.
        $this->addSql('ALTER TABLE cobranca_relatorio_linha ADD valor_recebido INT DEFAULT NULL');
        // A segunda coluna de dinheiro do rodapé de receitas.
        $this->addSql('ALTER TABLE cobranca_relatorio_totalizador ADD valor_recebido INT DEFAULT NULL');

        // 🔴 O `tipo` entra na CHAVE ÚNICA do lote.
        //
        // Antes desta fatia o espelho só guardava um tipo, e a chave
        // `(tenant, carteira, hash, versao_leitor)` bastava. Agora cada leitor versiona a si mesmo —
        // `versao_leitor = 1` significa coisas diferentes conforme o layout — e
        // `findOnePorHash()` passou a filtrar por tipo. Sem esta troca, o índice do banco e a
        // checagem do código discordam: o mesmo arquivo recarregado com `--tipo` diferente passa pela
        // checagem em memória e estoura no índice, no meio do lote, com os anteriores já gravados.
        //
        // Seguro de rodar: em produção há 9 lotes, todos `inadimplencia`, então nenhum par colide e o
        // índice novo aceita exatamente o mesmo conjunto que o antigo aceitava.
        $this->addSql('DROP INDEX uniq_cobranca_relatorio_arquivo');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_cobranca_relatorio_arquivo '
            . 'ON cobranca_relatorio_importado (tenant_id, carteira_id, arquivo_hash, versao_leitor, tipo)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cobranca_relatorio_arquivo');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_cobranca_relatorio_arquivo '
            . 'ON cobranca_relatorio_importado (tenant_id, carteira_id, arquivo_hash, versao_leitor)'
        );
        $this->addSql('ALTER TABLE cobranca_relatorio_linha DROP aba');
        $this->addSql('ALTER TABLE cobranca_relatorio_linha DROP tabela');
        $this->addSql('ALTER TABLE cobranca_relatorio_linha DROP parcela');
        $this->addSql('ALTER TABLE cobranca_relatorio_linha DROP liquidacao');
        $this->addSql('ALTER TABLE cobranca_relatorio_linha DROP valor_recebido');
        $this->addSql('ALTER TABLE cobranca_relatorio_totalizador DROP valor_recebido');
    }
}
