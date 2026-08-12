<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fatia 2 de docs/specs/isolamento-multitenant-cobertura.md — Kanban.
 *
 * As 7 filhas do Kanban nao tinham tenant_id: o TenantFilter so age em entidades TenantAware, e
 * TenantAware exige a coluna. Sem ela o dominio inteiro ficava fora do filtro — consulta de
 * Kanban devolvia dado de todos os escritorios sem erro nenhum.
 *
 * A coluna e denormalizada do pai. Por isso o backfill segue a cadeia
 * board -> coluna/card/marcador -> checklist/comentario/anexo -> checklist_item,
 * e a ordem dos comandos abaixo importa: checklist_item so resolve depois de checklist.
 *
 * Nao ha ON DELETE CASCADE na FK de tenant de proposito: quem manda na remocao e o pai
 * (que ja tem CASCADE). A FK aqui e integridade referencial, nao caminho de exclusao.
 *
 * NOTA sobre o arquivo gerado pelo make:migration: ele trouxe 6 comandos de OUTRA frente
 * (3 DROP INDEX da cobranca e 3 ALTER ... DROP DEFAULT), removidos daqui. Os DROP INDEX eram
 * de indices FUNCIONAIS, que o Doctrine nao sabe representar no mapeamento e por isso propoe
 * apagar; o down() gerado ainda os recriaria como indices simples — perda silenciosa.
 */
final class Version20260812185900 extends AbstractMigration
{
    /** Filha => SQL que resolve o tenant dela a partir do pai. A ordem e de dependencia. */
    private const BACKFILL = [
        'kanban_coluna'    => 'UPDATE kanban_coluna f SET tenant_id = p.tenant_id
                                 FROM kanban_board p WHERE f.board_id = p.id',
        'kanban_card'      => 'UPDATE kanban_card f SET tenant_id = p.tenant_id
                                 FROM kanban_board p WHERE f.board_id = p.id',
        'kanban_marcador'  => 'UPDATE kanban_marcador f SET tenant_id = p.tenant_id
                                 FROM kanban_board p WHERE f.board_id = p.id',
        'kanban_checklist' => 'UPDATE kanban_checklist f SET tenant_id = p.tenant_id
                                 FROM kanban_card p WHERE f.card_id = p.id',
        'kanban_comentario' => 'UPDATE kanban_comentario f SET tenant_id = p.tenant_id
                                 FROM kanban_card p WHERE f.card_id = p.id',
        'kanban_anexo'     => 'UPDATE kanban_anexo f SET tenant_id = p.tenant_id
                                 FROM kanban_card p WHERE f.card_id = p.id',
        // depende de kanban_checklist ja preenchida acima
        'kanban_checklist_item' => 'UPDATE kanban_checklist_item f SET tenant_id = p.tenant_id
                                      FROM kanban_checklist p WHERE f.checklist_id = p.id',
    ];

    /** Filha => sufixo do nome da FK/indice gerado pelo Doctrine. */
    private const NOMES = [
        'kanban_coluna'         => 'AEEEBCD4',
        'kanban_card'           => 'B2140480',
        'kanban_marcador'       => 'F7559ECE',
        'kanban_checklist'      => '1E27FFC5',
        'kanban_comentario'     => 'B0CA2F6',
        'kanban_anexo'          => '3FB8AFFE',
        'kanban_checklist_item' => '548D5BE5',
    ];

    public function getDescription(): string
    {
        return 'Kanban: tenant_id nas 7 filhas, denormalizado do pai, para o TenantFilter alcancar o dominio';
    }

    public function up(Schema $schema): void
    {
        // 1. coluna nullable — as tabelas ja tem linhas, NOT NULL direto falharia
        foreach (array_keys(self::BACKFILL) as $tabela) {
            $this->addSql(sprintf('ALTER TABLE %s ADD tenant_id INT DEFAULT NULL', $tabela));
        }

        // 2. backfill pela cadeia do pai, na ordem de dependencia
        foreach (self::BACKFILL as $sql) {
            $this->addSql($sql);
        }

        // 3. tranca. Se alguma linha ficou orfa (pai inexistente), o SET NOT NULL FALHA aqui —
        //    e falhar alto e o comportamento desejado: linha sem tenant e exatamente o defeito
        //    que esta migration existe para eliminar, nao algo a tolerar em silencio.
        foreach (self::NOMES as $tabela => $sufixo) {
            $this->addSql(sprintf('ALTER TABLE %s ALTER tenant_id SET NOT NULL', $tabela));
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT FK_%s9033212A FOREIGN KEY (tenant_id)'
                . ' REFERENCES tenant (id) NOT DEFERRABLE',
                $tabela,
                $sufixo
            ));
            $this->addSql(sprintf('CREATE INDEX IDX_%s9033212A ON %s (tenant_id)', $sufixo, $tabela));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::NOMES as $tabela => $sufixo) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT FK_%s9033212A', $tabela, $sufixo));
            $this->addSql(sprintf('DROP INDEX IDX_%s9033212A', $sufixo));
            $this->addSql(sprintf('ALTER TABLE %s DROP tenant_id', $tabela));
        }
    }
}
