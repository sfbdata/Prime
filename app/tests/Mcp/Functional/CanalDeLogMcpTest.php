<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Mcp\Service\ConexaoLeitura;
use App\Mcp\Tool\ConsultarSqlTool;
use App\Tests\Mcp\BancoDeLeituraDeTeste;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Prova que o canal Monolog "mcp" (Task 6) está de fato ligado ao `$mcpLogger` que
 * `ConsultarSqlTool` (Task 3) recebe — e não caindo no logger padrão da aplicação.
 *
 * Sem este teste, a suíte continuaria verde mesmo com `monolog.yaml` revertido:
 * `ConsultarSqlToolTest` constrói a ferramenta à mão com `NullLogger`, e
 * `McpServerCommandTest` só confere o stdout, nunca o conteúdo do log. Nenhum dos dois
 * exercita o autowiring por nome de argumento do Monolog.
 */
final class CanalDeLogMcpTest extends KernelTestCase
{
    /**
     * Prova de COMPILAÇÃO: o serviço que o container de verdade injeta em
     * `ConsultarSqlTool::$mcpLogger` é o logger do canal "mcp", não um logger genérico.
     */
    public function testContainerLigaOMcpLoggerAoCanalMcp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $ferramenta = $container->get(ConsultarSqlTool::class);

        $reflexao = new \ReflectionProperty(ConsultarSqlTool::class, 'mcpLogger');
        $loggerInjetado = $reflexao->getValue($ferramenta);

        self::assertInstanceOf(Logger::class, $loggerInjetado);
        self::assertSame('mcp', $loggerInjetado->getName(), 'mcpLogger não está no canal "mcp"');
    }

    /**
     * Prova de EXECUÇÃO: o logger real do canal "mcp" (o mesmo objeto que o teste acima
     * confirma estar ligado à ferramenta) grava de fato no arquivo declarado em
     * `monolog.yaml` quando `ConsultarSqlTool::consultar()` roda — não só o nome do canal
     * está certo, o handler por trás dele funciona.
     */
    public function testConsultaDeVerdadeGravaNoArquivoDoCanalMcp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $arquivoLog = $container->getParameter('kernel.logs_dir') . '/mcp_test.log';
        @unlink($arquivoLog);

        // Logger tirado do container de verdade (mesmo serviço que o teste acima prova estar
        // ligado ao "mcp"), numa ferramenta com a conexão de leitura do banco DA FRENTE —
        // combinando os dois, prova que o autowiring aponta pro canal certo E que o canal certo
        // escreve no arquivo certo.
        $loggerReal = $container->get('monolog.logger.mcp');
        $ferramenta = new ConsultarSqlTool(
            new ConexaoLeitura(BancoDeLeituraDeTeste::dsnLeitura()),
            $loggerReal,
        );

        $ferramenta->consultar('SELECT 1 AS n');

        self::assertFileExists($arquivoLog, 'canal "mcp" não gravou o arquivo esperado');

        $conteudo = file_get_contents($arquivoLog);
        self::assertIsString($conteudo);
        self::assertStringContainsString('consulta executada', $conteudo);
        self::assertStringContainsString('SELECT 1 AS n', $conteudo);
    }
}
