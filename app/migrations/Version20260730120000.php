<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Competência da obrigação (2026-07-30) — spec `docs/specs/cobranca-importar-chave-competencia.md`.
 *
 * O NN (Nosso Número) NÃO identifica a dívida sozinho: a contábil o reaproveita. Medido em produção, o
 * NN 61457 é uma taxa de 08/2022 na carteira TOP LIFE I e outra de 07/2026 na TOP LIFE 2. Com a busca só
 * pelo NN, o importador engole o boleto novo e grava os encargos dele sobre a dívida antiga.
 *
 * A competência vira a segunda metade da chave. NÃO se usa o vencimento para isso: boleto reemitido para
 * o devedor conseguir pagar mantém o NN e MUDA a data — casar por data duplicaria a dívida e cobraria a
 * pessoa duas vezes. A competência é o mês a que a dívida se refere e não muda com a reemissão.
 *
 * BACKFILL: a competência já existe em todas as obrigações importadas, só que como texto dentro de
 * `descricao`, no formato que o próprio importador monta (`BoletoImportavel::descricao()`):
 * "<classes> — competência MM/AAAA". Medido em produção: casa em 3.270 de 3.300 obrigações (99,1%). As
 * ~30 restantes são cadastro manual — ficam com competencia NULL e a importação cai no comportamento
 * antigo (casa só pelo NN), sem regredir.
 *
 * O `~` do PostgreSQL é sensível a acento, e o texto gravado tem "competência" com acento. O padrão usa
 * `compet.ncia` para não depender da codificação do banco.
 *
 * Escrita à mão, não gerada: `make:migration` roda contra o banco do dev (que carrega alterações de
 * outras frentes) e propõe dropar os índices funcionais criados por SQL cru. Aqui só entra esta frente.
 */
final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona cobranca_obrigacao.competencia (MM/AAAA) + índice, com backfill a partir da descrição';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_obrigacao ADD competencia VARCHAR(7) DEFAULT NULL');

        // Backfill: extrai "competência MM/AAAA" da descrição montada pelo importador.
        $this->addSql(<<<'SQL'
            UPDATE cobranca_obrigacao
               SET competencia = substring(descricao from 'compet.ncia ([0-9]{2}/[0-9]{4})')
             WHERE descricao ~ 'compet.ncia [0-9]{2}/[0-9]{4}'
            SQL);

        // O índice ÚNICO parcial criado pela Version20260710160000 impõe no banco a mesma suposição que
        // esta spec derruba: um boleto por (caso, NN). Sem trocá-lo, duas dívidas de competências
        // diferentes com o mesmo NN batem em unique violation — foi o teste
        // `testMesmoNnCompetenciaDiferenteCriaAsDuas` que expôs isso.
        //
        // `NULLS NOT DISTINCT` (PostgreSQL 15+) é essencial: sem ele o Postgres trata cada NULL como
        // valor distinto e DUAS obrigações com o mesmo NN e competência nula passariam — justamente o
        // dado legado que o fallback do repositório precisa continuar deduplicando.
        $this->addSql('DROP INDEX uniq_cobranca_obrigacao_ref_externa');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_cobranca_obrigacao_ref_competencia ON cobranca_obrigacao '
            . '(caso_id, referencia_externa, competencia) NULLS NOT DISTINCT WHERE referencia_externa IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        // Reverter só é possível se não existir NN repetido com competências diferentes — que é
        // exatamente o dado que esta migration passa a permitir. Se o down falhar por unique violation,
        // é porque o dado novo já existe e a volta exigiria escolher qual dívida descartar: decisão
        // humana, não automática.
        $this->addSql('DROP INDEX uniq_cobranca_obrigacao_ref_competencia');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_cobranca_obrigacao_ref_externa ON cobranca_obrigacao '
            . '(caso_id, referencia_externa) WHERE referencia_externa IS NOT NULL'
        );
        $this->addSql('ALTER TABLE cobranca_obrigacao DROP competencia');
    }
}
