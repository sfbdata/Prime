<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\UseCase\ConsultarContasUseCase;
use App\Master\UseCase\ConsultarVisaoGeralUseCase;
use App\Repository\TenantRepository;
use App\Tenant\Service\CodigoDaContaDoEscritorio;
use App\Tenant\Service\GeradorDeCodigoDaConta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * O painel monta os retratos do espaço EM LOTE (`SituacaoDoEspaco::retratarVarios`, commit c29b7a8c da f6):
 * uma consulta de espaços para todas as contas, o Free e a configuração lidos uma vez. Nunca uma por conta.
 */
#[CoversClass(ConsultarContasUseCase::class)]
#[CoversClass(ConsultarVisaoGeralUseCase::class)]
final class RetratosEmLoteNoPainelTest extends TestCase
{
    private const GB = 1073741824;

    private PainelMasterRepository&MockObject $repositorio;
    private EspacoDoEscritorioRepository&MockObject $espacos;
    private PlanoRepository&MockObject $planos;
    private SituacaoDoEspaco $situacao;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(PainelMasterRepository::class);
        $this->repositorio->method('escritorios')->willReturn([$this->escritorio(1), $this->escritorio(2), $this->escritorio(3)]);
        $this->repositorio->method('consumoPorEscritorio')->willReturn([1 => ['documentos' => 1, 'bytes' => 9 * self::GB, 'bytesRecentes' => 0]]);

        $free = new Plano(Plano::CODIGO_FREE, 'Free', 10, 0, 'Grátis', 1, 3);
        $semLimite = new EspacoDoEscritorio(new Tenant(), $free);
        $semLimite->definirLimite(null, true, 'dono', null);
        $this->espacos = $this->createMock(EspacoDoEscritorioRepository::class);
        $this->espacos->expects($this->once())->method('dosTenants')->with([1, 2, 3])->willReturn([3 => $semLimite]);
        $this->espacos->expects($this->never())->method('doTenant');
        $this->planos = $this->createMock(PlanoRepository::class);
        $this->planos->expects($this->once())->method('free')->willReturn($free);
        $configuracoes = $this->createMock(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());

        $this->situacao = new SituacaoDoEspaco($this->createMock(ConsumoDeArmazenamentoRepository::class), $this->espacos, $this->planos, $configuracoes, $this->createStub(BonusDeEspacoRepository::class), new MockClock());
    }

    #[TestDox('lista de contas: uma consulta de espaços para as três contas, o Free uma vez, e o espaço de cada uma')]
    public function testContas(): void
    {
        $contas = array_column((new ConsultarContasUseCase($this->repositorio, $this->relogio(), 365, $this->situacao, $this->createStub(ConvenioDaUfRepository::class), new CodigoDaContaDoEscritorio(new GeradorDeCodigoDaConta(), $this->createStub(TenantRepository::class)), $this->createStub(TenantRepository::class), $this->createStub(EntityManagerInterface::class)))->executar(), null, 'id');

        self::assertSame([10, 90, 'alerta'], [$contas[1]['espaco']['limiteGb'], $contas[1]['espaco']['percentual'], $contas[1]['espaco']['faixa']]);
        self::assertSame(10, $contas[2]['espaco']['limiteGb']);
        self::assertTrue($contas[3]['espaco']['semLimite']);
    }

    #[TestDox('lista de contas: cada linha leva o número da conta, o mesmo "conta Nº" da Contribua (spec perfis-1.2-permissoes-e-hierarquia A3)')]
    public function testNumeroDaConta(): void
    {
        $contas = (new ConsultarContasUseCase($this->repositorio, $this->relogio(), 365, $this->situacao, $this->createStub(ConvenioDaUfRepository::class), new CodigoDaContaDoEscritorio(new GeradorDeCodigoDaConta(), $this->createStub(TenantRepository::class)), $this->createStub(TenantRepository::class), $this->createStub(EntityManagerInterface::class)))->executar();

        self::assertSame(['000001', '000002', '000003'], array_column($contas, 'numero'));
    }

    #[TestDox('KPI de armazenamento: em lote também, com a conta sem limite à parte')]
    public function testVisaoGeral(): void
    {
        $configuracoes = $this->createMock(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());

        $kpi = (new ConsultarVisaoGeralUseCase($this->repositorio, $this->relogio(), $this->situacao, $configuracoes, $this->createStub(IndicacaoRepository::class)))->executar()['armazenamento'];

        self::assertSame([20, 9 * self::GB, 45, 1, 0], [$kpi['cotaGb'], $kpi['bytesUsados'], $kpi['percentual'], $kpi['contasSemLimite'], $kpi['bytesSemLimite']]);
    }

    /** @return array{id: int, nome: string, cidade: ?string, estado: ?string, criado_em: string, ativo: bool, titular: ?string, do_master: bool, excluido_em: ?string} */
    private function escritorio(int $id): array
    {
        return ['id' => $id, 'nome' => 'Conta ' . $id, 'cidade' => null, 'estado' => null, 'criado_em' => '2026-01-01 00:00:00', 'ativo' => true, 'titular' => null, 'do_master' => false, 'excluido_em' => null];
    }

    private function relogio(): ClockInterface
    {
        $relogio = $this->createMock(ClockInterface::class);
        $relogio->method('now')->willReturn(new \DateTimeImmutable('2026-10-19 12:00:00'));

        return $relogio;
    }
}
