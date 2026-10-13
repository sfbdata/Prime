<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAS-045b — "Ver alteração" (Antes e Depois) na Timeline inteligente também para as observações
 * de Detalhes e do Financeiro: o texto INTEIRO de antes da última edição
 * (`pasta_observacao_detalhes.conteudo_anterior` / `pasta_observacao_financeira.conteudo_anterior`),
 * como o registro de Dados da pasta já guarda (PAS-045, `Version20261007220558`). O `audit_log`
 * corta texto em 500 caracteres; a observação vai até 5000.
 *
 * Aditiva e anulável: as linhas existentes ficam com NULL (sem "Antes" a mostrar) — não há como
 * reconstruir o texto anterior de edições passadas sem o audit_log cortado, então não há backfill.
 *
 * Fora daqui, de propósito: qualquer divergência antiga do `--dump-sql` (`DROP INDEX` de índices
 * funcionais de cobrança e `ALTER cobranca_pessoa_* DROP DEFAULT`), que não é desta mudança.
 */
final class Version20261012230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PAS-045b: conteudo_anterior em pasta_observacao_detalhes e pasta_observacao_financeira';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_observacao_detalhes ADD conteudo_anterior TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_observacao_financeira ADD conteudo_anterior TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_observacao_financeira DROP conteudo_anterior');
        $this->addSql('ALTER TABLE pasta_observacao_detalhes DROP conteudo_anterior');
    }
}
