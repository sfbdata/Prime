<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AlocacaoPagamentoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\AlocadorPagamento;
use App\Cobranca\Service\AutoAlocadorFifo;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A OUTRA METADE da opção A (spec `cobranca-honorario-no-total.md` §4.3/§4.4): com o honorário DENTRO
 * do exigível, o caminho do pagamento não pode mais retirá-lo antes de abater.
 *
 * 🔴 **É o teste que impede a quebra funcional.** Enquanto o exigível exigia honorário e o rateio da
 * SPEC §18 (`ratearPagamento`) o carvava fora do valor distribuído, a dívida ficava permanentemente
 * curta pelo exato valor do honorário — **nenhuma dívida quitava**, e nenhum teste existente pegava
 * isso, porque todos afirmavam o comportamento ANTIGO (honorário fora do exigível).
 *
 * O que a medição de produção mostrou (§4.4) e este teste passa a garantir no código: dos 1.154
 * pagamentos em que os dois modelos divergem, **1.154 alocam o valor cheio** — o importador de
 * receitas nunca passou pelo rateio. Esta fatia alinha o caminho MANUAL ao que o importador já faz.
 */
#[CoversClass(AlocadorPagamento::class)]
#[CoversClass(AutoAlocadorFifo::class)]
final class HonorarioNoCaminhoDoPagamentoTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
    }

    #[TestDox('AlocadorPagamento: a Σ das alocações fecha com o valor CHEIO pago, não com uma parte dele')]
    public function testAlocadorFechaComOValorCheio(): void
    {
        $caso = $this->caso('10.00');
        $repo = $this->createMock(ObrigacaoRepository::class);
        $repo->method('findOneByIdDoTenant')->willReturn((new Obrigacao())->setTenant($this->tenant)->setCaso($caso));
        $sut = new AlocadorPagamento($repo);

        $item = new AlocacaoPagamentoInput();
        $item->obrigacaoId = 5;
        $item->valor = 1100;

        // Antes: 1100 pagos → rateio tirava 100 de honorário e exigia Σ alocações == 1000.
        [$valorDivida, $valorHonorarios, $alocacoes] = $sut->montar($caso, 1100, [$item], $this->tenant);

        self::assertSame(1100, $valorDivida, 'o valor pago abate a dívida por inteiro');
        self::assertSame(0, $valorHonorarios, 'o sistema não inventa split — quem declara é a contabilidade');
        self::assertSame(1100, $alocacoes[0]->getValor());
    }

    #[TestDox('AutoAlocadorFifo: distribui o valor CHEIO pelas dívidas, sem carvar honorário fora')]
    public function testFifoDistribuiOValorCheio(): void
    {
        $caso = $this->caso('10.00');
        $sut = $this->fifo([
            $this->obrigacao(10, 60000, '2026-07-18'),
            $this->obrigacao(11, 60000, '2026-07-19'),
        ]);

        // Antes: 110000 pagos → só 100000 eram distribuídos e 10000 sumiam do abatimento.
        $previa = $sut->derivar($caso, 110000, $this->tenant);

        self::assertFalse($previa->excede);
        self::assertSame(110000, $previa->valorDivida);
        self::assertSame(0, $previa->valorHonorarios);
        self::assertSame(110000, array_sum(array_map(static fn ($l) => $l->valor, $previa->linhas)));
    }

    #[TestDox('🔴 A dívida QUITA: pagando o exigível com honorário dentro, o FIFO cobre tudo')]
    public function testDividaComHonorarioQuitaComOValorCheio(): void
    {
        // P=170,00, venc 22/11/2025, ref 20/07/2026 → 240 dias. Config TOPLIFE I (20%):
        // juros 13,60 · multa 3,40 · honorários 37,40 → exigível 224,40.
        $caso = $this->caso('20.00');
        $sut = $this->fifo([$this->obrigacao(10, 17000, '2025-11-22')]);

        $previa = $sut->derivar($caso, 22440, $this->tenant);

        self::assertFalse($previa->excede, 'pagar o exigível cheio não pode "exceder o saldo"');
        self::assertSame(22440, array_sum(array_map(static fn ($l) => $l->valor, $previa->linhas)),
            'o pagamento tem de cobrir os 224,40 — com o rateio antigo cobriria só 187,00 e a dívida nunca quitaria');
    }

    private function caso(string $percentual): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios($percentual)
            ->setTaxaJurosMensalBp(100)
            ->setTaxaMultaBp(200)
            ->setCarenciaHonorariosDias(30);
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setObjeto((new ObjetoCobranca())->setCarteira($carteira));
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 1);

        return $caso;
    }

    /** @param list<Obrigacao> $exigiveis */
    private function fifo(array $exigiveis): AutoAlocadorFifo
    {
        $obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $obrigacaoRepository->method('doCasoExigiveis')->willReturn($exigiveis);
        $alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);
        $liquidacaoRepository = $this->createMock(LiquidacaoRepository::class);
        $liquidacaoRepository->method('totalReconhecidoNoCaso')->willReturn(0);

        return new AutoAlocadorFifo(
            $obrigacaoRepository,
            $alocacaoRepository,
            $liquidacaoRepository,
            new EncargosVivos(new MockClock(new \DateTimeImmutable('2026-07-20')), new CalculadoraEncargos(), new ResolvedorConfigEncargos()),
            new ResolvedorConfigEncargos(),
        );
    }

    private function obrigacao(int $id, int $valor, string $venc): Obrigacao
    {
        $o = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setDescricao('Obrigação')
            ->setValorOriginal($valor)
            ->setVencimentoOriginal(new \DateTimeImmutable($venc));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, $id);

        return $o;
    }
}
