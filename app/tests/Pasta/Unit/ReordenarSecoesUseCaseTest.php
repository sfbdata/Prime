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

    private function criarSecao(int $id, int $ordem, ?PastaSecao $pai = null): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setOrdem($ordem);
        $secao->setPai($pai);

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

    public function testNumeracaoReiniciaEmCadaGrupoDeIrmas(): void
    {
        $a  = $this->criarSecao(1, 1);
        $b  = $this->criarSecao(2, 2);
        $a1 = $this->criarSecao(3, 1, $a);
        $a2 = $this->criarSecao(4, 2, $a);

        $this->repo->method('findByPasta')->willReturn([$a, $b, $a1, $a2]);
        $this->em->expects($this->once())->method('flush');

        // ordem pedida: B antes de A na raiz; A2 antes de A1 dentro de A
        $this->useCase->executar($this->pasta, $this->tenant, [2, 1, 4, 3]);

        self::assertSame(1, $b->getOrdem(), 'B é a 1ª da RAIZ');
        self::assertSame(2, $a->getOrdem(), 'A é a 2ª da RAIZ');
        self::assertSame(1, $a2->getOrdem(), 'A2 é a 1ª DENTRO de A — a numeração reinicia');
        self::assertSame(2, $a1->getOrdem(), 'A1 é a 2ª DENTRO de A');
    }

    public function testNumeracaoPersisteComGruposIntercalados(): void
    {
        $a  = $this->criarSecao(1, 1);
        $b  = $this->criarSecao(2, 2);
        $a1 = $this->criarSecao(3, 1, $a);
        $a2 = $this->criarSecao(4, 2, $a);

        $this->repo->method('findByPasta')->willReturn([$a, $b, $a1, $a2]);
        $this->em->expects($this->once())->method('flush');

        // Grupos INTERCALADOS (raiz, A, raiz, A): B, depois A1, depois A, depois A2. Existe para
        // separar "mapa por chave persistente" (correto) de "reset por adjacência" — resetar o
        // contador só quando a chave muda em relação à anterior também passaria no teste acima
        // (grupos contíguos), mas aqui colide A com B e A2 com A1.
        $this->useCase->executar($this->pasta, $this->tenant, [2, 3, 1, 4]);

        self::assertSame(1, $b->getOrdem(), 'B é a 1ª da RAIZ');
        self::assertSame(1, $a1->getOrdem(), 'A1 é a 1ª DENTRO de A');
        self::assertSame(2, $a->getOrdem(), 'A é a 2ª da RAIZ — não colide com B');
        self::assertSame(2, $a2->getOrdem(), 'A2 é a 2ª DENTRO de A — não colide com A1');
    }
}
