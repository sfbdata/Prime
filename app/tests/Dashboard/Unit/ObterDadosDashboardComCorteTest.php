<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\Entity\DashboardZeramento;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\Repository\DashboardZeramentoRepository;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O painel com o "Zerar" (DASH-014): a coluna zerada é recontada a partir do corte (o repositório
 * recebe `contar_desde` junto do período e usa o mais tardio), só para quem tem corte; o troféu
 * acompanha; a linha diz quais colunas estão zeradas e a tela recebe os cortes.
 */
#[CoversClass(ObterDadosDashboardUseCase::class)]
#[Group('dashboard')]
final class ObterDadosDashboardComCorteTest extends TestCase
{
    private TarefaRepository&MockObject $tarefaRepo;
    private MetricasExtrasDoDashboardRepository&MockObject $metricas;
    private DashboardZeramentoRepository&MockObject $zeramentos;
    private Tenant $tenant;
    private ObterDadosDashboardUseCase $sut;
    /** @var array<int, User> */
    private array $pessoas = [];

    protected function setUp(): void
    {
        $this->tarefaRepo = $this->createMock(TarefaRepository::class);
        $this->metricas   = $this->createMock(MetricasExtrasDoDashboardRepository::class);
        $this->zeramentos = $this->createMock(DashboardZeramentoRepository::class);
        $this->tenant     = $this->createStub(Tenant::class);
        $userRepo = $this->createMock(UserRepository::class);
        foreach ([7 => 'Ana', 8 => 'Bruno'] as $id => $nome) {
            $u = $this->createStub(User::class);
            $u->method('getId')->willReturn($id);
            $u->method('getFullName')->willReturn($nome);
            $this->pessoas[$id] = $u;
        }
        $userRepo->method('findColaboradoresAtivosPorTenant')->willReturn(array_values($this->pessoas));
        $this->tarefaRepo->method('countMetasGlobal')->willReturn(['concluidas' => 0, 'total' => 0]);

        $this->sut = new ObterDadosDashboardUseCase(
            $this->createMock(PastaRepository::class),
            $this->tarefaRepo,
            $userRepo,
            $this->createMock(DashboardFotoRepository::class),
            $this->metricas,
            $this->zeramentos,
        );
    }

    private function zerou(string $coluna, ?int $pessoa, string $desde): DashboardZeramento
    {
        return new DashboardZeramento($this->tenant, $coluna, $pessoa === null ? null : $this->pessoas[$pessoa], new \DateTimeImmutable($desde), new User());
    }

    #[TestDox('Total metas zerado de Ana: só ela é recontada a partir do corte; o troféu passa a Bruno; a tela recebe a coluna zerada')]
    public function testColunaDeUmaPessoaRecontadaDesdeOCorte(): void
    {
        $this->zeramentos->method('listarAtivos')->willReturn([$this->zerou('metas', 7, '2026-10-15 14:30')]);
        $filtros = ['data_de' => '2026-10-01', 'data_ate' => '2026-10-23'];
        $this->tarefaRepo->method('countPorResponsavel')->willReturnCallback(
            static function (Tenant $t, array $f): array {
                if (isset($f['contar_desde'])) {
                    return $f['contar_desde']->format('Y-m-d H:i') === '2026-10-15 14:30' && $f['data_de'] === '2026-10-01' ? [7 => 1, 8 => 99] : [];
                }

                return ($f['data_de'] ?? null) === '2026-10-01' ? [7 => 10, 8 => 3] : [];
            },
        );

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'), $filtros);

        $linhas = [];
        foreach ($out->porAdvogado as $l) {
            $linhas[$l->userId] = $l;
        }
        self::assertSame(1, $linhas[7]->totalMetas, 'Ana: só o que entrou depois do corte');
        self::assertSame(3, $linhas[8]->totalMetas, 'Bruno não tem corte: a recontagem não vaza para ele');
        self::assertSame(['metas'], $linhas[7]->colunasZeradas);
        self::assertSame([], $linhas[8]->colunasZeradas);
        self::assertSame(8, $out->campeaoId, 'troféu com o corte: Bruno 3 concluídas contra 1 de Ana');
        self::assertArrayHasKey(7, $out->zeramentos['metas']['pessoas']);
        self::assertNull($out->zeramentos['metas']['todos']);
    }

    #[TestDox('Relatório zerado da equipe: a métrica extra é recontada desde o corte para todos')]
    public function testExtraZeradoDaEquipe(): void
    {
        $this->zeramentos->method('listarAtivos')->willReturn([$this->zerou(Extras::RELATORIO, null, '2026-10-20 08:00')]);
        $this->tarefaRepo->method('countPorResponsavel')->willReturn([]);
        $this->metricas->method('contarRegistrosPorAutor')->willReturnCallback(
            static fn (Tenant $t, array $f): array => isset($f['contar_desde']) ? [7 => 1] : [7 => 4, 8 => 2],
        );

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'), ['data_de' => '2026-10-01'], [Extras::RELATORIO]);

        $porPessoa = [];
        foreach ($out->porAdvogado as $l) {
            $porPessoa[$l->userId] = $l->extras[Extras::RELATORIO];
        }
        self::assertSame([7 => 1, 8 => 0], $porPessoa);
        self::assertSame(1, $out->totaisExtras[Extras::RELATORIO]);
    }

    #[TestDox('Sem zeramento ativo, nenhuma consulta com corte e nada marcado como zerado')]
    public function testSemZeramento(): void
    {
        $this->zeramentos->method('listarAtivos')->willReturn([]);
        $this->tarefaRepo->method('countPorResponsavel')->willReturnCallback(
            static function (Tenant $t, array $f): array {
                self::assertArrayNotHasKey('contar_desde', $f);

                return [7 => 2];
            },
        );

        $out = $this->sut->executar($this->tenant, new \DateTimeImmutable('2026-10-23'));

        self::assertSame([], $out->zeramentos);
        self::assertSame([], $out->porAdvogado[0]->colunasZeradas);
    }
}
