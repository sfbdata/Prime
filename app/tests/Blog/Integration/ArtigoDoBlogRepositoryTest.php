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

    /** @param list<string> $marcadores */
    private function comMarcadores(string $slug, array $marcadores): ArtigoDoBlog
    {
        $artigo = $this->artigo($slug, $slug, BlogsDoArtigo::Normal, '2026-11-01');
        $artigo->definirMarcadores($marcadores);

        return $artigo;
    }

    /** O banco de teste pode ter artigos de outra frente: começa do zero (DAMA desfaz). */
    private function semArtigos(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM artigo_blog');
    }

    #[TestDox('Marcadores usados: grafias do mesmo slug somam, fica a mais usada, mais usados primeiro e empate em ordem alfabética')]
    public function testMarcadoresUsados(): void
    {
        $this->semArtigos();
        $this->comMarcadores('a1', ['Prazos', 'Recesso', 'Agenda']);
        $this->comMarcadores('a2', ['prazos', 'Recesso', 'Bloco', 'Petição inicial']);
        $this->comMarcadores('a3', ['Prazos', 'Petição inicial']);
        $this->comMarcadores('a4', ['PETICAO INICIAL', 'Prazos']);
        $this->comMarcadores('a5', []);
        $this->comMarcadores('a6', ['Ética', 'Zebra']);
        $this->em()->flush();

        self::assertSame([
            ['marcador' => 'Prazos', 'usos' => 4],
            ['marcador' => 'Petição inicial', 'usos' => 3],
            ['marcador' => 'Recesso', 'usos' => 2],
            ['marcador' => 'Agenda', 'usos' => 1],
            ['marcador' => 'Bloco', 'usos' => 1],
            ['marcador' => 'Ética', 'usos' => 1],
            ['marcador' => 'Zebra', 'usos' => 1],
        ], $this->artigos->marcadoresUsados(null));
    }

    #[TestDox('Marcadores usados: busca sem diferenciar maiúsculas, % é texto, e no máximo 10')]
    public function testMarcadoresUsadosBuscaELimite(): void
    {
        $this->semArtigos();
        $this->comMarcadores('b1', ['Prazo em dobro', 'Recesso', '100% online']);
        $this->comMarcadores('b2', ['Contagem de prazo']);
        $this->comMarcadores('b3', array_map(static fn (int $n): string => 'Tema ' . $n, range(1, 8)));
        $this->comMarcadores('b4', ['Tema 9', 'Tema 10', 'Tema 11', 'Tema 12']);
        $this->em()->flush();

        self::assertSame(
            [['marcador' => 'Contagem de prazo', 'usos' => 1], ['marcador' => 'Prazo em dobro', 'usos' => 1]],
            $this->artigos->marcadoresUsados('PRAZO'),
        );
        self::assertSame([['marcador' => '100% online', 'usos' => 1]], $this->artigos->marcadoresUsados('%'));
        self::assertSame([['marcador' => 'Recesso', 'usos' => 1]], $this->artigos->marcadoresUsados('récesso'));
        self::assertSame([], $this->artigos->marcadoresUsados('nada-assim'));
        self::assertCount(10, $this->artigos->marcadoresUsados(null));
        self::assertCount(10, $this->artigos->marcadoresUsados('  tema '));
        self::assertCount(3, $this->artigos->marcadoresUsados('tema', 3));
    }

    #[TestDox('Títulos por slug: só os que existem; lista vazia não consulta')]
    public function testTitulosPorSlug(): void
    {
        $this->tres();

        self::assertSame(
            ['controle-de-prazos' => 'Controle de Prazos', 'prazo-em-dobro' => 'Prazo em dobro 100%'],
            $this->sortear($this->artigos->titulosPorSlug(['prazo-em-dobro', 'nao-existe', 'controle-de-prazos'])),
        );
        self::assertSame([], $this->artigos->titulosPorSlug([]));
        self::assertSame([], $this->artigos->titulosPorSlug(['nao-existe']));
    }

    /**
     * @param array<string, string> $mapa
     *
     * @return array<string, string>
     */
    private function sortear(array $mapa): array
    {
        ksort($mapa);

        return $mapa;
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
