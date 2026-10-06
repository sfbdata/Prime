<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\UseCase\RestaurarItensDaPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Restaurar (D7): o bloco excluído junto volta junto (mesmo carimbo); o que estava na lixeira
 * com outro carimbo fica; pai ainda na lixeira → o item volta para a RAIZ; ancestrais antes dos
 * descendentes; item vivo, de outra pasta ou de outro escritório é recusado sem efeito. UM flush.
 *
 * As coleções são alimentadas à mão (unit, sem banco): é o estado que o `AcessoALixeira` produz
 * na rota — a árvore carregada COM os itens da lixeira.
 */
#[CoversClass(RestaurarItensDaPastaUseCase::class)]
final class RestaurarItensDaPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RestaurarItensDaPastaUseCase $useCase;
    private Pasta $pasta;
    private Tenant $tenant;
    private User $autor;
    private \DateTimeImmutable $carimboA;
    private \DateTimeImmutable $carimboB;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new RestaurarItensDaPastaUseCase($this->em);
        $this->tenant  = new Tenant();
        $this->pasta   = (new Pasta())->setTenant($this->tenant);
        $this->autor   = (new User())->setEmail('autor@test.com');
        $this->carimboA = new \DateTimeImmutable('2026-10-01 10:00:00');
        // Outro objeto com o MESMO valor: o restaurar compara carimbo por valor (o que vem do banco
        // é outro objeto), e um instante diferente é outro bloco.
        $this->carimboB = new \DateTimeImmutable('2026-10-03 15:30:00');

        $this->em->expects($this->never())->method('remove');
    }

    private function secao(string $nome, ?PastaSecao $pai = null, ?\DateTimeImmutable $naLixeiraDesde = null, ?Tenant $tenant = null, ?Pasta $pasta = null): PastaSecao
    {
        $s = (new PastaSecao())->setNome($nome)->setPai($pai);
        $s->setPasta($pasta ?? $this->pasta);
        $s->setTenant($tenant ?? $this->tenant);
        if ($naLixeiraDesde !== null) {
            $s->marcarExcluido($this->autor, $naLixeiraDesde);
        }

        return $s;
    }

    private function documento(string $nome, ?PastaSecao $secao = null, ?\DateTimeImmutable $naLixeiraDesde = null, ?Pasta $pasta = null): PastaDocumento
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
        if ($naLixeiraDesde !== null) {
            $d->marcarExcluido($this->autor, $naLixeiraDesde);
        }

        return $d;
    }

    #[TestDox('documento solto na lixeira, com a seção de origem viva: volta para a seção')]
    public function testRestauraDocumentoNaSecaoViva(): void
    {
        $viva = $this->secao('VIVA');
        $doc  = $this->documento('doc.pdf', $viva, $this->carimboA);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, [$doc], [], $this->autor, $this->tenant);

        self::assertSame(1, $resultado->documentos);
        self::assertSame(0, $resultado->secoes);
        self::assertSame(0, $resultado->paraARaiz);
        self::assertFalse($doc->estaNaLixeira());
        self::assertNull($doc->getExcluidoPor());
        self::assertSame($viva, $doc->getSecao(), 'a origem está viva: fica nela');
    }

    #[TestDox('subpasta: volta com a subárvore do MESMO carimbo; o que tinha outro carimbo fica na lixeira')]
    public function testRestauraSubarvoreDoMesmoCarimbo(): void
    {
        $mae    = $this->secao('MAE', null, $this->carimboB);
        $filha  = $this->secao('FILHA', $mae, clone $this->carimboB);
        $naMae  = $this->documento('na-mae.pdf', $mae, clone $this->carimboB);
        $naFilha = $this->documento('na-filha.pdf', $filha, clone $this->carimboB);
        $antigo = $this->documento('antigo.pdf', $filha, $this->carimboA);
        $antiga = $this->secao('ANTIGA', $mae, $this->carimboA);

        $resultado = $this->useCase->executar($this->pasta, [], [$mae], $this->autor, $this->tenant);

        self::assertSame(2, $resultado->secoes, 'MAE + FILHA');
        self::assertSame(2, $resultado->documentos);
        self::assertSame(0, $resultado->paraARaiz);
        foreach ([$mae, $filha] as $s) {
            self::assertFalse($s->estaNaLixeira(), $s->getNome());
        }
        foreach ([$naMae, $naFilha] as $d) {
            self::assertFalse($d->estaNaLixeira(), $d->getNomeOriginal());
        }
        self::assertTrue($antigo->estaNaLixeira(), 'excluído em outra ação: não volta junto');
        self::assertTrue($antiga->estaNaLixeira());
        self::assertSame($this->carimboA, $antigo->getExcluidoEm(), 'e mantém o carimbo dele');
    }

    #[TestDox('pai ausente → raiz: seção cujo pai continua na lixeira volta como raiz; documento cuja seção continua na lixeira volta solto')]
    public function testPaiNaLixeiraDevolveParaARaiz(): void
    {
        $pai   = $this->secao('PAI', null, $this->carimboA);
        $filha = $this->secao('FILHA', $pai, $this->carimboB);
        $doc   = $this->documento('doc.pdf', $pai, $this->carimboB);

        $resultado = $this->useCase->executar($this->pasta, [$doc], [$filha], $this->autor, $this->tenant);

        self::assertSame(1, $resultado->secoes);
        self::assertSame(1, $resultado->documentos);
        self::assertSame(2, $resultado->paraARaiz);
        self::assertFalse($filha->estaNaLixeira());
        self::assertNull($filha->getPai(), 'o pai ficou na lixeira: a filha vai para a raiz');
        self::assertFalse($pai->getFilhas()->contains($filha), 'setPai(null) sincroniza o lado inverso');
        self::assertFalse($doc->estaNaLixeira());
        self::assertNull($doc->getSecao(), 'a seção de origem ficou na lixeira: o documento vai para a raiz');
        self::assertTrue($pai->estaNaLixeira(), 'o pai não foi pedido: fica');
    }

    #[TestDox('pai e filha na seleção: o pai volta primeiro e a filha fica NELE (não vai para a raiz)')]
    public function testAncestralAntesDoDescendente(): void
    {
        $pai   = $this->secao('PAI', null, $this->carimboA);
        $filha = $this->secao('FILHA', $pai, $this->carimboB);

        // Filha antes do pai na seleção, de propósito: a ordem de chegada não pode mandar.
        $resultado = $this->useCase->executar($this->pasta, [], [$filha, $pai], $this->autor, $this->tenant);

        self::assertSame(2, $resultado->secoes);
        self::assertSame(0, $resultado->paraARaiz);
        self::assertSame($pai, $filha->getPai(), 'o pai já estava vivo quando a filha voltou');
        self::assertFalse($pai->estaNaLixeira());
        self::assertFalse($filha->estaNaLixeira());
    }

    #[TestDox('documento já restaurado pela subárvore da seção selecionada na mesma ação: contado uma vez')]
    public function testDocumentoDentroDaSubpastaNaoRepete(): void
    {
        $a   = $this->secao('A', null, $this->carimboA);
        $doc = $this->documento('dentro.pdf', $a, clone $this->carimboA);

        $resultado = $this->useCase->executar($this->pasta, [$doc], [$a], $this->autor, $this->tenant);

        self::assertSame(1, $resultado->documentos);
        self::assertSame(1, $resultado->secoes);
        self::assertFalse($doc->estaNaLixeira());
        self::assertSame($a, $doc->getSecao());
    }

    #[TestDox('item VIVO na seleção: recusado, nada muda, nada gravado')]
    public function testItemVivoEhRecusado(): void
    {
        $vivo     = $this->documento('vivo.pdf');
        $naLixeira = $this->documento('na-lixeira.pdf', null, $this->carimboA);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($this->pasta, [$naLixeira, $vivo], [], $this->autor, $this->tenant);
        } finally {
            self::assertTrue($naLixeira->estaNaLixeira(), 'sem efeito parcial');
        }
    }

    #[TestDox('item de outro escritório: AccessDenied, nada gravado')]
    public function testTenantErrado(): void
    {
        $alheia = $this->secao('ALHEIA', null, $this->carimboA, new Tenant());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);
        $this->useCase->executar($this->pasta, [], [$alheia], $this->autor, $this->tenant);
    }

    #[TestDox('item de outra pasta do escritório: recusado, nada gravado')]
    public function testPastaIrma(): void
    {
        $daIrma = $this->documento('irma.pdf', null, $this->carimboA, (new Pasta())->setTenant($this->tenant));
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, [$daIrma], [], $this->autor, $this->tenant);
    }

    #[TestDox('seleção vazia é recusada')]
    public function testSelecaoVazia(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->pasta, [], [], $this->autor, $this->tenant);
    }
}
