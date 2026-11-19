<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conector de I.A.: o Master deixa de liberar o conector escritório por escritório (decisão do Samuel, 19/11; spec
 * docs/specs/mcp-remoto-oauth.md, 14.a). A coluna `liberado_pelo_master` sai de `conector_ia_escritorio`; o que o
 * administrador ligou e o aceite dele ficam como estão. Nenhum índice nem default de outra coluna a referencia.
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conector de I.A.: remove conector_ia_escritorio.liberado_pelo_master (o Master não libera mais por escritório).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conector_ia_escritorio DROP COLUMN liberado_pelo_master');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conector_ia_escritorio ADD COLUMN liberado_pelo_master BOOLEAN DEFAULT false NOT NULL');
    }
}
