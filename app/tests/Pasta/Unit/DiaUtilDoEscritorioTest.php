<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Agenda\Repository\AjusteDeDataRepository;
use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\UfsDaAgenda;
use App\Agenda\UseCase\ListarDatasDoSistemaUseCase;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiaUtilDoEscritorio;
use App\Ponto\Entity\Feriado;
use App\Ponto\Repository\FeriadoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O dia útil do escritório inteiro (cobrança do checklist e resumo diário) diante do feriado por lotação do Ponto (spec
 * `ponto-desenho-0811.md`, E6): só o de "Todas as sedes" pula o dia; o de uma lotação não para o escritório.
 */
#[CoversClass(DiaUtilDoEscritorio::class)]
#[CoversClass(ListarDatasDoSistemaUseCase::class)]
final class DiaUtilDoEscritorioTest extends TestCase
{
    private const TERCA = '2026-03-10';

    #[TestDox('Feriado de uma lotação (Maceió) não pula o dia do escritório')]
    public function testFeriadoDaLotacaoNaoPulaODia(): void
    {
        $lotacao = (new Lotacao())->setNome('MACEIÓ/AL');
        (new \ReflectionProperty(Lotacao::class, 'id'))->setValue($lotacao, 11);

        self::assertTrue($this->diaUtil([$this->feriado('Emancipação de Alagoas', $lotacao)])->ehDiaUtil(new Tenant(), new \DateTimeImmutable(self::TERCA)));
    }

    #[TestDox('Feriado de "Todas as sedes" pula o dia do escritório')]
    public function testFeriadoDeTodasAsSedesPulaODia(): void
    {
        self::assertFalse($this->diaUtil([$this->feriado('Padroeira da cidade', null)])->ehDiaUtil(new Tenant(), new \DateTimeImmutable(self::TERCA)));
    }

    #[TestDox('Sem feriado, a terça é dia útil')]
    public function testSemFeriado(): void
    {
        self::assertTrue($this->diaUtil([])->ehDiaUtil(new Tenant(), new \DateTimeImmutable(self::TERCA)));
    }

    /** @param list<Feriado> $feriados */
    private function diaUtil(array $feriados): DiaUtilDoEscritorio
    {
        $repo = $this->createStub(FeriadoRepository::class);
        $repo->method('findByTenant')->willReturn($feriados);
        $ajustes = $this->createStub(AjusteDeDataRepository::class);
        $ajustes->method('doEscritorio')->willReturn([]);

        return new DiaUtilDoEscritorio(new ListarDatasDoSistemaUseCase(new CalendarioDoSistema(), new UfsDaAgenda(), $repo, $ajustes));
    }

    private function feriado(string $nome, ?Lotacao $lotacao): Feriado
    {
        return (new Feriado())->setNome($nome)->setData(new \DateTimeImmutable(self::TERCA))->setRecorrente(false)->setLotacao($lotacao);
    }
}
