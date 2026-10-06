<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M1 da aba Documentos (D1, DOC-65): quem enviou, quando foi editado e quantas páginas tem.
 *
 * Escrita à mão, só com as três colunas desta frente (nenhum `DROP INDEX` de índice funcional
 * nem ruído de outra frente entra aqui). Nomes de FK e índice no padrão do DBAL (`FK_`/`IDX_` +
 * crc32 da tabela e da coluna), conferidos contra o precedente `excluida_por_id` da pasta, para
 * o `doctrine:schema:validate` não propor nada.
 *
 * Nada de dado existente é tocado: as três nascem nulas. NULL é "ainda não preenchido" — o
 * acervo anterior recebe `paginas` por `app:documentos:calcular-hash --paginas`; `enviado_por`
 * e `modificado_em` só passam a existir daqui em diante.
 *
 * `ON DELETE SET NULL`: apagar o usuário não pode apagar o documento nem o registro de que ele
 * existiu — o mesmo motivo da lápide da pasta.
 */
final class Version20261006230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona enviado_por_id, modificado_em e paginas em pasta_documento (aba Documentos, D1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento ADD enviado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD modificado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD paginas INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD CONSTRAINT FK_69D2B38166B4CF03 FOREIGN KEY (enviado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_69D2B38166B4CF03 ON pasta_documento (enviado_por_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento DROP CONSTRAINT FK_69D2B38166B4CF03');
        $this->addSql('DROP INDEX IDX_69D2B38166B4CF03');
        $this->addSql('ALTER TABLE pasta_documento DROP enviado_por_id');
        $this->addSql('ALTER TABLE pasta_documento DROP modificado_em');
        $this->addSql('ALTER TABLE pasta_documento DROP paginas');
    }
}
