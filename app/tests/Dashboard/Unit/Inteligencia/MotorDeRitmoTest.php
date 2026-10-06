<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\Inteligencia\Leitura;
use App\Dashboard\Inteligencia\MotorDeRitmo;
use App\Dashboard\Inteligencia\TempoDoPeriodo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regras do motor de ritmo com os números e limiares de bluejus-intelligence.js, incluindo
 * as bordas (> 0,3 de entrada × saída, > 0,45 de sobrecarga) e o caso sem período, em que
 * só entram as regras que não dependem de contar dias.
 */
#[CoversClass(MotorDeRitmo::class)]
#[Group('dashboard')]
final class MotorDeRitmoTest extends TestCase
{
    private MotorDeRitmo $sut;

    protected function setUp(): void
    {
        $this->sut = new MotorDeRitmo();
    }

    private function linha(int $id, string $nome, int $total, int $ativas, int $vencidas = 0, int $prazos = 0): LinhaAdvogadoDashboardOutput
    {
        return new LinhaAdvogadoDashboardOutput(
            userId:         $id,
            nomeAdvogado:   $nome,
            cargoNome:      'Advogado(a)',
            fotoUrl:        null,
            totalMetas:     $total,
            metasAtivas:    $ativas,
            metasVencidas:  $vencidas,
            prazosProximos: $prazos,
            totalDemandas:  0,
            demandasAtivas: 0,
            pastasCriadas:  0,
        );
    }

    /** @param LinhaAdvogadoDashboardOutput[] $linhas */
    private function dashboard(array $linhas): DashboardOutput
    {
        return new DashboardOutput(0, 0, 0, $linhas);
    }

    /** Março/2024 visto do dia 10: 10 passados, 21 restantes. */
    private function dia10(): TempoDoPeriodo
    {
        $t = TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-03-10'));
        self::assertNotNull($t);

        return $t;
    }

    /** @param Leitura[] $leituras */
    private function tipos(array $leituras): array
    {
        return array_map(static fn (Leitura $l): string => $l->tipo, $leituras);
    }

    // ── números-base ─────────────────────────────────────────────────────

    #[TestDox('Os números-base são as somas das linhas: concluídas = total − ativas (dadosDe do desenho)')]
    public function testSomaAsLinhasComoOProtótipo(): void
    {
        $r = $this->sut->analisar($this->dashboard([
            $this->linha(1, 'Ana Lima', 10, 4, 2, 1),
            $this->linha(2, 'Bruno Melo', 4, 3, 1, 2),
        ]), null);

        self::assertSame(14, $r->novas);
        self::assertSame(7, $r->ativas);
        self::assertSame(7, $r->concluidas);
        self::assertSame(3, $r->vencidas);
        self::assertSame(3, $r->prazos);
    }

    // ── sem período ──────────────────────────────────────────────────────

