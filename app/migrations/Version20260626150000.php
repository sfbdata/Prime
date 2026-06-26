<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Torna cpf/cnpj de Cliente únicos POR ESCRITÓRIO (C3 da remediação multi-tenant).
 *
 * O unique de cpf (cliente_pf) e cnpj (cliente_pj) era GLOBAL: o primeiro escritório a cadastrar
 * uma pessoa/empresa "trancava" aquele documento no sistema inteiro — outro escritório não
 * conseguia ter o mesmo cliente (INSERT batia no unique global → erro 500). Numa SaaS jurídica a
 * mesma pessoa pode legitimamente ser cliente de 2 escritórios.
 *
 * `Cliente` usa herança JOINED: tenant_id mora na base `cliente`, mas cpf/cnpj moram em
 * `cliente_pf`/`cliente_pj` — tabelas distintas. Um unique composto `(tenant_id, cpf)` exigiria
 * as duas colunas na mesma tabela, o que JOINED não permite sem denormalizar. Decisão do dono
 * (Opção B): **remover o unique global do banco** e garantir a unicidade POR TENANT na aplicação
 * (`#[UniqueEntity(['cpf','tenant'])]`/`['cnpj','tenant']` + setTenant antes da validação). Sem
 * trava no banco — a unicidade dentro do escritório passa a ser responsabilidade da validação.
 *
 * Só estrutural (DROP de 2 índices) — sem backfill, sem dados tocados. Pré-deploy em prod:
 * conferir que NÃO há cpf/cnpj duplicado dentro do MESMO tenant (não há trava nova que pegue
 * isso); duplicados CROSS-tenant passam a ser permitidos e são o objetivo.
 *
 * Aplicar ISOLADA via `migrations:execute --up` — o ledger não tem as 2 migrations antigas do
 * Ponto (Version20260401000000/Version20260408180237), então `migrate` puro é inseguro.
 *
 * down() recria o unique GLOBAL — só funciona se nesse momento NÃO existir o mesmo cpf/cnpj em
 * tenants diferentes (depois que cadastros cross-tenant existirem, o rollback falha de propósito).
 */
final class Version20260626150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove o unique global de cpf/cnpj (Cliente PF/PJ); unicidade passa a ser por escritório na aplicação';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_ab7d86653e3e11f0');
        $this->addSql('DROP INDEX uniq_a2cbca4ec8c6906b');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_ab7d86653e3e11f0 ON cliente_pf (cpf)');
        $this->addSql('CREATE UNIQUE INDEX uniq_a2cbca4ec8c6906b ON cliente_pj (cnpj)');
    }
}
