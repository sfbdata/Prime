<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cobranças Etapa 7 — idempotência da importação (decisão C): índice PARCIAL ÚNICO em
 * cobranca_obrigacao(caso_id, referencia_externa) WHERE referencia_externa IS NOT NULL.
 *
 * Garante no banco que um boleto (NN) importado não vira duas Obrigações no mesmo Caso — a
 * reimportação ATUALIZA em vez de duplicar. Obrigações manuais (referencia_externa NULL) ficam de
 * fora do índice (WHERE), então não são afetadas pela restrição.
 *
 * ATENÇÃO (drift de schema): índice parcial não é expressável por atributos Doctrine; o
 * `doctrine:migrations:diff` emitirá `DROP INDEX uniq_cobranca_obrigacao_ref_externa`. Ao gerar a
 * próxima migration por diff, REMOVA esse DROP à mão (mesma nota da Version20260710130000).
 */
final class Version20260710160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranças Etapa 7 — índice parcial único de idempotência da importação em '
            . 'cobranca_obrigacao(caso_id, referencia_externa) WHERE referencia_externa IS NOT NULL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_cobranca_obrigacao_ref_externa ON cobranca_obrigacao '
            . '(caso_id, referencia_externa) WHERE referencia_externa IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cobranca_obrigacao_ref_externa');
    }
}
