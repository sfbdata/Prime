<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use App\Ponto\Service\FeriadosDaPessoa;
use App\Ponto\Service\HistoricoDeLotacoes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra da abrangência do feriado (spec `docs/specs/ponto-desenho-0811.md`, E6, P71 com o desvio da E6 e a lotação
 * com vigência): sem lotação, vale para todos; com lotação, só para quem tinha aquela lotação no escritório DO FERIADO
 * no dia. Sem N+1: sem feriado por lotação, nenhuma consulta; com ele, uma por pessoa e escritório até o `reset`.
 */
#[CoversClass(FeriadosDaPessoa::class)]
#[Group('ponto')]
final class FeriadosDaPessoaTest extends TestCase
{
    private const DIA = '2026-09-16';

    private Tenant $x;
    private Tenant $y;
    private Lotacao $maceio;
    private Lotacao $brasilia;
    private Lotacao $deY;

    protected function setUp(): void
    {
        $this->x = $this->comId(new Tenant(), 1);
        $this->y = $this->comId(new Tenant(), 2);
        $this->maceio = $this->lotacao(11, 'MACEIÓ/AL', $this->x);
        $this->brasilia = $this->lotacao(12, 'BRASÍLIA/DF', $this->x);
        $this->deY = $this->lotacao(21, 'RECIFE/PE', $this->y);
    }

    #[TestDox('"Todas as sedes" vale para todos, até sem pessoa, e não consulta o histórico')]
    public function testSemLotacaoValeParaTodos(): void
    {
        $lotacoes = $this->createMock(LotacaoComVigenciaRepository::class);
        $lotacoes->expects(self::never())->method('historicoDoVinculo');
        $regra = new FeriadosDaPessoa($lotacoes);
        $todas = $this->feriado('Aniversário da cidade', $this->x, null);

        self::assertSame([$todas], $regra->filtrar([$todas], $this->pessoa(1), $this->dia()));
        self::assertTrue($regra->valePara($todas, null, $this->dia()));
    }

    #[TestDox('Com lotação, vale só para quem tem aquela lotação no escritório do feriado')]
    public function testComLotacaoValeSoParaQuemTemALotacao(): void
    {
        $ana = $this->pessoa(1);
        $bruno = $this->pessoa(2);
        $semLotacao = $this->pessoa(3);
        $regra = new FeriadosDaPessoa($this->historicos([
            '1:1' => HistoricoDeLotacoes::doVinculo(11, []),
            '2:1' => HistoricoDeLotacoes::doVinculo(12, []),
            '3:1' => HistoricoDeLotacoes::doVinculo(null, []),
        ]));
        $deMaceio = $this->feriado('Emancipação de Alagoas', $this->x, $this->maceio);
        $todas = $this->feriado('Padroeira', $this->x, null);

        self::assertSame([$deMaceio, $todas], $regra->filtrar([$deMaceio, $todas], $ana, $this->dia()));
        self::assertSame([$todas], $regra->filtrar([$deMaceio, $todas], $bruno, $this->dia()));
        self::assertSame([$todas], $regra->filtrar([$deMaceio, $todas], $semLotacao, $this->dia()));
        self::assertFalse($regra->valePara($deMaceio, null, $this->dia()), 'sem pessoa, o feriado de uma lotação não vale');
    }

    #[TestDox('Lotação com vigência: o feriado de Maceió vale para a Ana até a véspera da troca; da troca em diante, o de Brasília')]
    public function testLotacaoNoDiaDoFeriado(): void
    {
        $ana = $this->pessoa(1);
        $regra = new FeriadosDaPessoa($this->historicos([
            '1:1' => HistoricoDeLotacoes::doVinculo(12, [['desde' => null, 'lotacao' => 11], ['desde' => self::DIA, 'lotacao' => 12]]),
        ]));
        $deMaceio = $this->feriado('Emancipação de Alagoas', $this->x, $this->maceio);
        $deBrasilia = $this->feriado('Aniversário de Brasília', $this->x, $this->brasilia);
        $vespera = new \DateTimeImmutable('2026-09-15');

        self::assertTrue($regra->valePara($deMaceio, $ana, $vespera));
        self::assertFalse($regra->valePara($deBrasilia, $ana, $vespera));
        self::assertFalse($regra->valePara($deMaceio, $ana, $this->dia()));
        self::assertTrue($regra->valePara($deBrasilia, $ana, $this->dia()));
        self::assertTrue($regra->valePara($deMaceio, $ana, new \DateTimeImmutable('2000-01-01')), 'a inicial vale para trás');
    }

