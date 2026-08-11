<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use App\Mcp\Tool\ConsultarSqlTool;
use App\Tests\Mcp\BancoDeLeituraDeTeste;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConsultarSqlToolTest extends TestCase
{
    private function ferramenta(): ConsultarSqlTool
    {
        // A role restrita, no banco DA FRENTE. Não é preciosismo: `$_ENV['DATABASE_URL']` cru
        // aponta para `saas`, o banco de DEV com dataset real de produção, e a conexão
        // administrativa nem sobe mais (`ConexaoLeitura` recusa usuário com escrita).
        return new ConsultarSqlTool(
            new ConexaoLeitura(BancoDeLeituraDeTeste::dsnLeitura()),
            new NullLogger(),
        );
    }

    public function testDevolveColunasLinhasETotal(): void
    {
        $resultado = $this->ferramenta()->consultar('SELECT 7 AS sete');

        self::assertSame(['sete'], $resultado['colunas']);
        self::assertSame([['sete' => 7]], $resultado['linhas']);
        self::assertSame(1, $resultado['total']);
        self::assertFalse($resultado['truncado']);
    }

    public function testTruncaEm500Linhas(): void
    {
        $resultado = $this->ferramenta()->consultar('SELECT generate_series(1, 900) AS n');

        self::assertSame(ConsultarSqlTool::LIMITE_LINHAS, $resultado['total']);
        self::assertCount(ConsultarSqlTool::LIMITE_LINHAS, $resultado['linhas']);
        self::assertTrue($resultado['truncado']);
    }

    public function testSqlInvalidoViraErroDeFerramentaComMensagemEmPortugues(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Falha ao executar a consulta/');

        $this->ferramenta()->consultar('SELECT * FROM tabela_que_nao_existe_nenhuma');
    }

    public function testConsultaLentaEstouraOTimeoutEViraErroDeFerramenta(): void
    {
        // 1 segundo de teto contra um sleep de 3: prova o statement_timeout sem fazer a
        // suíte esperar os 15 segundos de produção.
        $ferramenta = new ConsultarSqlTool(
            new ConexaoLeitura(BancoDeLeituraDeTeste::dsnLeitura(), 1),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Falha ao executar a consulta/');

        $ferramenta->consultar('SELECT pg_sleep(3)');
    }

    public function testRegistraAConsultaNoLog(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $registros = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->registros[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        (new ConsultarSqlTool(new ConexaoLeitura(BancoDeLeituraDeTeste::dsnLeitura()), $logger))
            ->consultar('SELECT 1 AS n');

        self::assertCount(1, $logger->registros);
        self::assertSame('SELECT 1 AS n', $logger->registros[0]['context']['sql']);
        self::assertSame(1, $logger->registros[0]['context']['linhas']);
        self::assertArrayHasKey('duracao_ms', $logger->registros[0]['context']);
    }
}
