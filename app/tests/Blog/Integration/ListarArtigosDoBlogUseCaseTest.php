<?php

declare(strict_types=1);

namespace App\Tests\Blog\Integration;

use App\Blog\DTO\ArtigoDaListaOutput;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\UseCase\ListarArtigosDoBlogUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A lista da seção "Artigos dos blogs" no banco de verdade (o Paginator é do Doctrine; DAMA desfaz cada teste). */
#[CoversClass(ListarArtigosDoBlogUseCase::class)]
#[CoversClass(ArtigoDaListaOutput::class)]
final class ListarArtigosDoBlogUseCaseTest extends KernelTestCase
{
    private ListarArtigosDoBlogUseCase $listar;
    private ArtigoDoBlogRepository $artigos;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->listar = static::getContainer()->get(ListarArtigosDoBlogUseCase::class);
        $this->artigos = static::getContainer()->get(ArtigoDoBlogRepository::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM artigo_blog');
    }

    private function artigo(string $slug, BlogsDoArtigo $blogs, string $alteradoEm, string $titulo = 'Título'): ArtigoDoBlog
    {
        $artigo = new ArtigoDoBlog($slug, $blogs, null, new \DateTimeImmutable($alteradoEm));
        $artigo->editarTextos($titulo, $titulo, $titulo, 'd', 'l', 'r');
        $artigo->classificar('prazos-processuais', 'equipe-editorial-bluejus', null, null, '1 min', $slug === 'gratis');
        $this->artigos->salvar($artigo);

        return $artigo;
    }

    #[TestDox('Linha da lista: título (ou "(sem título)"), rótulos, categoria pelo nome, data e caminho no site')]
    public function testLinha(): void
    {
        $publicado = $this->artigo('gratis', BlogsDoArtigo::Gratis, '2026-11-02');
        $publicado->publicar(new \DateTimeImmutable('2026-11-02 14:05'));
        $this->artigo('sem-titulo', BlogsDoArtigo::Normal, '2026-11-01', '');
        $this->em->flush();

        $saida = $this->listar->executar(null, null, null);

        self::assertSame(2, $saida['total']);
        self::assertSame(1, $saida['pagina']);
        self::assertSame(50, $saida['porPagina']);
        [$a, $b] = $saida['itens'];
        self::assertSame('gratis', $a->slug);
        self::assertSame('publicado', $a->situacao);
        self::assertSame('2026-11-02T14:05', $a->data);
        self::assertTrue($a->destaque);
        self::assertSame('/gratis/prazos-processuais/gratis', $a->caminhoNoSite);
        self::assertNotSame('', $a->categoria);
        self::assertSame('(sem título)', $b->titulo);
        self::assertNull($b->data);
        self::assertSame('/blog/prazos-processuais/sem-titulo', $b->caminhoNoSite);
    }

    #[TestDox('Filtros com valor desconhecido são ignorados (lista tudo); os conhecidos filtram')]
    public function testFiltrosDesconhecidos(): void
    {
        $this->artigo('gratis', BlogsDoArtigo::Gratis, '2026-11-02');
        $this->artigo('normal', BlogsDoArtigo::Normal, '2026-11-01');
        $this->em->flush();

        self::assertSame(2, $this->listar->executar(null, 'qualquer', 'outro')['total']);
        self::assertSame(2, $this->listar->executar(null, 'rascunho', null)['total']);
        self::assertSame(0, $this->listar->executar(null, 'publicado', null)['total']);
        $gratis = $this->listar->executar(null, null, 'gratis');
        self::assertSame(['gratis'], array_map(static fn (ArtigoDaListaOutput $i): string => $i->slug, $gratis['itens']));
    }

    #[TestDox('Paginação de 50 em 50; página menor que 1 vira 1')]
    public function testPaginacao(): void
    {
        for ($n = 1; $n <= 51; ++$n) {
            $this->artigo('artigo-' . $n, BlogsDoArtigo::Normal, sprintf('2026-10-01 10:%02d', $n));
        }
        $this->em->flush();

        $primeira = $this->listar->executar(null, null, null);
        self::assertCount(50, $primeira['itens']);
        self::assertSame(51, $primeira['total']);
        self::assertSame('artigo-51', $primeira['itens'][0]->slug, 'o alterado por último primeiro');

        $segunda = $this->listar->executar(null, null, null, 2);
        self::assertSame(2, $segunda['pagina']);
        self::assertSame(['artigo-1'], array_map(static fn (ArtigoDaListaOutput $i): string => $i->slug, $segunda['itens']));

        $zero = $this->listar->executar(null, null, null, 0);
        self::assertSame(1, $zero['pagina']);
        self::assertCount(50, $zero['itens']);
    }
}
