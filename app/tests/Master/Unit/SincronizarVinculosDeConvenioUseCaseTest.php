<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Tenant\Tenant;
use App\Master\DTO\EscritorioParaConvenio;
use App\Master\DTO\ResultadoDaSincronizacao;
use App\Master\Entity\Convenio;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\ChaveDoConvenio;
use App\Master\Service\RegraDosVinculosDeConvenio;
use App\Master\UseCase\SincronizarVinculosDeConvenioUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Falha de um escritório na volta diária (revisão do PR 3 dos convênios): vai para o log, a volta segue com os outros e
 * o total acusa. Nos ganchos (um escritório, logo depois de gravar), a falha sobe para o gancho registrar.
 */
#[CoversClass(SincronizarVinculosDeConvenioUseCase::class)]
#[CoversClass(ResultadoDaSincronizacao::class)]
final class SincronizarVinculosDeConvenioUseCaseTest extends TestCase
{
    /** @var ConvenioVinculoRepository&MockObject */
    private ConvenioVinculoRepository $vinculos;

    protected function setUp(): void
    {
        $this->vinculos = $this->createMock(ConvenioVinculoRepository::class);
        $this->vinculos->method('escritoriosParaConvenio')->willReturnCallback(static function (?array $ids = null): array {
            if ($ids === [1]) {
                throw new \RuntimeException('escritório com defeito');
            }

            return $ids === null
                ? [new EscritorioParaConvenio(1, 'GO', false, true), new EscritorioParaConvenio(2, 'GO', false, true)]
                : [new EscritorioParaConvenio(2, 'GO', false, true)];
        });
        $this->vinculos->method('abertosDosTenants')->willReturn([]);
    }

    #[TestDox('volta diária: o escritório com defeito vai para o log e o seguinte ganha o vínculo')]
    public function testFalhaNaoParaAVolta(): void
    {
        $this->vinculos->expects($this->once())->method('salvar');

        $resultado = $this->useCase()->executar();

        self::assertSame(1, $resultado->falhas);
        self::assertSame(1, $resultado->criados);
    }

    #[TestDox('gancho de um escritório: a falha sobe (o gancho registra e segue)')]
    public function testGanchoRepassaAFalha(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->useCase()->doEscritorio(1);
    }

    private function useCase(): SincronizarVinculosDeConvenioUseCase
    {
        $oab = new Convenio(Convenio::CHAVE_OAB, 'Convênio OAB', 'OAB', 'oab', AbrangenciaDoConvenio::Nacional, null, ProvaDoConvenio::Oab, BeneficioDoConvenio::Gratuidade, 0, 'CONVÊNIO OAB', new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-10-26'));
        $convenios = $this->createStub(ConvenioRepository::class);
        $convenios->method('porChave')->willReturn($oab);
        $configuracoes = $this->createStub(ConfiguracaoGlobalRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('getReference')->willReturn(new Tenant());
        $conexao = $this->createStub(Connection::class);
        $conexao->method('transactional')->willReturnCallback(static fn (\Closure $f): mixed => $f());

        return new SincronizarVinculosDeConvenioUseCase(
            $this->vinculos,
            $convenios,
            $this->createStub(ConvenioDaUfRepository::class),
            new ChaveDoConvenio($configuracoes),
            $configuracoes,
            new RegraDosVinculosDeConvenio(),
            new EscopoDeAuditoriaDePlataforma(),
            $em,
            $conexao,
            new MockClock('2026-11-24 02:10:00'),
            new NullLogger(),
        );
    }
}
