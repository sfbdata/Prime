<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Assert;

/**
 * Fonte ÚNICA dos DSNs que os testes do MCP usam.
 *
 * Existe por dois motivos, os dois medidos:
 *
 * 1. `$_ENV['DATABASE_URL']` chega SEM o sufixo de teste. Quem aplica
 *    `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` é o `doctrine.yaml`, e só dentro do
 *    container de serviços do Symfony — uma conexão manual via `DriverManager` (ou uma env var
 *    arbitrária como `DATABASE_URL_LEITURA`) não passa por ali. Sem recalcular o sufixo, o teste
 *    cai no banco de DEV (`saas`), que carrega dataset REAL de produção, em vez do banco da
 *    frente (`saas_test<TEST_TOKEN>`). Isso já foi medido: `saas` tem 6 clientes,
 *    `saas_testmcp-leitura` tem 0 — e uma divergência de migração da branch passaria batida.
 *
 * 2. `ConexaoLeitura::conexao()` RECUSA subir com um usuário que tenha escrita (a invariante que
 *    substituiu a promessa do runbook). Logo, todo teste que constrói uma `ConexaoLeitura` de
 *    verdade precisa da role restrita — não dá mais para "usar o admin porque é só leitura".
 *    A role é provisionada aqui, uma vez por processo.
 *
 * O cálculo do sufixo já estava duplicado em dois arquivos, cada um do seu jeito (regex num,
 * concatenação no outro); esta classe é o lugar único.
 */
final class BancoDeLeituraDeTeste
{
    public const ROLE = 'jusprime_leitura_teste';
    private const SENHA = 'leitura_teste';

    /**
     * Role usada para reproduzir o buraco: `SELECT, UPDATE, DELETE` e, de propósito, SEM
     * `INSERT`. Antes da correção, `ConexaoLeitura` só perguntava por `INSERT` ao Postgres —
     * essa role passava pela checagem e escrevia de verdade depois de desligar o
     * `default_transaction_read_only`.
     */
    public const ROLE_SEM_INSERT = 'jusprime_sem_insert_teste';
    private const SENHA_SEM_INSERT = 'sem_insert_teste';

    private static ?string $dsnLeitura = null;
    private static ?string $dsnSemInsert = null;

    /**
     * DSN do usuário administrativo da suíte, já apontando para o banco DA FRENTE.
     * Serve para provisionar a role e para provar que a invariante recusa este usuário.
     */
    public static function dsnAdministrativo(): string
    {
        $params = self::parametrosDoBancoDaFrente();

        return self::montarDsn(
            (string) ($params['user'] ?? 'symfony'),
            (string) ($params['password'] ?? ''),
            $params,
        );
    }

    /**
     * DSN da role restrita (só `SELECT`), criando-a se ainda não existir.
     *
     * A role é criada por uma conexão SEPARADA, fora da transação do DAMA: uma role criada
     * dentro da transação do teste não existiria para uma conexão nova, que é justamente o que
     * `ConexaoLeitura` vai abrir em seguida.
     */
    public static function dsnLeitura(): string
    {
        if (self::$dsnLeitura !== null) {
            return self::$dsnLeitura;
        }

        $params = self::parametrosDoBancoDaFrente();
        $banco = (string) $params['dbname'];

        $admin = DriverManager::getConnection($params);

        // DO block porque o PostgreSQL não tem CREATE ROLE IF NOT EXISTS.
        $admin->executeStatement(sprintf(
            "DO $$ BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '%s') THEN
                    CREATE ROLE %s LOGIN PASSWORD '%s';
                END IF;
            END $$;",
            self::ROLE,
            self::ROLE,
            self::SENHA,
        ));
        $admin->executeStatement(sprintf('GRANT CONNECT ON DATABASE "%s" TO %s', $banco, self::ROLE));
        $admin->executeStatement(sprintf('GRANT USAGE ON SCHEMA public TO %s', self::ROLE));
        $admin->executeStatement(sprintf('GRANT SELECT ON ALL TABLES IN SCHEMA public TO %s', self::ROLE));
        $admin->executeStatement(sprintf(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO %s',
            self::ROLE,
        ));

        $admin->close();

        return self::$dsnLeitura = self::montarDsn(self::ROLE, self::SENHA, $params);
    }

