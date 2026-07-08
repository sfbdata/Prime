<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\DTO\CriarCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\ClienteCredorNaoEncontradoException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\UseCase\CriarCarteiraUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarCarteiraUseCase::class)]
final class CriarCarteiraUseCaseTest extends TestCase
{
    private CarteiraRepository&MockObject $carteiraRepository;
    private ClienteRepository&MockObject $clienteRepository;
    private CriarCarteiraUseCase $sut;
    private Tenant&MockObject $tenant;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->carteiraRepository = $this->createMock(CarteiraRepository::class);
        $this->clienteRepository = $this->createMock(ClienteRepository::class);
        $this->sut = new CriarCarteiraUseCase($this->carteiraRepository, $this->clienteRepository);
        $this->tenant = $this->createMock(Tenant::class);
        $this->user = $this->createMock(User::class);
    }

    #[Test]
    public function criaCarteiraComOsCamposDoInputQuandoCredorExisteNoTenant(): void
    {
        $cliente = $this->createStub(Cliente::class);

        // Guarda multi-tenant: o credor é resolvido por id + tenant do usuário.
        $this->clienteRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 42, 'tenant' => $this->tenant])
            ->willReturn($cliente);

        // Persistência com flush em uma única transação.
        $this->carteiraRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($this->isInstanceOf(Carteira::class), true);

        $carteira = $this->sut->executar($this->input(), $this->tenant, $this->user);

        self::assertSame('Condomínio Alfa', $carteira->getNome());
        self::assertSame($this->tenant, $carteira->getTenant());
        self::assertSame($cliente, $carteira->getCliente());
        self::assertSame(ModoCarteira::Multiplo, $carteira->getModo());
        self::assertSame(FormaHonorarios::AcrescidoDivida, $carteira->getFormaHonorarios());
        self::assertSame('10.00', $carteira->getPercentualHonorarios());
        self::assertSame(5, $carteira->getToleranciaAtrasoDias());
        self::assertSame(TipoVinculo::Proprietario, $carteira->getTipoVinculoPreferido());
        self::assertSame('Unidade', $carteira->getRotuloObjeto());
        self::assertSame($this->user, $carteira->getCriadoPor());
    }

    #[Test]
    public function rejeitaComExcecaoQuandoCredorNaoExisteNoTenantENaoSalva(): void
    {
        // Cliente inexistente OU de outro escritório: findOneBy(id + tenant) devolve null.
        $this->clienteRepository->method('findOneBy')->willReturn(null);
        $this->carteiraRepository->expects($this->never())->method('salvar');

        $this->expectException(ClienteCredorNaoEncontradoException::class);

        $this->sut->executar($this->input(), $this->tenant, $this->user);
    }

    private function input(): CriarCarteiraInput
    {
        $input = new CriarCarteiraInput();
        $input->nome = 'Condomínio Alfa';
        $input->clienteId = 42;
        $input->modo = ModoCarteira::Multiplo;
        $input->formaHonorarios = FormaHonorarios::AcrescidoDivida;
        $input->percentualHonorarios = '10.00';
        $input->toleranciaAtrasoDias = 5;
        $input->tipoVinculoPreferido = TipoVinculo::Proprietario;
        $input->rotuloObjeto = 'Unidade';

        return $input;
    }
}
