<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Importar do Google Drive" (spec `docs/specs/importar-do-drive.md`): `pasta.drive_manter_nome` marca a pasta
 * ligada à pasta de origem no Drive. O envio automático usa essa pasta e não a renomeia para
 * "NUP - CLIENTE - AÇÃO" (decisão do Samuel, 04/11/2026).
 *
 * **Aditiva:** uma coluna com padrão false; as pastas que já existem seguem como estão.
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Importar do Google Drive: pasta ligada à pasta de origem mantém o nome do Drive';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD drive_manter_nome BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP drive_manter_nome');
    }
}