    /**
     * DSN da role `SELECT, UPDATE, DELETE` sem `INSERT`, criando-a se ainda não existir.
     *
     * Mesmo padrão de `dsnLeitura()`: conexão administrativa separada, fora da transação do
     * DAMA, porque a role precisa existir para uma conexão NOVA (a que `ConexaoLeitura` abre em
     * seguida), não para a transação do teste que a criou.
     */
    public static function dsnSemInsert(): string
    {
        if (self::$dsnSemInsert !== null) {
            return self::$dsnSemInsert;
        }

        $params = self::parametrosDoBancoDaFrente();
        $banco = (string) $params['dbname'];

        $admin = DriverManager::getConnection($params);

        // DO block porque o PostgreSQL não tem CREATE ROLE IF NOT EXISTS.
        $admin->executeStatement(sprintf(
            "DO $$ BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '%s') THEN
                    CREATE ROLE %s LOGIN PASSWORD '%s';
                END IF;
            END $$;",
            self::ROLE_SEM_INSERT,
            self::ROLE_SEM_INSERT,
            self::SENHA_SEM_INSERT,
        ));
        $admin->executeStatement(sprintf('GRANT CONNECT ON DATABASE "%s" TO %s', $banco, self::ROLE_SEM_INSERT));
        $admin->executeStatement(sprintf('GRANT USAGE ON SCHEMA public TO %s', self::ROLE_SEM_INSERT));
        $admin->executeStatement(sprintf(
            'GRANT SELECT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %s',
            self::ROLE_SEM_INSERT,
        ));
        $admin->executeStatement(sprintf(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, UPDATE, DELETE ON TABLES TO %s',
            self::ROLE_SEM_INSERT,
        ));
        // Sem este REVOKE explícito, `GRANT SELECT, UPDATE, DELETE ON ALL TABLES` teria
        // deixado a role só com esses três privilégios de qualquer forma (GRANT não acumula
        // privilégio que não foi pedido) — mas o REVOKE torna a ausência de INSERT uma
        // afirmação, não uma inferência de quem lê o teste.
        $admin->executeStatement(sprintf('REVOKE INSERT ON ALL TABLES IN SCHEMA public FROM %s', self::ROLE_SEM_INSERT));

        $admin->close();

        return self::$dsnSemInsert = self::montarDsn(self::ROLE_SEM_INSERT, self::SENHA_SEM_INSERT, $params);
    }

    /** @return array<string, mixed> */
    private static function parametrosDoBancoDaFrente(): array
    {
        $dsn = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');

        if (!is_string($dsn) || trim($dsn) === '') {
            // Falha explícita: devolver string vazia daqui faria o teste morrer lá na frente com
            // "DATABASE_URL_LEITURA não está configurada", que aponta para o lugar errado.
            Assert::fail('DATABASE_URL não está definida no ambiente de teste — sem ela não dá para achar o banco da frente.');
        }

        $parser = new DsnParser(['pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
        $params = $parser->parse($dsn);
        $params['dbname'] = ($params['dbname'] ?? 'saas') . '_test' . (getenv('TEST_TOKEN') ?: '');

        return $params;
    }

    /** @param array<string, mixed> $params */
    private static function montarDsn(string $usuario, string $senha, array $params): string
    {
        return sprintf(
            'pgsql://%s:%s@%s:%d/%s',
            rawurlencode($usuario),
            rawurlencode($senha),
            $params['host'] ?? 'db',
            $params['port'] ?? 5432,
            $params['dbname'],
        );
    }
}
