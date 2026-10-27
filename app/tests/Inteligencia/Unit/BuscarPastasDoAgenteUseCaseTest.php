<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Cliente\Entity\ClientePF;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Inteligencia\DTO\FiltroDoAgenteInput;
use App\Inteligencia\Service\ConsultaDePastasDoAgente;
use App\Inteligencia\UseCase\BuscarPastasDoAgenteUseCase;
use App\Inteligencia\UseCase\ContarPastasDoAgenteUseCase;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserTenantRepository;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Pasta\Unit\Cadeado\CriaCenarioDoCadeadoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A linha que o agente recebe tem o MESMO texto que a Lista mostra: o identificador de uma pasta
 * judicializada pela cobrança é o derivado (`<credor> - <devedor>`), não o `nome_cliente` gravado — o
 * agente não pode dizer um nome e a tela outro. E a ação cai para a classe do processo principal.
 */
#[CoversClass(BuscarPastasDoAgenteUseCase::class)]
#[CoversClass(ContarPastasDoAgenteUseCase::class)]
final class BuscarPastasDoAgenteUseCaseTest extends TestCase
{
    use CriaCenarioDoCadeadoTrait {
        pasta as pastaDoCadeado;
    }

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    private function pasta(Tenant $tenant, int $id, string $nup, string $nomeGravado): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($tenant);
        $pasta->setNup($nup);
        $pasta->setNomeCliente($nomeGravado);
        DefineId::em($pasta, $id);

        return $pasta;
    }

    #[TestDox('Identificador da pasta judicializada é o derivado da cobrança; o das outras, o gravado')]
    public function testIdentificadorComoNaLista(): void
    {
        $tenant = new Tenant();
        $judicializada = $this->pasta($tenant, 1, '10', 'APLC TOP LIFE 1 -');
        $comum = $this->pasta($tenant, 2, '11', 'MARIA');

        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findByFilters')->willReturn([$judicializada, $comum]);
        $pastas->method('countByFilters')->willReturn(2);
        $casos = $this->createMock(CasoCobrancaRepository::class);
        $casos->expects(self::once())->method('clienteDaCarteiraPorPasta')
            ->with([1, 2], $tenant)
            ->willReturn([1 => ['fantasia' => 'APLC TOP LIFE 1', 'razaoSocial' => 'APLC EMPREENDIMENTOS LTDA']]);

        $useCase = new BuscarPastasDoAgenteUseCase(
            new ConsultaDePastasDoAgente($pastas, $this->createStub(MarcadorRepository::class), $this->createStub(UserTenantRepository::class)),
            new ResolvedorIdentificadorDaPasta($casos, new ComporNomeDaPastaJudicial()),
            $this->acessoAPasta(),
        );

        $saida = $useCase->executar(FiltroDoAgenteInput::daQuery([]), $tenant);

        self::assertSame(2, $saida->total);
        self::assertSame('APLC TOP LIFE 1', $saida->pastas[0]->identificador);
        self::assertSame('MARIA', $saida->pastas[1]->identificador);
        self::assertNull($saida->pastas[1]->acao);
    }

    #[TestDox('Clientes vinculados só saem quando a pasta não tem identificador, como na coluna da Lista')]
    public function testClientesSoSemIdentificador(): void
    {
        $tenant = new Tenant();
        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Empresa Vinculada');
        $comIdentificador = $this->pasta($tenant, 1, '10', 'MARIA');
        $comIdentificador->addCliente($cliente);
        $semIdentificador = new Pasta();
        $semIdentificador->setTenant($tenant);
        $semIdentificador->setNup('11');
        $semIdentificador->addCliente($cliente);
        DefineId::em($semIdentificador, 2);

        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findByFilters')->willReturn([$comIdentificador, $semIdentificador]);
        $casos = $this->createStub(CasoCobrancaRepository::class);
        $casos->method('clienteDaCarteiraPorPasta')->willReturn([]);

        $useCase = new BuscarPastasDoAgenteUseCase(
            new ConsultaDePastasDoAgente($pastas, $this->createStub(MarcadorRepository::class), $this->createStub(UserTenantRepository::class)),
            new ResolvedorIdentificadorDaPasta($casos, new ComporNomeDaPastaJudicial()),
            $this->acessoAPasta(),
        );

        $saida = $useCase->executar(FiltroDoAgenteInput::daQuery([]), $tenant);

        self::assertSame([], $saida->pastas[0]->clientes);
        self::assertNull($saida->pastas[1]->identificador);
        self::assertSame(['EMPRESA VINCULADA'], $saida->pastas[1]->clientes);
    }

    #[TestDox('Contar devolve só o total, sem buscar as linhas')]
    public function testContar(): void
    {
        $tenant = new Tenant();
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->expects(self::never())->method('findByFilters');
        $pastas->expects(self::once())->method('countByFilters')->with(['busca' => 'maria'], $tenant)->willReturn(7);

        $useCase = new ContarPastasDoAgenteUseCase(
            new ConsultaDePastasDoAgente($pastas, $this->createStub(MarcadorRepository::class), $this->createStub(UserTenantRepository::class)),
        );

        self::assertSame(7, $useCase->executar(FiltroDoAgenteInput::daQuery(['busca' => 'maria']), $tenant));
    }

    #[TestDox('Cadeado (D2): da pasta oculta para quem conversa, o agente recebe só o número e o cliente')]
    public function testPastaOcultaSaiSoComNumeroECliente(): void
    {
        $ana    = $this->pessoa('Ana Equipe');
        $oculta = $this->pastaDoCadeado('20');
        $oculta->setNomeCliente('JOANA SIGILOSA');
        $oculta->setNomeAcao('Ação de cobrança');
        $livre = $this->pastaDoCadeado('21');
        $livre->setNomeCliente('PEDRO');
        $livre->setNomeAcao('Divórcio');
        $this->cadeadoRepository->method('dasPastas')->willReturn([
            (int) $oculta->getId() => new CadeadoDaPasta($this->tenant, $oculta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()),
        ]);

        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findByFilters')->willReturn([$oculta, $livre]);
        $casos = $this->createStub(CasoCobrancaRepository::class);
        $casos->method('clienteDaCarteiraPorPasta')->willReturn([]);
        $useCase = new BuscarPastasDoAgenteUseCase(
            new ConsultaDePastasDoAgente($pastas, $this->createStub(MarcadorRepository::class), $this->createStub(UserTenantRepository::class)),
            new ResolvedorIdentificadorDaPasta($casos, new ComporNomeDaPastaJudicial()),
            $this->acessoAPasta(),
        );

        $saida = $useCase->executar(FiltroDoAgenteInput::daQuery([]), $this->tenant, $ana);

        self::assertTrue($saida->pastas[0]->oculta);
        self::assertSame('20', $saida->pastas[0]->nup);
        self::assertSame('JOANA SIGILOSA', $saida->pastas[0]->identificador);
        self::assertNull($saida->pastas[0]->acao);
        self::assertSame('', $saida->pastas[0]->situacao);
        self::assertFalse($saida->pastas[1]->oculta);
        self::assertSame('DIVÓRCIO', $saida->pastas[1]->acao);
    }
}
