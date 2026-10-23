<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agendador de tarefas (Symfony Scheduler; spec `docs/specs/scheduler-tarefas-agendadas.md`): as duas tabelas de
 * infraestrutura do contêiner `scheduler`, no formato que o próprio Symfony gera (o Doctrine as enxerga pelos
 * schema listeners da trava e do cache, então o schema:validate fica em dia sem filtro).
 *
 * - `lock_keys`: a trava do agendador (DoctrineDbalStore, recurso `agendador` do config/packages/lock.yaml). Garante
 *   que dois consumidores nunca gerem a mesma tarefa.
 * - `cache_agendador`: o estado do Schedule `stateful` (pool `cache.agendador`), a última execução de cada tarefa.
 *   Sobrevive ao deploy, que recria o contêiner.
 *
 * Sem dado de escritório nenhum: não há tenant_id aqui. Nada é preenchido para trás.
 */
final class Version20261023150800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agendador de tarefas: tabelas da trava (lock_keys) e do estado (cache_agendador)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id))');
        $this->addSql('CREATE TABLE cache_agendador (item_id VARCHAR(255) NOT NULL, item_data BYTEA NOT NULL, item_lifetime INT DEFAULT NULL, item_time INT NOT NULL, PRIMARY KEY (item_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cache_agendador');
        $this->addSql('DROP TABLE lock_keys');
    }
}
