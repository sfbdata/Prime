<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FPN-005 — "Responder" no Relatório financeiro (aba Financeiro, desenho 1.2.3): um registro
 * pode responder a outro, com UM nível de recuo, como o Registro da aba Dados
 * (`Version20261006010231`).
 *
 * Gerada por `make:migration` e LIMPA: saíram os 3 `DROP INDEX` de índices funcionais de
 * cobrança (`uniq_cobranca_obrigacao_ref_competencia`, `idx_cobranca_pessoa_tenant_cnpj_digitos`,
 * `idx_cobranca_pessoa_tenant_cpf_digitos`) e os 3 `ALTER ... atual DROP DEFAULT` de
 * `cobranca_pessoa_*` — divergência que já existia no dev antes desta mudança.
 *   - `resposta_a_id` → FK para a própria `pasta_observacao_financeira`, `ON DELETE SET NULL`:
 *     excluir a original não apaga as respostas dos outros.
 *   - `eh_resposta` → a marca de que o registro nasceu como resposta (o `SET NULL` apaga o
 *     vínculo). `DEFAULT false` deixa todo registro existente como registro comum.
 */
final class Version20261007205740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona resposta_a_id e eh_resposta em pasta_observacao_financeira (responder no Relatório financeiro)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_observacao_financeira ADD eh_resposta BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE pasta_observacao_financeira ADD resposta_a_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_observacao_financeira ADD CONSTRAINT FK_7EBCB49E2EF701A0 FOREIGN KEY (resposta_a_id) REFERENCES pasta_observacao_financeira (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_pasta_obs_fin_resposta_a ON pasta_observacao_financeira (resposta_a_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_observacao_financeira DROP CONSTRAINT FK_7EBCB49E2EF701A0');
        $this->addSql('DROP INDEX idx_pasta_obs_fin_resposta_a');
        $this->addSql('ALTER TABLE pasta_observacao_financeira DROP eh_resposta');
        $this->addSql('ALTER TABLE pasta_observacao_financeira DROP resposta_a_id');
    }
}