    #[TestDox('Sem período: nada por dia, sem "o que está acontecendo", só a ação de reavaliar, e o limite declarado')]
    public function testSemPeriodoSoEntramRegrasQueNaoContamDias(): void
    {
        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 10, 4)]), null);

        self::assertNull($r->tempo);
        self::assertNull($r->ritmoAtual);
        self::assertNull($r->novasPorDia);
        self::assertNull($r->entradaVsSaida);
        self::assertNull($r->oQue);
        self::assertSame([], $r->porQue, 'sem dia contado não se afirma equilíbrio entre entrada e saída');
        self::assertSame([MotorDeRitmo::TIPO_REAVALIAR], $this->tipos($r->acoes));
        self::assertSame([], $r->riscos);
        self::assertFalse($r->alerta);
        self::assertCount(2, $r->limites);
        self::assertStringContainsString('não há dia a contar', $r->limites[1]);
    }

    #[TestDox('Sem período, vencidas e prazos continuam entrando (são relativos a hoje)')]
    public function testSemPeriodoVencidasEPrazosEntram(): void
    {
        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 10, 4, 2, 3)]), null);

        self::assertSame([MotorDeRitmo::TIPO_VENCIDAS, MotorDeRitmo::TIPO_PRAZOS, MotorDeRitmo::TIPO_REAVALIAR], $this->tipos($r->acoes));
        self::assertSame([MotorDeRitmo::TIPO_VENCIDAS, MotorDeRitmo::TIPO_PRAZOS], $this->tipos($r->riscos));
        self::assertSame([MotorDeRitmo::TIPO_VENCIDAS], $this->tipos($r->porQue));
        self::assertTrue($r->alerta);
    }

    // ── ritmo por dia e entrada × saída (L29, L39, L81, L93, L104) ──────────

    #[TestDox('Dia 10: 14 novas e 10 concluídas dão 1,4 e 1,0 por dia; 0,4 > 0,3 → a fila cresce')]
    public function testEntradaMaiorQueSaidaAcimaDoLimiar(): void
    {
        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 14, 4)]), $this->dia10());

        self::assertEqualsWithDelta(1.0, $r->ritmoAtual, 1e-9);
        self::assertEqualsWithDelta(1.4, $r->novasPorDia, 1e-9);
        self::assertEqualsWithDelta(0.4, $r->entradaVsSaida, 1e-9);

        self::assertSame([MotorDeRitmo::TIPO_ENTRADA_VS_SAIDA], $this->tipos($r->porQue));
        self::assertSame(
            'Entram mais metas por dia (1,4) do que são concluídas (1), o que faz a fila crescer.',
            $r->porQue[0]->texto,
        );
        self::assertSame(['novas_por_dia' => 1.4, 'concluidas_por_dia' => 1.0], $r->porQue[0]->numeros);

        self::assertContains(MotorDeRitmo::TIPO_SEGURAR_ENTRADA, $this->tipos($r->acoes));
        self::assertContains(MotorDeRitmo::TIPO_ENTRADA_VS_SAIDA, $this->tipos($r->riscos));
        self::assertSame('Fila de metas crescendo mais rápido do que a equipe conclui', $r->riscos[0]->texto);
        self::assertSame(MotorDeRitmo::NIVEL_ENTRADA, $r->riscos[0]->severidade);
    }

    #[TestDox('Dia 10: 12 novas e 10 concluídas (0,2 ≤ 0,3) → entrada e saída equilibradas')]
    public function testEntradaAbaixoDoLimiarEEquilibrio(): void
    {
        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 12, 2)]), $this->dia10());

        self::assertEqualsWithDelta(0.2, $r->entradaVsSaida, 1e-9);
        self::assertSame([MotorDeRitmo::TIPO_EQUILIBRIO], $this->tipos($r->porQue));
        self::assertSame('Entrada e saída de metas estão equilibradas no período.', $r->porQue[0]->texto);
        self::assertSame([MotorDeRitmo::TIPO_REAVALIAR], $this->tipos($r->acoes));
        self::assertSame([], $r->riscos);
    }

    #[TestDox('Antes de o período começar (0 dias passados) o ritmo é 0 e nada por dia dispara')]
    public function testPeriodoAindaNaoComecou(): void
    {
        $t = TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-02-20'));
        self::assertNotNull($t);

        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 14, 4)]), $t);

        self::assertSame(0.0, $r->ritmoAtual);
        self::assertSame(0.0, $r->entradaVsSaida);
        self::assertSame([MotorDeRitmo::TIPO_EQUILIBRIO], $this->tipos($r->porQue));
        self::assertNotNull($r->oQue);
        self::assertStringStartsWith('Início do período: dia 0 de 31.', $r->oQue->texto);
    }

    // ── vencidas e prazos (L82, L89-90, L101-102) ────────────────────────

    #[TestDox('3 vencidas: "Priorizar 3 metas vencidas." vem primeiro (peso 100), risco nível 3, e o porquê explica')]
    public function testVencidasNoPlural(): void
    {
        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 10, 4, 3)]), null);

        $acao = $r->oQueFazer();
        self::assertNotNull($acao);
        self::assertSame('Priorizar 3 metas vencidas.', $acao->texto);
        self::assertSame(MotorDeRitmo::PESO_VENCIDAS, $acao->severidade);
        self::assertSame(MotorDeRitmo::GRUPO_ATENCAO_IMEDIATA, $acao->grupo);
        self::assertSame(['vencidas' => 3], $acao->numeros);

        self::assertSame('3 metas vencidas', $r->riscos[0]->texto);
        self::assertSame(MotorDeRitmo::NIVEL_VENCIDAS, $r->riscos[0]->severidade);
        self::assertSame('3 metas vencidas seguram a taxa de conclusão.', $r->porQue[0]->texto);
        self::assertTrue($r->alerta);
    }

    #[TestDox('1 vencida: concordância no singular em ação, risco e porquê')]
    public function testVencidaNoSingular(): void
    {
        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 10, 4, 1)]), null);

        self::assertSame('Priorizar 1 meta vencida.', $r->acoes[0]->texto);
        self::assertSame('1 meta vencida', $r->riscos[0]->texto);
        self::assertSame('1 meta vencida segura a taxa de conclusão.', $r->porQue[0]->texto);
    }

    #[TestDox('Prazos próximos: "a meta" no singular, "as 4 metas" no plural; risco nível 2')]
    public function testPrazosProximos(): void
    {
        $um = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 10, 4, 0, 1)]), null);
        self::assertSame('Concluir primeiro a meta com prazo próximo.', $um->acoes[0]->texto);
        self::assertSame(MotorDeRitmo::PESO_PRAZOS, $um->acoes[0]->severidade);
        self::assertSame('1 meta vence nos próximos dias', $um->riscos[0]->texto);

        $quatro = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 10, 4, 0, 4)]), null);
        self::assertSame('Concluir primeiro as 4 metas com prazo próximo.', $quatro->acoes[0]->texto);
        self::assertSame('4 metas vencem nos próximos dias', $quatro->riscos[0]->texto);
        self::assertSame(MotorDeRitmo::NIVEL_PRAZOS, $quatro->riscos[0]->severidade);
        self::assertFalse($quatro->alerta, 'prazo próximo sozinho não acende o alerta (L146)');
    }

    // ── equipe: sobrecarga e redistribuição (L118-128, L91, L103) ───────────

    #[TestDox('Ativas 10/4/4: média 6, razão 0,67 > 0,45 → Ana em sobrecarga; redistribuir ⌊6/3⌋ = 2 para quem tem menos')]
    public function testSobrecargaERedistribuicao(): void
    {
        $r = $this->sut->analisar($this->dashboard([
            $this->linha(1, 'Ana Lima', 12, 10),
            $this->linha(2, 'Bia Souza', 6, 4),
            $this->linha(3, 'Caio Nunes', 5, 4),
        ]), null);

        self::assertEqualsWithDelta(6.0, $r->equipe->media, 1e-9);
        self::assertSame(1, $r->equipe->sobrecarga?->userId);
        self::assertEqualsWithDelta(10 / 6 - 1, $r->equipe->razao, 1e-9);
        // empate em 4: o usort estável mantém a ordem da tabela, e "o de menor fila" é o último (Caio)
        self::assertSame(3, $r->equipe->folga?->userId);
        self::assertSame(2, $r->equipe->redistribuir);

        self::assertSame('Redistribuir 2 metas de Ana para Caio, que tem menor carga.', $r->acoes[0]->texto);
        self::assertSame(MotorDeRitmo::PESO_REDISTRIBUIR, $r->acoes[0]->severidade);
        self::assertSame(MotorDeRitmo::GRUPO_ACAO_RECOMENDADA, $r->acoes[0]->grupo);

        self::assertSame('Ana concentra 10 metas ativas (67% acima da média)', $r->riscos[0]->texto);
        self::assertSame(MotorDeRitmo::NIVEL_SOBRECARGA, $r->riscos[0]->severidade);
        self::assertSame(['ativas' => 10, 'media' => 6.0, 'acima_da_media' => 67], $r->riscos[0]->numeros);
        self::assertTrue($r->alerta, 'sobrecarga acende o alerta (L146)');
    }

    #[TestDox('Razão exatamente no limiar (29/20 − 1 = 0,45) não é sobrecarga; 30/20 − 1 = 0,5 é')]
    public function testSobrecargaNoLimiar(): void
    {
        $noLimiar = $this->sut->analisar($this->dashboard([
            $this->linha(1, 'Ana Lima', 30, 29),
            $this->linha(2, 'Bia Souza', 12, 11),
        ]), null);
        self::assertNull($noLimiar->equipe->sobrecarga);
        self::assertSame(0, $noLimiar->equipe->redistribuir);
        self::assertSame([], $noLimiar->riscos);

        $acima = $this->sut->analisar($this->dashboard([
            $this->linha(1, 'Ana Lima', 30, 30),
            $this->linha(2, 'Bia Souza', 12, 10),
        ]), null);
        self::assertSame(1, $acima->equipe->sobrecarga?->userId);
        // ⌊(30 − 10) / 3⌋ = 6
        self::assertSame(6, $acima->equipe->redistribuir);
    }

    #[TestDox('Quem não tem meta no período fica fora da média; sozinho na média ninguém está em sobrecarga')]
    public function testPessoaSemMetaNaoEntraNaMedia(): void
    {
        $r = $this->sut->analisar($this->dashboard([
            $this->linha(1, 'Ana Lima', 10, 10),
            $this->linha(2, 'Bia Souza', 0, 0),
        ]), null);

        self::assertEqualsWithDelta(10.0, $r->equipe->media, 1e-9, 'a média é só de quem tem meta');
        self::assertNull($r->equipe->sobrecarga);
        self::assertNull($r->equipe->folga);
    }

    #[TestDox('Redistribuir no mínimo 1 mesmo quando a diferença é menor que 3 (max(1, ⌊diff/3⌋))')]
    public function testRedistribuirMinimoUm(): void
    {
        // média 1,5 → 3/1,5 − 1 = 1 > 0,45; diff 3 − 0 = 3 → ⌊3/3⌋ = 1
        $r = $this->sut->analisar($this->dashboard([
            $this->linha(1, 'Ana Lima', 5, 3),
            $this->linha(2, 'Bia Souza', 2, 0),
        ]), null);

        self::assertSame(1, $r->equipe->redistribuir);
        self::assertSame('Redistribuir 1 meta de Ana para Bia, que tem menor carga.', $r->acoes[0]->texto);
    }

    // ── ordem das ações e riscos ─────────────────────────────────────────

    #[TestDox('Ações por peso (100 vencidas, 90 prazos, 70 redistribuir, 50 segurar entrada) e "reavaliar" sempre por último')]
    public function testAcoesOrdenadasPorPeso(): void
    {
        $r = $this->sut->analisar($this->dashboard([
            $this->linha(1, 'Ana Lima', 20, 10, 2, 1),
            $this->linha(2, 'Bia Souza', 4, 2),
        ]), $this->dia10());

        self::assertSame([
            MotorDeRitmo::TIPO_VENCIDAS,
            MotorDeRitmo::TIPO_PRAZOS,
            MotorDeRitmo::TIPO_REDISTRIBUIR,
            MotorDeRitmo::TIPO_SEGURAR_ENTRADA,
            MotorDeRitmo::TIPO_REAVALIAR,
        ], $this->tipos($r->acoes));
        self::assertSame([100, 90, 70, 50, 0], array_map(static fn (Leitura $l): int => $l->severidade, $r->acoes));
        self::assertSame('Reavaliar o resultado em 24 horas.', $r->acoes[4]->texto);
        self::assertSame(MotorDeRitmo::GRUPO_ACOMPANHAMENTO, $r->acoes[4]->grupo);

        // riscos: nível 3 (vencidas) antes dos de nível 2, que mantêm a ordem das regras
        self::assertSame([
            MotorDeRitmo::TIPO_VENCIDAS,
            MotorDeRitmo::TIPO_PRAZOS,
            MotorDeRitmo::TIPO_SOBRECARGA,
            MotorDeRitmo::TIPO_ENTRADA_VS_SAIDA,
        ], $this->tipos($r->riscos));
    }

    // ── "o que está acontecendo" por fase (L75-79) ───────────────────────

    #[TestDox('Meio do período: "Dia 10 de 31." seguido dos números reais')]
    public function testOQueNoMeio(): void
    {
        $r = $this->sut->analisar($this->dashboard([$this->linha(1, 'Ana Lima', 14, 4)]), $this->dia10());

        self::assertNotNull($r->oQue);
        self::assertSame(MotorDeRitmo::TIPO_FASE, $r->oQue->tipo);
        self::assertSame('Dia 10 de 31. 14 metas abertas no período, 10 concluídas e 4 ativas.', $r->oQue->texto);
        self::assertSame('meio', $r->oQue->numeros['fase']);
        self::assertSame(10, $r->oQue->numeros['dia']);
        self::assertSame(31, $r->oQue->numeros['dias']);
    }

    #[TestDox('Início (dia 3 de 31), reta final (restam 4) e encerrado têm cada um a sua abertura')]
    public function testOQueNasOutrasFases(): void
    {
        $linhas = [$this->linha(1, 'Ana Lima', 1, 0)];

        $inicio = $this->sut->analisar($this->dashboard($linhas), TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-03-03')));
        self::assertSame('Início do período: dia 3 de 31. 1 meta aberta no período, 1 concluída e 0 ativas.', $inicio->oQue?->texto);

        $final = $this->sut->analisar($this->dashboard($linhas), TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-03-27')));
        self::assertStringStartsWith('Restam 4 dias.', (string) $final->oQue?->texto);

        $encerrado = $this->sut->analisar($this->dashboard($linhas), TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-04-10')));
        self::assertStringStartsWith('A equipe encerrou o período com 1 meta concluída.', (string) $encerrado->oQue?->texto);
    }

    #[TestDox('O limite do objetivo (sem período anterior com conclusões) é sempre declarado')]
    public function testLimiteDoObjetivoSempreDeclarado(): void
    {
        $r = $this->sut->analisar($this->dashboard([]), $this->dia10());

        self::assertCount(1, $r->limites);
        self::assertStringContainsString('não define objetivo', $r->limites[0]);
        self::assertStringContainsString('não projeta o fechamento', $r->limites[0]);
    }

    #[TestDox('Sem nenhuma linha: zeros, sem equipe, sem risco; só reavaliar')]
    public function testSemLinhas(): void
    {
        $r = $this->sut->analisar($this->dashboard([]), $this->dia10());

        self::assertSame(0, $r->novas);
        self::assertSame(0.0, $r->ritmoAtual);
        self::assertSame(0.0, $r->equipe->media);
        self::assertNull($r->equipe->sobrecarga);
        self::assertSame([MotorDeRitmo::TIPO_REAVALIAR], $this->tipos($r->acoes));
        self::assertSame([], $r->riscos);
        self::assertFalse($r->alerta);
    }
}
