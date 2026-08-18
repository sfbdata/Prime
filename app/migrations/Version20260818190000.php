<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill de `pasta.cliente_principal_id` (2026-08-18) — spec
 * `docs/specs/pasta-cliente-principal.md`.
 *
 * A `Version20260818150000` criou a coluna deliberadamente SEM backfill, porque naquele desenho
 * "coluna nula" queria dizer "use o critério automático". O dono trocou o critério: agora
 * **pasta com cliente nunca fica sem principal** — ele é o primeiro cliente vinculado, ou quem
 * for marcado pela estrela. Sem preencher o passado, as pastas que já existem cairiam para sempre
 * no fallback, que é justamente o que se quis tirar do caminho.
 *
 * 🔑 GRAVA EXATAMENTE QUEM A TELA JÁ MOSTRA HOJE. O critério antigo era o cliente de **menor id**
 * (`Pasta::clienteMaisAntigo()`), então é ele que entra. Logo **nenhum número muda de valor** no
 * dia em que isto sobe: o que muda é a resposta deixar de ser recalculada a cada leitura e passar
 * a ficar gravada.
 *
 * Por que MIN(cliente_id) e não "o primeiro vinculado": `pasta_cliente` tem só `pasta_id` e
 * `cliente_id` — **nenhuma coluna de data ou sequência**. A ordem de vínculo do passado não foi
 * registrada e não é recuperável. Inventar uma ordem para decidir de quem é a média seria dado
 * chutado movendo número na tela, que é o defeito que este projeto já pagou caro na data dos
 * acordos. Então o passado fica com o critério que a tela já usava, e a ordem de vínculo passa a
 * valer só do primeiro vínculo NOVO em diante, onde ela é fato.
 *
 * Escopo real medido em 2026-08-18, antes de escrever: a PROD tem 1.070 pastas e **1** vínculo
 * pasta↔cliente no total, em uma única pasta de um único cliente — onde MIN() é a resposta certa
 * sem ambiguidade nenhuma. O dev bate com a prod (1 vínculo, 0 pastas com 2+ clientes).
 */
final class Version20260818190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preenche pasta.cliente_principal_id com o cliente que a tela já mostrava (menor id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pasta p
               SET cliente_principal_id = (
                   SELECT MIN(pc.cliente_id)
                     FROM pasta_cliente pc
                    WHERE pc.pasta_id = p.id
               )
             WHERE p.cliente_principal_id IS NULL
               AND EXISTS (SELECT 1 FROM pasta_cliente pc2 WHERE pc2.pasta_id = p.id)
            SQL);
    }

    /**
     * A volta apaga só o que ESTA migration escreveu — e não há como distinguir isso de uma
     * marcação feita à mão depois. Por isso o `down()` é deliberadamente inerte: reverter mexeria
     * em escolha de usuário, e a coluna nula não quebra nada (o `getClientePrincipal()` tem
     * fallback). Quem quiser mesmo zerar, faz por SQL, com a decisão na mão.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }
}
