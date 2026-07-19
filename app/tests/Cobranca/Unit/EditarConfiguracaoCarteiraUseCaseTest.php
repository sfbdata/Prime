<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarConfiguracaoCarteiraInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\RegimeJuros;
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
        // Tenant não é abstração do domínio: instância real, não mock.
        $this->tenant = new Tenant();
    }

    #[Test]
    public function atualizaConfiguracaoQuandoCarteiraExisteNoTenant(): void
    {
        $carteira = new Carteira();

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
            ->with(self::isInstanceOf(Carteira::class), true);

        $resultado = $this->sut->executar($this->input(), $this->tenant);

        self::assertSame($carteira, $resultado);
        self::assertSame(ModoCarteira::Multiplo, $resultado->getModo());
        self::assertSame(FormaHonorarios::AcrescidoDivida, $resultado->getFormaHonorarios());
        self::assertSame('15.50', $resultado->getPercentualHonorarios());
        self::assertSame(7, $resultado->getToleranciaAtrasoDias());
        self::assertSame(TipoVinculo::Inquilino, $resultado->getTipoVinculoPreferido());
        self::assertSame('Sala', $resultado->getRotuloObjeto());
    }

    #[Test]
    public function rejeitaQuandoCarteiraNaoExisteNoTenantENaoSalva(): void
    {
        // Carteira inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->carteiraRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->carteiraRepository->expects($this->never())->method('salvar');

        $this->expectException(CarteiraNaoEncontradaException::class);

        $this->sut->executar($this->input(), $this->tenant);
    }

    #[Test]
    public function gravaAConfiguracaoDeEncargosNaCarteira(): void
    {
        // Carteira nova: todos os 9 campos de encargo estão no default NEUTRO da entidade.
        $carteira = new Carteira();

        $this->carteiraRepository->method('findOneByIdDoTenant')->willReturn($carteira);
        $this->carteiraRepository->expects($this->once())->method('salvar')->with($carteira, true);

        $resultado = $this->sut->executar($this->inputComEncargos(), $this->tenant);

        // Cada valor é DIFERENTE do default da entidade: se um setter faltar, a asserção acusa.
        self::assertSame(100, $resultado->getTaxaJurosMensalBp());
        self::assertSame(RegimeJuros::Composto, $resultado->getRegimeJuros());
        self::assertSame(200, $resultado->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $resultado->getBaseMulta());
        self::assertSame(50, $resultado->getTaxaCorrecaoBp());
        self::assertSame(BaseEncargo::Composta, $resultado->getBaseCorrecao());
        self::assertSame(BaseEncargo::Principal, $resultado->getBaseHonorarios());
        self::assertSame(30, $resultado->getCarenciaHonorariosDias());
        self::assertSame(5, $resultado->getToleranciaJurosMultaDias());
    }

    #[Test]
    public function carenciaDeHonorariosVaziaEGravadaComoNullParaHerdarATolerancia(): void
    {
        $carteira = (new Carteira())->setCarenciaHonorariosDias(30);

        $this->carteiraRepository->method('findOneByIdDoTenant')->willReturn($carteira);

        $input = $this->inputComEncargos();
        // Vazio no formulário = null = "usa a tolerância de atraso"; não pode virar 0 (que seria
        // "sem carência nenhuma", regra oposta).
        $input->carenciaHonorariosDias = null;

        $resultado = $this->sut->executar($input, $this->tenant);

        self::assertNull($resultado->getCarenciaHonorariosDias());
    }

    /** Input com os 7 campos originais + os 9 de encargo, todos fora do default da entidade. */
    private function inputComEncargos(): EditarConfiguracaoCarteiraInput
    {
        $input = $this->input();
        $input->taxaJurosMensalBp = 100;
        $input->regimeJuros = RegimeJuros::Composto;
        $input->taxaMultaBp = 200;
        $input->baseMulta = BaseEncargo::Composta;
        $input->taxaCorrecaoBp = 50;
        $input->baseCorrecao = BaseEncargo::Composta;
        $input->baseHonorarios = BaseEncargo::Principal;
        $input->carenciaHonorariosDias = 30;
        $input->toleranciaJurosMultaDias = 5;

        return $input;
    }

    private function input(): EditarConfiguracaoCarteiraInput
    {
        $input = new EditarConfiguracaoCarteiraInput();
        $input->carteiraId = 7;
        $input->modo = ModoCarteira::Multiplo;
        $input->formaHonorarios = FormaHonorarios::AcrescidoDivida;
        $input->percentualHonorarios = '15.50';
        $input->toleranciaAtrasoDias = 7;
        $input->tipoVinculoPreferido = TipoVinculo::Inquilino;
        $input->rotuloObjeto = 'Sala';

        return $input;
    }
}
