<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Cenário (tipo de entidade) da assembleia do Voto: define só rótulos; as existentes ficam como condomínio. */
final class Version20261117010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona o cenário (tipo de entidade) à assembleia do Voto, padrão condomínio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE voto.assembleia ADD cenario VARCHAR(12) DEFAULT 'condominio' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia DROP cenario');
    }
}
