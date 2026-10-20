<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Service\CalculadoraDeImportancia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A régua de `impDe()` do desenho (spec VS 18.10.26 §3.2). */
#[CoversClass(CalculadoraDeImportancia::class)]
final class CalculadoraDeImportanciaTest extends TestCase
{
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->hoje = new \DateTimeImmutable('2026-10-19');
    }

    #[TestDox('urgente com prazo amanhã e aguardando ação soma 7 pontos: nível 3, com os motivos na ordem do desenho')]
    public function testUrgenteComPrazoCurto(): void
    {
        $d = $this->demanda(urgente: true, prazo: '2026-10-20');

        $r = (new CalculadoraDeImportancia())->calcular($d, 0, null, $this->hoje);

        self::assertSame(3, $r['nivel']);
        self::assertSame(['marcada como urgente', 'prazo em até 1 dia', 'aguardando ação'], $r['motivos']);
    }

    #[TestDox('prioridade Alta (2), mensagens sem ler (1), prazo na semana (1) e aguardando ação (1) somam 5: nível 2')]
    public function testPrioridadeAltaENaoLidas(): void
    {
        $d = $this->demanda(prioridade: 'Alta', prazo: '2026-10-25');

        $r = (new CalculadoraDeImportancia())->calcular($d, 2, null, $this->hoje);

        self::assertSame(2, $r['nivel']);
        self::assertContains('2 mensagem(ns) sem ler', $r['motivos']);
        self::assertContains('prazo na semana', $r['motivos']);
    }

    #[TestDox('rascunho conta como fechada: prazo curto e prioridade não passam do nível 1')]
    public function testRascunhoLimitadoA1(): void
    {
        $d = $this->demanda(prioridade: 'Urgente', prazo: '2026-10-19', enviada: false);

        self::assertSame(1, (new CalculadoraDeImportancia())->calcular($d, 0, null, $this->hoje)['nivel']);
    }

    #[TestDox('demanda fechada fica no máximo no nível 1, e prazo e urgência deixam de contar')]
    public function testFechadaLimitadaA1(): void
    {
        $d = Apoio::demanda(Situacao::Concluida);
        $r = (new CalculadoraDeImportancia())->calcular($d, 5, null, $this->hoje);

        self::assertLessThanOrEqual(1, $r['nivel']);
    }

    #[TestDox('a escolha manual vence o cálculo: importante é 3, não importante é 0')]
    public function testEscolhaManualVence(): void
    {
        $d = $this->demanda(urgente: true, prazo: '2026-10-19');
        $calc = new CalculadoraDeImportancia();

        self::assertSame(['nivel' => 3, 'motivos' => ['você marcou como importante']], $calc->calcular($d, 0, true, $this->hoje));
        self::assertSame(['nivel' => 0, 'motivos' => ['você marcou como não importante']], $calc->calcular($d, 0, false, $this->hoje));
    }

    #[TestDox('o valor da última proposta conta: R$ 100 não soma nada')]
    public function testValorPequenoNaoConta(): void
    {
        $d = Apoio::demanda(Situacao::Proposta);
        $r = (new CalculadoraDeImportancia())->calcular($d, 0, null, $this->hoje);

        self::assertNotContains('valor acima de R$ 1 mil', $r['motivos']);
        self::assertContains('aguardando ação', $r['motivos']);
    }

    private function demanda(bool $urgente = false, string $prioridade = 'Normal', ?string $prazo = null, bool $enviada = true): DemandaExterna
    {
        $d = new DemandaExterna(Apoio::tenant(1), Apoio::usuario(10));
        $dados = new DadosDaSolicitacao();
        $dados->descricao = 'Audiência';
        $dados->cidadeExecucao = 'Brasília';
        $dados->urgente = $urgente;
        $dados->prioridade = $prioridade;
        $dados->prazoFinal = $prazo !== null ? new \DateTimeImmutable($prazo) : null;
        $d->preencher($dados);
        if ($enviada) {
            $d->enviar(Apoio::autor(Perfil::Solicitante));
        }

        return $d;
    }
}
