<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\Exception\FiltroDoAgenteInvalidoException;
use App\Inteligencia\Service\ConsultaDePastasDoAgente;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O escritório vai EXPLÍCITO em toda consulta do agente. Na requisição HTTP o `TenantFilter` da sessão
 * já esconde o que é de outro escritório — o teste funcional prova o isolamento, mas não esta guarda
 * (reintroduzir a busca sem tenant não o derruba). Aqui, sem o filtro, a guarda fica sozinha: é o caso
 * do worker e do conector MCP previsto na Fase 4, onde o filtro da sessão fica inerte.
 */
#[CoversClass(ConsultaDePastasDoAgente::class)]
final class ConsultaDePastasDoAgenteTest extends TestCase
{
    #[TestDox('A lista de NUPs consulta com o escritório junto')]
    public function testNupsComTenant(): void
    {
        $tenant = new Tenant();
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::once())->method('findBy')
            ->with(['tenant' => $tenant, 'nup' => ['101', '102']], self::anything())
            ->willReturn([]);
        $pastas->expects(self::once())->method('count')
            ->with(['tenant' => $tenant, 'nup' => ['101', '102']])
            ->willReturn(0);

        $consulta = new ConsultaDePastasDoAgente($pastas, $this->createStub(MarcadorRepository::class), $this->createStub(UserTenantRepository::class));
        $filtro = FiltroDoAgenteInput::daQuery(['nups' => '101,102']);

        $consulta->buscar($filtro, $tenant, 50);
        $consulta->contar($filtro, $tenant);
    }

    #[TestDox('O marcador é procurado no escritório; achar só fora dele é recusa, não lista vazia')]
    public function testMarcadorDoEscritorio(): void
    {
        $tenant = new Tenant();
        $marcadores = $this->createMock(MarcadorRepository::class);
        $marcadores->expects(self::once())->method('findPorTenant')->with(9, $tenant)->willReturn(null);
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::never())->method('countByFiltrosEMarcador');
        $pastas->expects(self::never())->method('countByFilters');

        $consulta = new ConsultaDePastasDoAgente($pastas, $marcadores, $this->createStub(UserTenantRepository::class));

        try {
            $consulta->contar(FiltroDoAgenteInput::daQuery(['marcador' => '9']), $tenant);
            self::fail('Marcador fora do escritório deveria ser recusado.');
        } catch (FiltroDoAgenteInvalidoException $e) {
            self::assertSame('marcador', $e->campo);
        }
    }

    #[TestDox('Com marcador do escritório, a busca e a contagem são as da Lista por marcador, com o escritório')]
    public function testMarcadorUsaAConsultaDaLista(): void
    {
        $tenant = new Tenant();
        $marcador = $this->createStub(Marcador::class);
        $marcadores = $this->createStub(MarcadorRepository::class);
        $marcadores->method('findPorTenant')->willReturn($marcador);
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::once())->method('findByFiltrosEMarcador')
            ->with(['busca' => 'Maria'], $marcador, $tenant, 2, 50, 'nup', 'asc')
            ->willReturn([]);
        $pastas->expects(self::once())->method('countByFiltrosEMarcador')
            ->with(['busca' => 'Maria'], $marcador, $tenant)
            ->willReturn(0);

        $consulta = new ConsultaDePastasDoAgente($pastas, $marcadores, $this->createStub(UserTenantRepository::class));
        $filtro = FiltroDoAgenteInput::daQuery(['marcador' => '9', 'busca' => 'Maria', 'pagina' => '2']);

        $consulta->buscar($filtro, $tenant, 50);
        $consulta->contar($filtro, $tenant);
    }

    #[TestDox('Sem marcador, a busca e a contagem são as da Lista do Expediente, com o escritório')]
    public function testSemMarcadorUsaAConsultaDaLista(): void
    {
        $tenant = new Tenant();
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::once())->method('findByFilters')
            ->with(['status' => 'ativo'], $tenant, 1, 50, 'nup', 'asc')
            ->willReturn([]);
        $pastas->expects(self::once())->method('countByFilters')
            ->with(['status' => 'ativo'], $tenant)
            ->willReturn(0);

        $consulta = new ConsultaDePastasDoAgente($pastas, $this->createStub(MarcadorRepository::class), $this->createStub(UserTenantRepository::class));
        $filtro = FiltroDoAgenteInput::daQuery(['situacao' => 'ativo']);

        $consulta->buscar($filtro, $tenant, 50);
        $consulta->contar($filtro, $tenant);
    }

    #[TestDox('O responsável é procurado no escritório; sem vínculo com ele é recusa, não "nenhuma pasta"')]
    public function testResponsavelDoEscritorio(): void
    {
        $tenant = new Tenant();
        $vinculos = $this->createMock(UserTenantRepository::class);
        $vinculos->expects(self::exactly(2))->method('findOneBy')->with(['user' => 7, 'tenant' => $tenant])->willReturn(null);
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::never())->method('findByFilters');
        $pastas->expects(self::never())->method('countByFilters');

        $consulta = new ConsultaDePastasDoAgente($pastas, $this->createStub(MarcadorRepository::class), $vinculos);
        $filtro = FiltroDoAgenteInput::daQuery(['responsavel' => '7']);

        foreach ([static fn () => $consulta->buscar($filtro, $tenant, 50), static fn () => $consulta->contar($filtro, $tenant)] as $chamada) {
            try {
                $chamada();
                self::fail('Responsável fora do escritório deveria ser recusado.');
            } catch (FiltroDoAgenteInvalidoException $e) {
                self::assertSame('responsavel', $e->campo);
            }
        }
    }
}
