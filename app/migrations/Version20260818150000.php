<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `pasta.cliente_principal_id`: qual cliente representa a pasta nos indicadores (2026-08-18) —
 * spec `docs/specs/pasta-cliente-principal.md`.
 *
 * O problema: a "Média por CPF" da aba Financeiro é de UM cliente, mas a pasta pode ter vários.
 * Até aqui quem escolhia era `Pasta::getPrimeiroCliente()`, pelo id do cliente — a ordem de
 * cadastro no escritório. Consequência real: vincular depois um cliente mais antigo TROCA o
 * número exibido, sem ninguém ter pedido. Um ato sem relação com dinheiro mexendo num indicador
 * financeiro.
 *
 * Por que uma coluna aqui, e não a flag `principal` numa tabela de vínculo (como em
 * `pasta_processo`): `pasta_cliente` é uma ManyToMany pura, e promovê-la a entidade obrigaria a
 * trocar a PK de uma tabela populada e a remover o mapeamento atual — o que arrasta 4 templates,
 * 4 consultas DQL, o formulário e 4 arquivos de teste. A coluna entrega o mesmo resultado e ainda
 * dá a unicidade DE GRAÇA E NO BANCO (uma coluna aponta para um cliente só); o precedente dos
 * processos mantém o invariante em memória, sem trava nenhuma. Decisão do dono em 18/08.
 *
 * NÃO HÁ BACKFILL, e isso é de propósito. `Pasta::getClientePrincipal()` cai no critério antigo
 * quando a coluna é nula — que é o estado de 100% das pastas no instante em que isto sobe. Logo
 * nenhum número muda de valor no dia do deploy; a tela só muda quando alguém marcar de propósito.
 *
 * `ON DELETE SET NULL`: apagar um cliente não pode derrubar a pasta. Sem marcação, a pasta volta
 * sozinha ao critério automático.
 *
 * Escrita à mão, não gerada: `make:migration` roda contra o banco apontado pelo `.env`, e a
 * worktree não herda o `app/.env.local` — apontaria para `saas` (parado no tempo) em vez de
 * `saas_ux`, trazendo alteração de outras frentes e `DROP INDEX` em índice funcional no diff.
 */
final class Version20260818150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona pasta.cliente_principal_id (qual cliente manda nos indicadores da pasta)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD cliente_principal_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD CONSTRAINT fk_pasta_cliente_principal FOREIGN KEY (cliente_principal_id) REFERENCES cliente (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_pasta_cliente_principal ON pasta (cliente_principal_id)');
    }

    /**
     * A volta é segura e sem perda de dado de negócio: a coluna guarda uma PREFERÊNCIA de
     * exibição, não um fato. Apagá-la devolve todas as pastas ao critério automático, que é
     * exatamente onde elas estavam antes desta migration.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP CONSTRAINT fk_pasta_cliente_principal');
        $this->addSql('DROP INDEX idx_pasta_cliente_principal');
        $this->addSql('ALTER TABLE pasta DROP cliente_principal_id');
    }
}
