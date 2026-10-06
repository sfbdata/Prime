<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Excluir em lote (D4): as chaves de TODA a árvore saem coletadas ANTES do remove(), sem
 * repetição; o que está dentro de uma pasta selecionada cai no cascade dela (não é removido
 * duas vezes nem contado duas vezes); e o UseCase não apaga arquivo nenhum — devolve as chaves.
 */
#[CoversClass(ExcluirItensDaPastaUseCase::class)]
#[CoversClass(SelecaoDeItensDaPasta::class)]
final class ExcluirItensDaPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirItensDaPastaUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;
    private User $autor;

    /** @var list<object> */
    private array $removidos = [];

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirItensDaPastaUseCase($this->em);
        $this->tenant  = new Tenant();
        // A chave de armazenamento precisa do id do escritório (R1).
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->pasta = (new Pasta())->setTenant($this->tenant);
        $this->autor = (new User())->setEmail('autor@test.com');

        $this->em->method('remove')->willReturnCallback(function (object $entidade): void {
            $this->removidos[] = $entidade;
        });
    }

    private function secao(string $nome, ?PastaSecao $pai = null, ?Tenant $tenant = null): PastaSecao
    {
        $s = (new PastaSecao())->setNome($nome)->setPai($pai);
        $s->setPasta($this->pasta);
        $s->setTenant($tenant ?? $this->tenant);

        return $s;
    }

    /** `setSecao()` não sincroniza a coleção da seção; aqui a coleção é alimentada à mão. */
    private function documento(string $nome, ?PastaSecao $secao = null, ?Pasta $pasta = null): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setPasta($pasta ?? $this->pasta);
        $d->setTenant($this->tenant);
        $d->setTitulo($nome);
        $d->setNomeOriginal($nome);
        $d->setCategoria('DEMAIS');
        $d->setCaminhoArquivo($nome);
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(1);
        $d->setSecao($secao);
        $secao?->getDocumentos()->add($d);

        return $d;
    }

    /** @return list<string> */
    private function nomes(array $chaves): array
    {
        $nomes = array_map(static fn (ChaveDeArquivo $c): string => $c->nome, $chaves);
        sort($nomes);

        return $nomes;
    }

    #[TestDox('documento solto + subpasta com árvore: chaves de todos, contagens certas, remove() só nos selecionados, UM flush')]
    public function testExcluiArvoreInteira(): void
    {
        $solto = $this->documento('solto.pdf');
        $mae   = $this->secao('MAE');
        $filha = $this->secao('FILHA', $mae);
        $neta  = $this->secao('NETA', $filha);
        $this->documento('na-mae.pdf', $mae);
        $this->documento('na-neta.pdf', $neta);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, [$solto], [$mae], $this->autor, $this->tenant);

        self::assertSame(['na-mae.pdf', 'na-neta.pdf', 'solto.pdf'], $this->nomes($resultado->chaves));
        self::assertSame(1, $resultado->documentosRemovidos);
        self::assertSame(3, $resultado->subpastasRemovidas, 'MAE + FILHA + NETA');
        self::assertSame(3, $resultado->arquivosRemovidos);
        self::assertSame([$solto, $mae], $this->removidos, 'filha e neta saem pelo cascade, não por remove()');
        self::assertSame(7, $resultado->chaves[0]->escopo->tenantIdOuNull(), 'R1: a chave carrega o escritório');
    }

    #[TestDox('documento dentro de subpasta selecionada: uma chave só, removido pelo cascade')]
    public function testDocumentoDentroDaSubpastaNaoRepete(): void
    {
        $a      = $this->secao('A');
        $dentro = $this->documento('dentro.pdf', $a);

        $resultado = $this->useCase->executar($this->pasta, [$dentro], [$a], $this->autor, $this->tenant);

        self::assertSame(['dentro.pdf'], $this->nomes($resultado->chaves));
        self::assertSame(0, $resultado->documentosRemovidos);
        self::assertSame(1, $resultado->arquivosRemovidos);
        self::assertSame([$a], $this->removidos);
    }

    #[TestDox('filha e mãe selecionadas: a mãe cobre a filha')]
    public function testFilhaEMaeSelecionadas(): void
    {
        $mae   = $this->secao('MAE');
        $filha = $this->secao('FILHA', $mae);

        $resultado = $this->useCase->executar($this->pasta, [], [$filha, $mae], $this->autor, $this->tenant);

        self::assertSame(2, $resultado->subpastasRemovidas);
        self::assertSame([$mae], $this->removidos);
    }

    #[TestDox('item de outro escritório: AccessDenied, nada removido, nada gravado')]
    public function testTenantErrado(): void
    {
        $alheia = $this->secao('ALHEIA', null, new Tenant());
        $meu    = $this->documento('meu.pdf');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        try {
            $this->useCase->executar($this->pasta, [$meu], [$alheia], $this->autor, $this->tenant);
        } finally {
            self::assertSame([], $this->removidos);
        }
    }

    #[TestDox('documento de outra pasta do escritório: recusado, nada removido')]
    public function testPastaIrma(): void
    {
        $alheio = $this->documento('alheio.pdf', null, (new Pasta())->setTenant($this->tenant));
        $meu    = $this->documento('meu.pdf');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($this->pasta, [$meu, $alheio], [], $this->autor, $this->tenant);
        } finally {
            self::assertSame([], $this->removidos);
        }
    }

    #[TestDox('seleção vazia é recusada')]
    public function testSelecaoVazia(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, [], [], $this->autor, $this->tenant);
    }
}
