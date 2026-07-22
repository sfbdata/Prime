<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anotação editável por 48h (ajuste 2026-07-22): coluna `editado_em` no evento de histórico.
 *
 * ⚠️ Escrita à MÃO de propósito. O `doctrine:migrations:diff` gerou junto três DROPs indevidos —
 * `uniq_cobranca_obrigacao_ref_externa` (que garante a dedup da importação), `idx_cobranca_pessoa_
 * tenant_cpf_digitos` e `idx_cobranca_pessoa_tenant_cnpj_digitos` — porque esses índices são
 * PARCIAIS/FUNCIONAIS e vivem só nas migrations: o mapping do ORM não os enxerga e o diff conclui que
 * sobram. Aplicar aquilo em produção derrubaria a proteção contra importar o mesmo boleto duas vezes.
 * Também descartados os `ALTER … DROP DEFAULT` das listas de contato, que são ruído do mesmo tipo.
 *
 * Aditiva e reversível: só acrescenta uma coluna nullable.
 */
final class Version20260722213721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranca: coluna editado_em no evento de historico (anotacao editavel por 48h)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_evento_historico ADD editado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cobranca_evento_historico DROP editado_em');
    }
}
