<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Desfaz o congelamento acidental de `Version20260719140000` (spec
 * `docs/specs/cobranca-cancelar-acordo.md` §5). Migração de DADO: nenhum DDL.
 *
 * ── O QUE ACONTECEU ─────────────────────────────────────────────────────────────────────────────
 *
 * Aquela migração congelou `WHERE encargos_congelados_em IS NULL AND (juros+multa+correcao) > 0` para
 * proteger os números vindos da contabilidade. No instante em que rodou (21/07 21:04:26) as ÚNICAS
 * obrigações do banco com encargo ≠ 0 eram as 4 taxas que o acordo #1 (QUADRA 11 CHACARA 02/11) havia
 * materializado três dias antes — `CriarAcordoUseCase` materializa a substituída SEM congelar, de
 * propósito, justamente para que ela volte a crescer se o acordo cair. Foram congeladas por engano.
 *
 * Efeito: `EncargosVivos::hidratar` pula obrigação congelada, então ao cancelar o acordo elas voltaram
 * ao saldo com os JUROS PARADOS — o defeito que o dono reportou em 01/08.
 *
 * ── ALCANCE MEDIDO (01/08, nos dois ambientes) ──────────────────────────────────────────────────
 *
 *   4 congeladas vivas · 0 congeladas liquidadas · 3.380 (dev) e 3.482 (prod) obrigações com encargo
 *   > 0 e LIVRES.
 *
 * Ou seja: no estado atual do sistema o normal é crescer ao vivo, e essas 4 são a anomalia — não o
 * contrário. Confirmado em produção antes de escrever esta migração, não presumido.
 *
 * ── POR QUE O `liquidada_em IS NULL` ────────────────────────────────────────────────────────────
 *
 * Não é decoração. `Obrigacao::liquidar()` é o ÚNICO ponto do código que congela, e quem desfaz é
 * `reabrir()`. Descongelar uma liquidada poria juros a correr sobre dívida já paga. Hoje não existe
 * nenhuma nesse estado, mas é essa cláusula que mantém a migração correta se existir.
 *
 * ── O QUE ESTA MIGRAÇÃO DELIBERADAMENTE NÃO FAZ ─────────────────────────────────────────────────
 *
 * Não apaga acordo cancelado nem parcelas. Uma versão anterior apagava, e teria criado um defeito de
 * dinheiro: sem a linha do acordo, o importador de inadimplência não reconhece o número externo,
 * **cria um acordo novo já ATIVO** e ressuscita as parcelas — enquanto as originais seguem exigíveis,
 * contando a mesma dívida duas vezes. O acordo cancelado agora some da TELA (404 na rota, fora das
 * listas) e permanece no banco. Ver `CancelarAcordoUseCase`.
 *
 * Vale a régua que o dono declarou em 01/08: ação de dinheiro **não pode ser irreversível** — e isso
 * vale inclusive para os escritórios que não importam planilha nenhuma.
 *
 * ── down() ──────────────────────────────────────────────────────────────────────────────────────
 *
 * NO-OP deliberado, não preguiça: recongelar não tem como distinguir quem estava congelado antes e
 * PARARIA os juros de obrigação viva — dano ativo, não reversão. Mesma "limitação honesta" que a
 * `Version20260719140000` assume no próprio docblock. Em produção, reverter = restaurar backup.
 */
final class Version20260801150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Descongela as obrigações vivas congeladas por engano pela Version20260719140000';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE cobranca_obrigacao
                SET encargos_congelados_em = NULL
              WHERE encargos_congelados_em IS NOT NULL
                AND liquidada_em IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        // Ver "down()" no docblock da classe: recongelar pararia juros de obrigação viva.
        $this->addSql('SELECT 1');
    }
}
