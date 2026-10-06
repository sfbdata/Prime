<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3 da aba Documentos (D7, DOC-58): a lixeira de documentos e subpastas.
 *
 * `excluido_em`/`excluido_por_id` em `pasta_documento` e `pasta_secao`: preenchidos = o item está
 * na lixeira (a linha e o arquivo físico ficam até `app:documentos:purgar-lixeira`); NULL = vivo.
 * O `LixeiraFilter` (Doctrine) esconde as linhas preenchidas de toda leitura comum.
 *
 * Escrita à mão, só com as colunas desta frente. Nomes de FK e do índice da FK no padrão do DBAL
 * (`FK_`/`IDX_` + crc32 da tabela e da coluna), conferidos contra o precedente `enviado_por_id`
 * (`Version20261006230000`: `69D2B381` = pasta_documento, e `66B4CF03` = enviado_por_id).
 * `C35DDF20` = pasta_secao; `D6157167` = excluido_por_id.
 *
 * Os dois índices parciais (`WHERE excluido_em IS NOT NULL`) são a fila da purga — pequena por
 * natureza; indexar a coluna inteira seria quase todo NULL. O `where` vai entre parênteses porque
 * é assim que o PostgreSQL o devolve (`pg_get_expr`), e é com essa forma que o mapeamento da
 * entidade (`options: ['where' => …]`) bate no `schema:validate`.
 *
 * `ON DELETE SET NULL`: o usuário sair do sistema não apaga a lápide nem o registro de quem excluiu.
 * Nada de dado existente é tocado: tudo nasce NULL (vivo).
 */
final class Version20261007020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lixeira da aba Documentos (D7): excluido_em/excluido_por_id em pasta_documento e pasta_secao, com índice parcial';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_documento ADD excluido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD excluido_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_documento ADD CONSTRAINT FK_69D2B381D6157167 FOREIGN KEY (excluido_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_69D2B381D6157167 ON pasta_documento (excluido_por_id)');
        $this->addSql('CREATE INDEX idx_pasta_documento_lixeira ON pasta_documento (excluido_em) WHERE (excluido_em IS NOT NULL)');

        $this->addSql('ALTER TABLE pasta_secao ADD excluido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_secao ADD excluido_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_secao ADD CONSTRAINT FK_C35DDF20D6157167 FOREIGN KEY (excluido_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_C35DDF20D6157167 ON pasta_secao (excluido_por_id)');
        $this->addSql('CREATE INDEX idx_pasta_secao_lixeira ON pasta_secao (excluido_em) WHERE (excluido_em IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pasta_secao_lixeira');
        $this->addSql('DROP INDEX IDX_C35DDF20D6157167');
        $this->addSql('ALTER TABLE pasta_secao DROP CONSTRAINT FK_C35DDF20D6157167');
        $this->addSql('ALTER TABLE pasta_secao DROP excluido_por_id');
        $this->addSql('ALTER TABLE pasta_secao DROP excluido_em');

        $this->addSql('DROP INDEX idx_pasta_documento_lixeira');
        $this->addSql('DROP INDEX IDX_69D2B381D6157167');
        $this->addSql('ALTER TABLE pasta_documento DROP CONSTRAINT FK_69D2B381D6157167');
        $this->addSql('ALTER TABLE pasta_documento DROP excluido_por_id');
        $this->addSql('ALTER TABLE pasta_documento DROP excluido_em');
    }
}
