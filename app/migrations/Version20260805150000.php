<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normaliza `user.email` para minúsculas e impede que a duplicata por caixa volte.
 * Fecha a classe de defeito descrita na F1 de `docs/specs/abertura-cadastro-publico.md`.
 *
 * ── O PROBLEMA ──────────────────────────────────────────────────────────────────────
 *
 * O índice único de `user.email` é btree comum, sem `lower()`. Para o banco, `Ana@Adv.com`
 * e `ana@adv.com` são contas diferentes. Quem gravou o e-mail com maiúscula loga
 * normalmente (o login compara o valor cru) mas some de qualquer busca normalizada —
 * inclusive da recuperação de senha, que é o caminho de quem já está trancado fora.
 *
 * O código passou a normalizar em todos os pontos de escrita. Esta migration cuida do que
 * já está gravado e do futuro: backfill + índice único funcional.
 *
 * ── POR QUE ELA PODE RECUSAR RODAR ──────────────────────────────────────────────────
 *
 * Se existirem `Ana@Adv.com` E `ana@adv.com` ao mesmo tempo, o backfill violaria o UNIQUE
 * no meio do deploy. Qual das duas contas sobrevive NÃO é decisão de migration — as duas
 * podem ter dados, vínculos e histórico. Então ela confere antes e aborta com a lista,
 * para um humano decidir.
 *
 * Medido em 2026-08-05 nos bancos de desenvolvimento (`saas`, `saas_ux`): 13 usuários,
 * nenhum com maiúscula, nenhuma colisão. **A produção é outro banco e não foi medida** —
 * daí a trava existir.
 *
 * Para saber antes do deploy:
 *   SELECT lower(email), count(*), array_agg(email), array_agg(id)
 *   FROM "user" GROUP BY lower(email) HAVING count(*) > 1;
 *
 * ── SOBRE O ÍNDICE FUNCIONAL ────────────────────────────────────────────────────────
 *
 * O Doctrine não sabe representar índice funcional no mapeamento, então
 * `doctrine:schema:update --dump-sql` vai propor apagar `uq_user_email_lower` daqui em
 * diante. **Não aceite esse DROP**: sem ele, a duplicata por caixa volta a ser possível.
 * Mesma ressalva já registrada na `Version20260710130000`.
 */
final class Version20260805150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normaliza user.email para minúsculas e cria índice único funcional em lower(email)';
    }

    public function preUp(Schema $schema): void
    {
        $colisoes = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT lower(email) AS normalizado,
                   string_agg(email, ', ' ORDER BY id) AS emails,
                   string_agg(id::text, ', ' ORDER BY id) AS ids
            FROM "user"
            GROUP BY lower(email)
            HAVING count(*) > 1
        SQL);

        if ($colisoes === []) {
            return;
        }

        $linhas = array_map(
            static fn (array $c): string => sprintf('  %s → ids [%s]: %s', $c['normalizado'], $c['ids'], $c['emails']),
            $colisoes,
        );

        $this->abortIf(
            true,
            "Existem contas que diferem apenas em maiúsculas/minúsculas no e-mail.\n"
            . "Normalizar agora violaria o UNIQUE de user.email, e decidir qual conta sobrevive\n"
            . "não é decisão de migration — as duas podem ter dados e vínculos.\n\n"
            . implode("\n", $linhas)
            . "\n\nResolva essas contas (unificar ou desativar) e rode a migration de novo."
        );
    }

    public function up(Schema $schema): void
    {
        // Backfill: só toca em quem realmente tem maiúscula.
        $this->addSql('UPDATE "user" SET email = lower(email) WHERE email <> lower(email)');

        // Índice funcional: torna a duplicata por caixa impossível daqui em diante.
        $this->addSql('CREATE UNIQUE INDEX uq_user_email_lower ON "user" (lower(email))');
    }

    public function down(Schema $schema): void
    {
        // O backfill não é reversível: a caixa original foi perdida, e reconstruí-la seria
        // inventar dado. Só o índice volta atrás.
        $this->addSql('DROP INDEX IF EXISTS uq_user_email_lower');
    }
}
