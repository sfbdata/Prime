<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Roda o comando como PROCESSO DE VERDADE. É a única forma de provar que o stdout carrega
 * exclusivamente JSON-RPC: um CommandTester não enxergaria um `echo` perdido nem um handler
 * de log escrevendo na saída padrão.
 */
final class McpServerCommandTest extends TestCase
{
    // PHP 8.2 não aceita `new` em inicializador de constante de classe (só a partir do 8.3),
    // e este projeto roda 8.2 — por isso é método, não `const`, apesar do brief pedir `const`.
    /** @return list<array<string, mixed>> */
    private static function handshake(): array
    {
        return [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'teste', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ];
    }

    public function testHandshakeRespondeEStdoutSoTemJsonRpc(): void
    {
        $processo = $this->rodarServidor(self::handshake());

        self::assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());

        $linhas = $this->linhasDaSaida($processo->getOutput());
        self::assertNotEmpty($linhas, 'servidor não respondeu nada no stdout');

        foreach ($linhas as $linha) {
            $decodificado = json_decode($linha, true);
            self::assertIsArray(
                $decodificado,
                sprintf('stdout tem linha que não é JSON-RPC: %s', $linha),
            );
            self::assertSame('2.0', $decodificado['jsonrpc'] ?? null);
        }
    }

    public function testRespondeAoInitializeComONomeDoServidor(): void
    {
        $processo = $this->rodarServidor(self::handshake());

        $resposta = $this->respostaComId($processo->getOutput(), 1);

        self::assertNotNull($resposta, 'nenhuma resposta para o initialize (id 1)');
        self::assertSame('JusPrime (leitura)', $resposta['result']['serverInfo']['name']);
    }

    /** @param list<array<string, mixed>> $mensagens */
    private function rodarServidor(array $mensagens): Process
    {
        $entrada = '';
        foreach ($mensagens as $mensagem) {
            $entrada .= json_encode($mensagem, \JSON_THROW_ON_ERROR) . "\n";
        }

        $processo = new Process(
            ['php', 'bin/console', 'mcp:server'],
            dirname(__DIR__, 3),          // .../app — funciona na worktree e no repo principal
            [
                'APP_ENV' => 'test',
                // Sem isto o subprocesso cairia no saas_test compartilhado em vez do banco
                // da frente. O Process herda o ambiente do pai, mas depender dessa herança
                // é a diferença entre um teste que sabe o que testa e um que dá sorte.
                'TEST_TOKEN' => getenv('TEST_TOKEN') ?: '',
            ],
        );
        $processo->setInput($entrada);    // fecha o STDIN ao terminar → o servidor encerra
        $processo->setTimeout(30);
        $processo->run();

        return $processo;
    }

    /** @return list<string> */
    private function linhasDaSaida(string $saida): array
    {
        return array_values(array_filter(
            preg_split('/\R/', $saida) ?: [],
            static fn (string $linha): bool => trim($linha) !== '',
        ));
    }

    /** @return array<string, mixed>|null */
    private function respostaComId(string $saida, int $id): ?array
    {
        foreach ($this->linhasDaSaida($saida) as $linha) {
            $decodificado = json_decode($linha, true);
            if (is_array($decodificado) && ($decodificado['id'] ?? null) === $id) {
                return $decodificado;
            }
        }

        return null;
    }
}
