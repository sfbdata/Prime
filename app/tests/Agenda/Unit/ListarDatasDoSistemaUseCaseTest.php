<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\DTO\DataDoSistemaOutput as D;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\UfsDaAgenda;
use App\Agenda\UseCase\ListarDatasDoSistemaUseCase;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\Feriado;
use App\Ponto\Repository\FeriadoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarDatasDoSistemaUseCase::class)]
final class ListarDatasDoSistemaUseCaseTest extends TestCase
{
    #[TestDox('Feriado do Ponto que coincide com o calculado só marca "conta no ponto"')]
    public function testCoincidenciaMarcaContaNoPonto(): void
    {
        $r = $this->executar([$this->feriado('Natal (escritório)', '2000-12-25', true)], '2026-12-25', '2026-12-25');
        $natal = $this->so($r['datas'], D::CAL_NACIONAL);

        self::assertCount(1, $natal);
        self::assertSame('Natal', $natal[0]->titulo);
        self::assertTrue($natal[0]->contaNoPonto);
    }

    #[TestDox('Feriado calculado que o escritório não cadastrou não conta no ponto')]
    public function testSoCalculadoNaoContaNoPonto(): void
    {
        $r = $this->executar([], '2026-04-03', '2026-04-03');

        self::assertFalse($this->so($r['datas'], D::CAL_NACIONAL)[0]->contaNoPonto);
    }

    #[TestDox('Feriado só do Ponto (municipal) entra como feriado do escritório')]
    public function testFeriadoSoDoPontoEntra(): void
    {
        $r = $this->executar([$this->feriado('Aniversário da cidade', '2026-08-28', false)], '2026-08-01', '2026-08-31');
        $esc = array_values(array_filter($r['datas'], static fn (D $d): bool => D::TIPO_ESCRITORIO === $d->tipo));

        self::assertCount(1, $esc);
        self::assertSame('Aniversário da cidade', $esc[0]->titulo);
        self::assertSame('2026-08-28', $esc[0]->data);
        self::assertTrue($esc[0]->contaNoPonto);
    }

    #[TestDox('Feriado não recorrente de outro ano não aparece; recorrente aparece em cada ano do período')]
    public function testRecorrencia(): void
    {
        $r = $this->executar([
            $this->feriado('Ponte 2025', '2025-08-28', false),
            $this->feriado('Padroeira', '2000-08-28', true),
        ], '2026-08-01', '2027-08-31');
        $esc = array_values(array_filter($r['datas'], static fn (D $d): bool => D::TIPO_ESCRITORIO === $d->tipo));

        self::assertSame(['2026-08-28', '2027-08-28'], array_map(static fn (D $d): string => $d->data, $esc));
        self::assertSame('Padroeira', $esc[0]->titulo);
    }

    #[TestDox('Ponto facultativo do sistema só conta no ponto se o escritório cadastrou o dia')]
    public function testPontoFacultativoNaoViraFeriadoSozinho(): void
    {
        $r = $this->executar([], '2026-02-17', '2026-02-17');
        $carnaval = $this->so($r['datas'], D::CAL_NACIONAL)[0];

        self::assertSame(D::TIPO_PONTO_FACULTATIVO, $carnaval->tipo);
        self::assertFalse($carnaval->contaNoPonto);
    }

    #[TestDox('Data comemorativa no mesmo dia de um feriado do Ponto não é marcada')]
    public function testComemorativaNaoMarca(): void
    {
        $r = $this->executar([$this->feriado('Feriado local', '2026-06-12', false)], '2026-06-12', '2026-06-12');

        self::assertFalse($this->so($r['datas'], D::CAL_COMEMORATIVA)[0]->contaNoPonto);
        self::assertCount(1, array_filter($r['datas'], static fn (D $d): bool => D::TIPO_ESCRITORIO === $d->tipo));
    }

    #[TestDox('Feriado do Ponto em data só de tribunal ganha item próprio e o do tribunal não conta no ponto')]
    public function testFeriadoDoPontoEmDataDeTribunal(): void
    {
        $r = $this->executar([$this->feriado('Feriado municipal', '2026-11-01', false)], '2026-11-01', '2026-11-01');
        $esc = array_values(array_filter($r['datas'], static fn (D $d): bool => D::TIPO_ESCRITORIO === $d->tipo));

        self::assertCount(1, $esc);
        self::assertSame('Feriado municipal', $esc[0]->titulo);
        self::assertFalse($this->so($r['datas'], D::CAL_TRIBUNAL)[0]->contaNoPonto);
    }

    #[TestDox('UFs vêm da lotação e do estado do escritório')]
    public function testUfs(): void
    {
        $tenant = new Tenant();
        $tenant->setEstado('DF');
        $repo = $this->createStub(FeriadoRepository::class);
        $repo->method('findByTenant')->willReturn([]);
        $sut = new ListarDatasDoSistemaUseCase(new CalendarioDoSistema(), new UfsDaAgenda(), $repo, $this->semAjustes());

        $r = $sut->executar($tenant, 'Maceió/AL', new \DateTimeImmutable('2026-06-24'), new \DateTimeImmutable('2026-06-24'));

        self::assertSame(['AL', 'DF'], $r['ufs']);
        self::assertSame('São João · AL', $this->so($r['datas'], D::CAL_ESTADUAL)[0]->titulo);
    }

    /**
     * @param list<Feriado> $feriados
     *
     * @return array{ufs: list<string>, datas: list<D>}
     */
    private function executar(array $feriados, string $de, string $ate): array
    {
        $repo = $this->createStub(FeriadoRepository::class);
        $repo->method('findByTenant')->willReturn($feriados);
        $sut = new ListarDatasDoSistemaUseCase(new CalendarioDoSistema(), new UfsDaAgenda(), $repo, $this->semAjustes());

        return $sut->executar(new Tenant(), null, new \DateTimeImmutable($de), new \DateTimeImmutable($ate));
    }

    private function feriado(string $nome, string $data, bool $recorrente): Feriado
    {
        return (new Feriado())->setNome($nome)->setData(new \DateTimeImmutable($data))->setRecorrente($recorrente);
    }

    /**
     * @param list<D> $datas
     *
     * @return list<D>
     */
    private function so(array $datas, string $calendario): array
    {
        return array_values(array_filter($datas, static fn (D $d): bool => $d->calendario === $calendario));
    }

    private function semAjustes(): AjusteDeDataRepository
    {
        $repo = $this->createStub(AjusteDeDataRepository::class);
        $repo->method('doEscritorio')->willReturn([]);

        return $repo;
    }
}
