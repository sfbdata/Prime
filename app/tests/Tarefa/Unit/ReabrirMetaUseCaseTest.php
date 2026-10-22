<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\MetaJaSeguiuAdianteException;
use App\Tarefa\UseCase\ReabrirMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReabrirMetaUseCase::class)]
final class ReabrirMetaUseCaseTest extends TestCase
{
    private TransacaoDaPassagemFalsa $transacao;
    private ReabrirMetaUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->transacao = new TransacaoDaPassagemFalsa();
        $this->useCase   = new ReabrirMetaUseCase($this->transacao);
        $this->tenant  = new Tenant();
    }

    private function meta(string $status): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Protocolar recurso');
        $tarefa->setDescricao('...');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($this->tenant);
        $tarefa->setStatus($status);
        if ($status === Tarefa::STATUS_CONCLUIDA) {
            $tarefa->setDataConclusao(new \DateTimeImmutable('2026-09-10 10:00'));
        }

        return $tarefa;
    }

    #[TestDox('concluída volta a pendente, sem data de conclusão, numa transação com a meta travada')]
    public function testReabre(): void
    {
        $tarefa = $this->meta(Tarefa::STATUS_CONCLUIDA);

        self::assertTrue($this->useCase->executar($tarefa, $this->tenant));
        self::assertSame(1, $this->transacao->concluidas);
        self::assertSame(Tarefa::STATUS_PENDENTE, $tarefa->getStatus());
        self::assertNull($tarefa->getDataConclusao());
    }

    /** @return iterable<string, array{string}> */
    public static function abertas(): iterable
    {
        yield 'pendente' => [Tarefa::STATUS_PENDENTE];
        yield 'em revisão' => [Tarefa::STATUS_EM_REVISAO];
    }

    #[DataProvider('abertas')]
    #[TestDox('meta que não está concluída ($_dataName) não muda')]
    public function testNaoConcluidaNaoMuda(string $status): void
    {
        $tarefa = $this->meta($status);

        self::assertFalse($this->useCase->executar($tarefa, $this->tenant));
        self::assertSame($status, $tarefa->getStatus());
    }

    #[TestDox('Meta que a passagem de etapa já levou adiante (ato "Etapa concluída"): recusa, nada muda')]
    public function testMetaQueSeguiuAdianteNaoReabre(): void
    {
        $tarefa = $this->meta(Tarefa::STATUS_CONCLUIDA);
        $tarefa->addMensagem((new TarefaMensagem())->setAcao(AcaoDoDespacho::Etapa));
        self::assertTrue(ReabrirMetaUseCase::seguiuAdiante($tarefa));

        try {
            $this->useCase->executar($tarefa, $this->tenant);
            self::fail('Devia recusar.');
        } catch (MetaJaSeguiuAdianteException $e) {
            self::assertSame('metas.passagem.ja_seguiu_adiante', $e->getMessage());
        }
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        self::assertNotNull($tarefa->getDataConclusao());
    }

    #[TestDox('Concluída pelo concluir simples (só despachos comuns no histórico): reabre')]
    public function testConcluidaSemPassagemReabre(): void
    {
        $tarefa = $this->meta(Tarefa::STATUS_CONCLUIDA);
        $tarefa->addMensagem((new TarefaMensagem())->setAcao(AcaoDoDespacho::Despacho));

        self::assertFalse(ReabrirMetaUseCase::seguiuAdiante($tarefa));
        self::assertTrue($this->useCase->executar($tarefa, $this->tenant));
    }

    #[TestDox('Corrida: a tela tinha a meta concluída, mas no banco ela já foi reaberta; nada muda')]
    public function testCorridaComAMetaVelhaEmMemoria(): void
    {
        $tarefa = $this->meta(Tarefa::STATUS_CONCLUIDA);
        $this->transacao->noBanco = static function (Tarefa $meta): void {
            $meta->setStatus(Tarefa::STATUS_EM_REVISAO)->setDataConclusao(null);
        };

        self::assertFalse($this->useCase->executar($tarefa, $this->tenant));
        self::assertSame(Tarefa::STATUS_EM_REVISAO, $tarefa->getStatus(), 'não volta a pendente por cima do que foi gravado');
    }

    #[TestDox('meta de outro escritório: recusa sem gravar')]
    public function testOutroEscritorio(): void
    {
        $tarefa = $this->meta(Tarefa::STATUS_CONCLUIDA);

        try {
            $this->useCase->executar($tarefa, new Tenant());
            self::fail('deveria recusar');
        } catch (\LogicException) {
        }

        self::assertSame(Tarefa::STATUS_CONCLUIDA, $tarefa->getStatus());
        self::assertNotNull($tarefa->getDataConclusao());
        self::assertSame(0, $this->transacao->concluidas);
    }
}
