<?php

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Auth\User;
use App\Entity\Pasta\Pasta;
use PHPUnit\Framework\TestCase;

/**
 * Testa o ciclo de vida da entidade Tarefa de forma isolada (sem banco).
 *
 * Cobre: estados iniciais, transições de status, prazo, mensagens,
 * conclusão e o callback PreUpdate de dataAlteracao.
 */
class TarefaEntityTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function novaTarefa(string $titulo = 'Redigir petição', string $descricao = 'Descrição padrão'): Tarefa
    {
        $pasta   = new Pasta();
        $tarefa  = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao($descricao);
        $tarefa->setPasta($pasta);
        return $tarefa;
    }

    private function novoUsuario(string $email = 'user@test.com'): User
    {
        return (new User())->setEmail($email)->setFullName('Usuário Teste');
    }

    // ──────────────────────────────────────────────────────────────────
    // Estado inicial
    // ──────────────────────────────────────────────────────────────────

    public function testEstadoInicialAoCriar(): void
    {
        $tarefa = $this->novaTarefa();

        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
        $this->assertNull($tarefa->getPrazo());
        $this->assertNull($tarefa->getCriadoPor());
        $this->assertCount(0, $tarefa->getResponsaveis());
        $this->assertNull($tarefa->getDataAlteracao());
        $this->assertNull($tarefa->getDataConclusao());
        $this->assertInstanceOf(\DateTimeImmutable::class, $tarefa->getDataCriacao());
        $this->assertCount(0, $tarefa->getMensagens());
    }

    public function testStatusDefaultEPendente(): void
    {
        $tarefa = $this->novaTarefa();

        $this->assertSame('pendente', $tarefa->getStatus());
    }

    public function testStatusLabelsContemTodosOsEstados(): void
    {
        $labels = Tarefa::STATUS_LABELS;

        $this->assertArrayHasKey(Tarefa::STATUS_PENDENTE,   $labels);
        $this->assertArrayHasKey(Tarefa::STATUS_EM_REVISAO, $labels);
        $this->assertArrayHasKey(Tarefa::STATUS_CONCLUIDA,  $labels);
    }

    // ──────────────────────────────────────────────────────────────────
    // Transição: Pendente → Para Revisão
    // ──────────────────────────────────────────────────────────────────

    public function testTransicaoPendenteParaEmRevisao(): void
    {
        $tarefa = $this->novaTarefa();
        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());

        $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);

        $this->assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus());
    }

    // ──────────────────────────────────────────────────────────────────
    // Transição: Para Revisão → Pendente (devolução)
    // ──────────────────────────────────────────────────────────────────

    public function testTransicaoEmRevisaoParaPendente(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);

        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);

        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
    }

    // ──────────────────────────────────────────────────────────────────
    // Transição: qualquer estado → Concluída
    // ──────────────────────────────────────────────────────────────────

    public function testConclusaoDePendente(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
        $tarefa->setDataConclusao(new \DateTimeImmutable());

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $tarefa->getDataConclusao());
    }

    public function testConclusaoDeEmRevisao(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);
        $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
        $tarefa->setDataConclusao(new \DateTimeImmutable());

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        $this->assertNotNull($tarefa->getDataConclusao());
    }

    public function testDataConclusaoNaoDefinidaAntesDeContluir(): void
    {
        $tarefa = $this->novaTarefa();

        $this->assertNull($tarefa->getDataConclusao());
    }

    // ──────────────────────────────────────────────────────────────────
    // Prazo
    // ──────────────────────────────────────────────────────────────────

    public function testPrazoNuloRetornaInfoVazia(): void
    {
        $tarefa = $this->novaTarefa();

        $info = $tarefa->getPrazoInfo();

        $this->assertSame('-', $info['texto']);
        $this->assertSame('', $info['cor']);
        $this->assertNull($info['dias']);
    }

    public function testPrazoConcluidaRetornaInfoVazia(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setPrazo(new \DateTimeImmutable('+10 days'));
        $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);

        $info = $tarefa->getPrazoInfo();

        $this->assertSame('-', $info['texto']);
    }

    public function testPrazoFuturoLongeRetornaCorSuccess(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setPrazo(new \DateTimeImmutable('+10 days'));

        $info = $tarefa->getPrazoInfo();

        $this->assertSame('success', $info['cor']);
        $this->assertStringContainsString('dias', $info['texto']);
    }

    public function testPrazoProximoRetornaCorWarning(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setPrazo(new \DateTimeImmutable('+2 days'));

        $info = $tarefa->getPrazoInfo();

        $this->assertSame('warning', $info['cor']);
    }

    public function testPrazoVenceHojeRetornaCorDanger(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setPrazo(new \DateTimeImmutable('today'));

        $info = $tarefa->getPrazoInfo();

        $this->assertSame('danger', $info['cor']);
        $this->assertSame('Vence hoje', $info['texto']);
    }

    public function testPrazoAtrasadoRetornaTextoAtrasado(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setPrazo(new \DateTimeImmutable('-3 days'));

        $info = $tarefa->getPrazoInfo();

        $this->assertSame('danger', $info['cor']);
        $this->assertStringContainsString('atrasado', $info['texto']);
    }

    public function testPrazoAmanha(): void
    {
        $tarefa = $this->novaTarefa();
        $tarefa->setPrazo(new \DateTimeImmutable('tomorrow'));

        $info = $tarefa->getPrazoInfo();

        $this->assertSame('1 dia', $info['texto']);
        $this->assertSame('warning', $info['cor']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Responsável e criador
    // ──────────────────────────────────────────────────────────────────

    public function testDefinirResponsavel(): void
    {
        $tarefa = $this->novaTarefa();
        $user   = $this->novoUsuario('resp@test.com');

        $tarefa->addResponsavel($user);

        $this->assertCount(1, $tarefa->getResponsaveis());
        $this->assertSame($user, $tarefa->getResponsaveis()->first());
        $this->assertSame($user, $tarefa->getResponsavel());
    }

    public function testGetResponsavelRetornaPrimeiroResponsavel(): void
    {
        $tarefa  = $this->novaTarefa();
        $first   = $this->novoUsuario('first@test.com');
        $second  = $this->novoUsuario('second@test.com');

        $tarefa->addResponsavel($first);
        $tarefa->addResponsavel($second);

        $this->assertSame($first, $tarefa->getResponsavel());
    }

    public function testGetResponsavelRetornaNullQuandoSemResponsaveis(): void
    {
        $tarefa = $this->novaTarefa();

        $this->assertNull($tarefa->getResponsavel());
    }

    public function testDefinirCriadoPor(): void
    {
        $tarefa = $this->novaTarefa();
        $user   = $this->novoUsuario('criador@test.com');

        $tarefa->setCriadoPor($user);

        $this->assertSame($user, $tarefa->getCriadoPor());
    }

    public function testRemoverResponsavel(): void
    {
        $tarefa = $this->novaTarefa();
        $user   = $this->novoUsuario();

        $tarefa->addResponsavel($user);
        $tarefa->removeResponsavel($user);

        $this->assertCount(0, $tarefa->getResponsaveis());
    }

    // ──────────────────────────────────────────────────────────────────
    // Mensagens
    // ──────────────────────────────────────────────────────────────────

    public function testAdicionarMensagem(): void
    {
        $tarefa   = $this->novaTarefa();
        $usuario  = $this->novoUsuario();
        $mensagem = (new TarefaMensagem())
            ->setUsuario($usuario)
            ->setMensagem('Revisei o documento.');

        $tarefa->addMensagem($mensagem);

        $this->assertCount(1, $tarefa->getMensagens());
        $this->assertSame($tarefa, $mensagem->getTarefa());
    }

    public function testAdicionarMesmaMensagemDuasVezesNaoDuplica(): void
    {
        $tarefa   = $this->novaTarefa();
        $mensagem = (new TarefaMensagem())
            ->setUsuario($this->novoUsuario())
            ->setMensagem('Texto.');

        $tarefa->addMensagem($mensagem);
        $tarefa->addMensagem($mensagem);

        $this->assertCount(1, $tarefa->getMensagens());
    }

    public function testRemoverMensagem(): void
    {
        $tarefa   = $this->novaTarefa();
        $mensagem = (new TarefaMensagem())
            ->setUsuario($this->novoUsuario())
            ->setMensagem('Texto.');

        $tarefa->addMensagem($mensagem);
        $tarefa->removeMensagem($mensagem);

        $this->assertCount(0, $tarefa->getMensagens());
        $this->assertNull($mensagem->getTarefa());
    }

    public function testVariasMensagensAdicionadas(): void
    {
        $tarefa  = $this->novaTarefa();
        $usuario = $this->novoUsuario();

        for ($i = 1; $i <= 3; $i++) {
            $tarefa->addMensagem(
                (new TarefaMensagem())->setUsuario($usuario)->setMensagem("Mensagem {$i}")
            );
        }

        $this->assertCount(3, $tarefa->getMensagens());
    }

    // ──────────────────────────────────────────────────────────────────
    // Callback PreUpdate — dataAlteracao
    // ──────────────────────────────────────────────────────────────────

    public function testDataAlteracaoEhNulaAntesDoPrimeiroUpdate(): void
    {
        $tarefa = $this->novaTarefa();

        $this->assertNull($tarefa->getDataAlteracao());
    }

    public function testAtualizarDataAlteracaoDefineDataAtual(): void
    {
        $tarefa = $this->novaTarefa();

        $before = new \DateTimeImmutable();
        $tarefa->atualizarDataAlteracao();
        $after = new \DateTimeImmutable();

        $dataAlteracao = $tarefa->getDataAlteracao();
        $this->assertNotNull($dataAlteracao);
        $this->assertGreaterThanOrEqual($before, $dataAlteracao);
        $this->assertLessThanOrEqual($after, $dataAlteracao);
    }

    public function testAtualizarDataAlteracaoMultiplasVezesAtualiza(): void
    {
        $tarefa = $this->novaTarefa();

        $tarefa->atualizarDataAlteracao();
        $primeira = $tarefa->getDataAlteracao();

        // Garante diferença de tempo detectável
        usleep(1000);

        $tarefa->atualizarDataAlteracao();
        $segunda = $tarefa->getDataAlteracao();

        $this->assertNotSame($primeira, $segunda);
    }

    // ──────────────────────────────────────────────────────────────────
    // Ciclo completo de vida
    // ──────────────────────────────────────────────────────────────────

    /**
     * Simula o fluxo completo: criar → pendente → enviar para revisão
     * → devolver → enviar novamente → concluir.
     */
    public function testCicloDeVidaCompleto(): void
    {
        $responsavel = $this->novoUsuario('resp@test.com');
        $gestor      = $this->novoUsuario('gestor@test.com');
        $pasta       = new Pasta();

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Elaborar recurso');
        $tarefa->setDescricao('Redigir recurso ao INSS.');
        $tarefa->setPasta($pasta);
        $tarefa->setCriadoPor($gestor);
        $tarefa->addResponsavel($responsavel);
        $tarefa->setPrazo(new \DateTimeImmutable('+7 days'));

        // 1. Criada → Pendente
        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
        $this->assertNull($tarefa->getDataConclusao());

        // 2. Responsável comenta e envia para revisão
        $msg1 = (new TarefaMensagem())->setUsuario($responsavel)->setMensagem('Pronto para revisão.');
        $tarefa->addMensagem($msg1);
        $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);

        $this->assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus());
        $this->assertCount(1, $tarefa->getMensagens());

        // 3. Gestor devolve com comentário
        $msg2 = (new TarefaMensagem())->setUsuario($gestor)->setMensagem('Ajustar o parágrafo 3.');
        $tarefa->addMensagem($msg2);
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);

        $this->assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
        $this->assertCount(2, $tarefa->getMensagens());

        // 4. Responsável corrige e envia de novo
        $msg3 = (new TarefaMensagem())->setUsuario($responsavel)->setMensagem('Ajustado conforme solicitado.');
        $tarefa->addMensagem($msg3);
        $tarefa->setStatus(Tarefa::STATUS_EM_REVISAO);

        $this->assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus());
        $this->assertCount(3, $tarefa->getMensagens());

        // 5. Gestor conclui
        $tarefa->setStatus(Tarefa::STATUS_CONCLUIDA);
        $tarefa->setDataConclusao(new \DateTimeImmutable());

        $this->assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        $this->assertNotNull($tarefa->getDataConclusao());
        $this->assertSame('-', $tarefa->getPrazoInfo()['texto']); // prazo oculto ao concluir
    }

    // ──────────────────────────────────────────────────────────────────
    // Constantes de status
    // ──────────────────────────────────────────────────────────────────

    public function testConstantesDeStatusSaoStrings(): void
    {
        $this->assertIsString(Tarefa::STATUS_PENDENTE);
        $this->assertIsString(Tarefa::STATUS_EM_REVISAO);
        $this->assertIsString(Tarefa::STATUS_CONCLUIDA);
    }

    public function testConstantesDeStatusSaoDiferentes(): void
    {
        $this->assertNotSame(Tarefa::STATUS_PENDENTE,   Tarefa::STATUS_EM_REVISAO);
        $this->assertNotSame(Tarefa::STATUS_PENDENTE,   Tarefa::STATUS_CONCLUIDA);
        $this->assertNotSame(Tarefa::STATUS_EM_REVISAO, Tarefa::STATUS_CONCLUIDA);
    }
}