    #[TestDox('Feriado de outro escritório é julgado pela lotação da pessoa NAQUELE escritório')]
    public function testFeriadoDeOutroEscritorio(): void
    {
        $ana = $this->pessoa(1);
        $regra = new FeriadosDaPessoa($this->historicos(['1:1' => HistoricoDeLotacoes::doVinculo(11, [])]));   // em Y, sem vínculo
        $deY = $this->feriado('Data magna de Pernambuco', $this->y, $this->deY);

        self::assertSame([], $regra->filtrar([$deY], $ana, $this->dia()));

        $comVinculoEmY = new FeriadosDaPessoa($this->historicos(['1:1' => HistoricoDeLotacoes::doVinculo(11, []), '1:2' => HistoricoDeLotacoes::doVinculo(21, [])]));
        self::assertSame([$deY], $comVinculoEmY->filtrar([$deY], $ana, $this->dia()));
    }

    #[TestDox('Uma consulta por pessoa e escritório, guardada até o reset (a folha do ano não volta ao banco a cada dia)')]
    public function testUmaConsultaPorPessoaEEscritorio(): void
    {
        $ana = $this->pessoa(1);
        $lotacoes = $this->createMock(LotacaoComVigenciaRepository::class);
        $lotacoes->expects(self::exactly(2))->method('historicoDoVinculo')->willReturn(HistoricoDeLotacoes::doVinculo(11, []));
        $regra = new FeriadosDaPessoa($lotacoes);
        $feriados = [
            $this->feriado('Um', $this->x, $this->maceio),
            $this->feriado('Dois', $this->x, $this->brasilia),
            $this->feriado('Três', $this->x, $this->maceio),
        ];

        for ($dia = new \DateTimeImmutable('2026-01-01'); $dia <= new \DateTimeImmutable('2026-12-31'); $dia = $dia->modify('+1 day')) {
            self::assertCount(2, $regra->filtrar($feriados, $ana, $dia));
        }
        $regra->reset();
        self::assertCount(2, $regra->filtrar($feriados, $ana, $this->dia()), 'depois do reset, consulta de novo (a lotação pode ter mudado)');
    }

    // ----------------------------------------------------------------- apoio

    private function dia(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::DIA);
    }

    /** @param array<string, HistoricoDeLotacoes> $historicos "pessoa:escritório" => histórico; chave ausente = sem vínculo */
    private function historicos(array $historicos): LotacaoComVigenciaRepository
    {
        $lotacoes = $this->createStub(LotacaoComVigenciaRepository::class);
        $lotacoes->method('historicoDoVinculo')->willReturnCallback(
            static fn (User $pessoa, Tenant $escritorio): HistoricoDeLotacoes => $historicos[$pessoa->getId() . ':' . $escritorio->getId()] ?? HistoricoDeLotacoes::semVinculo(),
        );

        return $lotacoes;
    }

    private function feriado(string $nome, Tenant $escritorio, ?Lotacao $lotacao): Feriado
    {
        return (new Feriado())->setNome($nome)->setData(new \DateTime(self::DIA))->setTenant($escritorio)->setLotacao($lotacao);
    }

    private function lotacao(int $id, string $nome, Tenant $escritorio): Lotacao
    {
        return $this->comId((new Lotacao())->setNome($nome)->setTenant($escritorio), $id);
    }

    private function pessoa(int $id): User
    {
        return $this->comId((new User())->setEmail('p' . $id . '@teste.com')->setFullName('Pessoa ' . $id), $id);
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
