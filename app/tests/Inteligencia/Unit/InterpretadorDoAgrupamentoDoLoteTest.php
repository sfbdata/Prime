<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\DTO\AgrupamentoDoLoteOutput;
use App\Inteligencia\DTO\ArvoreDoLote;
use App\Inteligencia\Exception\RespostaInvalidaException;
use App\Inteligencia\Service\InterpretadorDoAgrupamentoDoLote;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras de "pasta = caso" do agrupamento do lote (spec importar-pastas-em-lote, etapa 2), sobre esta árvore:
 *
 *   1 Clientes/                                    PASTA
 *   2 Clientes/JOAO/                               PASTA
 *   3 Clientes/JOAO/rg.pdf                         ARQUIVO
 *   4 Clientes/MARIA DA SILVA/                     PASTA
 *   5 Clientes/MARIA DA SILVA/Processo 1/          PASTA
 *   6 Clientes/MARIA DA SILVA/Processo 1/inicial.pdf
 *   7 Clientes/MARIA DA SILVA/procuracao.pdf
 *   8 Clientes/solto.pdf
 */
#[CoversClass(InterpretadorDoAgrupamentoDoLote::class)]
#[CoversClass(ArvoreDoLote::class)]
#[CoversClass(AgrupamentoDoLoteOutput::class)]
final class InterpretadorDoAgrupamentoDoLoteTest extends TestCase
{
    private InterpretadorDoAgrupamentoDoLote $interpretador;
    private ArvoreDoLote $arvore;

    protected function setUp(): void
    {
        $this->interpretador = new InterpretadorDoAgrupamentoDoLote();
        $this->arvore = ArvoreDoLote::montar([
            'Clientes/MARIA DA SILVA/procuracao.pdf',
            'Clientes/MARIA DA SILVA/Processo 1/inicial.pdf',
            'Clientes/JOAO/rg.pdf',
            'Clientes/solto.pdf',
        ], new MascaradorDeDadosPessoais());
    }

    /** @param list<array<string, mixed>> $casos */
    private function interpretar(array $casos, array $extra = []): AgrupamentoDoLoteOutput
    {
        return $this->interpretador->interpretar((string) json_encode(['casos' => $casos] + $extra), $this->arvore);
    }

    /** @return list<string> */
    private static function pastas(AgrupamentoDoLoteOutput $saida): array
    {
        return array_map(static fn (array $c): string => $c['pasta'], $saida->casos);
    }

    #[TestDox('a árvore numera pastas antes do conteúdo, em ordem estável')]
    public function testArvoreNumerada(): void
    {
        self::assertSame([
            '1 | PASTA | Clientes/',
            '2 | PASTA | Clientes/JOAO/',
            '3 | ARQUIVO | Clientes/JOAO/rg.pdf',
            '4 | PASTA | Clientes/MARIA DA SILVA/',
            '5 | PASTA | Clientes/MARIA DA SILVA/Processo 1/',
            '6 | ARQUIVO | Clientes/MARIA DA SILVA/Processo 1/inicial.pdf',
            '7 | ARQUIVO | Clientes/MARIA DA SILVA/procuracao.pdf',
            '8 | ARQUIVO | Clientes/solto.pdf',
        ], $this->arvore->linhas);
        self::assertFalse($this->arvore->truncado);
        self::assertSame(4, $this->arvore->totalDePastas);
    }

    #[TestDox('resposta boa: casos viram caminhos, mesmoClienteQue aponta a outra pasta e o arquivo fora de caso fica em soltos')]
    public function testRespostaBoa(): void
    {
        $saida = $this->interpretador->interpretar(
            "Segue:\n```json\n" . json_encode([
                'casos' => [
                    ['no' => 2, 'cliente' => 'JOÃO', 'mesmoClienteQue' => null, 'motivo' => 'tem RG'],
                    ['no' => 4, 'cliente' => ' MARIA DA SILVA ', 'mesmoClienteQue' => 2, 'motivo' => 'procuração — e inicial'],
                ],
                'soltos' => [8],
                'duvidas' => [['no' => 8, 'texto' => 'arquivo solto na raiz'], ['no' => 99, 'texto' => 'some']],
            ], JSON_UNESCAPED_UNICODE) . "\n```",
            $this->arvore,
        );

        self::assertSame([
            ['pasta' => 'Clientes/JOAO', 'cliente' => 'JOÃO', 'mesmoClienteQue' => null, 'motivo' => 'tem RG'],
            ['pasta' => 'Clientes/MARIA DA SILVA', 'cliente' => 'MARIA DA SILVA', 'mesmoClienteQue' => 'Clientes/JOAO', 'motivo' => 'procuração, e inicial'],
        ], $saida->casos);
        self::assertSame(['Clientes/solto.pdf'], $saida->soltos);
        self::assertSame([['caminho' => 'Clientes/solto.pdf', 'texto' => 'arquivo solto na raiz']], $saida->duvidas);
    }

