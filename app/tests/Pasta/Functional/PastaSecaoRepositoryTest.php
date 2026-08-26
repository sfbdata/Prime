<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PastaSecaoRepository::class)]
final class PastaSecaoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PastaSecaoRepository $repo;
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(PastaSecaoRepository::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Tenant Arvore ' . uniqid());
        $this->em->persist($this->tenant);

        $this->pasta = new Pasta();
        $this->pasta->setNup('TEST-ARV-' . uniqid());
        $this->pasta->setTenant($this->tenant);
        $this->em->persist($this->pasta);
        $this->em->flush();
    }

    private function criarSecao(string $nome, ?PastaSecao $pai, int $ordem): PastaSecao
    {
        $secao = (new PastaSecao())
            ->setNome($nome)
            ->setPai($pai)
            ->setOrdem($ordem);
        $secao->setPasta($this->pasta);
        $secao->setTenant($this->tenant);
        $this->em->persist($secao);
        $this->em->flush();

        return $secao;
    }

    private function criarDocumento(?PastaSecao $secao): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo('DOC ' . uniqid());
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('x/y.pdf');
        $doc->setNomeOriginal('y.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $doc->setPasta($this->pasta);
        $doc->setSecao($secao);
        $doc->setTenant($this->tenant);
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    public function testProximaOrdemContaSoAsIrmas(): void
    {
        // duas na raiz: ordens 1 e 2
        $this->criarSecao('RAIZ A', null, 1);
        $paiB = $this->criarSecao('RAIZ B', null, 2);
        // primeira filha de B tem de nascer com ordem 1, NÃO com 3
        self::assertSame(1, $this->repo->proximaOrdem($this->pasta, $this->tenant, $paiB));
    }

    public function testProximaOrdemNaRaizIgnoraAsAninhadas(): void
    {
        $pai = $this->criarSecao('RAIZ A', null, 1);
        $this->criarSecao('FILHA', $pai, 1);
        $this->criarSecao('NETA', $pai, 2);

        self::assertSame(2, $this->repo->proximaOrdem($this->pasta, $this->tenant, null));
    }

    public function testContagemRecursivaSomaAArvoreInteira(): void
    {
        $a = $this->criarSecao('A', null, 1);
        $b = $this->criarSecao('B', $a, 1);
        $c = $this->criarSecao('C', $b, 1);

        $this->criarDocumento($a);
        $this->criarDocumento($b);
        $this->criarDocumento($c);
        $this->criarDocumento($c);
        $this->criarDocumento(null); // na raiz da pasta, NÃO conta

        // Limpa e rebusca $a: em produção a seção sempre chega assim (recém-buscada do
        // repositório numa nova request), com as coleções lazy-loaded de verdade. Sem isso,
        // $a ficaria com a coleção "documentos" já travada vazia desde o persist() em memória,
        // porque PastaDocumento::setSecao() não sincroniza o lado inverso — um artefato de
        // como o teste monta o grafo, não um defeito de contarConteudoRecursivo().
        $idA = $a->getId();
        $this->em->clear();
        $a = $this->em->find(PastaSecao::class, $idA);

        $total = $this->repo->contarConteudoRecursivo($a);

        self::assertSame(2, $total['subpastas'], 'B e C');
        self::assertSame(4, $total['arquivos'], 'os 4 da árvore de A, sem o da raiz da pasta');
    }

    #[TestDox('ciclo gravado por fora dos guards (ex.: desfazer da auditoria) não estoura a memória em contarConteudoRecursivo()')]
    public function testContagemRecursivaComCicloNoPaiNaoEstouraAMemoria(): void
    {
        // Ciclo montado à mão, em memória, sem passar pelos UseCases (é exatamente o que
        // DesfazerAlteracaoAuditLogUseCase faz: grava `pai` direto pelo setter, sem guard de ciclo).
        // Não precisa persistir: contarConteudoRecursivo() só anda por getFilhas()/getDocumentos(),
        // populadas pelo próprio setPai() em memória.
        $a = $this->criarSecao('A', null, 1);
        $b = (new PastaSecao())->setNome('B')->setPai($a);
        $a->setPai($b);

        $total = $this->repo->contarConteudoRecursivo($a);

        self::assertIsInt($total['subpastas'], 'tem de RETORNAR, não estourar a memória');
        self::assertIsInt($total['arquivos']);
    }

    public function testApagarAMaeApagaFilhasNetasEDocumentos(): void
    {
        $a = $this->criarSecao('A', null, 1);
        $b = $this->criarSecao('B', $a, 1);
        $c = $this->criarSecao('C', $b, 1);

        $docNeta  = $this->criarDocumento($c);
        $docSolto = $this->criarDocumento(null); // na raiz da pasta: TEM de sobreviver

        $idB = $b->getId();
        $idC = $c->getId();
        $idDocNeta  = $docNeta->getId();
        $idDocSolto = $docSolto->getId();

        $this->em->remove($a);
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->find(PastaSecao::class, $idB), 'a filha some');
        self::assertNull($this->em->find(PastaSecao::class, $idC), 'a neta some');
        self::assertNull($this->em->find(PastaDocumento::class, $idDocNeta), 'o documento da neta some');
        self::assertNotNull(
            $this->em->find(PastaDocumento::class, $idDocSolto),
            'o documento da RAIZ da pasta não pode ser levado junto',
        );
    }
}
