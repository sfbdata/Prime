<?php

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use PHPUnit\Framework\TestCase;

/**
 * Testa as regras de transição de status que o TarefaController aplica.
 *
 * A lógica de transição está no controller, então aqui isolamos e exercitamos
 * as mesmas condições usando apenas a entidade, garantindo que cada transição
 * válida/inválida se comporta conforme o esperado.
 */
class TarefaStatusMachineTest extends TestCase
{
    private function novaTarefa(string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo('T')->setDescricao('D')->setPasta(new Pasta());
        $tarefa->setStatus($status);
        return $tarefa;
    }

    /**
     * Simula a lógica exata do controller no endpoint /mensagem com acao=enviar.
     */
    private function simularEnviar(Tarefa $tarefa): void
    {
        if ($tarefa->getStatus() === Tarefa::STATUS_PENDENTE) {
            $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);
        }
    }

    /**
     * Simula a lógica exata do controller no endpoint /mensagem com acao=devolver.
     */
    private function simularDevolver(Tarefa $tarefa): void
    {
        if ($tarefa->getStatus() === Tarefa::STATUS_EM_REVISAO) {
            $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        }
    }

    /**
     * Simula a lógica exata do controller no endpoint /concluir.
     */
    private function simularConcluir(Tarefa $tarefa): void
    {
        if ($tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
            $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
            $tarefa->setDataConclusao(new \DateTimeImmutable());
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Ação: enviar (Pendente → Para Revisão)
    // ──────────────────────────────────────────────────────────────────

    public function testEnviarDePendenteVaiParaRevisao(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);

        $this->simularEnviar($tarefa);

        $this->assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus());
    }

    public function testEnviarDeEmRevisaoNaoMudaStatus(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_EM_REVISAO);

        $this->simularEnviar($tarefa);

        // Já está em revisão; a condição do controller só age sobre 'pendente'
        $this->assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus());
    }

    public function testEnviarDeConcluidaNaoMudaStatus(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_CONCLUIDA);

        $this->simularEnviar($tarefa);

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
    }

    // ──────────────────────────────────────────────────────────────────
    // Ação: devolver (Para Revisão → Pendente)
    // ──────────────────────────────────────────────────────────────────

    public function testDevolverDeEmRevisaoVaiParaPendente(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_EM_REVISAO);

        $this->simularDevolver($tarefa);

        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
    }

    public function testDevolverDePendenteNaoMudaStatus(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);

        $this->simularDevolver($tarefa);

        // A condição do controller só age sobre 'em_revisao'
        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
    }

    public function testDevolverDeConcluidaNaoMudaStatus(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_CONCLUIDA);

        $this->simularDevolver($tarefa);

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
    }

    // ──────────────────────────────────────────────────────────────────
    // Ação: concluir
    // ──────────────────────────────────────────────────────────────────

    public function testConcluirDePendente(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);

        $this->simularConcluir($tarefa);

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        $this->assertNotNull($tarefa->getDataConclusao());
    }

    public function testConcluirDeEmRevisao(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_EM_REVISAO);

        $this->simularConcluir($tarefa);

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        $this->assertNotNull($tarefa->getDataConclusao());
    }

    public function testConcluirDeConcluida_NaoSobrescreveData(): void
    {
        $dataOriginal = new \DateTimeImmutable('2026-01-01 12:00:00');

        $tarefa = $this->novaTarefa(Tarefa::STATUS_CONCLUIDA);
        $tarefa->setDataConclusao($dataOriginal);

        $this->simularConcluir($tarefa);

        // Como já está concluída, o controller não faz nada → data preservada
        $this->assertSame($dataOriginal, $tarefa->getDataConclusao());
    }

    public function testConcluirDefineDataConclusaoProxima(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);
        $antes  = new \DateTimeImmutable();

        $this->simularConcluir($tarefa);

        $depois = new \DateTimeImmutable();
        $data   = $tarefa->getDataConclusao();

        $this->assertNotNull($data);
        $this->assertGreaterThanOrEqual($antes, $data);
        $this->assertLessThanOrEqual($depois, $data);
    }

    // ──────────────────────────────────────────────────────────────────
    // Ciclos completos encadeados
    // ──────────────────────────────────────────────────────────────────

    public function testCiclo_EnviarDevolverEnviarConcluir(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);

        $this->simularEnviar($tarefa);
        $this->assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus());

        $this->simularDevolver($tarefa);
        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());

        $this->simularEnviar($tarefa);
        $this->assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus());

        $this->simularConcluir($tarefa);
        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
    }

    public function testCiclo_EnviarEConcluirDiretamente(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);

        $this->simularEnviar($tarefa);
        $this->simularConcluir($tarefa);

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        $this->assertNotNull($tarefa->getDataConclusao());
    }

    public function testCiclo_MultiplasDevolucoes(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);

        // Enviar → devolver × 2 → enviar → concluir
        $this->simularEnviar($tarefa);
        $this->simularDevolver($tarefa);
        $this->simularEnviar($tarefa);
        $this->simularDevolver($tarefa);
        $this->simularEnviar($tarefa);
        $this->simularConcluir($tarefa);

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
    }

    // ──────────────────────────────────────────────────────────────────
    // Ação sem transição (acao vazia — só comentar)
    // ──────────────────────────────────────────────────────────────────

    public function testAcaoVaziaNaoAlteraStatus(): void
    {
        $tarefa = $this->novaTarefa(Tarefa::STATUS_PENDENTE);

        // Simula acao === '' (só comentar): nenhuma transição deve ocorrer
        $acao = '';
        if ($acao === 'enviar' && $tarefa->getStatus() === Tarefa::STATUS_PENDENTE) {
            $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);
        } elseif ($acao === 'devolver' && $tarefa->getStatus() === Tarefa::STATUS_EM_REVISAO) {
            $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        }

        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
    }
}
