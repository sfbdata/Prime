<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Identifica pautas eleitorais e mantém pautas antigas como deliberações. */
final class Version20261113214622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tipo explícito às pautas da assembleia.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE voto.assembleia_pauta ADD tipo VARCHAR(20) DEFAULT 'deliberacao' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_pauta DROP tipo');
    }
}
