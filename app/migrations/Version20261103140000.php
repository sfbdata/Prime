<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Painel de artigos dos blogs, fatia P7 (spec painel-artigos-blogs, seção 11): coluna `marcadores` (jsonb, lista de
 * textos) em `artigo_blog`. Artigo antigo fica com a lista vazia.
 */
final class Version20261103140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coluna marcadores (jsonb) em artigo_blog';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE artigo_blog ADD marcadores JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artigo_blog DROP marcadores');
    }
}
