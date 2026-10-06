<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard as Catalogo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A lista fechada do menu ⋮: o que entra, o que é recusado e como o aceito é normalizado.
 * O lado que mais importa é o da RECUSA — nada de JSON arbitrário chega ao banco.
 */
#[CoversClass(Catalogo::class)]
final class CatalogoDePreferenciasDoDashboardTest extends TestCase
{
    #[TestDox('As chaves aceitas são exatamente as quatro do menu (sons, extras e zerar ficam de fora)')]
    public function testChavesFechadas(): void
    {
        self::assertSame(
            ['dashboard.densidade', 'dashboard.animacoes', 'dashboard.setas', 'dashboard.colunas_ocultas'],
            Catalogo::chaves(),
        );
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function valoresAceitos(): iterable
    {
        yield 'densidade normal'      => [Catalogo::DENSIDADE, 'confortavel'];
        yield 'densidade compacta'    => [Catalogo::DENSIDADE, 'compacta'];
        yield 'animações ligadas'     => [Catalogo::ANIMACOES, 'ligadas'];
        yield 'animações reduzidas'   => [Catalogo::ANIMACOES, 'reduzidas'];
        yield 'setas ligadas'         => [Catalogo::SETAS, 'ligadas'];
        yield 'setas desligadas'      => [Catalogo::SETAS, 'desligadas'];
        yield 'nenhuma coluna oculta' => [Catalogo::COLUNAS_OCULTAS, []];
        yield 'cargo oculto'          => [Catalogo::COLUNAS_OCULTAS, ['cargo']];
    }

    #[DataProvider('valoresAceitos')]
    #[TestDox('Valor da lista é aceito como veio')]
    public function testValorAceito(string $chave, mixed $valor): void
    {
        self::assertSame($valor, Catalogo::validar($chave, $valor));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function valoresRecusados(): iterable
    {
        yield 'chave fora da lista'               => ['dashboard.sons', true];
        yield 'chave inventada'                   => ['qualquer.coisa', 'x'];
        yield 'chave sem prefixo'                 => ['densidade', 'compacta'];
        yield 'densidade fora da lista'           => [Catalogo::DENSIDADE, 'gigante'];
        yield 'densidade em maiúsculas'           => [Catalogo::DENSIDADE, 'COMPACTA'];
        yield 'densidade não-string'              => [Catalogo::DENSIDADE, 1];
        yield 'densidade nula'                    => [Catalogo::DENSIDADE, null];
        yield 'animações booleano'                => [Catalogo::ANIMACOES, false];
        yield 'setas como lista'                  => [Catalogo::SETAS, ['ligadas']];
        yield 'colunas como string'               => [Catalogo::COLUNAS_OCULTAS, 'cargo'];
        yield 'colunas como objeto'               => [Catalogo::COLUNAS_OCULTAS, ['cargo' => true]];
        yield 'coluna desconhecida'               => [Catalogo::COLUNAS_OCULTAS, ['salario']];
        yield 'Colaborador é fixa'                => [Catalogo::COLUNAS_OCULTAS, ['advogado']];
        yield 'coluna não-string'                 => [Catalogo::COLUNAS_OCULTAS, [1]];
        yield 'coluna aninhada'                   => [Catalogo::COLUNAS_OCULTAS, [['cargo']]];
        yield 'todas as numéricas ocultas'        => [Catalogo::COLUNAS_OCULTAS, Catalogo::COLUNAS_NUMERICAS];
        yield 'todas as ocultáveis'               => [Catalogo::COLUNAS_OCULTAS, Catalogo::COLUNAS_OCULTAVEIS];
    }

    #[DataProvider('valoresRecusados')]
    #[TestDox('Recusa chave ou valor fora do catálogo')]
    public function testValorRecusado(string $chave, mixed $valor): void
    {
        $this->expectException(PreferenciaInvalidaException::class);

        Catalogo::validar($chave, $valor);
    }

    #[TestDox('Colunas ocultas saem sem repetição e na ordem da tabela (o mesmo conjunto vira o mesmo JSON)')]
    public function testColunasNormalizadas(): void
    {
        self::assertSame(
            ['cargo', 'metas_vencidas', 'pastas_criadas'],
            Catalogo::validar(Catalogo::COLUNAS_OCULTAS, ['pastas_criadas', 'cargo', 'metas_vencidas', 'cargo']),
        );
    }

    #[TestDox('Seis das sete numéricas ocultas é aceito: sobra uma visível')]
    public function testSobraUmaNumerica(): void
    {
        $seis = array_slice(Catalogo::COLUNAS_NUMERICAS, 0, 6);

        self::assertSame(array_merge(['cargo'], $seis), Catalogo::validar(Catalogo::COLUNAS_OCULTAS, array_merge($seis, ['cargo'])));
    }

    #[TestDox('O padrão de cada chave é a tela de antes do menu: compacta, animações e setas ligadas, nada oculto')]
    public function testPadroes(): void
    {
        self::assertSame('compacta', Catalogo::padrao(Catalogo::DENSIDADE));
        self::assertSame('ligadas', Catalogo::padrao(Catalogo::ANIMACOES));
        self::assertSame('ligadas', Catalogo::padrao(Catalogo::SETAS));
        self::assertSame([], Catalogo::padrao(Catalogo::COLUNAS_OCULTAS));
    }

    #[TestDox('Pedir o padrão de chave desconhecida é recusado')]
    public function testPadraoDeChaveDesconhecida(): void
    {
        $this->expectException(PreferenciaInvalidaException::class);

        Catalogo::padrao('dashboard.sons');
    }
}
