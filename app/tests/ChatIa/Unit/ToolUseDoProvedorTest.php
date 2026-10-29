<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\Inteligencia\DTO\FerramentaDeLinguagem;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Service\LeitorDeFluxoDaAnthropic;
use App\Inteligencia\Service\ProvedorAnthropic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Tool use no provedor: as ferramentas vão no corpo e o `tool_use` do fluxo sai como chamada completa. Nenhuma chamada real. */
#[CoversClass(LeitorDeFluxoDaAnthropic::class)]
#[CoversClass(ProvedorAnthropic::class)]
final class ToolUseDoProvedorTest extends TestCase
{
    private static function evento(array $dados): string
    {
        return 'event: ' . $dados['type'] . "\ndata: " . json_encode($dados, JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /** @param list<string> $pedacosDoJson */
    private static function fluxoComChamada(array $pedacosDoJson, string $nome = 'propor_cliente', int $indice = 1): string
    {
        $sse = self::evento(['type' => 'message_start', 'message' => ['model' => 'm', 'usage' => ['input_tokens' => 10]]])
            . self::evento(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
            . self::evento(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Vou propor.']])
            . self::evento(['type' => 'content_block_stop', 'index' => 0])
            . self::evento(['type' => 'content_block_start', 'index' => $indice, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $nome, 'input' => []]]);
        foreach ($pedacosDoJson as $pedaco) {
            $sse .= self::evento(['type' => 'content_block_delta', 'index' => $indice, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $pedaco]]);
        }

        return $sse
            . self::evento(['type' => 'content_block_stop', 'index' => $indice])
            . self::evento(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 20]])
            . self::evento(['type' => 'message_stop']);
    }

    #[TestDox('O leitor junta os input_json_delta em qualquer fatiamento e expõe a chamada com a entrada decodificada')]
    public function testJuntaOJsonParcial(): void
    {
        $sse = self::fluxoComChamada(['{"tipo":"pf","cam', 'pos":{"nome":"Maria"', ',"cpf":"529.982.247-25"}}']);

        foreach ([1, 7, 50, \strlen($sse)] as $tamanho) {
            $leitor = new LeitorDeFluxoDaAnthropic();
            $trechos = [];
            foreach (str_split($sse, $tamanho) as $pedaco) {
                $trechos = [...$trechos, ...$leitor->alimentar($pedaco)];
            }

            self::assertSame(['Vou propor.'], $trechos, "fatia $tamanho");
            self::assertTrue($leitor->terminou());
            self::assertCount(1, $leitor->chamadasDeFerramenta());
            self::assertSame('propor_cliente', $leitor->chamadasDeFerramenta()[0]->nome);
            self::assertSame(['tipo' => 'pf', 'campos' => ['nome' => 'Maria', 'cpf' => '529.982.247-25']], $leitor->chamadasDeFerramenta()[0]->entrada);
        }
    }

    #[TestDox('JSON inválido, que não é objeto ou grande demais: a chamada é descartada; o texto segue')]
    public function testJsonInvalidoDescarta(): void
    {
        foreach ([['{"tipo":"pf","campos":{'], ['[1,2,3]'], ['"texto solto"'], ['{"a":"' . str_repeat('x', 70_000) . '"}']] as $pedacos) {
            $leitor = new LeitorDeFluxoDaAnthropic();
            $trechos = $leitor->alimentar(self::fluxoComChamada($pedacos));

            self::assertSame(['Vou propor.'], $trechos);
            self::assertSame([], $leitor->chamadasDeFerramenta(), substr($pedacos[0], 0, 20));
        }
    }

    #[TestDox('Sem argumentos (nenhum delta): objeto vazio; bloco que não fechou não vira chamada')]
    public function testSemDeltaEBlocoAberto(): void
    {
        $leitor = new LeitorDeFluxoDaAnthropic();
        $leitor->alimentar(self::fluxoComChamada([]));
        self::assertCount(1, $leitor->chamadasDeFerramenta());
        self::assertSame([], $leitor->chamadasDeFerramenta()[0]->entrada);

        $aberto = new LeitorDeFluxoDaAnthropic();
        $aberto->alimentar(self::evento(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 't', 'name' => 'propor_pasta', 'input' => []]])
            . self::evento(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"nome_cliente":"A"}']]));
        self::assertSame([], $aberto->chamadasDeFerramenta());
    }

    #[TestDox('O provedor manda `tools` no corpo só quando há ferramentas e devolve as chamadas no uso da transmissão')]
    public function testProvedorEnviaFerramentasEDevolveChamadas(): void
    {
        $corpos = [];
        $http = new MockHttpClient(function (string $metodo, string $url, array $opcoes) use (&$corpos): MockResponse {
            $corpos[] = json_decode($opcoes['body'], true, flags: JSON_THROW_ON_ERROR);

            return new MockResponse(self::fluxoComChamada(['{"tipo":"pj","campos":{"razao_social":"Acme"}}']), ['http_code' => 200, 'response_headers' => ['content-type' => 'text/event-stream']]);
        });
        $provedor = new ProvedorAnthropic($http, 'sk-ant-chave-123', 'modelo-da-plataforma', 'https://api.exemplo.test');
        $ferramenta = new FerramentaDeLinguagem('propor_cliente', 'Propõe um cliente.', ['type' => 'object', 'properties' => ['tipo' => ['type' => 'string']]]);

        $semFerramenta = $provedor->transmitir(new PedidoDeLinguagem('S.', [['papel' => 'usuario', 'conteudo' => 'oi']], 500, 0.2, false, 'chat'));
        iterator_to_array($semFerramenta, false);
        $comFerramenta = $provedor->transmitir(new PedidoDeLinguagem('S.', [['papel' => 'usuario', 'conteudo' => 'oi']], 500, 0.2, false, 'chat', [], null, [$ferramenta]));
        iterator_to_array($comFerramenta, false);
        $uso = $comFerramenta->getReturn();

        self::assertArrayNotHasKey('tools', $corpos[0]);
        self::assertSame([['name' => 'propor_cliente', 'description' => 'Propõe um cliente.', 'input_schema' => ['type' => 'object', 'properties' => ['tipo' => ['type' => 'string']]]]], $corpos[1]['tools']);
        self::assertCount(1, $uso->chamadasDeFerramenta);
        self::assertSame('propor_cliente', $uso->chamadasDeFerramenta[0]->nome);
        self::assertSame(['tipo' => 'pj', 'campos' => ['razao_social' => 'Acme']], $uso->chamadasDeFerramenta[0]->entrada);
    }
}
