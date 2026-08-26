<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\CriarPastaSecaoUseCase;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(CriarPastaSecaoUseCase::class)]
final class CriarPastaSecaoUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaSecaoRepository&MockObject $repo;
    private CriarPastaSecaoUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new CriarPastaSecaoUseCase($this->em, $this->repo);

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testCriarSecaoPersisteDadosCorretamente(): void
    {
        $this->repo->expects($this->once())
            ->method('proximaOrdem')
            ->willReturn(1);

        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(PastaSecao::class));
        $this->em->expects($this->once())->method('flush');

        $secao = $this->useCase->executar($this->pasta, $this->autor, 'Petições', $this->tenant);

        self::assertSame('PETIÇÕES', $secao->getNome());
        self::assertSame(1, $secao->getOrdem());
        self::assertSame($this->pasta, $secao->getPasta());
        self::assertSame($this->tenant, $secao->getTenant());
    }

    public function testNomeComEspacosEhTrimado(): void
    {
        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->method('persist');
        $this->em->method('flush');

        $secao = $this->useCase->executar($this->pasta, $this->autor, '  Contratos  ', $this->tenant);

        self::assertSame('CONTRATOS', $secao->getNome());
    }

    public function testNomeVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    public function testNomeAcimaDe255CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 256), $this->tenant);
    }

    public function testCriarDentroDeOutraGuardaOPai(): void
    {
        $pai = (new PastaSecao())->setNome('PAI');
        $pai->setPasta($this->pasta);
        $pai->setTenant($this->tenant);

        $this->repo->method('proximaOrdem')->willReturn(1);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $secao = $this->useCase->executar($this->pasta, $this->autor, 'Filha', $this->tenant, $pai);

        self::assertSame($pai, $secao->getPai());
        self::assertSame(2, $secao->getProfundidade());
    }

    public function testRecusaPaiDeOutraPasta(): void
    {
        $outraPasta = new Pasta();
        $pai = (new PastaSecao())->setNome('PAI');
        $pai->setPasta($outraPasta);
        $pai->setTenant($this->tenant);

        // sem isto, na ausência do guard de pasta o fluxo chegaria a setOrdem(proximaOrdem())
        // com o mock sem comportamento configurado e falharia por TypeError, não pela exceção
        // esperada — daria proteção acidental, não deliberada.
        $this->repo->method('proximaOrdem')->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, $this->autor, 'Filha', $this->tenant, $pai);
    }

    public function testRecusaPaiDeOutroTenant(): void
    {
        $pai = (new PastaSecao())->setNome('PAI');
        $pai->setPasta($this->pasta);
        $pai->setTenant(new Tenant());

        // idem: mock de proximaOrdem configurado para que, sem o guard de tenant, o teste falhe
        // pela ausência da exceção esperada, não por TypeError em setOrdem(null).
        $this->repo->method('proximaOrdem')->willReturn(1);

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($this->pasta, $this->autor, 'Filha', $this->tenant, $pai);
    }

    /**
     * A ORDEM dos guards decide o código HTTP: tenant errado tem de sair como
     * AccessDeniedException (403), pasta errada como InvalidArgumentException (422). Com um
     * $pai que viola os dois ao mesmo tempo, só a checagem de tenant PRIMEIRO garante o 403 —
     * se alguém inverter a ordem num refactor, este teste é o único que denuncia, porque
     * testRecusaPaiDeOutraPasta e testRecusaPaiDeOutroTenant variam só uma condição cada.
     */
    public function testTenantEhVerificadoAntesDaPasta(): void
    {
        $outraPasta = new Pasta();
        $pai = (new PastaSecao())->setNome('PAI');
        $pai->setPasta($outraPasta);
        $pai->setTenant(new Tenant());

        $this->repo->method('proximaOrdem')->willReturn(1);

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($this->pasta, $this->autor, 'Filha', $this->tenant, $pai);
    }

    public function testRecusaCriarNoNivelOnze(): void
    {
        // cadeia de 10: criar dentro do 10º daria nível 11
        $atual = null;
        for ($i = 0; $i < 10; ++$i) {
            $novo = (new PastaSecao())->setNome('N' . $i)->setPai($atual);
            $novo->setPasta($this->pasta);
            $novo->setTenant($this->tenant);
            $atual = $novo;
        }
        self::assertSame(10, $atual->getProfundidade(), 'sanidade do arranjo do teste');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('10 níveis');
        $this->useCase->executar($this->pasta, $this->autor, 'Estoura', $this->tenant, $atual);
    }

    public function testAceitaCriarNoNivelDez(): void
    {
        $atual = null;
        for ($i = 0; $i < 9; ++$i) {
            $novo = (new PastaSecao())->setNome('N' . $i)->setPai($atual);
            $novo->setPasta($this->pasta);
            $novo->setTenant($this->tenant);
            $atual = $novo;
        }

        $this->repo->method('proximaOrdem')->willReturn(1);
        $secao = $this->useCase->executar($this->pasta, $this->autor, 'Décima', $this->tenant, $atual);

        self::assertSame(10, $secao->getProfundidade());
    }
}
