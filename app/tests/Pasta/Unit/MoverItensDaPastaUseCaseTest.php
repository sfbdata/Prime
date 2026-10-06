<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use App\Pasta\UseCase\CriarPastaSecaoUseCase;
use App\Pasta\UseCase\MoverItensDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Mover em lote (D4): tudo ou nada, ciclo bloqueado item a item, teto de profundidade pela
 * subárvore, e o que está dentro de uma pasta selecionada vai JUNTO com ela — não por conta
 * própria (senão a árvore achataria).
 */
#[CoversClass(MoverItensDaPastaUseCase::class)]
#[CoversClass(SelecaoDeItensDaPasta::class)]
final class MoverItensDaPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaSecaoRepository&MockObject $repo;
    private MoverItensDaPastaUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->repo    = $this->createMock(PastaSecaoRepository::class);
        $this->useCase = new MoverItensDaPastaUseCase($this->em, $this->repo);
        $this->tenant  = new Tenant();
        $this->pasta   = (new Pasta())->setTenant($this->tenant);
        $this->autor   = (new User())->setEmail('autor@test.com');
        $this->repo->method('proximaOrdem')->willReturn(5);
    }

    private function secao(string $nome, ?PastaSecao $pai = null, ?Pasta $pasta = null, ?Tenant $tenant = null): PastaSecao
    {
        $s = (new PastaSecao())->setNome($nome)->setPai($pai);
        $s->setPasta($pasta ?? $this->pasta);
        $s->setTenant($tenant ?? $this->tenant);

        return $s;
    }

    private function documento(string $nome, ?PastaSecao $secao = null, ?Pasta $pasta = null, ?Tenant $tenant = null): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setPasta($pasta ?? $this->pasta);
        $d->setTenant($tenant ?? $this->tenant);
        $d->setTitulo($nome);
        $d->setNomeOriginal($nome);
        $d->setCategoria('DEMAIS');
        $d->setCaminhoArquivo('x/' . $nome);
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(1);
        $d->setSecao($secao);

        return $d;
    }

    #[TestDox('move documentos e subpastas para o destino, com ordem ao fim das irmãs e UM flush')]
    public function testMoveParaODestino(): void
    {
        $a       = $this->secao('A');
        $b       = $this->secao('B');
        $destino = $this->secao('DESTINO');
        $naRaiz  = $this->documento('raiz.pdf');
        $emA     = $this->documento('em-a.pdf', $a);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, [$naRaiz, $emA], [$b], $destino, $this->autor, $this->tenant);

        self::assertSame($destino, $naRaiz->getSecao());
        self::assertSame($destino, $emA->getSecao());
        self::assertSame($destino, $b->getPai());
        self::assertSame(5, $b->getOrdem());
        self::assertSame(2, $resultado->documentos);
        self::assertSame(1, $resultado->secoes);
    }

    #[TestDox('duas subpastas movidas juntas recebem ordens consecutivas (uma consulta só)')]
    public function testOrdensConsecutivas(): void
    {
        $a       = $this->secao('A');
        $b       = $this->secao('B');
        $destino = $this->secao('DESTINO');
        $this->repo->expects($this->once())->method('proximaOrdem');

        $this->useCase->executar($this->pasta, [], [$a, $b], $destino, $this->autor, $this->tenant);

        self::assertSame([5, 6], [$a->getOrdem(), $b->getOrdem()]);
    }

    #[TestDox('destino NULL devolve tudo para a raiz')]
    public function testMoveParaARaiz(): void
    {
        $a   = $this->secao('A');
        $b   = $this->secao('B', $a);
        $doc = $this->documento('d.pdf', $a);

        $resultado = $this->useCase->executar($this->pasta, [$doc], [$b], null, $this->autor, $this->tenant);

        self::assertNull($doc->getSecao());
        self::assertNull($b->getPai());
        self::assertFalse($a->getFilhas()->contains($b), 'saiu das filhas do pai antigo');
        self::assertNull($resultado->destinoId);
    }

    #[TestDox('a filha selecionada junto com a mãe vai JUNTO — continua filha dela, não é achatada')]
    public function testFilhaVaiJuntoComAMae(): void
    {
        $mae     = $this->secao('MAE');
        $filha   = $this->secao('FILHA', $mae);
        $destino = $this->secao('DESTINO');

        $resultado = $this->useCase->executar($this->pasta, [], [$filha, $mae], $destino, $this->autor, $this->tenant);

        self::assertSame($destino, $mae->getPai());
        self::assertSame($mae, $filha->getPai(), 'a filha não foi movida por conta própria');
        self::assertSame(1, $resultado->secoes);
    }

    #[TestDox('documento dentro de subpasta selecionada vai junto com ela')]
    public function testDocumentoDentroDaSubpastaVaiJunto(): void
    {
        $a       = $this->secao('A');
        $neta    = $this->secao('NETA', $this->secao('FILHA', $a));
        $destino = $this->secao('DESTINO');
        $doc     = $this->documento('d.pdf', $neta);

        $resultado = $this->useCase->executar($this->pasta, [$doc], [$a], $destino, $this->autor, $this->tenant);

        self::assertSame($neta, $doc->getSecao(), 'continua onde estava, dentro de A');
        self::assertSame($destino, $a->getPai());
        self::assertSame(0, $resultado->documentos);
        self::assertSame(1, $resultado->secoes);
    }

    #[TestDox('ciclo: mover a pasta para dentro da própria filha é recusado e NADA é movido (tudo ou nada)')]
    public function testCicloRecusadoSemEfeitoParcial(): void
    {
        $a     = $this->secao('A');
        $filha = $this->secao('FILHA', $a);
        $b     = $this->secao('B');
        $doc   = $this->documento('d.pdf');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dentro dela mesma');

        try {
            $this->useCase->executar($this->pasta, [$doc], [$b, $a], $filha, $this->autor, $this->tenant);
        } finally {
            self::assertNull($b->getPai(), 'B, que podia ir, não foi: tudo ou nada');
            self::assertNull($doc->getSecao());
            self::assertNull($a->getPai());
        }
    }

    #[TestDox('mover a pasta para ela mesma é recusado')]
    public function testParaSiMesma(): void
    {
        $a = $this->secao('A');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, [], [$a], $a, $this->autor, $this->tenant);
    }

    #[TestDox('teto de profundidade vale para a SUBÁRVORE inteira da pasta movida')]
    public function testTetoDeProfundidade(): void
    {
        $destino = null;
        for ($i = 1; $i < CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA; ++$i) {
            $destino = $this->secao('N' . $i, $destino);
        }
        self::assertSame(CriarPastaSecaoUseCase::PROFUNDIDADE_MAXIMA - 1, $destino->getProfundidade());

        $a = $this->secao('A');
        $this->secao('A-FILHA', $a); // altura 2: 9 + 2 > 10
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('níveis');
        $this->useCase->executar($this->pasta, [], [$a], $destino, $this->autor, $this->tenant);
    }

    #[TestDox('destino de outro escritório: AccessDenied')]
    public function testDestinoDeOutroTenant(): void
    {
        $destino = $this->secao('ALHEIA', null, (new Pasta())->setTenant(new Tenant()), new Tenant());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($this->pasta, [$this->documento('d.pdf')], [], $destino, $this->autor, $this->tenant);
    }

    #[TestDox('destino de outra pasta do mesmo escritório: recusado')]
    public function testDestinoDePastaIrma(): void
    {
        $destino = $this->secao('IRMA', null, (new Pasta())->setTenant($this->tenant));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, [$this->documento('d.pdf')], [], $destino, $this->autor, $this->tenant);
    }

    #[TestDox('documento de outra pasta na seleção: recusado antes de qualquer efeito')]
    public function testDocumentoDePastaIrma(): void
    {
        $meu    = $this->documento('meu.pdf');
        $alheio = $this->documento('alheio.pdf', null, (new Pasta())->setTenant($this->tenant));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($this->pasta, [$meu, $alheio], [], $this->secao('D'), $this->autor, $this->tenant);
        } finally {
            self::assertNull($meu->getSecao());
        }
    }

    #[TestDox('subpasta de outro escritório na seleção: AccessDenied')]
    public function testSecaoDeOutroTenant(): void
    {
        $alheia = $this->secao('ALHEIA', null, null, new Tenant());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($this->pasta, [], [$alheia], null, $this->autor, $this->tenant);
    }

    #[TestDox('seleção vazia é recusada')]
    public function testSelecaoVazia(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nenhum item');
        $this->useCase->executar($this->pasta, [], [], null, $this->autor, $this->tenant);
    }
}