    #[TestDox('nó dentro de outro escolhido: fica o de fora, venha antes ou depois')]
    public function testNoAninhado(): void
    {
        self::assertSame(['Clientes/MARIA DA SILVA'], self::pastas($this->interpretar([
            ['no' => 4, 'cliente' => 'MARIA'],
            ['no' => 5, 'cliente' => 'MARIA'],
        ])));
        self::assertSame(['Clientes/MARIA DA SILVA'], self::pastas($this->interpretar([
            ['no' => 5, 'cliente' => 'MARIA'],
            ['no' => 4, 'cliente' => 'MARIA'],
        ])));
        self::assertSame(['Clientes/MARIA DA SILVA'], self::pastas($this->interpretar([
            ['no' => 4, 'cliente' => 'MARIA'],
            ['no' => 4, 'cliente' => 'OUTRA'],
        ])));
    }

    #[TestDox('dois irmãos ficam dois casos; nunca viram um grupo só')]
    public function testIrmaosSeparados(): void
    {
        $saida = $this->interpretar([['no' => 2, 'cliente' => 'MARIA'], ['no' => 4, 'cliente' => 'MARIA']]);

        self::assertSame(['Clientes/JOAO', 'Clientes/MARIA DA SILVA'], self::pastas($saida));
    }

    #[TestDox('nó que é arquivo e índice inexistente saem; o arquivo vai para soltos')]
    public function testArquivoEIndiceInvalido(): void
    {
        $saida = $this->interpretar([
            ['no' => 3, 'cliente' => 'JOAO'],
            ['no' => 99, 'cliente' => 'X'],
            ['no' => 0, 'cliente' => 'X'],
            ['no' => 'abc', 'cliente' => 'X'],
            ['cliente' => 'SEM NO'],
            'nem objeto',
        ]);

        self::assertSame([], $saida->casos);
        self::assertSame(['Clientes/JOAO/rg.pdf', 'Clientes/MARIA DA SILVA/Processo 1/inicial.pdf', 'Clientes/MARIA DA SILVA/procuracao.pdf', 'Clientes/solto.pdf'], $saida->soltos);
    }

    #[TestDox('cliente vazio, não texto ou com mais de 255 caracteres sai')]
    public function testClienteInvalido(): void
    {
        $saida = $this->interpretar([
            ['no' => 2, 'cliente' => '   '],
            ['no' => 4, 'cliente' => str_repeat('A', 256)],
            ['no' => 1, 'cliente' => ['X']],
        ]);

        self::assertSame([], $saida->casos);
    }

    #[TestDox('mesmoClienteQue que não é caso (arquivo, pasta não escolhida, ele mesmo, inexistente) vira null')]
    public function testMesmoClienteInvalido(): void
    {
        $saida = $this->interpretar([
            ['no' => 2, 'cliente' => 'JOAO', 'mesmoClienteQue' => 3],
            ['no' => 4, 'cliente' => 'MARIA', 'mesmoClienteQue' => 4],
        ]);
        self::assertSame([null, null], array_column($saida->casos, 'mesmoClienteQue'));

        $saida = $this->interpretar([['no' => 2, 'cliente' => 'JOAO', 'mesmoClienteQue' => 5]]);
        self::assertNull($saida->casos[0]['mesmoClienteQue']);

        $saida = $this->interpretar([['no' => 2, 'cliente' => 'JOAO', 'mesmoClienteQue' => 999]]);
        self::assertNull($saida->casos[0]['mesmoClienteQue']);
    }

    #[TestDox('mesmoClienteQue apontando para um caso que saiu por estar dentro de outro vira null')]
    public function testMesmoClienteDeCasoQueSaiu(): void
    {
        $saida = $this->interpretar([
            ['no' => 5, 'cliente' => 'MARIA'],
            ['no' => 2, 'cliente' => 'JOAO', 'mesmoClienteQue' => 5],
            ['no' => 4, 'cliente' => 'MARIA'],
        ]);

        self::assertSame(['Clientes/JOAO', 'Clientes/MARIA DA SILVA'], self::pastas($saida));
        self::assertNull($saida->casos[0]['mesmoClienteQue']);
    }

    #[TestDox('JSON quebrado, sem casos ou com casos que não é lista: a resposta inteira é inválida')]
    public function testRespostaInvalida(): void
    {
        foreach (['nada aqui', '{"casos": [', '{"soltos": []}', '{"casos": {"a": 1}}', '{"casos": "x"}'] as $texto) {
            try {
                $this->interpretador->interpretar($texto, $this->arvore);
                self::fail('devia recusar: ' . $texto);
            } catch (RespostaInvalidaException $e) {
                self::assertSame('', $e->textoBruto, 'a resposta tem nomes: não vai junto na exceção');
            }
        }
    }

    #[TestDox('teto de 500 casos por resposta')]
    public function testTetoDeCasos(): void
    {
        $caminhos = [];
        for ($i = 1; $i <= 600; ++$i) {
            $caminhos[] = sprintf('C%04d/a.pdf', $i);
        }
        $arvore = ArvoreDoLote::montar($caminhos, new MascaradorDeDadosPessoais());
        $casos = [];
        foreach ($arvore->nos as $numero => $no) {
            if ($no['pasta']) {
                $casos[] = ['no' => $numero, 'cliente' => 'CLIENTE ' . $numero];
            }
        }

        $saida = $this->interpretador->interpretar((string) json_encode(['casos' => $casos]), $arvore);

        self::assertCount(InterpretadorDoAgrupamentoDoLote::TETO_DE_CASOS, $saida->casos);
        self::assertCount(100, $saida->soltos);
    }
}
