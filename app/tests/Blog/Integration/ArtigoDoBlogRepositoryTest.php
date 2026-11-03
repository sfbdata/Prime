<?php

declare(strict_types=1);

namespace App\Tests\Blog\Integration;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** As consultas dos artigos dos blogs no banco de verdade (DAMA desfaz cada teste). */
#[CoversClass(ArtigoDoBlogRepository::class)]
#[CoversClass(ImagemDoArtigoRepository::class)]
final class ArtigoDoBlogRepositoryTest extends KernelTestCase
{
    private ArtigoDoBlogRepository $artigos;
    private ImagemDoArtigoRepository $imagens;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->artigos = static::getContainer()->get(ArtigoDoBlogRepository::class);
        $this->imagens = static::getContainer()->get(ImagemDoArtigoRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function artigo(string $slug, string $titulo, BlogsDoArtigo $blogs, string $alteradoEm): ArtigoDoBlog
    {
        $artigo = new ArtigoDoBlog($slug, $blogs, null, new \DateTimeImmutable($alteradoEm));
        $artigo->editarTextos($titulo, $titulo, $titulo, 'd', 'l', 'r');
        $this->artigos->salvar($artigo);

        return $artigo;
    }

    /** @return array{ArtigoDoBlog, ArtigoDoBlog, ArtigoDoBlog} */
    private function tres(): array
    {
        $a = $this->artigo('controle-de-prazos', 'Controle de Prazos', BlogsDoArtigo::Normal, '2026-11-01');
        $b = $this->artigo('gestao-da-equipe', 'Gestão da equipe', BlogsDoArtigo::Gratis, '2026-11-02');
        $c = $this->artigo('prazo-em-dobro', 'Prazo em dobro 100%', BlogsDoArtigo::Ambos, '2026-11-03');
        $c->publicar(new \DateTimeImmutable('2026-11-03'));
        $this->em()->flush();

        return [$a, $b, $c];
    }

    #[TestDox('porSlug, existeSlug (sem o próprio artigo) e slugsExistentes em ordem alfabética')]
    public function testSlugs(): void
    {
        [$a] = $this->tres();

        self::assertSame($a, $this->artigos->porSlug('controle-de-prazos'));
        self::assertNull($this->artigos->porSlug('nao-existe'));
        self::assertTrue($this->artigos->existeSlug('controle-de-prazos'));
        self::assertFalse($this->artigos->existeSlug('controle-de-prazos', $a->getId()));
        self::assertSame(['controle-de-prazos', 'gestao-da-equipe', 'prazo-em-dobro'], $this->artigos->slugsExistentes());
    }

    #[TestDox('Lista: os alterados por último primeiro, busca no título e no slug sem diferenciar maiúsculas, e % é texto')]
    public function testListaComBusca(): void
    {
        [$a, $b, $c] = $this->tres();

        self::assertSame([$c, $b, $a], iterator_to_array($this->artigos->listar(null, null, null)));
        self::assertSame([$c, $a], iterator_to_array($this->artigos->listar('PRAZO', null, null)));
        self::assertSame([$b], iterator_to_array($this->artigos->listar('gestao-da', null, null)));
        self::assertSame([$c], iterator_to_array($this->artigos->listar('100%', null, null)));
        self::assertSame([$c], iterator_to_array($this->artigos->listar('%', null, null)), 'o % da busca não é curinga');
    }

    #[TestDox('Lista: filtros de situação e de blog, e paginação')]
    public function testListaComFiltros(): void
    {
        [$a, $b, $c] = $this->tres();

        self::assertSame([$c], iterator_to_array($this->artigos->listar(null, SituacaoDoArtigo::Publicado, null)));
        self::assertSame([$b, $a], iterator_to_array($this->artigos->listar(null, SituacaoDoArtigo::Rascunho, null)));
        self::assertSame([$b], iterator_to_array($this->artigos->listar(null, null, BlogsDoArtigo::Gratis)));
        $pagina2 = $this->artigos->listar(null, null, null, 2, 2);
        self::assertSame([$a], iterator_to_array($pagina2));
        self::assertCount(3, $pagina2);
    }

    #[TestDox('Imagens do artigo: mapa id => extensão, e somem junto com o artigo (ON DELETE CASCADE)')]
    public function testImagensEmCascata(): void
    {
        [$a] = $this->tres();
        $imagem = new ImagemDoArtigo($a, 'blog/imagens/x.png', 'tela.png', 'image/png', 800, 600, 12345, new \DateTimeImmutable());
        $this->imagens->salvar($imagem, flush: true);

        self::assertSame([(int) $imagem->getId() => 'png'], $this->imagens->extensoesDoArtigo($a));

        $conexao = $this->em()->getConnection();
        $conexao->executeStatement('DELETE FROM artigo_blog WHERE id = :id', ['id' => $a->getId()]);
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM artigo_blog_imagem WHERE id = :id', ['id' => $imagem->getId()]));
    }
}
