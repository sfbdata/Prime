<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Inteligencia;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\Inteligencia\Alerta;
use App\Dashboard\Inteligencia\LeituraAvancada;
use App\Dashboard\Inteligencia\MotorAvancado;
use App\Dashboard\Inteligencia\MotorDeRitmo;
use App\Dashboard\Inteligencia\NivelDeAlerta;
use App\Dashboard\Inteligencia\TempoDoPeriodo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Alertas, cockpit, qualidade, distribuição e perguntas do Modo avançado com os números e
 * limiares de bluejus-avancado.js — bordas incluídas (10 vencidas, 1,5×/2× a média, 15
 * prazos, 8 urgentes, 0,6× a média, 120/95/80% de capacidade) — e os números que antes
 * misturavam bases (vencidas de hoje ÷ fila do período = "1500%") ou afirmavam tendência
 * ("entrada acima da conclusão"), agora só contagens.
 */
#[CoversClass(MotorAvancado::class)]
#[Group('dashboard')]
final class MotorAvancadoTest extends TestCase
{
    private MotorAvancado $sut;
    private MotorDeRitmo $ritmo;

    protected function setUp(): void
    {
        $this->sut   = new MotorAvancado();
        $this->ritmo = new MotorDeRitmo();
    }

    private function linha(int $id, string $nome, int $total, int $ativas, int $vencidas = 0, int $prazos = 0, ?string $cargo = 'Advogado(a)'): LinhaAdvogadoDashboardOutput
    {
        return new LinhaAdvogadoDashboardOutput(
            userId:         $id,
            nomeAdvogado:   $nome,
            cargoNome:      $cargo,
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
    private function analisar(array $linhas, ?TempoDoPeriodo $tempo = null, int $urgentes = 0): LeituraAvancada
    {
        $dashboard = new DashboardOutput(0, $urgentes, 0, $linhas);

        return $this->sut->analisar($dashboard, $this->ritmo->analisar($dashboard, $tempo));
    }

    /** Março/2024 visto do dia 10: 10 passados, 21 restantes. */
    private function dia10(): TempoDoPeriodo
    {
        $t = TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-03-10'));
        self::assertNotNull($t);

        return $t;
    }

    private function alerta(LeituraAvancada $l, string $id): ?Alerta
    {
        foreach ($l->alertas as $a) {
            if ($a->id === $id) {
                return $a;
            }
        }

        return null;
    }

    /** @return string[] */
    private function ids(LeituraAvancada $l): array
    {
        return array_map(static fn (Alerta $a): string => $a->id, $l->alertas);
    }

    // ── 1. vencidas (L34-50) — só contagem ───────────────────────────────

    /** @return iterable<string, array{int, int, NivelDeAlerta}> */
    public static function niveisDeVencidas(): iterable
    {
        // o nível vem SÓ da contagem (≥ 10 é crítico, L38); o "% das ativas" saiu: bases diferentes
        yield '2 vencidas, 10 ativas (antes "20%" → crítico) → monitorar' => [2, 10, NivelDeAlerta::Monitorar];
        yield '1 vencida, 10 ativas → monitorar'                            => [1, 10, NivelDeAlerta::Monitorar];
        yield '9 vencidas, 1 ativa (antes "900%" → crítico) → monitorar'   => [9, 1, NivelDeAlerta::Monitorar];
        yield '10 vencidas, 200 ativas → crítico pela contagem'             => [10, 200, NivelDeAlerta::Critico];
        yield '15 vencidas, 1 ativa (antes "1500%") → crítico pela contagem' => [15, 1, NivelDeAlerta::Critico];
    }

    #[DataProvider('niveisDeVencidas')]
    #[TestDox('Nível do alerta de vencidas: $_dataName')]
    public function testNivelDasVencidas(int $vencidas, int $ativas, NivelDeAlerta $esperado): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', $ativas + 5, $ativas, $vencidas)]);

        self::assertSame($esperado, $this->alerta($l, MotorAvancado::ID_VENCIDAS)?->nivel);
    }

    #[TestDox('15 vencidas com 1 ativa no período: nenhuma frase com "%" — nem evidência, nem fatores, nem cockpit')]
    public function testVencidasNaoViramPorcentagemDeOutraBase(): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', 1, 1, 15)]);

        $a = $this->alerta($l, MotorAvancado::ID_VENCIDAS);
        self::assertNotNull($a);
        self::assertSame(NivelDeAlerta::Critico, $a->nivel);
        self::assertSame('15 metas vencidas', $a->titulo);
        self::assertSame('Ana tem 15.', $a->evidencia);
        self::assertSame(['Prazo: já vencido', 'Volume: 15'], $a->fatores);
        self::assertStringNotContainsString('%', $a->evidencia . implode(' ', $a->fatores) . $a->problema . $a->impacto);
        self::assertContains('metas vencidas por colaborador (contadas em relação a hoje, não ao período)', $a->dados);

        $vencidas = $l->cockpit[2];
        self::assertSame('Vencidas', $vencidas->rotulo);
        self::assertSame('15', $vencidas->valor);
        self::assertSame(NivelDeAlerta::Critico, $vencidas->nivel);
        self::assertStringNotContainsString('%', $vencidas->valor);
    }

