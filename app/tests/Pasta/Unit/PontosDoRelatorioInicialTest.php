<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Service\PontosDoRelatorioInicial;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A heurística `relInicial()` do desenho (VS 18.10.26, dc L.5486-5490) em PHP: vazio, parcial e
 * completo, o selo do card e a pendência da aba Detalhes (dc L.5504), mais as armadilhas do
 * texto que chega do editor rico (HTML, entidades, acento, @menção).
 */
#[CoversClass(PontosDoRelatorioInicial::class)]
final class PontosDoRelatorioInicialTest extends TestCase
{
    #[TestDox('sem observação: vazio, os três faltam, selo "3 pendentes" e a pendência "ainda não preenchido"')]
    public function testSemObservacao(): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos([]);

        self::assertTrue($pontos->vazio);
        self::assertFalse($pontos->completo());
        self::assertCount(3, $pontos->faltam());
        self::assertSame('3 pendentes', $pontos->selo());
        self::assertSame(
            ['n' => 3, 'txt' => 'relatório inicial de atendimento ainda não preenchido'],
            $pontos->pendenciaDaAba(),
        );
    }

    #[TestDox('observação só com marcação (sem texto) conta como vazia')]
    public function testSoMarcacaoEhVazio(): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos(['<p><br></p>', '<p>&nbsp;</p>']);

        self::assertTrue($pontos->vazio);
        self::assertSame('relatório inicial de atendimento ainda não preenchido', $pontos->pendenciaDaAba()['txt'] ?? null);
    }

    #[TestDox('texto sem nenhum ponto: não é vazio, e a pendência lista os três com vírgula e "e"')]
    public function testTextoSemPontos(): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos(['<p>Cliente compareceu ao escritório com documentos.</p>']);

        self::assertFalse($pontos->vazio);
        self::assertSame(
            [
                'n'   => 3,
                'txt' => 'relatório inicial sem objetivos do cliente, acordos iniciais e resultado esperado pelo cliente',
            ],
            $pontos->pendenciaDaAba(),
        );
    }

    #[TestDox('parcial: objetivo registrado, faltam acordos e resultado ("X e Y", selo "2 pendentes")')]
    public function testParcial(): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos(['<p>O cliente <strong>pretende</strong> revisar o contrato.</p>']);

        self::assertSame([true, false, false], array_column($pontos->itens, 'ok'));
        self::assertSame('Registrado no relatório', $pontos->itens[0]['dica']);
        self::assertSame('O que foi combinado no atendimento', $pontos->itens[1]['dica']);
        self::assertSame('O que o cliente espera ao final', $pontos->itens[2]['dica']);
        self::assertSame('2 pendentes', $pontos->selo());
        self::assertSame(
            ['n' => 2, 'txt' => 'relatório inicial sem acordos iniciais e resultado esperado pelo cliente'],
            $pontos->pendenciaDaAba(),
        );
    }

    #[TestDox('falta um só: selo no singular e a pendência sem "e"')]
    public function testFaltaUm(): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos([
            'Objetivo: indenização.',
            'Ficou acertado o envio dos documentos.',
        ]);

        self::assertSame('1 pendente', $pontos->selo());
        self::assertSame(['n' => 1, 'txt' => 'relatório inicial sem resultado esperado pelo cliente'], $pontos->pendenciaDaAba());
    }

    #[TestDox('completo: os três pontos, espalhados em observações diferentes; sem pendência e selo "Completo"')]
    public function testCompleto(): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos([
            '<p>Objetivos do cliente: rescisão indireta.</p>',
            '<p>Acordos iniciais: honorários de 30%.</p>',
            '<p>Expectativa de acordo na primeira audiência.</p>',
        ]);

        self::assertTrue($pontos->completo());
        self::assertSame([], $pontos->faltam());
        self::assertSame('Completo', $pontos->selo());
        self::assertNull($pontos->pendenciaDaAba());
        self::assertSame(['objetivos', 'acordos', 'resultado'], array_column($pontos->itens, 'k'));
        self::assertSame(
            ['Objetivos do cliente', 'Acordos iniciais', 'Resultado esperado pelo cliente'],
            array_column($pontos->itens, 'rot'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function gatilhos(): iterable
    {
        // Um gatilho de cada padrão do desenho (dc L.5489), já com acento e caixa alta.
        yield 'objetivos: "pretensão" com acento' => ['A PRETENSÃO é anular a multa.', 'objetivos'];
        yield 'objetivos: "busca " com espaço' => ['Ele busca reparação.', 'objetivos'];
        yield 'objetivos: "ajuizamento de"' => ['Ajuizamento de ação revisional.', 'objetivos'];
        yield 'acordos: "combinado"' => ['Foi combinado o pagamento em 3 vezes.', 'acordos'];
        yield 'acordos: "definiu-se"' => ['Definiu-se a estratégia.', 'acordos'];
        yield 'acordos: "orientado"' => ['Cliente orientado a juntar extratos.', 'acordos'];
        yield 'resultado: "êxito pretendido"' => ['Êxito pretendido: reintegração.', 'resultado'];
        yield 'resultado: "espera-se"' => ['Espera-se a procedência.', 'resultado'];
        yield 'resultado: "almeja"' => ['Almeja a guarda compartilhada.', 'resultado'];
    }

    #[DataProvider('gatilhos')]
    #[TestDox('o padrão $chave casa "$texto", sem depender de acento nem de caixa')]
    public function testGatilhos(string $texto, string $chave): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos([$texto]);

        $ok = array_column($pontos->itens, 'ok', 'k');
        self::assertTrue($ok[$chave]);
        self::assertSame(1, count(array_filter($ok)), 'só o ponto do gatilho acende');
    }

    #[TestDox('a marcação some sem comer o espaço: "busca <em>reparação</em>" casa; quebra de parágrafo não é espaço, como no desenho')]
    public function testMarcacaoESeparadores(): void
    {
        self::assertTrue(PontosDoRelatorioInicial::dosTextos(['<p>Ele busca <em>reparação</em>.</p>'])->itens[0]['ok']);
        // dc L.5487: os parágrafos se juntam com "\n" e o padrão é "busca " (com espaço).
        self::assertFalse(PontosDoRelatorioInicial::dosTextos(['<p>O que ele busca</p><p>Indenização</p>'])->itens[0]['ok']);
    }

    #[TestDox('"buscando" não casa "busca " (o espaço do padrão do desenho), e entidade HTML é decodificada')]
    public function testBuscandoNaoCasaEEntidadeDecodifica(): void
    {
        self::assertFalse(PontosDoRelatorioInicial::dosTextos(['Está buscando documentos.'])->itens[0]['ok']);
        self::assertTrue(PontosDoRelatorioInicial::dosTextos(['Meta: &ecirc;xito pretendido'])->itens[2]['ok']);
    }

    #[TestDox('a @menção vira "@Nome" e o id do usuário não entra na conta')]
    public function testMencao(): void
    {
        $pontos = PontosDoRelatorioInicial::dosTextos(['Falar com @[Ana Objetivo](user:12) amanhã.']);

        self::assertFalse($pontos->vazio);
        // O nome mencionado é texto da tela: "Objetivo" no nome casa, como no desenho.
        self::assertTrue($pontos->itens[0]['ok']);
        self::assertFalse(PontosDoRelatorioInicial::dosTextos(['@[Bia](user:7)'])->itens[0]['ok']);
    }

    #[TestDox('dasObservacoes junta o conteúdo de todas as observações, respostas inclusive')]
    public function testDasObservacoes(): void
    {
        $a = (new PastaObservacaoDetalhes())->setConteudo('<p>O cliente deseja o divórcio.</p>');
        $b = (new PastaObservacaoDetalhes())->setConteudo('<p>Resultado: partilha justa.</p>');

        $pontos = PontosDoRelatorioInicial::dasObservacoes([$a, $b]);

        self::assertSame([true, false, true], array_column($pontos->itens, 'ok'));
    }
}
