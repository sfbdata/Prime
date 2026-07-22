<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarConfiguracaoObjetoInput;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\UseCase\EditarConfiguracaoObjetoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * #9-T3: edita os 10 overrides de encargos do OBJETO (nível 2 da cascata Carteira → Objeto →
 * Obrigação). Cobre a gravação de cada override, o retorno a `null` (volta a herdar a carteira) e a
 * guarda multi-tenant (cross-tenant nega).
 */
#[CoversClass(EditarConfiguracaoObjetoUseCase::class)]
final class EditarConfiguracaoObjetoUseCaseTest extends TestCase
{
    private ObjetoCobrancaRepository&MockObject $objetoRepository;
    private EditarConfiguracaoObjetoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->objetoRepository = $this->createMock(ObjetoCobrancaRepository::class);
        $this->sut = new EditarConfiguracaoObjetoUseCase($this->objetoRepository);
        // Tenant não é abstração do domínio: instância real, não mock.
        $this->tenant = new Tenant();
    }

    #[Test]
    public function gravaOsDezOverridesNoObjeto(): void
    {
        // Objeto novo: os 10 campos de override estão no default NEUTRO (null) da entidade.
        $objeto = new ObjetoCobranca();

        $this->objetoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(9, $this->tenant)
            ->willReturn($objeto);

        $this->objetoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(ObjetoCobranca::class), true);

        $resultado = $this->sut->executar($this->inputComOverrides(), $this->tenant);

        self::assertSame($objeto, $resultado);
        // Cada valor é DIFERENTE do default null da entidade: se um setter faltar, a asserção acusa.
        self::assertSame(150, $resultado->getTaxaJurosMensalBp());
        self::assertSame(RegimeJuros::Composto, $resultado->getRegimeJuros());
        self::assertSame(300, $resultado->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $resultado->getBaseMulta());
        self::assertSame(80, $resultado->getTaxaCorrecaoBp());
        self::assertSame(BaseEncargo::Composta, $resultado->getBaseCorrecao());
        self::assertSame(2500, $resultado->getTaxaHonorariosBp());
        self::assertSame(BaseEncargo::Principal, $resultado->getBaseHonorarios());
        self::assertSame(15, $resultado->getCarenciaHonorariosDias());
        self::assertSame(5, $resultado->getToleranciaJurosMultaDias());
    }

    #[Test]
    public function camposVaziosVoltamAHerdarACarteiraMesmoComOverrideAnteriorExistente(): void
    {
        // Objeto que JÁ tinha overrides gravados de uma edição anterior.
        $objeto = (new ObjetoCobranca())
            ->setTaxaJurosMensalBp(999)
            ->setRegimeJuros(RegimeJuros::Composto)
            ->setTaxaMultaBp(999)
            ->setBaseMulta(BaseEncargo::Composta)
            ->setTaxaCorrecaoBp(999)
            ->setBaseCorrecao(BaseEncargo::Composta)
            ->setTaxaHonorariosBp(999)
            ->setBaseHonorarios(BaseEncargo::Composta)
            ->setCarenciaHonorariosDias(99)
            ->setToleranciaJurosMultaDias(99);

        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn($objeto);
        $this->objetoRepository->expects($this->once())->method('salvar')->with($objeto, true);

        // Input "vazio" (todos os overrides null) — o gestor limpou os campos para voltar a herdar.
        $input = new EditarConfiguracaoObjetoInput();
        $input->objetoId = 9;

        $resultado = $this->sut->executar($input, $this->tenant);

        self::assertNull($resultado->getTaxaJurosMensalBp());
        self::assertNull($resultado->getRegimeJuros());
        self::assertNull($resultado->getTaxaMultaBp());
        self::assertNull($resultado->getBaseMulta());
        self::assertNull($resultado->getTaxaCorrecaoBp());
        self::assertNull($resultado->getBaseCorrecao());
        self::assertNull($resultado->getTaxaHonorariosBp());
        self::assertNull($resultado->getBaseHonorarios());
        self::assertNull($resultado->getCarenciaHonorariosDias());
        self::assertNull($resultado->getToleranciaJurosMultaDias());
    }

    #[Test]
    public function rejeitaQuandoObjetoNaoExisteNoTenantENaoSalva(): void
    {
        // Objeto inexistente OU de outro escritório: findOneByIdDoTenant devolve null (guarda multi-tenant).
        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->objetoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObjetoNaoEncontradoException::class);

        $this->sut->executar($this->inputComOverrides(), $this->tenant);
    }

    private function inputComOverrides(): EditarConfiguracaoObjetoInput
    {
        $input = new EditarConfiguracaoObjetoInput();
        $input->objetoId = 9;
        $input->taxaJurosMensalBp = 150;
        $input->regimeJuros = RegimeJuros::Composto;
        $input->taxaMultaBp = 300;
        $input->baseMulta = BaseEncargo::Composta;
        $input->taxaCorrecaoBp = 80;
        $input->baseCorrecao = BaseEncargo::Composta;
        $input->taxaHonorariosBp = 2500;
        $input->baseHonorarios = BaseEncargo::Principal;
        $input->carenciaHonorariosDias = 15;
        $input->toleranciaJurosMultaDias = 5;

        return $input;
    }
}
