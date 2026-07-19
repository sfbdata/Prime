<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Encargos configuráveis em cascata — CONGELA O LEGADO antes que o cron da F3 exista (spec §9, INV-E4).
 *
 * PORQUÊ (o cenário de perda que esta migração desarma):
 * As obrigações que já estão no banco tiveram os encargos vindos da CONTABILIDADE (importação dos
 * relatórios de inadimplência) — aqueles números são a verdade, não um cálculo do sistema. Medição no
 * banco de desenvolvimento, com dado real: 3.271 obrigações têm `encargos_congelados_em IS NULL` e
 * `juros + multa + correcao > 0`, somando 15.520.973 centavos (R$ 155.209,73).
 *
 * O cron da F3 (`app:cobranca:atualizar-encargos`) recalcula exatamente as obrigações NÃO congeladas,
 * usando a config resolvida em cascata (Obrigação → Caso → Carteira). As 3 carteiras existentes estão
 * com config NEUTRA (todas as taxas em 0, que é o default não-breaking escolhido na F1). Logo, na
 * primeira rodada do cron essas 3.271 obrigações seriam recalculadas com taxa 0 → `juros = 0`,
 * `multa = 0`, `correcao = 0` → **R$ 155.209,73 de encargos apagados em silêncio**, sem erro, sem log
 * de negócio e sem forma barata de reconstruir (a fonte é um .xlsx externo, não versionado).
 *
 * O congelamento é o freio previsto pela própria spec: obrigação congelada é dado informado, não
 * derivado — o motor de cálculo não a toca. É o mesmo tratamento que o importador já dá às obrigações
 * novas desde a F2 (`ImportarRelatorioCarteiraUseCase::materializarEncargosImportados`); esta migração
 * apenas estende o mesmo critério ao que foi importado ANTES de o congelamento existir.
 *
 * CRITÉRIO — só congela quem tem encargo > 0. Obrigação com encargo zerado fica LIVRE de propósito:
 * não há valor materializado a proteger nela, e é justamente nela que o comportamento novo deve
 * passar a valer assim que o gestor configurar as taxas reais da carteira.
 *
 * ADITIVA E NÃO-BREAKING: não altera schema (nenhum DDL), não mexe em valor de dinheiro (`juros`,
 * `multa`, `correcao`, `honorarios`, `valor_original` ficam intactos), não muda saldo, exigível nem
 * Dashboard. Só preenche dois carimbos de tempo que hoje são nulos.
 *
 * LIMITAÇÃO HONESTA DO down(): esta migração é IRREVERSÍVEL COM FIDELIDADE. O `down()` não tem como
 * saber quais linhas estavam originalmente com `encargos_congelados_em IS NULL`, porque é exatamente
 * essa informação que o `up()` sobrescreve. O `down()` implementado devolve ao estado nulo as linhas
 * que casam com o MESMO critério (encargos > 0) — o que reverte esta migração quando ela é a única
 * coisa que aconteceu, mas TAMBÉM descongelaria obrigações congeladas por outro caminho (importação
 * posterior, edição manual da F5) se houver escrita entre o up() e o down(). Em produção, prefira
 * restaurar backup a confiar neste down().
 */
final class Version20260719140000 extends AbstractMigration
{
    /** Critério único do up()/down(): tem encargo materializado a proteger. */
    private const CRITERIO = '(juros + multa + correcao) > 0';

    public function getDescription(): string
    {
        return 'Congela encargos legados (> 0) para o cron da F3 não zerá-los';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE cobranca_obrigacao
                SET encargos_congelados_em = NOW(), encargos_atualizados_em = NOW()
              WHERE encargos_congelados_em IS NULL
                AND ' . self::CRITERIO
        );
    }

    public function down(Schema $schema): void
    {
        // Ver "LIMITAÇÃO HONESTA DO down()" no docblock da classe: reversão aproximada, não fiel.
        $this->addSql(
            'UPDATE cobranca_obrigacao
                SET encargos_congelados_em = NULL, encargos_atualizados_em = NULL
              WHERE encargos_congelados_em IS NOT NULL
                AND ' . self::CRITERIO
        );
    }
}
