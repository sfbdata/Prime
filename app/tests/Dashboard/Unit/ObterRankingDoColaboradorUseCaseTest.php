<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\UseCase\ObterRankingDoColaboradorUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Cartão do colaborador: posição no ranking da janela, registros e a guarda de posse (IDOR). */
#[CoversClass(ObterRankingDoColaboradorUseCase::class)]
#[Group('dashboard')]
final class ObterRankingDoColaboradorUseCaseTest extends TestCase
{
    private UserRepository&MockObject $userRepo;
    private TarefaRepository&MockObject $tarefaRepo;
    private MetricasExtrasDoDashboardRepository&MockObject $metricas;
    private Tenant $tenant;
    private ObterRankingDoColaboradorUseCase $sut;

    protected function setUp(): void
    {
        $this->userRepo   = $this->createMock(UserRepository::class);
        $this->tarefaRepo = $this->createMock(TarefaRepository::class);
        $this->metricas   = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $this->tenant     = $this->createStub(Tenant::class);

        $this->userRepo->method('findColaboradoresAtivosPorTenant')->willReturn([
            $this->usuario(7, 'Ana Lima'), $this->usuario(8, 'Bruno Reis'), $this->usuario(9, 'Carla Dias'),
        ]);
        $this->userRepo->method('findCargoPorColaboradores')->willReturn([7 => 'Advogada', 8 => null, 9 => 'Estagiária']);

        $this->sut = new ObterRankingDoColaboradorUseCase($this->userRepo, $this->tarefaRepo, $this->metricas);
    }

    private function usuario(int $id, string $nome): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        $u->method('getFullName')->willReturn($nome);

        return $u;
    }

    #[TestDox('Trimestre: consulta do 1º dia do trimestre até hoje, com a equipe inteira; concluídas = total − ativas; devolve posição, total da equipe e registros')]
    public function testPosicaoNoTrimestre(): void
    {
        $periodo = ['data_de' => '2026-07-01', 'data_ate' => '2026-08-14'];
        $this->tarefaRepo->expects(self::once())->method('countPorResponsavel')
            ->with($this->tenant, $periodo, [4])->willReturn([7 => 6, 8 => 4, 9 => 1]);
        $this->tarefaRepo->expects(self::once())->method('countAtivasPorResponsavel')
            ->with($this->tenant, $periodo, [4])->willReturn([7 => 1, 8 => 2, 9 => 1]);
        $this->tarefaRepo->method('countMetasGlobal')->with($this->tenant, $periodo, [4])
            ->willReturn(['concluidas' => 6, 'total' => 10]);
        $this->metricas->expects(self::once())->method('contarRegistrosPorAutor')
            ->with($this->tenant, $periodo, [4])
            ->willReturn([8 => 11]);

        $out = $this->sut->executar($this->tenant, 8, 'trimestre', new \DateTimeImmutable('2026-08-14 10:00'), [4]);

        self::assertSame(['Bruno Reis', null, 'trimestre', 2, 3, 2, 6, 11, false], [
            $out->nome, $out->cargo, $out->janela, $out->posicao, $out->pessoas, $out->metasConcluidas,
            $out->metasConcluidasEquipe, $out->registros, $out->campeao,
        ]);
    }

    #[TestDox('O primeiro sozinho é o campeão; janela desconhecida vira Mês')]
    public function testCampeaoEJanelaPadrao(): void
    {
        $this->tarefaRepo->method('countPorResponsavel')->willReturn([7 => 5, 8 => 2]);
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 7, 'total' => 7]);

        $out = $this->sut->executar($this->tenant, 7, 'semana', new \DateTimeImmutable('2026-08-14'));

        self::assertSame([1, true, 'mes', '2026-08-01'], [$out->posicao, $out->campeao, $out->janela, $out->dataDe]);
    }

    #[TestDox('Id que não é colaborador ativo do escritório: exceção, sem consultar nada (IDOR)')]
    public function testForaDoEscritorio(): void
    {
        $this->tarefaRepo->expects(self::never())->method(self::anything());
        $this->metricas->expects(self::never())->method(self::anything());

        $this->expectException(ColaboradorForaDoEscritorioException::class);
        $this->sut->executar($this->tenant, 999, 'mes', new \DateTimeImmutable('2026-08-14'));
    }
}
