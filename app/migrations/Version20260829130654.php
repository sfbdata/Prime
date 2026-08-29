<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Exclusão-lápide da pasta: quem excluiu e quando.
 *
 * Excluir uma pasta que já tem posterior deixa de apagar a linha — ela fica na lista riscada,
 * arquivada e somente-leitura. Sem isso o número da pasta (MAX(prefixo)+1, ver GerarNumeroDePasta)
 * virava buraco sem nenhum registro: em produção, 185 buracos entre 1 e 1240.
 *
 * Nada de dado existente é tocado: as duas colunas nascem nulas, e pasta com `excluida_em` nulo é
 * exatamente o que o sistema sempre teve.
 *
 * O `--dump-sql` desta base também propunha, por ruído alheio a esta frente, `DROP INDEX` em três
 * índices criados por SQL cru (o Doctrine não sabe representá-los no mapeamento) e um
 * `ALTER ... DROP DEFAULT` em três tabelas de cobrança. Ficaram DE FORA de propósito: um deles é o
 * `uniq_cobranca_obrigacao_ref_competencia`, que é o que impede dívida duplicada.
 */
final class Version20260829130654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona excluida_em e excluida_por_id na pasta (exclusão-lápide)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD excluida_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD excluida_por_id INT DEFAULT NULL');
        // ON DELETE SET NULL: apagar o usuário não pode apagar a lápide junto — o registro de que
        // a pasta foi excluída vale mesmo depois que o autor sai do escritório.
        $this->addSql('ALTER TABLE pasta ADD CONSTRAINT FK_9B3BBC81C39F6355 FOREIGN KEY (excluida_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9B3BBC81C39F6355 ON pasta (excluida_por_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP CONSTRAINT FK_9B3BBC81C39F6355');
        $this->addSql('DROP INDEX IDX_9B3BBC81C39F6355');
        $this->addSql('ALTER TABLE pasta DROP excluida_em');
        $this->addSql('ALTER TABLE pasta DROP excluida_por_id');
    }
}
