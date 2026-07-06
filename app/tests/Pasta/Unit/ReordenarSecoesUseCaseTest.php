<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\ReordenarSecoesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReordenarSecoesUseCase::class)]
final class ReordenarSecoesUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaSecaoRepository&MockObject $repo;
    private ReordenarSecoesUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->repo    = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new ReordenarSecoesUseCase($this->em, $this->repo);

        $this->pasta  = new Pasta();
        $this->tenant = new Tenant();
    }

    private function criarSecao(int $id, int $ordem): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setOrdem($ordem);

        $ref = new \ReflectionProperty(PastaSecao::class, 'id');
        $ref->setValue($secao, $id);

        return $secao;
    }

    public function testReordenarAtualizaOrdemCorretamente(): void
    {
        $s1 = $this->criarSecao(1, 1);
        $s2 = $this->criarSecao(2, 2);
        $s3 = $this->criarSecao(3, 3);

        $this->repo->method('findByPasta')->willReturn([$s1, $s2, $s3]);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->pasta, $this->tenant, [3, 1, 2]);

        self::assertSame(1, $s3->getOrdem());
        self::assertSame(2, $s1->getOrdem());
        self::assertSame(3, $s2->getOrdem());
    }

    public function testArrayVazioNaoChamaFlush(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->useCase->executar($this->pasta, $this->tenant, []);
    }

    public function testIdInvalidoIgnoradoSilenciosamente(): void
    {
        $s1 = $this->criarSecao(1, 1);

        $this->repo->method('findByPasta')->willReturn([$s1]);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->pasta, $this->tenant, [1, 99]);

        self::assertSame(1, $s1->getOrdem());
    }
}
