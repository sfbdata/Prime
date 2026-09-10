<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Em acompanhamento": a marcação PESSOAL de uma meta, que alimenta a aba de mesmo nome
 * em Minhas Metas. Ver `docs/specs/minhas-metas-abas.md`.
 *
 * Tabela de junção pura, com a mesma anatomia de `tarefa_responsaveis` — PK composta e
 * CASCADE dos dois lados. **Sem `tenant_id` de propósito**: o escopo vem da `tarefa`, que é
 * TenantAware e passa pelo TenantFilter; repetir o tenant aqui criaria uma segunda fonte de
 * verdade que pode divergir da primeira.
 *
 * O CASCADE em `user_id` é o que faz a marcação sumir quando o colaborador é excluído —
 * sem ele sobrariam linhas apontando para usuário inexistente, e a aba quebraria ao montar
 * o avatar de quem marcou.
 *
 * O que o `make:migration` propôs e foi RETIRADO daqui (ruído pré-existente do dev,
 * conferido antes com `doctrine:schema:update --dump-sql` no master, e nada disso é desta
 * frente):
 *   - DROP de `uniq_cobranca_obrigacao_ref_competencia`, `idx_cobranca_pessoa_tenant_cpf_digitos`
 *     e `idx_cobranca_pessoa_tenant_cnpj_digitos` — índices funcionais criados por SQL cru, que o
 *     Doctrine não sabe representar no mapeamento e por isso propõe apagar a cada geração. O
 *     primeiro é UNIQUE e é ele que impede dívida duplicada.
 *   - `ALTER … ALTER atual DROP DEFAULT` em cobranca_pessoa_{email,endereco,telefone}, de outra
 *     frente ainda não aplicada no dev.
 */
final class Version20260910171500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tarefa_acompanhamento: marcação pessoal de meta para acompanhar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tarefa_acompanhamento (tarefa_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (tarefa_id, user_id))');
        $this->addSql('CREATE INDEX IDX_7E10BBBF78217710 ON tarefa_acompanhamento (tarefa_id)');
        $this->addSql('CREATE INDEX IDX_7E10BBBFA76ED395 ON tarefa_acompanhamento (user_id)');
        $this->addSql('ALTER TABLE tarefa_acompanhamento ADD CONSTRAINT FK_7E10BBBF78217710 FOREIGN KEY (tarefa_id) REFERENCES tarefa (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE tarefa_acompanhamento ADD CONSTRAINT FK_7E10BBBFA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa_acompanhamento DROP CONSTRAINT FK_7E10BBBF78217710');
        $this->addSql('ALTER TABLE tarefa_acompanhamento DROP CONSTRAINT FK_7E10BBBFA76ED395');
        $this->addSql('DROP TABLE tarefa_acompanhamento');
    }
}