    #[TestDox('Vencidas: evidência nomeia os 2 que mais têm, ação começa por eles, sem "causa" (dependia do ritmo necessário)')]
    public function testAlertaDeVencidas(): void
    {
        $l = $this->analisar([
            $this->linha(1, 'Ana Lima', 10, 6, 1),
            $this->linha(2, 'Bruno Melo', 10, 8, 3),
            $this->linha(3, 'Caio Nunes', 10, 6, 2),
        ]);

        $a = $this->alerta($l, MotorAvancado::ID_VENCIDAS);
        self::assertNotNull($a);
        self::assertSame(NivelDeAlerta::Monitorar, $a->nivel, '6 < 10');
        self::assertSame('6 metas vencidas', $a->titulo);
        self::assertSame('6 metas passaram do prazo e continuam abertas.', $a->problema);
        self::assertSame('Bruno tem 3 e Caio tem 2.', $a->evidencia);
        self::assertNull($a->causa);
        self::assertSame('Priorizar as 6 vencidas antes de novas tarefas, começando por Bruno e Caio.', $a->acao);
        self::assertSame(['Prazo: já vencido', 'Volume: 6'], $a->fatores);
        self::assertSame(['3 colaboradores', '30 metas no período', 'metas vencidas por colaborador (contadas em relação a hoje, não ao período)'], $a->dados);
        self::assertArrayNotHasKey('Causa provável', $a->linhas());
    }

    #[TestDox('Sem vencidas não há alerta de vencidas')]
    public function testSemVencidasSemAlerta(): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', 10, 6)]);

