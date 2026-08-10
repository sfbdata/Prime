<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * A garantia de que este MCP não escreve NÃO é análise do texto do SQL — isso se burla com
 * comentário, encadeamento ou CTE com RETURNING. A garantia é o próprio PostgreSQL recusar,
 * porque o usuário não tem permissão. Este teste prova a recusa contra o banco de verdade.
 *
 * A role é criada por uma conexão SEPARADA, fora da transação do DAMA: uma role criada dentro
 * da transação do teste não existiria para uma conexão nova, que é justamente o que vamos
 * abrir em seguida.
 */
final class ConexaoLeituraTest extends TestCase
{
    private const ROLE = 'jusprime_leitura_teste';
    private const SENHA = 'leitura_teste';

    private static string $dsnLeitura = '';

    public static function setUpBeforeClass(): void
    {
        $dsnAdmin = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertIsString($dsnAdmin, 'DATABASE_URL não definida no ambiente de teste');

        $parser = new DsnParser(['pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
        $params = $parser->parse($dsnAdmin);

        // $_ENV['DATABASE_URL'] chega SEM o sufixo de teste: quem aplica '_test%TEST_TOKEN%'
        // é o `dbname_suffix` do doctrine.yaml, e só dentro do container de serviços do
        // Symfony — uma conexão manual via DriverManager não passa por ali. Sem recalcular o
        // sufixo aqui, este `admin` cairia no banco de DEV ("saas"), não no banco de teste da
        // frente ("saas_test<TEST_TOKEN>"): a role seria criada no banco errado e os GRANT
        // seguintes (que dependem de EM QUAL banco a conexão está) não valeriam para o banco
        // onde a suíte realmente roda.
        $banco = ($params['dbname'] ?? 'saas') . '_test' . (getenv('TEST_TOKEN') ?: '');
        $params['dbname'] = $banco;

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

        $params['user'] = self::ROLE;
        $params['password'] = self::SENHA;
        self::$dsnLeitura = sprintf(
            'pgsql://%s:%s@%s:%d/%s',
            self::ROLE,
            self::SENHA,
            $params['host'] ?? 'db',
            $params['port'] ?? 5432,
            $banco,
        );

        $admin->close();
    }

    public function testRecusaInsert(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $this->expectException(\Doctrine\DBAL\Exception::class);

        // Colunas reais de `tenant`: `name`/`is_active` (não `nome`/`ativo` — a entidade é
        // legado, em inglês). Usar nomes inexistentes faria o Postgres recusar por "column
        // does not exist" na análise da consulta, ANTES de sequer checar permissão — o mesmo
        // erro apareceria para o admin, e o teste passaria pelo motivo errado (decorativo).
        $conexao->conexao()->executeStatement(
            'INSERT INTO tenant (name, is_active, created_at) VALUES (\'invasor\', true, now())',
        );
    }

    public function testRecusaUpdate(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $this->expectException(\Doctrine\DBAL\Exception::class);

        $conexao->conexao()->executeStatement('UPDATE tenant SET name = \'invadido\'');
    }

    public function testRecusaDelete(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $this->expectException(\Doctrine\DBAL\Exception::class);

        $conexao->conexao()->executeStatement('DELETE FROM tenant');
    }

    public function testLeituraFunciona(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT 1 AS um, 2 AS dois', 500);

        self::assertSame(['um', 'dois'], $resultado['colunas']);
        self::assertSame([['um' => 1, 'dois' => 2]], $resultado['linhas']);
        self::assertFalse($resultado['truncado']);
    }

    public function testTetoDeLinhasTruncaEAvisa(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT generate_series(1, 100) AS n', 10);

        self::assertCount(10, $resultado['linhas']);
        self::assertTrue($resultado['truncado']);
    }

    public function testResultadoExatamenteNoTetoNaoDizQueTruncou(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT generate_series(1, 10) AS n', 10);

        self::assertCount(10, $resultado['linhas']);
        self::assertFalse($resultado['truncado'], 'nao truncou nada, nao pode dizer que truncou');
    }

    public function testConsultaVaziaDevolveColunasVazias(): void
    {
        $conexao = new ConexaoLeitura(self::$dsnLeitura);

        $resultado = $conexao->consultar('SELECT 1 AS n WHERE false', 500);

        self::assertSame([], $resultado['linhas']);
        self::assertSame([], $resultado['colunas']);
        self::assertFalse($resultado['truncado']);
    }

    public function testDsnVazioFalhaComMensagemClara(): void
    {
        $conexao = new ConexaoLeitura('');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DATABASE_URL_LEITURA/');

        $conexao->conexao();
    }
}
