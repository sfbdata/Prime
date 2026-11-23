<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fatia 6 de docs/specs/isolamento-multitenant-cobertura.md: Ponto.
 *
 * Os blocos de horário (o do escritório, bloco_jornada, e o de cada pessoa, bloco_jornada_colaborador) não
 * tinham tenant_id: o TenantFilter só age em entidade TenantAware, e TenantAware exige a coluna. A coluna é
 * denormalizada do pai (jornada_tenant e jornada_colaborador, que já têm tenant_id NOT NULL), e o backfill
 * copia dele. Aprovada pelo Samuel em 23/11/2026.
 *
 * Volume medido em produção (MCP, 23/11/2026): 1 linha em bloco_jornada e 12 em bloco_jornada_colaborador,
 * todas com pai e escritório.
 *
 * Sem ON DELETE CASCADE na FK de tenant, de propósito, como no Kanban (Version20260812185900): quem manda
 * na remoção é o pai. A FK aqui é integridade, não caminho de exclusão.
 *
 * O dump do Doctrine trouxe 15 comandos de outra divergência do banco (não deste trabalho); ficaram fora.
 */
final class Version20261123190000 extends AbstractMigration
{
    /** Tabela => [SQL que copia o escritório do pai, sufixo do nome da FK/índice gerado pelo Doctrine]. */
    private const BLOCOS = [
        'bloco_jornada' => [
            'UPDATE bloco_jornada b SET tenant_id = p.tenant_id FROM jornada_tenant p WHERE b.jornada_tenant_id = p.id',
            '461DB94D',
        ],
        'bloco_jornada_colaborador' => [
            'UPDATE bloco_jornada_colaborador b SET tenant_id = p.tenant_id FROM jornada_colaborador p'
            . ' WHERE b.jornada_colaborador_id = p.id',
            '79973F80',
        ],
    ];

    public function getDescription(): string
    {
        return 'Ponto: tenant_id nos blocos de horário, copiado da jornada dona, para o TenantFilter alcançá-los';
    }

    public function up(Schema $schema): void
    {
        foreach (self::BLOCOS as $tabela => [$backfill, $sufixo]) {
            // 1. coluna aceitando nulo: as tabelas já têm linhas
            $this->addSql(sprintf('ALTER TABLE %s ADD tenant_id INT DEFAULT NULL', $tabela));
            // 2. escritório do pai
            $this->addSql($backfill);
            // 3. tranca. Linha sem pai deixa o SET NOT NULL falhar alto: bloco sem escritório é o defeito
            //    que esta migration existe para eliminar.
            $this->addSql(sprintf('ALTER TABLE %s ALTER tenant_id SET NOT NULL', $tabela));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT FK_%s9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE',
                $tabela,
                $sufixo,
            ));
            $this->addSql(sprintf('CREATE INDEX IDX_%s9033212A ON %s (tenant_id)', $sufixo, $tabela));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::BLOCOS as $tabela => [, $sufixo]) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT FK_%s9033212A', $tabela, $sufixo));
            $this->addSql(sprintf('DROP INDEX IDX_%s9033212A', $sufixo));
            $this->addSql(sprintf('ALTER TABLE %s DROP tenant_id', $tabela));
        }
    }
}
