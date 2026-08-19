<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ReconciliadorLiquidacao;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O honorário entra no exigível (spec `cobranca-honorario-no-total.md`) — a contabilidade soma
 * `principal + juros + multa + honorários`, e o sistema copia o total dela. **INV-E2 revogada.**
 *
 * 🔴 **Por que este arquivo existe, e por que ele tem um teste POR CÓPIA:** a fórmula do exigível
 * estava escrita em TRÊS lugares (spec §2) — o método da entidade, o cálculo ao vivo do
 * `EncargosVivos` (por onde o FIFO enxerga a dívida) e a soma inline do `ReconciliadorLiquidacao`
 * (que decide quitar ou reabrir). Um teste que exercitasse só o método passaria verde com as outras
 * duas erradas, e o sistema ficaria com o saldo dizendo uma coisa e a quitação dizendo outra, **em
 * silêncio**. Cada teste aqui morre se a sua cópia voltar a somar sem o honorário.
 *
 * Números de todos os casos, config TOPLIFE I (juros 1% a.m., multa 2% do principal, honorários 20%
 * sobre base composta, carência 30 dias, tolerância 0):
 *   P=170,00 · venc 22/11/2025 · ref 20/07/2026 → 240 dias
 *   juros 13,60 · multa 3,40 · correção 0 · honorários (170,00+13,60+3,40)·20% = 37,40
 *   exigível = 224,40   (antes desta spec: 187,00)
 */
#[CoversClass(Obrigacao::class)]
#[CoversClass(EncargosVivos::class)]
#[CoversClass(ReconciliadorLiquidacao::class)]
final class HonorarioNoExigivelTest extends TestCase
{
    private const REF = '2026-07-20';

    #[TestDox('CÓPIA 1 (entidade): valorExigivel() soma o honorário materializado')]
    public function testCopia1EntidadeSomaHonorario(): void
    {
        $o = $this->obrigacao(17000, '2025-11-22');
        $o->definirEncargos(1360, 340, 0, 3740, $this->ref());

        self::assertSame(22440, $o->valorExigivel(), 'exigível tem de incluir os 37,40 de honorário');
    }

    #[TestDox('CÓPIA 2 (EncargosVivos): o exigível VIVO — o que o FIFO enxerga — soma o honorário')]
    public function testCopia2ExigivelVivoSomaHonorario(): void
    {
        $sut = new EncargosVivos(new MockClock($this->ref()), new CalculadoraEncargos(), new ResolvedorConfigEncargos());
        $o = $this->obrigacao(17000, '2025-11-22');

        // `exigivelVivo` NÃO muta a entidade: é o caminho de leitura da alocação FIFO.
        self::assertSame(22440, $sut->exigivelVivo($this->config(), $o, $this->ref()));
        self::assertSame(0, $o->getHonorarios(), 'exigivelVivo não pode materializar nada');
    }

    #[TestDox('CÓPIA 2 (EncargosVivos): a hidratação em memória também leva o honorário ao exigível')]
    public function testCopia2HidratacaoSomaHonorario(): void
    {
        $sut = new EncargosVivos(new MockClock($this->ref()), new CalculadoraEncargos(), new ResolvedorConfigEncargos());
        $o = $this->obrigacao(17000, '2025-11-22');

        $sut->hidratar($this->config(), [$o]);

        self::assertSame(3740, $o->getHonorarios());
        self::assertSame(22440, $o->valorExigivel());
    }

    #[TestDox('CÓPIA 3 (ReconciliadorLiquidacao): pagar principal+juros+multa NÃO quita quem deve honorário')]
    public function testCopia3NaoQuitaSemPagarHonorario(): void
    {
        $o = $this->obrigacao(17000, '2025-11-22');

        // 187,00 = principal + juros + multa. Era exatamente o que quitava antes desta spec.
        $this->reconciliador()->reconciliar($this->config(), [$o], [$this->idDe($o) => 18700], $this->ref());

        self::assertFalse($o->estaLiquidada(), 'faltam os 37,40 de honorário — a dívida segue aberta');
        self::assertFalse($o->encargosCongelados());
    }

