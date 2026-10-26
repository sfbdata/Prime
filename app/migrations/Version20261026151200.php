<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chat I.A na Central, PR 1 (`docs/specs/ia-chat-central.md`): `central_mensagem.de_ia`, a marca da fala da IA. O tipo
 * novo `ia` de `central_conversa.tipo` cabe no VARCHAR(20) existente (sem CHECK nem enum no banco). Escrita à mão: o
 * gerador mistura divergência de outras frentes e propõe apagar índices funcionais.
 */
final class Version20261026151200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chat I.A na Central: coluna central_mensagem.de_ia';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_mensagem ADD de_ia BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE central_mensagem DROP de_ia');
    }
}
