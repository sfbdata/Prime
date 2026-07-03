<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Processo\Exception\TribunalNaoIdentificadoException;
use App\Processo\Service\TribunalCnjResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(TribunalCnjResolver::class)]
final class TribunalCnjResolverTest extends TestCase
{
    /**
     * Monta um número CNJ de 20 dígitos com o segmento (J) e o tribunal (TR) desejados:
     * NNNNNNN(7) DD(2) AAAA(4) J(1) TR(2) OOOO(4).
     */
    private static function numeroCnj(string $j, string $tr): string
    {
        return '0000000' . '00' . '2020' . $j . $tr . '0000';
    }

    #[DataProvider('siglasProvider')]
    #[TestDox('Deriva a sigla $sigla do número (J=$j, TR=$tr)')]
    public function testResolverSigla(string $j, string $tr, string $sigla): void
    {
        $resolver = new TribunalCnjResolver();

        self::assertSame($sigla, $resolver->resolverSigla(self::numeroCnj($j, $tr)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function siglasProvider(): iterable
    {
        yield 'STF (superior, TR=00)'          => ['1', '00', 'STF'];
        yield 'STJ (superior, TR=00)'          => ['3', '00', 'STJ'];
        yield 'TRF1 (federal)'                 => ['4', '01', 'TRF1'];
        yield 'TRF6 (federal)'                 => ['4', '06', 'TRF6'];
        yield 'TST (trabalho, TR=00)'          => ['5', '00', 'TST'];
        yield 'TRT2 (trabalho)'                => ['5', '02', 'TRT2'];
        yield 'TRT24 (trabalho, dois dígitos)' => ['5', '24', 'TRT24'];
        yield 'TSE (eleitoral, TR=00)'         => ['6', '00', 'TSE'];
        yield 'TRE-AC (eleitoral)'             => ['6', '01', 'TRE-AC'];
        yield 'TRE-DF (eleitoral DF)'          => ['6', '07', 'TRE-DF'];
        yield 'TRE-SP (eleitoral)'             => ['6', '26', 'TRE-SP'];
        yield 'STM (militar união, TR=00)'     => ['7', '00', 'STM'];
        yield '1CJM (militar união)'           => ['7', '01', '1CJM'];
        yield 'TJDFT (estadual DF)'            => ['8', '07', 'TJDFT'];
        yield 'TJSP (estadual)'                => ['8', '26', 'TJSP'];
        yield 'TJTO (estadual)'                => ['8', '27', 'TJTO'];
        yield 'TJMMG (militar estadual, J=9)'  => ['9', '13', 'TJMMG'];
        yield 'TJMSP (militar estadual, J=9)'  => ['9', '26', 'TJMSP'];
    }

    #[TestDox('Número mascarado (com pontos e hífen) resolve igual ao número puro')]
    public function testNumeroMascaradoResolveIgualAoNumeroPuro(): void
    {
        $resolver = new TribunalCnjResolver();

        // Mesmo processo (J=8, TR=02 → TJAL) nas duas formas.
        self::assertSame('TJAL', $resolver->resolverSigla('0710802-55.2018.8.02.0001'));
        self::assertSame('TJAL', $resolver->resolverSigla('07108025520188020001'));
    }

    #[DataProvider('aliasesProvider')]
    #[TestDox('Deriva o alias Datajud $alias do número (J=$j, TR=$tr)')]
    public function testResolverAlias(string $j, string $tr, string $alias): void
    {
        $resolver = new TribunalCnjResolver();

        self::assertSame($alias, $resolver->resolverAlias(self::numeroCnj($j, $tr)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function aliasesProvider(): iterable
    {
        yield 'TJSP → tjsp'     => ['8', '26', 'tjsp'];
        yield 'TRF1 → trf1'     => ['4', '01', 'trf1'];
        yield 'TRE-AC → tre-ac' => ['6', '01', 'tre-ac'];
        yield 'TRE-DF → tre-df' => ['6', '07', 'tre-df'];
        yield 'TJDFT → tjdft'   => ['8', '07', 'tjdft'];
    }

    #[DataProvider('invalidosProvider')]
    #[TestDox('Número inválido "$numero" lança TribunalNaoIdentificadoException')]
    public function testNumeroInvalidoLancaExcecao(string $numero): void
    {
        $resolver = new TribunalCnjResolver();

        $this->expectException(TribunalNaoIdentificadoException::class);
        $resolver->resolverSigla($numero);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidosProvider(): iterable
    {
        yield 'vazio'                    => [''];
        yield '19 dígitos (curto)'       => ['0000000002020826000'];
        yield '21 dígitos (longo)'       => ['000000000202082600000'];
        yield 'não numérico'             => ['processo-sem-numero'];
        yield 'segmento fora do mapa (0)'      => [self::numeroCnj('0', '01')];
        yield 'TR fora do segmento 9'          => [self::numeroCnj('9', '01')];
        yield 'TR fora do segmento 1 (só 00)'  => [self::numeroCnj('1', '05')];
        yield 'TR 91 inexistente (federal)'    => [self::numeroCnj('4', '91')];
    }

    #[TestDox('resolverAlias também lança para número inválido')]
    public function testResolverAliasLancaParaInvalido(): void
    {
        $resolver = new TribunalCnjResolver();

        $this->expectException(TribunalNaoIdentificadoException::class);
        $resolver->resolverAlias('123');
    }
}