    #[TestDox('CÓPIA 3 (ReconciliadorLiquidacao): pagando o total COM honorário, quita')]
    public function testCopia3QuitaPagandoTotalComHonorario(): void
    {
        $o = $this->obrigacao(17000, '2025-11-22');

        $this->reconciliador()->reconciliar($this->config(), [$o], [$this->idDe($o) => 22440], $this->ref());

        self::assertTrue($o->estaLiquidada());
        self::assertTrue($o->encargosCongelados());
        self::assertSame(3740, $o->getHonorarios(), 'o snapshot guarda o honorário que foi cobrado');
        self::assertSame(22440, $o->valorExigivel());
    }

    #[TestDox('CÓPIA 3 (ReconciliadorLiquidacao): quitada que deixa de cobrir o total com honorário REABRE')]
    public function testCopia3ReabreQuandoAlocadoNaoCobreOHonorario(): void
    {
        $o = $this->obrigacao(17000, '2025-11-22');
        $this->reconciliador()->reconciliar($this->config(), [$o], [$this->idDe($o) => 22440], $this->ref());
        self::assertTrue($o->estaLiquidada(), 'pré-condição: nasce quitada');

        // Correção do pagamento derrubou o alocado para o total SEM honorário.
        $this->reconciliador()->reconciliar($this->config(), [$o], [$this->idDe($o) => 18700], $this->ref());

        self::assertFalse($o->estaLiquidada(), 'o honorário descoberto reabre a dívida');
    }

    #[TestDox('Carência: dentro dos 30 dias não há honorário, e o total NÃO muda')]
    public function testCarenciaDeTrintaDiasNaoMudaOTotal(): void
    {
        // P=100,00, venc 20/06/2026 → exatamente 30 dias em 20/07: juros 1,00 + multa 2,00,
        // honorário 0 (corte ESTRITO: dias <= carência). O exigível segue 103,00, como antes.
        $sut = new EncargosVivos(new MockClock($this->ref()), new CalculadoraEncargos(), new ResolvedorConfigEncargos());
        $o = $this->obrigacao(10000, '2026-06-20');

        self::assertSame(10300, $sut->exigivelVivo($this->config(), $o, $this->ref()));

        $this->reconciliador()->reconciliar($this->config(), [$o], [$this->idDe($o) => 10300], $this->ref());
        self::assertTrue($o->estaLiquidada(), 'sem honorário a cobrar, 103,00 continua quitando');
        self::assertSame(0, $o->getHonorarios());
    }

    #[TestDox('As três cópias concordam entre si na MESMA obrigação e na MESMA data')]
    public function testAsTresCopiasConcordam(): void
    {
        $sut = new EncargosVivos(new MockClock($this->ref()), new CalculadoraEncargos(), new ResolvedorConfigEncargos());
        $o = $this->obrigacao(17000, '2025-11-22');

        $vivo = $sut->exigivelVivo($this->config(), $o, $this->ref());
        $sut->hidratar($this->config(), [$o]);
        $daEntidade = $o->valorExigivel();

        $quitavel = $this->obrigacao(17000, '2025-11-22');
        $this->reconciliador()->reconciliar($this->config(), [$quitavel], [$this->idDe($quitavel) => $vivo], $this->ref());

        self::assertSame($vivo, $daEntidade, 'cópia 2 e cópia 1 têm de dar o mesmo número');
        self::assertTrue($quitavel->estaLiquidada(), 'cópia 3 tem de aceitar como quitação o número das outras duas');
    }

    private function reconciliador(): ReconciliadorLiquidacao
    {
        return new ReconciliadorLiquidacao(new CalculadoraEncargos(), new ResolvedorConfigEncargos());
    }

    private function config(): ConfigEncargos
    {
        return ConfigEncargos::padraoTopLife(2000);
    }

    private function ref(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::REF);
    }

    private function obrigacao(int $valorOriginal, string $venc): Obrigacao
    {
        static $seq = 0;
        $o = (new Obrigacao())
            ->setDescricao('Obrigação')
            ->setValorOriginal($valorOriginal)
            ->setVencimentoOriginal(new \DateTimeImmutable($venc));
        (new \ReflectionProperty(Obrigacao::class, 'id'))->setValue($o, ++$seq);

        return $o;
    }

    private function idDe(Obrigacao $o): int
    {
        return (int) $o->getId();
    }
}
