<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\UseCase\MoverPastaSecaoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(MoverPastaSecaoUseCase::class)]
final class MoverPastaSecaoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaSecaoRepository&MockObject $repo;
    private MoverPastaSecaoUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->repo    = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new MoverPastaSecaoUseCase($this->em, $this->repo);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    private function secao(string $nome, ?PastaSecao $pai = null): PastaSecao
    {
        // NÃO acrescente $pai->getFilhas()->add($s) aqui: desde o fix da Task 1, setPai() já
        // sincroniza os dois lados da associação, e o add() manual DUPLICARIA a entrada.
        $s = (new PastaSecao())->setNome($nome)->setPai($pai);
        $s->setPasta($this->pasta);
        $s->setTenant($this->tenant);

        return $s;
    }

    public function testMoveParaDentroDeOutra(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');

        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($b, $a, $this->autor, $this->tenant);

        self::assertSame($a, $b->getPai());
        self::assertSame(1, $b->getOrdem());
        self::assertTrue($a->getFilhas()->contains($b), 'entrou nas filhas do destino');
    }

    public function testMoverEntreDoisPaisTiraDoAntigo(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');
        $x = $this->secao('X', $a);

        $this->repo->method('proximaOrdem')->willReturn(1);

        $this->useCase->executar($x, $b, $this->autor, $this->tenant);

        self::assertSame($b, $x->getPai());
        self::assertTrue($b->getFilhas()->contains($x), 'entrou nas filhas do novo pai');
        self::assertFalse(
            $a->getFilhas()->contains($x),
            'saiu das filhas do pai antigo — senão getAltura() de A fica inflada para sempre',
        );
        self::assertSame(1, $a->getAltura(), 'A voltou a ser folha');
    }

    public function testMoveDeVoltaParaARaiz(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);

        $this->repo->method('proximaOrdem')->willReturn(3);

        $this->useCase->executar($b, null, $this->autor, $this->tenant);

        self::assertNull($b->getPai());
        self::assertSame(3, $b->getOrdem());
        self::assertFalse($a->getFilhas()->contains($b), 'saiu das filhas do pai antigo');
    }

    public function testRecusaMoverParaDentroDaPropriaFilha(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dentro dela mesma');
        $this->useCase->executar($a, $b, $this->autor, $this->tenant);
    }

    public function testRecusaMoverParaDentroDaPropriaNeta(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B', $a);
        $c = $this->secao('C', $b);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($a, $c, $this->autor, $this->tenant);
    }

    public function testRecusaMoverParaSiMesma(): void
    {
        $a = $this->secao('A');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($a, $a, $this->autor, $this->tenant);
    }

    public function testRecusaQuandoASubarvoreEstouraOTeto(): void
    {
        // Borda exata do lado de fora: destino no nível 7 recebendo subárvore de altura 4.
        // A raiz da subárvore cairia no nível 8 e a folha dela no 11 — um a mais que o teto.
        $destino = null;
        for ($i = 0; $i < 7; ++$i) {
            $destino = $this->secao('N' . $i, $destino);
        }
        self::assertSame(7, $destino->getProfundidade(), 'sanidade do arranjo');

        $r = $this->secao('R');
        $x = $this->secao('X', $r);
        $y = $this->secao('Y', $x);
        $this->secao('Z', $y);
        self::assertSame(4, $r->getAltura(), 'sanidade do arranjo');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('10 níveis');
        $this->useCase->executar($r, $destino, $this->autor, $this->tenant);
    }

    public function testAceitaQuandoASubarvoreCabeExatamente(): void
    {
        // Borda exata do lado de dentro: destino no nível 6 + altura 4 → folha no nível 10.
        // 6 + 4 = 10, e o guard só recusa acima de 10.
        $destino = null;
        for ($i = 0; $i < 6; ++$i) {
            $destino = $this->secao('N' . $i, $destino);
        }
        self::assertSame(6, $destino->getProfundidade(), 'sanidade do arranjo');

        $r = $this->secao('R');
        $x = $this->secao('X', $r);
        $y = $this->secao('Y', $x);
        $this->secao('Z', $y);
        self::assertSame(4, $r->getAltura(), 'sanidade do arranjo');

        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->useCase->executar($r, $destino, $this->autor, $this->tenant);

        self::assertSame($destino, $r->getPai());
    }

    public function testRecusaDestinoDeOutroTenant(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');
        $b->setTenant(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($a, $b, $this->autor, $this->tenant);
    }

    public function testRecusaSecaoDeOutroTenant(): void
    {
        $a = $this->secao('A');
        $a->setTenant(new Tenant());

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($a, null, $this->autor, $this->tenant);
    }

    public function testRecusaDestinoDeOutraPasta(): void
    {
        $a = $this->secao('A');
        $b = $this->secao('B');
        $b->setPasta(new Pasta());

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($a, $b, $this->autor, $this->tenant);
    }
}
