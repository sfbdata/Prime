<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\PagamentoInconsistenteException;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AlocadorPagamento::class)]
final class AlocadorPagamentoTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private AlocadorPagamento $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        // CalculadoraHonorarios é final e pura: usa-se a REAL dentro do alocador REAL.
        $this->sut = new AlocadorPagamento($this->obrigacaoRepository, new CalculadoraHonorarios());
        $this->tenant = new Tenant();
    }

    #[Test]
    public function rateiaAcrescidoDividaSeparandoHonorariosEFechando(): void
    {
        // Caso 10% na forma acrescido_divida: bruto 1100 → dívida 1000 + honorários 100.
        $caso = (new CasoCobranca())
            ->setTenant($this->tenant)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('10.00');

        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        $item = new AlocacaoPagamentoInput();
        $item->obrigacaoId = 5;
        $item->valor = 1000;

        [$valorDivida, $valorHonorarios, $alocacoes] = $this->sut->montar($caso, 1100, [$item], $this->tenant);

        self::assertSame(1000, $valorDivida);
        self::assertSame(100, $valorHonorarios);
        self::assertCount(1, $alocacoes);
        self::assertSame(1000, $alocacoes[0]->getValor());
        self::assertSame($this->tenant, $alocacoes[0]->getTenant());
        self::assertSame($obrigacao, $alocacoes[0]->getObrigacao());
    }

    #[Test]
    public function semPercentualAlocaTudoNaDivida(): void
    {
        // sem_percentual (default): o devedor paga só a dívida → honorários 0.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        $item = new AlocacaoPagamentoInput();
        $item->obrigacaoId = 7;
        $item->valor = 5000;

        [$valorDivida, $valorHonorarios, $alocacoes] = $this->sut->montar($caso, 5000, [$item], $this->tenant);

        self::assertSame(5000, $valorDivida);
        self::assertSame(0, $valorHonorarios);
        self::assertCount(1, $alocacoes);
        self::assertSame(5000, $alocacoes[0]->getValor());
    }

    #[Test]
    public function rejeitaObrigacaoDeOutroCaso(): void
    {
        // Invariável 12: obrigação de OUTRO caso (outra instância) não pode ser alocada.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $outroCaso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($outroCaso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        $item = new AlocacaoPagamentoInput();
        $item->obrigacaoId = 9;
        $item->valor = 5000;

        $this->expectException(ObrigacaoDeOutroCasoException::class);

        $this->sut->montar($caso, 5000, [$item], $this->tenant);
    }

    #[Test]
    public function rejeitaQuandoSomaNaoFechaComParteDaDivida(): void
    {
        // Invariável 20: Σ das alocações (4000) diverge da parte da dívida (5000).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        $item = new AlocacaoPagamentoInput();
        $item->obrigacaoId = 11;
        $item->valor = 4000;

        $this->expectException(PagamentoInconsistenteException::class);

        $this->sut->montar($caso, 5000, [$item], $this->tenant);
    }

    #[Test]
    public function rejeitaObrigacaoInexistenteOuDeOutroTenant(): void
    {
        // findOneByIdDoTenant devolve null (id inexistente ou de outro escritório).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn(null);

        $item = new AlocacaoPagamentoInput();
        $item->obrigacaoId = 999;
        $item->valor = 5000;

        $this->expectException(ObrigacaoNaoEncontradaException::class);

        $this->sut->montar($caso, 5000, [$item], $this->tenant);
    }
}
