<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObterDadosDashboardUseCase::class)]
#[Group('dashboard')]
final class ObterDadosDashboardUseCaseTest extends TestCase
{
    private PastaRepository  $pastaRepo;
    private TarefaRepository $tarefaRepo;
    private UserRepository   $userRepo;
    private Tenant           $tenant;
    private \DateTimeImmutable $referencia;
    private ObterDadosDashboardUseCase $sut;

    protected function setUp(): void
    {
        $this->pastaRepo  = $this->createMock(PastaRepository::class);
        $this->tarefaRepo = $this->createMock(TarefaRepository::class);
        $this->userRepo   = $this->createMock(UserRepository::class);
        $this->tenant     = $this->createMock(Tenant::class);
        $this->referencia = new \DateTimeImmutable('2024-01-10 00:00:00');

        $this->sut = new ObterDadosDashboardUseCase(
            $this->pastaRepo,
            $this->tarefaRepo,
            $this->userRepo,
        );
    }

    private function configureMapasVazios(): void
    {
        $this->tarefaRepo->method('countPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([]);
    }

    private function mockUser(int $id, string $nome): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getFullName')->willReturn($nome);

        return $user;
    }

    // ─── CARDS ───────────────────────────────────────────────────────

    #[TestDox('cards retornam totalMetasAtivas, demandasUrgentes e metaGlobalPercent corretos')]
    public function testCardsRetornamValoresCorretos(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(7);
        $this->pastaRepo->method('countUrgentes')->willReturn(3);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 2, 'total' => 4]);

        $this->configureMapasVazios();
        $this->userRepo->method('findBy')->willReturn([]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertSame(7, $output->totalMetasAtivas);
        self::assertSame(3, $output->demandasUrgentes);
        self::assertSame(50, $output->metaGlobalPercent);
    }

    #[TestDox('metaGlobalPercent é 0 quando total de metas é zero (guard divisão por zero)')]
    public function testMetaGlobalComTotalZeroRetornaZeroPorcento(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(0);
        $this->pastaRepo->method('countUrgentes')->willReturn(0);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 0]);

        $this->configureMapasVazios();
        $this->userRepo->method('findBy')->willReturn([]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertSame(0, $output->metaGlobalPercent);
    }

    #[TestDox('metaGlobalPercent usa round: 1/3 → 33% (não 34%)')]
    public function testMetaGlobalArredondamento(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(2);
        $this->pastaRepo->method('countUrgentes')->willReturn(0);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 1, 'total' => 3]);

        $this->configureMapasVazios();
        $this->userRepo->method('findBy')->willReturn([]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertSame(33, $output->metaGlobalPercent);
    }

    // ─── TABELA — guard e casos extremos ─────────────────────────────

    #[TestDox('porAdvogado é vazio quando nenhum responsável existe (guard $allIds vazio)')]
    public function testSemResponsaveisRetornaTabelaVazia(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(0);
        $this->pastaRepo->method('countUrgentes')->willReturn(0);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 0]);

        $this->configureMapasVazios();
        // findBy NÃO deve ser chamado quando $allIds está vazio
        $this->userRepo->expects(self::never())->method('findBy');

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertSame([], $output->porAdvogado);
    }

    #[TestDox('userId presente nos mapas mas não retornado por findBy gera linha com nomeAdvogado vazio')]
    public function testUserIdOrfaoRetornaNomeVazio(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(3);
        $this->pastaRepo->method('countUrgentes')->willReturn(0);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 3]);

        $this->tarefaRepo->method('countPorResponsavel')->willReturn([42 => 3]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([42 => 3]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([]);

        // findBy retorna array vazio — user 42 foi deletado
        $this->userRepo->method('findBy')->willReturn([]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertCount(1, $output->porAdvogado);
        self::assertSame(42, $output->porAdvogado[0]->userId);
        self::assertSame('', $output->porAdvogado[0]->nomeAdvogado);
        self::assertSame(3, $output->porAdvogado[0]->totalMetas);
    }

    // ─── TABELA — union de mapas ──────────────────────────────────────

    #[TestDox('advogado com pasta mas sem tarefa tem todos os campos de tarefa zerados')]
    public function testAdvogadoComPastaMasSemTarefa(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(0);
        $this->pastaRepo->method('countUrgentes')->willReturn(2);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 0]);

        $this->tarefaRepo->method('countPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countPorResponsavel')->willReturn([10 => 5]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([10 => 3]);

        $u10 = $this->mockUser(10, 'Ana Lima');
        $this->userRepo->method('findBy')->willReturn([$u10]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertCount(1, $output->porAdvogado);
        $linha = $output->porAdvogado[0];
        self::assertSame(10, $linha->userId);
        self::assertSame('Ana Lima', $linha->nomeAdvogado);
        self::assertSame(0, $linha->totalMetas);
        self::assertSame(0, $linha->metasAtivas);
        self::assertSame(0, $linha->metasVencidas);
        self::assertSame(0, $linha->prazosProximos);
        self::assertSame(5, $linha->totalDemandas);
        self::assertSame(3, $linha->demandasAtivas);
    }

    #[TestDox('advogado com tarefa mas sem pasta tem todos os campos de pasta zerados')]
    public function testAdvogadoComTarefaMasSemPasta(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(4);
        $this->pastaRepo->method('countUrgentes')->willReturn(0);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 1, 'total' => 4]);

        $this->tarefaRepo->method('countPorResponsavel')->willReturn([20 => 4]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([20 => 3]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([20 => 1]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([20 => 2]);
        $this->pastaRepo->method('countPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([]);

        $u20 = $this->mockUser(20, 'Carlos Melo');
        $this->userRepo->method('findBy')->willReturn([$u20]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertCount(1, $output->porAdvogado);
        $linha = $output->porAdvogado[0];
        self::assertSame(20, $linha->userId);
        self::assertSame(4, $linha->totalMetas);
        self::assertSame(3, $linha->metasAtivas);
        self::assertSame(1, $linha->metasVencidas);
        self::assertSame(2, $linha->prazosProximos);
        self::assertSame(0, $linha->totalDemandas);
        self::assertSame(0, $linha->demandasAtivas);
    }

    // ─── TABELA — linha completa e ordenação ─────────────────────────

    #[TestDox('linha completa: todos os 6 campos preenchidos corretamente')]
    public function testLinhaCompleta(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(5);
        $this->pastaRepo->method('countUrgentes')->willReturn(1);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 2, 'total' => 5]);

        $this->tarefaRepo->method('countPorResponsavel')->willReturn([1 => 5]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([1 => 4]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([1 => 2]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([1 => 3]);
        $this->pastaRepo->method('countPorResponsavel')->willReturn([1 => 7]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([1 => 6]);

        $u1 = $this->mockUser(1, 'Beatriz Costa');
        $this->userRepo->method('findBy')->willReturn([$u1]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertCount(1, $output->porAdvogado);
        $linha = $output->porAdvogado[0];
        self::assertSame(1, $linha->userId);
        self::assertSame('Beatriz Costa', $linha->nomeAdvogado);
        self::assertSame(5, $linha->totalMetas);
        self::assertSame(4, $linha->metasAtivas);
        self::assertSame(2, $linha->metasVencidas);
        self::assertSame(3, $linha->prazosProximos);
        self::assertSame(7, $linha->totalDemandas);
        self::assertSame(6, $linha->demandasAtivas);
    }

    #[TestDox('porAdvogado ordenado por totalMetas decrescente')]
    public function testOrdenacaoPorTotalMetasDecrescente(): void
    {
        $this->tarefaRepo->method('countMetasAtivas')->willReturn(9);
        $this->pastaRepo->method('countUrgentes')->willReturn(0);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 9]);

        $this->tarefaRepo->method('countPorResponsavel')->willReturn([1 => 3, 2 => 5, 3 => 1]);
        $this->tarefaRepo->method('countAtivasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countVencidasPorResponsavel')->willReturn([]);
        $this->tarefaRepo->method('countPrazosProximosPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countPorResponsavel')->willReturn([]);
        $this->pastaRepo->method('countAtivasPorResponsavel')->willReturn([]);

        $u1 = $this->mockUser(1, 'Alice');
        $u2 = $this->mockUser(2, 'Bruno');
        $u3 = $this->mockUser(3, 'Carla');
        $this->userRepo->method('findBy')->willReturn([$u1, $u2, $u3]);

        $output = $this->sut->executar($this->tenant, $this->referencia);

        self::assertCount(3, $output->porAdvogado);
        self::assertSame(2, $output->porAdvogado[0]->userId); // totalMetas=5
        self::assertSame(1, $output->porAdvogado[1]->userId); // totalMetas=3
        self::assertSame(3, $output->porAdvogado[2]->userId); // totalMetas=1
    }
}
