<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Service\SituacaoProcessoRotulo;
use App\Twig\SituacaoProcessoExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A situação do processo é gravada como código ("EM_ANDAMENTO") e a tela da pasta imprimia o código.
 * O desenho (02 - EXPEDIENTES 1.2.3, dc de 13/10) mostra "Em andamento".
 *
 * Mapeamento EXPLÍCITO dos quatro valores que o cadastro oferece (os selects de processo/new e do
 * modal de vínculo na pasta). Valor fora da lista volta EXATAMENTE como veio: o Datajud grava texto
 * livre, e "humanizar" um código que não conhecemos seria inventar rótulo. É apresentação — o valor
 * gravado não muda.
 */
#[CoversClass(SituacaoProcessoRotulo::class)]
#[CoversClass(SituacaoProcessoExtension::class)]
final class SituacaoProcessoRotuloTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function conhecidos(): iterable
    {
        yield 'em andamento' => ['EM_ANDAMENTO', 'Em andamento'];
        yield 'encerrado' => ['ENCERRADO', 'Encerrado'];
        yield 'suspenso' => ['SUSPENSO', 'Suspenso'];
        yield 'paralisado' => ['PARALISADO', 'Paralisado'];
    }

    #[DataProvider('conhecidos')]
    #[TestDox('$codigo vira "$rotulo"')]
    public function testValoresConhecidos(string $codigo, string $rotulo): void
    {
        self::assertSame($rotulo, SituacaoProcessoRotulo::de($codigo));
    }

    /** @return iterable<string, array{string}> */
    public static function desconhecidos(): iterable
    {
        yield 'texto livre do Datajud' => ['EM GRAU DE RECURSO'];
        yield 'código que não está na lista' => ['BAIXADO_DEFINITIVAMENTE'];
        yield 'minúsculas (não é como o setter grava)' => ['em_andamento'];
        yield 'vazio' => [''];
    }

    #[DataProvider('desconhecidos')]
    #[TestDox('valor fora da lista volta exatamente como veio: "$valor"')]
    public function testDesconhecidoVoltaComoVeio(string $valor): void
    {
        self::assertSame($valor, SituacaoProcessoRotulo::de($valor));
    }

    #[TestDox('o filtro Twig `situacao_processo` usa o mesmo mapeamento (tela e PDF não divergem)')]
    public function testFiltroTwigDelegaAoMapeamento(): void
    {
        $filtros = (new SituacaoProcessoExtension())->getFilters();
        self::assertCount(1, $filtros);
        self::assertSame('situacao_processo', $filtros[0]->getName());

        $callable = $filtros[0]->getCallable();
        self::assertSame('Em andamento', $callable('EM_ANDAMENTO'));
        self::assertSame('EM GRAU DE RECURSO', $callable('EM GRAU DE RECURSO'));
        self::assertSame('', $callable(null));
    }
}
