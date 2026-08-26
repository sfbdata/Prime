<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Service\CalculadoraHonorarios;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * #9-T2 (cascata de encargos ao vivo sem snapshot): a calculadora resolve a política de honorários do
 * caso via `caso→objeto→carteira` (a FORMA sempre da carteira; a ALÍQUOTA da cascata do objeto), não
 * mais do snapshot do caso. O helper `caso()` monta um grafo Carteira→Objeto→Caso em memória —
 * qualquer teste que passar por ele fica automaticamente sob a nova fonte, sem mudar comportamento
 * para dados "frescos" (sem override no objeto): é exatamente o cenário legado que a T2 corrige.
 */
#[CoversClass(CalculadoraHonorarios::class)]
final class CalculadoraHonorariosTest extends TestCase
{
    private CalculadoraHonorarios $sut;

    protected function setUp(): void
    {
        $this->sut = new CalculadoraHonorarios(new ResolvedorConfigEncargos());
    }

    // ---- realizadosSobreRecuperacao --------------------------------------

    #[Test]
    #[DataProvider('cenariosRealizados')]
    public function realizadosPorForma(FormaHonorarios $forma, ?string $percentual, int $recuperado, int $esperado): void
    {
        $caso = $this->caso($forma, $percentual);

        self::assertSame($esperado, $this->sut->realizadosSobreRecuperacao($caso, $recuperado));
    }

    /**
     * @return iterable<string, array{0:FormaHonorarios,1:?string,2:int,3:int}>
     */
    public static function cenariosRealizados(): iterable
    {
        yield 'acrescido 10% de 1000' => [FormaHonorarios::AcrescidoDivida, '10.00', 1000, 100];
        yield 'retido 20% de 5000' => [FormaHonorarios::RetidoRecuperado, '20.00', 5000, 1000];
        yield 'cobrado separado 15% de 2000' => [FormaHonorarios::CobradoSeparado, '15.00', 2000, 300];
        yield 'sem percentual' => [FormaHonorarios::SemPercentual, null, 5000, 0];
        yield 'recuperado zero' => [FormaHonorarios::AcrescidoDivida, '10.00', 0, 0];
    }

    // ---- brutoParaRecuperar -----------------------------------------------

    /**
     * I-1 (T2): a alíquota efetiva de um caso SEM override no objeto vem da CARTEIRA, nunca do
     * snapshot morto do próprio caso — o cenário dos 194 casos legados fotografados a 10% enquanto a
     * carteira já estava a 20% (spec §2/§9).
     *
     * ⚠️ Este teste conferia DOIS caminhos: o exigível e o split de pagamento. O split deixou de
     * existir (spec `cobranca-honorario-no-total.md` §4.3 — `ratearPagamento` foi apagado), e com ele
     * a própria divergência I-1: agora há UMA fonte só. O que sobra é a guarda de que o snapshot
     * morto do caso não volta a ser lido.
     */
    #[Test]
    #[TestDox('I-1: a alíquota efetiva vem da CARTEIRA, ignorando o snapshot divergente do caso')]
    public function aAliquotaEfetivaVemDaCarteiraIgnorandoOSnapshotDivergenteDoCaso(): void
    {
        $carteira = (new Carteira())
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00');
        // Objeto SEM override — legado: herda a carteira integralmente.
        $objeto = (new ObjetoCobranca())->setCarteira($carteira);
        $caso = (new CasoCobranca())->setObjeto($objeto);

        // Snapshot MORTO do caso, DIVERGENTE (10%) — simula os 194 casos legados fotografados a 10%
        // enquanto a carteira já está a 20%. Se o resolvedor voltasse a lê-lo, o teste tem de acusar.
        $caso->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)->setPercentualHonorarios('10.00');

        $resolvedor = new ResolvedorConfigEncargos();

        // A alíquota efetiva do objeto/carteira é 2000 bp (20%), não os 1000 bp (10%) do snapshot
        // morto do caso. É a MESMA fonte que o exigível usa ao materializar o honorário.
        self::assertSame(2000, $resolvedor->resolverDoObjeto($objeto)->taxaHonorariosBp);

        // E a FORMA, que segue vindo da carteira, continua legível pela calculadora.
        self::assertSame(FormaHonorarios::AcrescidoDivida, $this->sut->forma($caso));
    }

    private function caso(FormaHonorarios $forma, ?string $percentual): CasoCobranca
    {
        $carteira = (new Carteira())
            ->setFormaHonorarios($forma)
            ->setPercentualHonorarios($percentual);
        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        return (new CasoCobranca())->setObjeto($objeto);
    }
}
