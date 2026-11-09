<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261109160000;
use Psr\Log\NullLogger;

/**
 * Prepara no banco de teste o papel `voto_acesso` e as permissões da Version20261109160000, sem depender do snapshot.
 *
 * O snapshot de teste (scripts/gerar-schema-de-teste.sh) sai com --no-privileges e já leva a versão em
 * doctrine_migration_versions: regerado depois desta migration, o CI não a roda e o banco fica sem papel e sem
 * GRANT. Por isso cada teste roda aqui o SQL do up da própria migration, dentro da transação do DAMA: o CREATE ROLE
 * e os GRANT voltam no rollback do fim do teste. O SQL é idempotente (o papel só nasce se não existir).
 */
final class PapelDoVotoNoBancoDeTeste
{
    public static function preparar(Connection $banco): void
    {
        require_once \dirname(__DIR__, 3) . '/migrations/Version20261109160000.php';

        $migration = new Version20261109160000($banco, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $consulta) {
            $banco->executeStatement($consulta->getStatement());
        }
    }
}
