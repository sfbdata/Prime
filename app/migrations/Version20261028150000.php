<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tela "Meu Escritório" (spec `docs/specs/meu-escritorio.md`): registro na OAB, e-mail e telefone de contato, a chave
 * da logo, quem alterou os dados e quando, e o código da conta. Só aditiva: colunas nulas e um índice único parcial.
 * Sem UPDATE e sem DROP: o `codigo_conta` dos escritórios existentes é gerado no primeiro acesso à tela.
 */
final class Version20261028150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meu Escritório: oab, email_contato, telefone, logo_chave, alterado_em, alterado_por_id e codigo_conta no tenant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD oab VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD email_contato VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD telefone VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD logo_chave VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD alterado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD alterado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD codigo_conta VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD CONSTRAINT fk_tenant_alterado_por FOREIGN KEY (alterado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_4E59C4624D466817 ON tenant (alterado_por_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_codigo_conta ON tenant (codigo_conta) WHERE codigo_conta IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_tenant_codigo_conta');
        $this->addSql('ALTER TABLE tenant DROP CONSTRAINT fk_tenant_alterado_por');
        $this->addSql('DROP INDEX IDX_4E59C4624D466817');
        $this->addSql('ALTER TABLE tenant DROP alterado_em, DROP alterado_por_id, DROP codigo_conta, DROP logo_chave, DROP telefone, DROP email_contato, DROP oab');
    }
}
