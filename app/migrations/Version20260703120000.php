<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Habilita a extensão `unaccent` do PostgreSQL para a busca livre dos filtros
 * ficar tolerante a acento/maiúsculas/ç (ex.: "jose" acha "José", "goncalves"
 * acha "Gonçalves", "sao" acha "São"). Usada via a função DQL UNACCENT.
 */
final class Version20260703120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Habilita a extensão unaccent (busca livre tolerante a acento/maiúsculas/ç nos filtros)';
    }

    public function up(Schema $schema): void
    {
        // "unaccent" é extensão trusted no PG13+: instalável por usuário com
        // privilégio CREATE no banco (não exige superuser). Idempotente.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(Schema $schema): void
    {
        // Não removemos a extensão: outras queries de busca dependem dela e um
        // DROP EXTENSION exigiria remover as dependências. Deixá-la instalada é seguro.
    }
}
