<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Service\FeriadosDaPessoa;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra da abrangência do feriado (spec `docs/specs/ponto-desenho-0811.md`, E6, P71 com o desvio da E6): sem
 * lotação, vale para todos; com lotação, só para quem tem aquela lotação no escritório DO FERIADO. Sem N+1: sem
 * feriado por lotação, nenhuma consulta; com ele, uma por pessoa e escritório até o `reset`.
 */
#[CoversClass(FeriadosDaPessoa::class)]
#[Group('ponto')]
final class FeriadosDaPessoaTest extends TestCase
{
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

    #[TestDox('"Todas as sedes" vale para todos, até sem pessoa, e não consulta o vínculo')]
    public function testSemLotacaoValeParaTodos(): void
    {
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->expects(self::never())->method('findPorUserETenant');
        $regra = new FeriadosDaPessoa($vinculos);
        $todas = $this->feriado('Aniversário da cidade', $this->x, null);

        self::assertSame([$todas], $regra->filtrar([$todas], $this->pessoa(1)));
        self::assertTrue($regra->valePara($todas, null));
    }

    #[TestDox('Com lotação, vale só para quem tem aquela lotação no escritório do feriado')]
    public function testComLotacaoValeSoParaQuemTemALotacao(): void
    {
        $ana = $this->pessoa(1);
        $bruno = $this->pessoa(2);
        $semLotacao = $this->pessoa(3);
        $regra = new FeriadosDaPessoa($this->vinculos([
            '1:1' => $this->maceio,
            '2:1' => $this->brasilia,
            '3:1' => null,
        ]));
        $deMaceio = $this->feriado('Emancipação de Alagoas', $this->x, $this->maceio);
        $todas = $this->feriado('Padroeira', $this->x, null);

        self::assertSame([$deMaceio, $todas], $regra->filtrar([$deMaceio, $todas], $ana));
        self::assertSame([$todas], $regra->filtrar([$deMaceio, $todas], $bruno));
        self::assertSame([$todas], $regra->filtrar([$deMaceio, $todas], $semLotacao));
        self::assertFalse($regra->valePara($deMaceio, null), 'sem pessoa, o feriado de uma lotação não vale');
    }

    #[TestDox('Feriado de outro escritório é julgado pela lotação da pessoa NAQUELE escritório')]
    public function testFeriadoDeOutroEscritorio(): void
    {
        $ana = $this->pessoa(1);
        $regra = new FeriadosDaPessoa($this->vinculos(['1:1' => $this->maceio]));   // em Y, sem vínculo
        $deY = $this->feriado('Data magna de Pernambuco', $this->y, $this->deY);

        self::assertSame([], $regra->filtrar([$deY], $ana));

        $comVinculoEmY = new FeriadosDaPessoa($this->vinculos(['1:1' => $this->maceio, '1:2' => $this->deY]));
        self::assertSame([$deY], $comVinculoEmY->filtrar([$deY], $ana));
    }

    #[TestDox('Uma consulta por pessoa e escritório, guardada até o reset (o saldo do ano não volta ao banco a cada mês)')]
    public function testUmaConsultaPorPessoaEEscritorio(): void
    {
        $ana = $this->pessoa(1);
        $vinculo = new UserTenant($ana, $this->x);
        $vinculo->setLotacao($this->maceio);
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->expects(self::exactly(2))->method('findPorUserETenant')->willReturn($vinculo);
        $regra = new FeriadosDaPessoa($vinculos);
        $feriados = [
            $this->feriado('Um', $this->x, $this->maceio),
            $this->feriado('Dois', $this->x, $this->brasilia),
            $this->feriado('Três', $this->x, $this->maceio),
        ];

        for ($mes = 1; $mes <= 12; ++$mes) {
            self::assertCount(2, $regra->filtrar($feriados, $ana));
        }
        $regra->reset();
        self::assertCount(2, $regra->filtrar($feriados, $ana), 'depois do reset, consulta de novo (a lotação pode ter mudado)');
    }

    // ----------------------------------------------------------------- apoio

    /** @param array<string, ?Lotacao> $lotacoes "pessoa:escritório" => lotação; chave ausente = sem vínculo */
    private function vinculos(array $lotacoes): UserTenantRepository
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findPorUserETenant')->willReturnCallback(
            static function (User $pessoa, Tenant $escritorio) use ($lotacoes): ?UserTenant {
                $chave = $pessoa->getId() . ':' . $escritorio->getId();
                if (!\array_key_exists($chave, $lotacoes)) {
                    return null;
                }

                return (new UserTenant($pessoa, $escritorio))->setLotacao($lotacoes[$chave]);
            }
        );

        return $vinculos;
    }

    private function feriado(string $nome, Tenant $escritorio, ?Lotacao $lotacao): Feriado
    {
        return (new Feriado())->setNome($nome)->setData(new \DateTime('2026-09-16'))->setTenant($escritorio)->setLotacao($lotacao);
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
