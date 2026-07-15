<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\UseCase\MontarDetalheAcordoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Detalhe do Acordo (Ajuste 7, Fatia 3): leitura derivada — total/entrada saem do snapshot (com
 * fallback derivado nos acordos antigos), desconto/juros é SEMPRE derivado (Σ substituídas − total)
 * e o "quitada" de cada parcela vem do alocado real (invariável 20: nada de saldo em coluna).
 */
#[CoversClass(MontarDetalheAcordoUseCase::class)]
final class MontarDetalheAcordoUseCaseTest extends TestCase
{
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private MontarDetalheAcordoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);
        $this->sut = new MontarDetalheAcordoUseCase($this->alocacaoRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function montaDetalheComSnapshotParcelasEDescontoDerivado(): void
    {
        $caso = $this->novoCaso(50, 77);
        $acordo = $this->novoAcordo($caso, valorTotalNegociado: 100000, valorEntrada: 40000);

        // Parcelas: entrada 400,00 (quitada) + 2 × 300,00 (a 1ª parcialmente paga).
        $entrada = $this->novaParcela($caso, $acordo, 1, 'Entrada', 40000, '2026-08-01');
        $p1 = $this->novaParcela($caso, $acordo, 2, 'Parcela 1/2', 30000, '2026-09-01');
        $p2 = $this->novaParcela($caso, $acordo, 3, 'Parcela 2/2', 30000, '2026-10-01');

        // Substituídas: Σ exigível 1.200,00 → desconto = 1200 − 1000 = 200,00.
        $this->novaSubstituida($caso, $acordo, 10, 'Dívida A', 100000, 20000);

        $this->alocacaoRepository
            ->expects($this->once())
            ->method('somasPorObrigacaoDosCasos')
            ->with([50], $this->tenant)
            ->willReturn([1 => 40000, 2 => 10000]);

        $detalhe = $this->sut->executar($acordo, $this->tenant);

        self::assertSame(7, $detalhe->id);
        self::assertSame(77, $detalhe->objetoId);
        self::assertSame(100000, $detalhe->valorTotalNegociado);
        self::assertSame(40000, $detalhe->valorEntrada);
        self::assertSame(120000, $detalhe->valorSubstituidas);
        self::assertSame(20000, $detalhe->valorDesconto, 'desconto = Σ substituídas − total');
        self::assertFalse($detalhe->temJuros);

        self::assertCount(3, $detalhe->parcelas);
        // Entrada: alocado == valor → quitada.
        self::assertSame('Entrada', $detalhe->parcelas[0]->descricao);
        self::assertSame(40000, $detalhe->parcelas[0]->alocado);
        self::assertTrue($detalhe->parcelas[0]->quitada);
        // Parcela parcialmente paga não é quitada.
        self::assertSame(10000, $detalhe->parcelas[1]->alocado);
        self::assertFalse($detalhe->parcelas[1]->quitada);
        // Parcela sem pagamento: alocado 0.
        self::assertSame(0, $detalhe->parcelas[2]->alocado);
        self::assertFalse($detalhe->parcelas[2]->quitada);
        self::assertSame(50000, $detalhe->totalAlocado);

        self::assertCount(1, $detalhe->substituidas);
        self::assertSame('Dívida A', $detalhe->substituidas[0]->descricao);
        self::assertSame(120000, $detalhe->substituidas[0]->valor, 'exigível = original + encargos');

        unset($entrada, $p1, $p2);
    }

    #[Test]
    public function derivaTotalQuandoAcordoAntigoNaoTemSnapshot(): void
    {
        // Acordo anterior ao Ajuste 7: snapshot nulo → total = Σ parcelas; entrada 0.
        $caso = $this->novoCaso(50, 77);
        $acordo = $this->novoAcordo($caso, valorTotalNegociado: null, valorEntrada: 0);
        $this->novaParcela($caso, $acordo, 1, 'Parcela 1/2', 25000, '2026-09-01');
        $this->novaParcela($caso, $acordo, 2, 'Parcela 2/2', 25000, '2026-10-01');
        $this->novaSubstituida($caso, $acordo, 10, 'Dívida A', 60000, 0);

        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);

        $detalhe = $this->sut->executar($acordo, $this->tenant);

        self::assertSame(50000, $detalhe->valorTotalNegociado, 'total derivado da soma das parcelas');
        self::assertSame(0, $detalhe->valorEntrada);
        self::assertSame(10000, $detalhe->valorDesconto);
        self::assertSame(0, $detalhe->totalAlocado);
    }

    #[Test]
    public function marcaJurosQuandoTotalSuperaAsSubstituidas(): void
    {
        $caso = $this->novoCaso(50, 77);
        $acordo = $this->novoAcordo($caso, valorTotalNegociado: 70000, valorEntrada: 0);
        $this->novaParcela($caso, $acordo, 1, 'Parcela 1/1', 70000, '2026-09-01');
        $this->novaSubstituida($caso, $acordo, 10, 'Dívida A', 50000, 0);

        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);

        $detalhe = $this->sut->executar($acordo, $this->tenant);

        // Total negociado acima da dívida original = juros (desconto negativo).
        self::assertSame(-20000, $detalhe->valorDesconto);
        self::assertTrue($detalhe->temJuros);
    }

    #[Test]
    public function naoAnunciaJurosQuandoNaoHaDividaSubstituida(): void
    {
        // Sem substituídas não há dívida original para comparar: desconto = -total NÃO é "juros".
        $caso = $this->novoCaso(50, 77);
        $acordo = $this->novoAcordo($caso, valorTotalNegociado: 70000, valorEntrada: 0);
        $this->novaParcela($caso, $acordo, 1, 'Parcela 1/1', 70000, '2026-09-01');

        $this->alocacaoRepository->method('somasPorObrigacaoDosCasos')->willReturn([]);

        $detalhe = $this->sut->executar($acordo, $this->tenant);

        self::assertSame(0, $detalhe->valorSubstituidas);
        self::assertFalse($detalhe->temJuros, 'sem substituídas a tela não pode anunciar juros');
    }

    private function novoCaso(int $casoId, int $objetoId): CasoCobranca
    {
        $objeto = new ObjetoCobranca();
        (new \ReflectionProperty(ObjetoCobranca::class, 'id'))->setValue($objeto, $objetoId);
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setObjeto($objeto);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, $casoId);

        return $caso;
    }

    private function novoAcordo(CasoCobranca $caso, ?int $valorTotalNegociado, int $valorEntrada): Acordo
    {
        $acordo = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordo->setDataAcordo(new \DateTimeImmutable('2026-08-01'));
        $acordo->setValorTotalNegociado($valorTotalNegociado);
        $acordo->setValorEntrada($valorEntrada);
        (new \ReflectionProperty(Acordo::class, 'id'))->setValue($acordo, 7);

        return $acordo;
    }

    private function novaParcela(CasoCobranca $caso, Acordo $acordo, int $id, string $descricao, int $valor, string $vencimento): Obrigacao
    {
        $o = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao($descricao)
            ->setValorOriginal($valor)
            ->setVencimentoOriginal(new \DateTimeImmutable($vencimento))
            ->setAcordoOrigem($acordo);
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, $id);
        // Coleção inversa: no runtime o Doctrine popula; no unit, alimenta-se em memória.
        $acordo->getParcelas()->add($o);

        return $o;
    }

    private function novaSubstituida(CasoCobranca $caso, Acordo $acordo, int $id, string $descricao, int $valor, int $encargos): Obrigacao
    {
        $o = (new Obrigacao())
            ->setTenant($this->tenant)
            ->setCaso($caso)
            ->setDescricao($descricao)
            ->setValorOriginal($valor)
            ->setEncargosReconhecidos($encargos)
            ->setVencimentoOriginal(new \DateTimeImmutable('2026-01-10'))
            ->setAcordoSubstituto($acordo);
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, $id);
        $acordo->getObrigacoesSubstituidas()->add($o);

        return $o;
    }
}
