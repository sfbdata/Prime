<?php
declare(strict_types=1);
namespace App\Tests\Processo\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use App\Processo\UseCase\ExcluirProcessoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirProcessoUseCase::class)]
final class ExcluirProcessoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaRepository&MockObject $pastaRepository;
    private ExcluirProcessoUseCase $useCase;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->pastaRepository = $this->createMock(PastaRepository::class);
        $this->useCase = new ExcluirProcessoUseCase($this->em, $this->pastaRepository);
    }

    public function testExcluirSemPastasRemoveProcessoERetornaZero(): void
    {
        $processo = new Processo();

        $this->pastaRepository->method('findByProcesso')->willReturn([]);
        $this->em->expects($this->once())->method('remove')->with($processo);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($processo);

        self::assertSame(0, $resultado);
    }

    public function testExcluirComPastasDesvinculaTodasERetornaContagem(): void
    {
        $processo = new Processo();
        $pasta1 = (new Pasta())->setProcesso($processo);
        $pasta2 = (new Pasta())->setProcesso($processo);

        $this->pastaRepository->method('findByProcesso')->willReturn([$pasta1, $pasta2]);
        $this->em->expects($this->once())->method('remove')->with($processo);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($processo);

        self::assertSame(2, $resultado);
        self::assertNull($pasta1->getProcesso());
        self::assertNull($pasta2->getProcesso());
    }
}
