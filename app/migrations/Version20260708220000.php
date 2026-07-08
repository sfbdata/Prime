<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cobranças Etapa 1 — índices de deduplicação de Pessoa.
 *
 * Suportam a busca advisory de duplicidades por documento DENTRO do mesmo tenant
 * (SugerirPessoasDuplicadas, SPEC §7/§24). São índices compostos (tenant_id, cpf) e
 * (tenant_id, cnpj) — NÃO são UNIQUE: a deduplicação é apenas uma sugestão, nunca uma
 * restrição, e CPF/CNPJ são opcionais (invariável 24). O escopo do documento nunca
 * atravessa tenants.
 */
final class Version20260708220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cobranças Etapa 1 — índices compostos (tenant_id, cpf) e (tenant_id, cnpj) '
            . 'em cobranca_pessoa para a sugestão de duplicidades intra-tenant (não-unique).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_cobranca_pessoa_tenant_cpf ON cobranca_pessoa (tenant_id, cpf)');
        $this->addSql('CREATE INDEX idx_cobranca_pessoa_tenant_cnpj ON cobranca_pessoa (tenant_id, cnpj)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cobranca_pessoa_tenant_cpf');
        $this->addSql('DROP INDEX idx_cobranca_pessoa_tenant_cnpj');
    }
}
