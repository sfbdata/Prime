<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\AjusteDeData;
use App\Agenda\Enum\TipoDeAjuste;
use App\Agenda\Repository\AjusteDeDataRepository;
use App\Agenda\Service\IdDeDataDaAgenda;
use App\Agenda\UseCase\AcrescentarDataUseCase;
use App\Agenda\UseCase\OcultarDataUseCase;
use App\Agenda\UseCase\RenomearDataUseCase;
use App\Agenda\UseCase\RestaurarDatasDoSistemaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcrescentarDataUseCase::class)]
#[CoversClass(RenomearDataUseCase::class)]
#[CoversClass(OcultarDataUseCase::class)]
#[CoversClass(RestaurarDatasDoSistemaUseCase::class)]
#[CoversClass(IdDeDataDaAgenda::class)]
#[CoversClass(AjusteDeData::class)]
final class AjustesDeDataUseCasesTest extends TestCase
{
    #[TestDox('Acrescentar guarda data, nome, calendário e fonte; recusa data, nome e calendário inválidos')]
    public function testAcrescentar(): void
    {
        $salvo = null;
        $repo = $this->createMock(AjusteDeDataRepository::class);
        $repo->method('salvar')->willReturnCallback(static function (AjusteDeData $a) use (&$salvo): void {
            $salvo = $a;
        });
        $sut = new AcrescentarDataUseCase($repo);

        $sut->executar(new Tenant(), new User(), '2026-11-10', '  Feriado municipal ', 'fer', '');
        self::assertSame(['2026-11-10', 'Feriado municipal', 'fer', null], [$salvo->getData()->format('Y-m-d'), $salvo->getTitulo(), $salvo->getCalendario(), $salvo->getFonte()]);

        foreach ([['2026-02-31', 'x', 'fer'], ['2026-11-10', ' ', 'fer'], ['2026-11-10', 'x', 'aniv']] as [$data, $titulo, $cal]) {
            try {
                $sut->executar(new Tenant(), new User(), $data, $titulo, $cal, '');
                self::fail('Devia recusar ' . $data . ' ' . $cal);
            } catch (\DomainException) {
            }
        }
    }

    #[TestDox('Renomear data do sistema cria um renome uma vez e depois só troca o nome; acrescentada troca o próprio nome')]
    public function testRenomear(): void
    {
        $repo = $this->createMock(AjusteDeDataRepository::class);
        $existente = AjusteDeData::renomear(new Tenant(), 'fer-2026-11-15-proclamacao-da-republica', 'Antigo', new User());
        $acrescentada = AjusteDeData::acrescentar(new Tenant(), new \DateTimeImmutable('2026-11-10'), 'Velho', 'fer', null, new User());
        $repo->method('daData')->willReturnOnConsecutiveCalls(null, $existente);
        $repo->method('doEscritorioPorId')->willReturn($acrescentada);
        $salvos = [];
        $repo->method('salvar')->willReturnCallback(static function (AjusteDeData $a) use (&$salvos): void {
            $salvos[] = $a;
        });
        $sut = new RenomearDataUseCase($repo);

        $sut->executar(new Tenant(), new User(), 'fer-2026-11-15-proclamacao-da-republica', 'Proclamação');
        $sut->executar(new Tenant(), new User(), 'fer-2026-11-15-proclamacao-da-republica', 'Novo');
        $sut->executar(new Tenant(), new User(), 'x-7', 'Feriado municipal');

        self::assertSame(TipoDeAjuste::Renomear, $salvos[0]->getTipo());
        self::assertSame('Proclamação', $salvos[0]->getTitulo());
        self::assertSame('Novo', $existente->getTitulo());
        self::assertSame('Feriado municipal', $acrescentada->getTitulo());
    }

    #[TestDox('Ocultar data do sistema guarda uma ocultação só; ocultar acrescentada a exclui; id estranho é recusado')]
    public function testOcultar(): void
    {
        $repo = $this->createMock(AjusteDeDataRepository::class);
        $acrescentada = AjusteDeData::acrescentar(new Tenant(), new \DateTimeImmutable('2026-11-10'), 'Municipal', 'fer', null, new User());
        $repo->method('daData')->willReturnOnConsecutiveCalls(null, AjusteDeData::ocultar(new Tenant(), 'fer-2026-11-02-finados', new User()));
        $repo->method('doEscritorioPorId')->willReturn($acrescentada);
        $repo->expects(self::once())->method('salvar');
        $repo->expects(self::once())->method('remover')->with($acrescentada);
        $sut = new OcultarDataUseCase($repo);

        $sut->executar(new Tenant(), new User(), 'fer-2026-11-02-finados');
        $sut->executar(new Tenant(), new User(), 'fer-2026-11-02-finados');
        $sut->executar(new Tenant(), new User(), 'x-3');

        $this->expectException(\DomainException::class);
        $sut->executar(new Tenant(), new User(), 'qualquer coisa');
    }

    #[TestDox('Restaurar apaga renomes e ocultações do escritório')]
    public function testRestaurar(): void
    {
        $repo = $this->createMock(AjusteDeDataRepository::class);
        $repo->expects(self::once())->method('apagarRenomesEOcultacoes')->willReturn(3);

        self::assertSame(3, (new RestaurarDatasDoSistemaUseCase($repo))->executar(new Tenant()));
    }
}
