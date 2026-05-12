<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260512140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed smoke test: Sede Smoke B para tenant_id=30 — ignorada em produção';
    }

    public function preUp(Schema $schema): void
    {
        $this->skipIf(($_ENV['APP_ENV'] ?? 'dev') === 'prod', 'Seed de smoke test — ignorada em produção');
    }

    public function preDown(Schema $schema): void
    {
        $this->skipIf(($_ENV['APP_ENV'] ?? 'dev') === 'prod', 'Rollback de seed de smoke test — ignorado em produção');
    }

    public function up(Schema $schema): void
    {
        $check = $this->connection->executeQuery(
            'SELECT id FROM sede WHERE tenant_id = 30 AND nome = :nome',
            ['nome' => 'Sede Smoke B']
        );
        if ($check->fetchOne() !== false) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO sede (nome, latitude, longitude, raio_permitido, timezone, tenant_id)'
            . ' VALUES (:nome, :latitude, :longitude, :raio_permitido, :timezone, :tenant_id)',
            [
                'nome'           => 'Sede Smoke B',
                'latitude'       => '-22.90680000',
                'longitude'      => '-43.17290000',
                'raio_permitido' => 100,
                'timezone'       => 'America/Sao_Paulo',
                'tenant_id'      => 30,
            ]
        );
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement(
            'DELETE FROM sede WHERE tenant_id = 30 AND nome = :nome',
            ['nome' => 'Sede Smoke B']
        );
    }
}