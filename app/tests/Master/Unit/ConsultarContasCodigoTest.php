<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Tenant\Tenant;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\UseCase\ConsultarContasUseCase;
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
 * Código da conta (BJ-XXXX-XXXX-XXXX) e data de abertura na lista de "Contas e escritórios" (desenho 05 VS 30.10.26,
 * L173). Contas sem código ganham um na listagem, com um flush só e sem repetir; as que já têm ficam como estão.
 */
#[CoversClass(ConsultarContasUseCase::class)]
final class ConsultarContasCodigoTest extends TestCase
{
    private PainelMasterRepository&MockObject $repositorio;
    private TenantRepository&MockObject $tenants;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(PainelMasterRepository::class);
        $this->tenants = $this->createMock(TenantRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    #[TestDox('a conta com código devolve o código como está, sem buscar o Tenant nem dar flush; criadoEm sai em ISO')]
    public function testCodigoExistente(): void
    {
        $this->repositorio->method('escritorios')->willReturn([$this->escritorio(1, 'BJ-ABCD-EFGH-JKLM')]);
        $this->tenants->expects($this->never())->method('find');
        $this->em->expects($this->never())->method('flush');

        $conta = $this->caso()->executar()[0];

        self::assertSame('BJ-ABCD-EFGH-JKLM', $conta['codigo']);
        self::assertStringStartsWith('2026-01-01T00:00:00', $conta['criadoEm']);
        self::assertSame('000001', $conta['numero']);
    }

    #[TestDox('contas sem código ganham um cada (formato e alfabeto certos, sem repetir) e a listagem dá UM flush só')]
    public function testGarantiaSemColisao(): void
    {
        $this->repositorio->method('escritorios')->willReturn([$this->escritorio(1, null), $this->escritorio(2, 'BJ-ABCD-EFGH-JKLM'), $this->escritorio(3, null)]);
        $this->repositorio->method('escritoriosEmQuarentena')->willReturn([$this->escritorio(4, null)]);
        $this->tenants->method('existeCodigoDaConta')->willReturn(false);
        $tenants = [1 => new Tenant(), 3 => new Tenant(), 4 => new Tenant()];
        $this->tenants->expects($this->exactly(3))->method('find')->willReturnCallback(static fn (int $id): Tenant => $tenants[$id]);
        $this->em->expects($this->once())->method('flush');

        $contas = array_column($this->caso()->executar(), 'codigo', 'id');

        self::assertSame('BJ-ABCD-EFGH-JKLM', $contas[2], 'quem já tinha código não muda');
        foreach ([1, 3, 4] as $id) {
            self::assertMatchesRegularExpression(GeradorDeCodigoDaConta::PADRAO, (string) $contas[$id]);
            self::assertSame($contas[$id], $tenants[$id]->getCodigoConta(), 'o código mostrado é o gravado no Tenant');
        }
        self::assertCount(4, array_unique($contas), 'nenhum código repetido');
    }

    private function caso(): ConsultarContasUseCase
    {
        $free = new Plano(Plano::CODIGO_FREE, 'Free', 10, 0, 'Grátis', 1, 3);
        $espacos = $this->createStub(EspacoDoEscritorioRepository::class);
        $planos = $this->createStub(PlanoRepository::class);
        $planos->method('free')->willReturn($free);
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());
        $situacao = new SituacaoDoEspaco($this->createStub(ConsumoDeArmazenamentoRepository::class), $espacos, $planos, $configuracoes, $this->createStub(BonusDeEspacoRepository::class), new MockClock());
        $relogio = $this->createStub(ClockInterface::class);
        $relogio->method('now')->willReturn(new \DateTimeImmutable('2026-10-19 12:00:00'));

        return new ConsultarContasUseCase(
            $this->repositorio,
            $relogio,
            365,
            $situacao,
            $this->createStub(ConvenioDaUfRepository::class),
            new CodigoDaContaDoEscritorio(new GeradorDeCodigoDaConta(), $this->tenants),
            $this->tenants,
            $this->em,
        );
    }

    /** @return array<string, mixed> */
    private function escritorio(int $id, ?string $codigo): array
    {
        return ['id' => $id, 'nome' => 'Conta ' . $id, 'cidade' => null, 'estado' => null, 'criado_em' => '2026-01-01 00:00:00', 'ativo' => true, 'titular' => null, 'do_master' => false, 'excluido_em' => $id === 4 ? '2026-10-01 00:00:00' : null, 'codigo_conta' => $codigo];
    }
}
