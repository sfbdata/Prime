<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAS-045 — "Ver alteração" (Antes e Depois) na Timeline inteligente: o texto INTEIRO de antes da
 * última edição do registro de Dados da pasta (`pasta_mensagem.conteudo_anterior`). O `audit_log`
 * corta texto em 500 caracteres; o registro vai até 5000.
 *
 * Aditiva e anulável: as linhas existentes ficam com NULL (sem "Antes" a mostrar) — não há como
 * reconstruir o texto anterior de edições passadas sem o audit_log cortado, então não há backfill.
 *
 * Fora daqui, de propósito: a divergência antiga do `--dump-sql` (3 `DROP INDEX` de índices
 * funcionais de cobrança e 3 `ALTER cobranca_pessoa_* DROP DEFAULT`), que não é desta mudança.
 */
final class Version20261007220558 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PAS-045: pasta_mensagem.conteudo_anterior (texto inteiro de antes da última edição)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_mensagem ADD conteudo_anterior TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_mensagem DROP conteudo_anterior');
    }
}
