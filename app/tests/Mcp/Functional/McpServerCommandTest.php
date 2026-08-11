<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Functional;

use App\Tests\Mcp\BancoDeLeituraDeTeste;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Roda o comando como PROCESSO DE VERDADE. É a única forma de provar que o stdout carrega
 * exclusivamente JSON-RPC: um CommandTester não enxergaria um `echo` perdido nem um handler
 * de log escrevendo na saída padrão.
 */
final class McpServerCommandTest extends TestCase
{
    // `new` em inicializador de CONSTANTE DE CLASSE não é aceito em nenhuma versão do PHP: a
    // RFC "New in Initializers" (PHP 8.1) liberou `new` em default de parâmetro, argumento de
    // atributo, variável estática e constante GLOBAL, e deixou de fora constante de classe e
    // default de propriedade DE PROPÓSITO (ordem de avaliação) — nenhuma RFC posterior mudou
    // isso. A mensagem "New expressions are not supported in this context" é contextual, não
    // versional; não adianta esperar um upgrade. Por isso é método, não `const`, apesar de o
    // brief pedir `const`.
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
        $this->assertStdoutSoTemJsonRpc($this->rodarServidor(self::handshake()));
    }

    public function testRespondeAoInitializeComONomeDoServidor(): void
    {
        $processo = $this->rodarServidor(self::handshake());

        $resposta = $this->respostaComId($processo->getOutput(), 1);

        self::assertNotNull($resposta, 'nenhuma resposta para o initialize (id 1)');
        self::assertSame('JusPrime (leitura)', $resposta['result']['serverInfo']['name']);
    }

    public function testAnunciaAsDuasFerramentas(): void
    {
        $mensagens = self::handshake();
        $processo = $this->rodarServidor($mensagens);

        $resposta = $this->respostaComId($processo->getOutput(), 2);

        self::assertNotNull($resposta, 'nenhuma resposta para tools/list (id 2)');

        $nomes = array_column($resposta['result']['tools'], 'name');
        sort($nomes);

        self::assertSame(['consultar_sql', 'descrever_esquema'], $nomes);
    }

    public function testChamarConsultarSqlDevolveResultado(): void
    {
        $mensagens = self::handshake();
        $mensagens[] = [
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'consultar_sql',
                'arguments' => ['sql' => 'SELECT 42 AS resposta'],
            ],
        ];

        $processo = $this->rodarServidor($mensagens);

        // `ConsultarSqlTool` loga (`info` no sucesso) — é justamente o caminho que o
        // handshake sozinho nunca exercita. Sem checar aqui, um `LoggerInterface` que
        // escrevesse no stdout (ex.: canal `console` do Monolog mal configurado) passaria
        // despercebido, porque `respostaComId()` pula em silêncio qualquer linha que não
        // seja JSON.
        $this->assertStdoutSoTemJsonRpc($processo);

        $resposta = $this->respostaComId($processo->getOutput(), 3);

        self::assertNotNull($resposta, 'nenhuma resposta para tools/call (id 3)');
        self::assertNotTrue($resposta['result']['isError'] ?? false, json_encode($resposta));
        self::assertStringContainsString('42', $resposta['result']['content'][0]['text']);
    }

    public function testSqlInvalidoViraErroDeFerramentaSemDerrubarOServidor(): void
    {
        $mensagens = self::handshake();
        $mensagens[] = [
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'consultar_sql',
                'arguments' => ['sql' => 'SELECT * FROM nao_existe_essa_tabela'],
            ],
        ];
        // Se o servidor tivesse morrido no erro acima, esta última mensagem ficaria sem resposta.
        $mensagens[] = ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping'];

        $processo = $this->rodarServidor($mensagens);

        // `ConsultarSqlTool` loga (`error` na falha) — mesmo motivo do comentário em
        // `testChamarConsultarSqlDevolveResultado`, agora no caminho de erro.
        $this->assertStdoutSoTemJsonRpc($processo);

        $erro = $this->respostaComId($processo->getOutput(), 3);
        self::assertNotNull($erro);
        self::assertTrue($erro['result']['isError'] ?? false, 'erro de SQL deveria virar isError');

        self::assertNotNull(
            $this->respostaComId($processo->getOutput(), 4),
            'o servidor morreu no erro de SQL em vez de seguir respondendo',
        );
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
                // `DATABASE_URL_LEITURA` (Task 2) só é configurada em prod — de propósito, não
                // vem do .env.test. Sem passá-la aqui, `consultar_sql`/`descrever_esquema`
                // falhariam sempre com "não está configurada", e os testes abaixo (que precisam
                // de um banco de verdade para provar execução ponta a ponta e o caminho de erro
                // de SQL) testariam esse erro fixo em vez do comportamento real. Aponta para o
                // banco de teste da frente com a ROLE RESTRITA: desde que `ConexaoLeitura`
                // recusa usuário com escrita, o DSN administrativo nem subiria aqui.
                'DATABASE_URL_LEITURA' => BancoDeLeituraDeTeste::dsnLeitura(),
            ],
        );
        $processo->setInput($entrada);    // fecha o STDIN ao terminar → o servidor encerra
        $processo->setTimeout(30);
        $processo->run();

        return $processo;
    }

    /**
     * Confirma que TODA linha do stdout decodifica como JSON-RPC — nem um byte solto de log
     * ou `echo`. Extraída para reuso porque o handshake sozinho não exercita nenhum log real:
     * é só chamando uma ferramenta (sucesso ou erro) que `ConsultarSqlTool` escreve no
     * `LoggerInterface`, e é exatamente esse caminho que precisa da checagem.
     */
    private function assertStdoutSoTemJsonRpc(Process $processo): void
    {
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
