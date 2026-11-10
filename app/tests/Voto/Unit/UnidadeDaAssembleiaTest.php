<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnidadeDaAssembleia::class)]
final class UnidadeDaAssembleiaTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function situacoes(): iterable
    {
        yield 'sem situação' => [null, true];
        yield 'vazia' => ['  ', true];
        yield 'apto' => ['apto', true];
        yield 'Apta com caixa' => ['Apta', true];
        yield 'ADIMPLENTE' => ['ADIMPLENTE', true];
        yield 'regular' => [' regular ', true];
        yield 'quite' => ['Quite', true];
        yield 'inadimplente' => ['inadimplente', false];
        yield 'em débito' => ['Em débito', false];
        yield 'inapto' => ['inapto', false];
    }

    #[DataProvider('situacoes')]
    #[TestDox('Situação "$situacao" deixa votar: $apta')]
    public function testSituacaoDefineAptidao(?string $situacao, bool $apta): void
    {
        $unidade = new UnidadeDaAssembleia($this->assembleia(PesoDoVoto::Unidade), '101', str_repeat('a', 32), str_repeat('b', 64), situacao: $situacao);

        self::assertSame($apta, $unidade->isApta());
        self::assertSame($apta, UnidadeDaAssembleia::situacaoPermiteVotar($situacao));
    }

    #[TestDox('Situação com acento é comparada sem acento')]
    public function testSituacaoSemAcento(): void
    {
        self::assertTrue(UnidadeDaAssembleia::situacaoPermiteVotar('Ápto'));
    }

    #[TestDox('Herda o escritório da assembleia e entra na lista dela; bloco padrão vazio')]
    public function testHerdaEscritorio(): void
    {
        $assembleia = $this->assembleia(PesoDoVoto::Unidade);
        $unidade = new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));

        self::assertSame($assembleia->getTenant(), $unidade->getTenant());
        self::assertSame('', $unidade->getBloco());
        self::assertTrue($assembleia->getUnidades()->contains($unidade));
    }

    #[TestDox('Gerar novo link troca semente e hash e volta a "ainda não recebeu"')]
    public function testTrocarSemente(): void
    {
        $unidade = new UnidadeDaAssembleia($this->assembleia(PesoDoVoto::Unidade), '101', str_repeat('a', 32), str_repeat('b', 64));
        $unidade->registrarEnvioDoLink(new \DateTimeImmutable('2026-11-09 20:00'));
        self::assertNotNull($unidade->getLinkEnviadoEm());

        $unidade->trocarSemente(str_repeat('c', 32), str_repeat('d', 64));

        self::assertSame(str_repeat('c', 32), $unidade->getSemente());
        self::assertSame(str_repeat('d', 64), $unidade->getTokenHash());
        self::assertNull($unidade->getLinkEnviadoEm());
    }

    #[TestDox('Peso do voto: a fração quando o peso é a fração ideal; 1 quando é uma unidade, um voto')]
    public function testPesoDoVoto(): void
    {
        $porFracao = new UnidadeDaAssembleia($this->assembleia(PesoDoVoto::Fracao), '101', str_repeat('a', 32), str_repeat('b', 64), fracao: '0.0250');
        $semFracao = new UnidadeDaAssembleia($this->assembleia(PesoDoVoto::Fracao), '102', str_repeat('a', 32), str_repeat('e', 64));
        $porUnidade = new UnidadeDaAssembleia($this->assembleia(PesoDoVoto::Unidade), '103', str_repeat('a', 32), str_repeat('f', 64), fracao: '0.0250');

        self::assertSame('0.025000', $porFracao->pesoDoVoto());
        self::assertSame('0.000000', $semFracao->pesoDoVoto());
        self::assertSame('1.000000', $porUnidade->pesoDoVoto());
    }

    private function assembleia(PesoDoVoto $peso): Assembleia
    {
        return new Assembleia(new Tenant(), 'AGO', $peso, 'Gestora');
    }
}
