<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cliente\Entity\Cliente;
use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Exception\CarteiraNaoEncontradaException;
use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\UseCase\EditarConfiguracaoCarteiraUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarConfiguracaoCarteiraUseCase::class)]
final class EditarConfiguracaoCarteiraUseCaseTest extends TestCase
{
    private CarteiraRepository&MockObject $carteiraRepository;
    private EditarConfiguracaoCarteiraUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->carteiraRepository = $this->createMock(CarteiraRepository::class);
        $this->sut = new EditarConfiguracaoCarteiraUseCase($this->carteiraRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function aplicaConfiguracaoNaCarteiraDoTenantSemTocarCredor(): void
    {
        $cliente = $this->createStub(Cliente::class);

        // Carteira pré-existente do escritório, com credor e valores originais.
        $carteira = new Carteira();
        $carteira->setTenant($this->tenant);
        $carteira->setCliente($cliente);
        $carteira->setNome('Nome antigo');
        $carteira->setModo(ModoCarteira::Unico);
        $carteira->setFormaHonorarios(FormaHonorarios::SemPercentual);

        // Guarda multi-tenant: a carteira é resolvida por id + tenant do usuário.
        $this->carteiraRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(7, $this->tenant)
            ->willReturn($carteira);

        // Persistência com flush em uma única transação.
        $this->carteiraRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($carteira, true);

        $resultado = $this->sut->executar(7, $this->input(), $this->tenant);

        self::assertSame($carteira, $resultado);
        self::assertSame('Condomínio Beta', $carteira->getNome());
        self::assertSame(ModoCarteira::Multiplo, $carteira->getModo());
        self::assertSame(FormaHonorarios::AcrescidoDivida, $carteira->getFormaHonorarios());
        self::assertSame('12.50', $carteira->getPercentualHonorarios());
        self::assertSame(3, $carteira->getToleranciaAtrasoDias());
        self::assertSame(TipoVinculo::Inquilino, $carteira->getTipoVinculoPreferido());
        self::assertSame('Sala', $carteira->getRotuloObjeto());

        // Invariável 4: credor e tenant permanecem intocados na edição.
        self::assertSame($cliente, $carteira->getCliente());
        self::assertSame($this->tenant, $carteira->getTenant());
    }

    #[Test]
    public function rejeitaComExcecaoQuandoCarteiraNaoExisteNoTenantENaoSalva(): void
    {
        // Carteira inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->carteiraRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->carteiraRepository->expects($this->never())->method('salvar');

        $this->expectException(CarteiraNaoEncontradaException::class);

        $this->sut->executar(99, $this->input(), $this->tenant);
    }

    private function input(): EditarConfiguracaoCarteiraInput
    {
        $input = new EditarConfiguracaoCarteiraInput();
        $input->nome = 'Condomínio Beta';
        $input->modo = ModoCarteira::Multiplo;
        $input->formaHonorarios = FormaHonorarios::AcrescidoDivida;
        $input->percentualHonorarios = '12.50';
        $input->toleranciaAtrasoDias = 3;
        $input->tipoVinculoPreferido = TipoVinculo::Inquilino;
        $input->rotuloObjeto = 'Sala';

        return $input;
    }
}
