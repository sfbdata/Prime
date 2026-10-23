<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E12c: prazos LIDOS das datas escritas nos registros da pasta (desenho 1.2.3, `lerDatas`).
 *
 * `pasta_prazo.origem` separa o prazo do "+" do trilho (`manual`, todas as linhas existentes) do lido de um registro
 * (`registro`); `registro_ref` aponta o registro ("expedientes:12"); `estimado` marca a data contada de "prazo de N
 * dias". Só colunas com default: nada existente muda.
 */
final class Version20261016220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E12c: origem, registro_ref e estimado em pasta_prazo (prazos lidos dos registros)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pasta_prazo ADD origem VARCHAR(20) DEFAULT 'manual' NOT NULL");
        $this->addSql('ALTER TABLE pasta_prazo ADD registro_ref VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta_prazo ADD estimado BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta_prazo DROP origem');
        $this->addSql('ALTER TABLE pasta_prazo DROP registro_ref');
        $this->addSql('ALTER TABLE pasta_prazo DROP estimado');
    }
}