        self::assertNull($this->alerta($l, MotorAvancado::ID_VENCIDAS));
    }

    // ── 2. concentração (L53-68) ────────────────────────────────────────

    #[TestDox('Ativas 9/3/0 (média 4): 9 ≥ 6 dispara e 9 ≥ 8 é atenção; excesso 5, redistribuir ⌈5/2⌉ = 3 para quem tem 0')]
    public function testConcentracaoAtencao(): void
    {
        $l = $this->analisar([
            $this->linha(1, 'Ana Lima', 12, 9),
            $this->linha(2, 'Bia Souza', 5, 3),
            $this->linha(3, 'Caio Nunes', 2, 0),
        ]);

        $a = $this->alerta($l, MotorAvancado::ID_CONCENTRACAO);
        self::assertNotNull($a);
        self::assertSame(NivelDeAlerta::Atencao, $a->nivel);
        self::assertSame('Carga concentrada em Ana', $a->titulo);
        self::assertSame('Ana tem 9 metas ativas.', $a->problema);
        self::assertSame('São 5 acima da média da equipe (4). Volume não é sinônimo de baixa produtividade: Ana concluiu 3 no período.', $a->evidencia);
        // L61 dizia "cresce mais rápido que a dos demais": tendência não medida — fica o fato
        self::assertSame('A fila desse responsável é a maior da equipe: 9 ativas contra a média de 4.', $a->impacto);
        self::assertSame('Avaliar redistribuir cerca de 3 metas para Caio (0 ativas), respeitando processos estratégicos.', $a->acao);
        self::assertSame(['Carga: 225% da média', 'Menor fila: Caio, 0 ativas'], $a->fatores);
    }

    #[TestDox('Ativas 7/3/2 (média 4): 7 ≥ 6 dispara, 7 < 8 fica em monitorar; 5/3/4 não dispara')]
    public function testConcentracaoNoLimiar(): void
    {
        $monitorar = $this->analisar([
            $this->linha(1, 'Ana Lima', 8, 7),
            $this->linha(2, 'Bia Souza', 5, 3),
            $this->linha(3, 'Caio Nunes', 3, 2),
        ]);
        self::assertSame(NivelDeAlerta::Monitorar, $this->alerta($monitorar, MotorAvancado::ID_CONCENTRACAO)?->nivel);

        $nada = $this->analisar([
            $this->linha(1, 'Ana Lima', 8, 5),
            $this->linha(2, 'Bia Souza', 5, 3),
            $this->linha(3, 'Caio Nunes', 5, 4),
        ]);
        self::assertNull($this->alerta($nada, MotorAvancado::ID_CONCENTRACAO));
    }

    // ── 3. prazos (L71-86) ──────────────────────────────────────────────

    #[TestDox('15 prazos próximos é atenção; 14 é monitorar; a evidência aponta quem concentra e o percentual (mesma base)')]
    public function testPrazosProximos(): void
    {
        $atencao = $this->analisar([
            $this->linha(1, 'Ana Lima', 20, 15, 0, 12),
            $this->linha(2, 'Bia Souza', 5, 3, 0, 3),
        ]);
        $a = $this->alerta($atencao, MotorAvancado::ID_PRAZOS);
        self::assertNotNull($a);
        self::assertSame(NivelDeAlerta::Atencao, $a->nivel);
        self::assertSame('15 prazos próximos', $a->titulo);
        self::assertSame('Ana concentra 12 (80%).', $a->evidencia);
        self::assertSame('Concluir primeiro as metas com prazo nos próximos 7 dias e checar a agenda de Ana.', $a->acao);
        self::assertSame('Ana e coordenação', $a->responsavel);
        self::assertSame(['Prazo: até 7 dias', 'Concentração: 80% em uma pessoa'], $a->fatores);

        $monitorar = $this->analisar([$this->linha(1, 'Ana Lima', 20, 15, 0, 14)]);
        self::assertSame(NivelDeAlerta::Monitorar, $this->alerta($monitorar, MotorAvancado::ID_PRAZOS)?->nivel);

        $um = $this->analisar([$this->linha(1, 'Ana Lima', 20, 15, 0, 1)]);
        self::assertSame('1 prazo próximo', $this->alerta($um, MotorAvancado::ID_PRAZOS)?->titulo);
        self::assertSame('1 meta vence nos próximos dias.', $this->alerta($um, MotorAvancado::ID_PRAZOS)?->problema);
    }

    // ── 4. "entrada acima da conclusão" (L89-105) NÃO existe ─────────────

    #[TestDox('Dia 10, 15 novas e 5 ativas (antes "entrada acima da conclusão"): nenhum alerta de entrada, nenhuma projeção, nenhuma pergunta "se nada mudar"')]
    public function testEntradaAcimaDaConclusaoNaoEAlerta(): void
    {
        foreach ([[15, 5], [25, 15], [62, 31]] as [$novas, $ativas]) {
            $l = $this->analisar([$this->linha(1, 'Ana Lima', $novas, $ativas)], $this->dia10());

            self::assertNotContains('entrada', $this->ids($l));
            self::assertSame([MotorAvancado::ID_SEM_VENCIDAS], $this->ids($l));
            self::assertNotContains('Se nada mudar, o que pode acontecer?', array_map(static fn ($q) => $q->pergunta, $l->perguntas));
            foreach ($l->alertas as $a) {
                self::assertStringNotContainsString('fila', (string) $a->impacto);
            }
        }
    }

    // ── 6. urgentes (L134-148) — pelo card, sem evidência por pessoa ───────

    #[TestDox('8 urgentes é atenção, 7 é monitorar, 0 não dispara; sem "evidência" (não há urgentes por colaborador)')]
    public function testUrgentes(): void
    {
        $atencao = $this->analisar([$this->linha(1, 'Ana Lima', 1, 1)], null, 8);
        $a = $this->alerta($atencao, MotorAvancado::ID_URGENTES);
        self::assertNotNull($a);
        self::assertSame(NivelDeAlerta::Atencao, $a->nivel);
        self::assertSame('8 pastas urgentes', $a->titulo);
        self::assertSame('8 pastas estão com prioridade Urgente.', $a->problema);
        self::assertNull($a->evidencia);
        self::assertSame(['Prioridade: Urgente', 'Volume: 8'], $a->fatores);
        self::assertContains('card Demandas urgentes (pastas por prioridade)', $a->dados);

        self::assertSame(NivelDeAlerta::Monitorar, $this->alerta($this->analisar([], null, 7), MotorAvancado::ID_URGENTES)?->nivel);
        self::assertNull($this->alerta($this->analisar([], null, 0), MotorAvancado::ID_URGENTES));

        $um = $this->alerta($this->analisar([], null, 1), MotorAvancado::ID_URGENTES);
        self::assertSame('1 pasta urgente', $um?->titulo);
        self::assertSame('1 pasta está com prioridade Urgente.', $um?->problema);
    }

    // ── 8. capacidade disponível (L167-177) ─────────────────────────────

    #[TestDox('Ativas 10/2 (média 6): 2 ≤ 3,6 → oportunidade para Bia; com 10/5 (média 7,5) não (5 > 4,5)')]
    public function testCapacidadeDisponivel(): void
    {
        $sim = $this->analisar([
            $this->linha(1, 'Ana Lima', 12, 10),
            $this->linha(2, 'Bia Souza', 5, 2, 1),
        ]);
        $a = $this->alerta($sim, MotorAvancado::ID_CAPACIDADE);
        self::assertNotNull($a);
        self::assertSame(NivelDeAlerta::Oportunidade, $a->nivel);
        self::assertSame('Bia tem 2 metas ativas, abaixo da média (6).', $a->problema);
        self::assertSame('Metas vencidas: 1.', $a->evidencia, 'o número real, não "sem vencidas" como o JS afirmava');
        self::assertSame('Direcionar as próximas metas novas para Bia.', $a->acao);
        self::assertSame(['Carga: 33% da média'], $a->fatores);

        $nao = $this->analisar([
            $this->linha(1, 'Ana Lima', 12, 10),
            $this->linha(2, 'Bia Souza', 6, 5),
        ]);
        self::assertNull($this->alerta($nao, MotorAvancado::ID_CAPACIDADE));
    }

    #[TestDox('Com uma pessoa só não há "capacidade disponível" (maior e menor são a mesma)')]
    public function testCapacidadeExigeDuasPessoas(): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', 12, 0)]);

        self::assertNull($this->alerta($l, MotorAvancado::ID_CAPACIDADE));
    }

    // ── 9. sem vencidas (L180-186) ──────────────────────────────────────

    #[TestDox('Quem tem meta e zero vencidas aparece em "N sem metas vencidas"; quem não tem meta não conta')]
    public function testSemVencidas(): void
    {
        $l = $this->analisar([
            $this->linha(1, 'Ana Lima', 10, 4),
            $this->linha(2, 'Bia Souza', 4, 2),
            $this->linha(3, 'Caio Nunes', 4, 2, 1),
            $this->linha(4, 'Dora Reis', 0, 0),
        ]);

        $a = $this->alerta($l, MotorAvancado::ID_SEM_VENCIDAS);
        self::assertNotNull($a);
        self::assertSame(NivelDeAlerta::Normal, $a->nivel);
        self::assertSame('2 sem metas vencidas', $a->titulo);
        self::assertSame('Ana, Bia estão sem vencidas.', $a->problema);
        self::assertArrayNotHasKey('Responsável', $a->linhas(), 'sem responsável o JS mostra "-" e a linha some');
    }

    // ── ordem, contagem e pior nível (L188-189, L204) ───────────────────

    #[TestDox('Alertas por nível (crítico → oportunidade), contagem por nível e o pior no topo')]
    public function testOrdemEContagem(): void
    {
        // vencidas 4 (< 10) → monitorar; concentração 10 ≥ 2×4 → atenção; capacidade → oportunidade; sem vencidas → normal
        $l = $this->analisar([
            $this->linha(1, 'Ana Lima', 14, 10, 4, 2),
            $this->linha(2, 'Bia Souza', 5, 2),
            $this->linha(3, 'Caio Nunes', 1, 0),
        ], null, 1);

        self::assertSame([
            MotorAvancado::ID_CONCENTRACAO,
            MotorAvancado::ID_VENCIDAS,
            MotorAvancado::ID_PRAZOS,
            MotorAvancado::ID_URGENTES,
            MotorAvancado::ID_SEM_VENCIDAS,
            MotorAvancado::ID_CAPACIDADE,
        ], $this->ids($l));
        self::assertSame(NivelDeAlerta::Atencao, $l->pior);
        self::assertSame(0, $l->contagemDe(NivelDeAlerta::Critico));
        self::assertSame(1, $l->contagemDe(NivelDeAlerta::Atencao));
        self::assertSame(3, $l->contagemDe(NivelDeAlerta::Monitorar));
        self::assertSame(1, $l->contagemDe(NivelDeAlerta::Normal));
        self::assertSame(1, $l->contagemDe(NivelDeAlerta::Oportunidade));
        self::assertSame(
            [NivelDeAlerta::Critico, NivelDeAlerta::Atencao, NivelDeAlerta::Monitorar, NivelDeAlerta::Normal, NivelDeAlerta::Oportunidade],
            array_column($l->contagemPorNivel(), 'nivel'),
        );
    }

    // ── qualidade dos dados (L192-200) ──────────────────────────────────

    #[TestDox('Qualidade: 100 − 6 (1 sem cargo) − 15 (dia 5 de 31 < 25%) − 20 (< 3 pessoas) − 4 = 55, com 4 notas')]
    public function testQualidadeComPenalidades(): void
    {
        $t = TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-03-05'));
        self::assertNotNull($t);
        $l = $this->analisar([
            $this->linha(1, 'Ana Lima', 4, 2, 0, 0, null),
            $this->linha(2, 'Bia Souza', 4, 2),
        ], $t);

        self::assertSame(55, $l->qualidadeNota);
        self::assertSame([
            '1 colaborador sem cargo informado.',
            'Início do período: tendências ainda pouco confiáveis.',
            'Poucos colaboradores no filtro para comparar.',
            'Complexidade das metas não é registrada; volume não mede esforço.',
        ], $l->qualidadeNotas);
    }

    #[TestDox('Qualidade: 3 pessoas com cargo e período encerrado = 96 (só o −4 fixo); em andamento no dia 10 = 90')]
    public function testQualidadeMelhorCaso(): void
    {
        $linhas = [
            $this->linha(1, 'Ana Lima', 4, 2),
            $this->linha(2, 'Bia Souza', 4, 2),
            $this->linha(3, 'Caio Nunes', 4, 2),
        ];
        $encerrado = TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-04-10'));

        self::assertSame(96, $this->analisar($linhas, $encerrado)->qualidadeNota);
        self::assertSame(['Complexidade das metas não é registrada; volume não mede esforço.'], $this->analisar($linhas, $encerrado)->qualidadeNotas);

        $andamento = $this->analisar($linhas, $this->dia10());
        self::assertSame(90, $andamento->qualidadeNota);
        self::assertContains('Período em andamento: os números ainda vão mudar.', $andamento->qualidadeNotas);

        self::assertSame(96, $this->analisar($linhas)->qualidadeNota, 'sem período não há penalidade de andamento');
    }

    #[TestDox('Qualidade nunca cai abaixo de 40 (8 sem cargo no início do período: 100 − 48 − 15 − 4 = 33 → 40)')]
    public function testQualidadeMinimo(): void
    {
        $t = TempoDoPeriodo::de('2024-03-01', '2024-03-31', new \DateTimeImmutable('2024-03-02'));
        $linhas = [];
        for ($i = 1; $i <= 8; ++$i) {
            $linhas[] = $this->linha($i, 'Pessoa ' . $i, 2, 1, 0, 0, '');
        }

        self::assertSame(40, $this->analisar($linhas, $t)->qualidadeNota);
    }

    // ── cockpit (L203-212) ──────────────────────────────────────────────

    /** @return iterable<string, array{int, string, NivelDeAlerta}> */
    public static function capacidades(): iterable
    {
        // dia 10 de 31 (restam 21), 10 concluídas → 1/dia; uso = ativas ÷ (1 × 21) — tudo do período
        yield '30 ativas → 143% → crítico'   => [30, '143%', NivelDeAlerta::Critico];
        yield '21 ativas → 100% → atenção'   => [21, '100%', NivelDeAlerta::Atencao];
        yield '20 ativas → 95% → monitorar'  => [20, '95%', NivelDeAlerta::Monitorar];
        yield '17 ativas → 81% → monitorar'  => [17, '81%', NivelDeAlerta::Monitorar];
        yield '16 ativas → 76% → normal'     => [16, '76%', NivelDeAlerta::Normal];
    }

    #[DataProvider('capacidades')]
    #[TestDox('Cockpit · Capacidade: $_dataName')]
    public function testCockpitCapacidade(int $ativas, string $valor, NivelDeAlerta $nivel): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', $ativas + 10, $ativas)], $this->dia10());

        $cap = $l->cockpit[3];
        self::assertSame('Capacidade', $cap->rotulo);
        self::assertSame($valor, $cap->valor);
        self::assertSame($nivel, $cap->nivel);
        self::assertSame('Fila ativa ÷ (conclusão diária × dias restantes)', $cap->dica);
    }

    #[TestDox('Cockpit sem período: Capacidade "Sem base" (monitorar); 12 vencidas é crítico pela contagem, sem percentual')]
    public function testCockpitSemPeriodo(): void
    {
        // 12 vencidas (≥ 10) → alerta crítico → saúde "Em risco", 1 crítico; 12 vencidas com 10 ativas: nada de "120%"
        $l = $this->analisar([$this->linha(1, 'Ana Lima', 15, 10, 12, 3)]);

        self::assertCount(5, $l->cockpit, 'sem "Produtividade": o estado do ritmo não é classificado');
        self::assertSame(['Saúde operacional', 'Prazos', 'Vencidas', 'Capacidade', 'Riscos'], array_map(static fn ($k) => $k->rotulo, $l->cockpit));
        self::assertSame('Em risco', $l->cockpit[0]->valor);
        self::assertSame(NivelDeAlerta::Critico, $l->cockpit[0]->nivel);
        self::assertSame('3 próximos', $l->cockpit[1]->valor);
        self::assertSame(NivelDeAlerta::Monitorar, $l->cockpit[1]->nivel);
        self::assertSame('12', $l->cockpit[2]->valor);
        self::assertSame(NivelDeAlerta::Critico, $l->cockpit[2]->nivel);
        self::assertSame('Metas com prazo anterior a hoje ainda abertas (não depende do período)', $l->cockpit[2]->dica);
        self::assertSame('Sem base', $l->cockpit[3]->valor);
        self::assertSame(NivelDeAlerta::Monitorar, $l->cockpit[3]->nivel);
        self::assertSame('1 crítico', $l->cockpit[4]->valor);
        self::assertSame(NivelDeAlerta::Critico, $l->cockpit[4]->nivel);
    }

    #[TestDox('Cockpit · Vencidas: 2 (< 10) é monitorar, 0 é normal')]
    public function testCockpitVencidasPorContagem(): void
    {
        $duas = $this->analisar([$this->linha(1, 'Ana Lima', 15, 10, 2)]);
        self::assertSame('2', $duas->cockpit[2]->valor);
        self::assertSame(NivelDeAlerta::Monitorar, $duas->cockpit[2]->nivel);
        self::assertSame('Estável', $duas->cockpit[0]->valor, '2 vencidas não é crítico nem atenção');

        $zero = $this->analisar([$this->linha(1, 'Ana Lima', 15, 10)]);
        self::assertSame('0', $zero->cockpit[2]->valor);
        self::assertSame(NivelDeAlerta::Normal, $zero->cockpit[2]->nivel);
    }

    #[TestDox('Cockpit estável: sem alertas de atenção, 0 prazos, 0 vencidas, 0 críticos')]
    public function testCockpitEstavel(): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', 10, 4), $this->linha(2, 'Bia Souza', 10, 4)]);

        self::assertSame('Estável', $l->cockpit[0]->valor);
        self::assertSame(NivelDeAlerta::Normal, $l->cockpit[0]->nivel);
        self::assertSame('0 próximos', $l->cockpit[1]->valor);
        self::assertSame(NivelDeAlerta::Normal, $l->cockpit[1]->nivel);
        self::assertSame('0', $l->cockpit[2]->valor);
        self::assertSame('0 críticos', $l->cockpit[4]->valor);
        self::assertSame(NivelDeAlerta::Normal, $l->cockpit[4]->nivel);
    }

    // ── distribuição (L215-216) ─────────────────────────────────────────

    #[TestDox('Barras em % da maior contagem (8): 100/50/0 ativas, 25/0/0 vencidas; traço da média (4) em 50%')]
    public function testDistribuicao(): void
    {
        $l = $this->analisar([
            $this->linha(1, 'Bia Souza', 6, 4),
            $this->linha(2, 'Ana Lima', 10, 8, 2),
            $this->linha(3, 'Caio Nunes', 1, 0),
        ]);

        self::assertSame(['Ana', 'Bia', 'Caio'], array_map(static fn ($d) => $d->nome, $l->distribuicao));
        self::assertSame([100, 50, 0], array_map(static fn ($d) => $d->pctAtivas, $l->distribuicao));
        self::assertSame([25, 0, 0], array_map(static fn ($d) => $d->pctVencidas, $l->distribuicao));
        self::assertSame(50, $l->mediaPct);
        self::assertEqualsWithDelta(4.0, $l->mediaValor, 1e-9);
    }

    #[TestDox('Vencidas (15) acima da maior fila do período (1): a escala é a maior contagem, nenhuma barra passa de 100%')]
    public function testDistribuicaoComVencidasAcimaDaFila(): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', 1, 1, 15)]);

        self::assertSame(100, $l->distribuicao[0]->pctVencidas, 'antes: 1500%, a barra vazava');
        self::assertSame(7, $l->distribuicao[0]->pctAtivas);
        self::assertSame(7, $l->mediaPct);
    }

    #[TestDox('Sem linhas não há divisão por zero: distribuição vazia, média 0, cockpit "Estável"')]
    public function testSemLinhas(): void
    {
        $l = $this->analisar([]);

        self::assertSame([], $l->distribuicao);
        self::assertSame(0, $l->mediaPct);
        self::assertSame([], $l->alertas);
        self::assertSame('Estável', $l->cockpit[0]->valor);
        self::assertSame('Nenhum gargalo relevante nos dados atuais.', $l->perguntas[0]->resposta);
    }

    // ── perguntas guiadas (L220-226, sem a L223) ────────────────────────

    #[TestDox('Perguntas respondidas pelos achados: gargalo, sobrecarga, prioridades e riscos — "se nada mudar" não existe')]
    public function testPerguntasGuiadasComAchados(): void
    {
        // vencidas 3 (< 10) → monitorar; concentração 9 ≥ 1,5×5 mas < 2×5 → monitorar; prazos 2; urgentes 1
        $l = $this->analisar([
            $this->linha(1, 'Ana Lima', 14, 9, 3, 2),
            $this->linha(2, 'Bia Souza', 6, 1),
        ], $this->dia10(), 1);

        $p = array_combine(
            array_map(static fn ($q) => $q->pergunta, $l->perguntas),
            array_map(static fn ($q) => $q->resposta, $l->perguntas),
        );

        self::assertCount(4, $p);
        self::assertArrayNotHasKey('Se nada mudar, o que pode acontecer?', $p);
        self::assertSame('Ana tem 9 metas ativas.', $p['Onde está nosso maior gargalo?'], 'concentração vem antes de vencidas');
        self::assertStringStartsWith('Ana tem 9 metas ativas. São 4 acima da média', $p['Qual equipe ou pessoa está sobrecarregada?']);
        self::assertSame(
            'Priorizar as 3 vencidas antes de novas tarefas, começando por Ana. '
            . 'Concluir primeiro as metas com prazo nos próximos 7 dias e checar a agenda de Ana. '
            . 'Confirmar responsável e próximo passo de cada pasta urgente.',
            $p['Quais tarefas deveriam ser priorizadas?'],
        );
        // só crítico/atenção entram: tudo ficou em monitorar
        self::assertSame('Sem riscos críticos ou de atenção.', $p['Mostre os principais riscos operacionais.']);

        $critico = $this->analisar([$this->linha(1, 'Ana Lima', 14, 9, 12)]);
        self::assertSame('12 metas vencidas', $critico->perguntas[3]->resposta);
    }

    #[TestDox('Sem achados: respostas de "nada relevante", quatro perguntas')]
    public function testPerguntasGuiadasSemAchados(): void
    {
        $l = $this->analisar([$this->linha(1, 'Ana Lima', 10, 4), $this->linha(2, 'Bia Souza', 10, 4)]);

        self::assertCount(4, $l->perguntas);
        self::assertSame('Nenhum gargalo relevante nos dados atuais.', $l->perguntas[0]->resposta);
        self::assertSame('A carga está distribuída perto da média (4 por pessoa).', $l->perguntas[1]->resposta);
        self::assertSame('Não há vencidas, prazos próximos ou urgentes em aberto.', $l->perguntas[2]->resposta);
        self::assertSame('Sem riscos críticos ou de atenção.', $l->perguntas[3]->resposta);
    }

    // ── visão estratégica da equipe (dc L2688-2697) ─────────────────────

    #[TestDox('Visão estratégica: gargalo = 1º risco, maior concentração nomeia quem tem mais fila, prazos e ação de hoje')]
    public function testVisaoEstrategica(): void
    {
        $semSobrecarga = $this->analisar([
            $this->linha(1, 'Ana Lima', 10, 5, 0, 2),
            $this->linha(2, 'Bia Souza', 10, 4),
        ]);
        $v = array_combine(
            array_map(static fn ($q) => $q->pergunta, $semSobrecarga->visaoEstrategica),
            array_map(static fn ($q) => $q->resposta, $semSobrecarga->visaoEstrategica),
        );
        self::assertSame('2 metas vencem nos próximos dias', $v['Principal gargalo']);
        self::assertSame('Ana Lima, 5 metas ativas (dentro do normal)', $v['Maior concentração']);
        self::assertSame('2 metas com prazo próximo', $v['Metas perto do vencimento']);
        self::assertSame('Concluir primeiro as 2 metas com prazo próximo.', $v['Ação de hoje']);
        self::assertArrayNotHasKey('Quem precisa de atenção', $v, 'condição por pessoa fica fora');
        self::assertArrayNotHasKey('Como está a equipe?', $v, 'estado do ritmo não é classificado');

        // média 6,5: 10 / 6,5 − 1 = 0,54 > 0,45 → sobrecarga
        $comSobrecarga = $this->analisar([
            $this->linha(1, 'Ana Lima', 12, 10),
            $this->linha(2, 'Bia Souza', 6, 3),
        ]);
        $v2 = array_combine(
            array_map(static fn ($q) => $q->pergunta, $comSobrecarga->visaoEstrategica),
            array_map(static fn ($q) => $q->resposta, $comSobrecarga->visaoEstrategica),
        );
        self::assertSame('Ana Lima, 10 metas ativas', $v2['Maior concentração']);
        self::assertSame('Nenhum gargalo relevante', $this->analisar([])->visaoEstrategica[0]->resposta);
    }
}
