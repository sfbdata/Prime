<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Telefone opcional da unidade da assembleia, só para o "Lembrar quem não votou" (WhatsApp). */
final class Version20261116233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona telefone opcional à unidade da assembleia do Voto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_unidade ADD telefone VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_unidade DROP telefone');
    }
}
