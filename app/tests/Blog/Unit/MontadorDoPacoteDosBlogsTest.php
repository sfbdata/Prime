<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\Service\ExportadorDeArtigo;
use App\Blog\Service\MontadorDoPacoteDosBlogs;
use App\Blog\Service\PastaDosPedidos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * O pacote do contrato da seção 6.1 (spec painel-artigos-blogs), montado numa pasta temporária do processo.
 */
#[CoversClass(MontadorDoPacoteDosBlogs::class)]
#[CoversClass(PastaDosPedidos::class)]
final class MontadorDoPacoteDosBlogsTest extends TestCase
{
    private string $raiz;
    private ArtigoDoBlogRepository&MockObject $artigos;
    private ImagemDoArtigoRepository&MockObject $imagens;
    private ArmazenamentoEmMemoria $armazenamento;

    /** @var array<int, list<ImagemDoArtigo>> id do artigo => imagens */
    private array $imagensPorArtigo = [];

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/pacote-blogs-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->imagens = $this->createMock(ImagemDoArtigoRepository::class);
        $this->imagens->method('doArtigo')->willReturnCallback(fn (ArtigoDoBlog $a): array => $this->imagensPorArtigo[(int) $a->getId()] ?? []);
        $this->armazenamento = new ArmazenamentoEmMemoria();
    }

    protected function tearDown(): void
    {
        $this->apagar($this->raiz);
    }

    private function sut(): MontadorDoPacoteDosBlogs
    {
        return new MontadorDoPacoteDosBlogs($this->artigos, $this->imagens, new ExportadorDeArtigo(), $this->armazenamento, new PastaDosPedidos($this->raiz));
    }

    private function publicacao(int $id): PublicacaoDosBlogs
    {
        $p = new PublicacaoDosBlogs(new \DateTimeImmutable('2026-11-03 14:05:00'), null, MotivoDaPublicacao::Publicar);
        (new \ReflectionProperty(PublicacaoDosBlogs::class, 'id'))->setValue($p, $id);

        return $p;
    }

    private function publicado(string $slug, BlogsDoArtigo $blogs, int $id, string $corpo = "Texto.\n"): ArtigoDoBlog
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto($slug, $blogs);
        $artigo->definirCorpo('<p>Texto.</p>', $corpo);
        $artigo->publicar(new \DateTimeImmutable('2026-11-02 10:00'));
        (new \ReflectionProperty(ArtigoDoBlog::class, 'id'))->setValue($artigo, $id);

        return $artigo;
    }

    private function imagem(ArtigoDoBlog $artigo, int $id, string $mime, ?string $conteudo): ImagemDoArtigo
    {
        $imagem = new ImagemDoArtigo($artigo, 'arq-' . $id, 'foto', $mime, 10, 10, 3, new \DateTimeImmutable());
        (new \ReflectionProperty(ImagemDoArtigo::class, 'id'))->setValue($imagem, $id);
        if ($conteudo !== null) {
            $this->armazenamento->semear(ChavesDoBlog::imagem($imagem), $conteudo);
        }
        $this->imagensPorArtigo[(int) $artigo->getId()][] = $imagem;

        return $imagem;
    }

    #[TestDox('Todos os publicados por pasta, imagens do corpo e da capa ao lado, canonical só na cópia do grátis de "os dois", e o pedido.json')]
    public function testMontaOPacote(): void
    {
        $normal = $this->publicado('so-no-blog', BlogsDoArtigo::Normal, 1, "Antes.\n\n![Gráfico](./imagens/11.png)\n");
        $this->imagem($normal, 11, 'image/png', 'PNG-11');
        $this->imagem($normal, 12, 'image/jpeg', 'sem uso');
        $ambos = $this->publicado('nos-dois', BlogsDoArtigo::Ambos, 2);
        $this->imagem($ambos, 21, 'image/webp', 'WEBP-21');
        $ambos->definirImagem('21', 'Capa');
        $gratis = $this->publicado('so-no-gratis', BlogsDoArtigo::Gratis, 3);
        $this->artigos->method('publicados')->willReturn([$ambos, $normal, $gratis]);

        $slugs = $this->sut()->montar($this->publicacao(12));

        self::assertSame(['blog' => ['nos-dois', 'so-no-blog'], 'blog-gratis' => ['nos-dois', 'so-no-gratis']], $slugs);
        $p = $this->raiz . '/12';
        self::assertSame(['.', '..', '12'], scandir($this->raiz), 'sem sobra da montagem');
        self::assertSame([
            'artigos/blog-gratis/imagens/21.webp', 'artigos/blog-gratis/nos-dois.md', 'artigos/blog-gratis/so-no-gratis.md',
            'artigos/blog/imagens/11.png', 'artigos/blog/imagens/21.webp', 'artigos/blog/nos-dois.md', 'artigos/blog/so-no-blog.md',
            'pedido.json',
        ], $this->arquivos($p));
        self::assertSame('PNG-11', file_get_contents($p . '/artigos/blog/imagens/11.png'));
        self::assertSame('WEBP-21', file_get_contents($p . '/artigos/blog-gratis/imagens/21.webp'));

        $noBlog = $this->frontmatter($p . '/artigos/blog/nos-dois.md');
        $noGratis = $this->frontmatter($p . '/artigos/blog-gratis/nos-dois.md');
        self::assertArrayNotHasKey('canonical', $noBlog);
        self::assertSame('https://bluejus.com.br/blog/prazos-processuais/nos-dois', $noGratis['canonical']);
        self::assertSame('./imagens/21.webp', $noGratis['imagem']);
        self::assertArrayNotHasKey('canonical', $this->frontmatter($p . '/artigos/blog-gratis/so-no-gratis.md'));

        self::assertSame([
            'versao' => 1, 'id' => 12, 'pedidaEm' => '2026-11-03T14:05:00-03:00', 'motivo' => 'publicar',
            'artigos' => ['blog' => ['nos-dois', 'so-no-blog'], 'blog-gratis' => ['nos-dois', 'so-no-gratis']],
        ], json_decode((string) file_get_contents($p . '/pedido.json'), true));
    }

    #[TestDox('Nenhum publicado: pacote só com o pedido.json e as duas listas vazias (o build tira tudo do ar)')]
    public function testSemPublicados(): void
    {
        $this->artigos->method('publicados')->willReturn([]);

        $slugs = $this->sut()->montar($this->publicacao(3));

        self::assertSame(['blog' => [], 'blog-gratis' => []], $slugs);
        self::assertSame(['pedido.json'], $this->arquivos($this->raiz . '/3'));
        self::assertStringContainsString('"blog": []', (string) file_get_contents($this->raiz . '/3/pedido.json'));
    }

    #[TestDox('Imagem que sumiu do armazenamento: a exceção sobe e nada fica na pasta (nem a montagem pela metade)')]
    public function testFalhaApagaAMontagem(): void
    {
        $artigo = $this->publicado('sem-imagem', BlogsDoArtigo::Normal, 1, "![Foto](./imagens/5.jpg)\n");
        $this->imagem($artigo, 5, 'image/jpeg', null);
        $this->artigos->method('publicados')->willReturn([$artigo]);

        try {
            $this->sut()->montar($this->publicacao(4));
            self::fail('Deveria falhar.');
        } catch (ArquivoNaoEncontrado) {
        }

        self::assertSame(['.', '..'], scandir($this->raiz));
    }

    #[TestDox('Sobra de uma montagem que caiu no meio é apagada antes de montar de novo')]
    public function testSobraDeMontagemAnterior(): void
    {
        mkdir($this->raiz . '/.6.montando/artigos/blog', 0o775, true);
        file_put_contents($this->raiz . '/.6.montando/artigos/blog/velho.md', 'velho');
        $this->artigos->method('publicados')->willReturn([]);

        $this->sut()->montar($this->publicacao(6));

        self::assertSame(['pedido.json'], $this->arquivos($this->raiz . '/6'));
        self::assertFalse(file_exists($this->raiz . '/.6.montando'));
    }

    /** @return array<string, mixed> */
    private function frontmatter(string $arquivo): array
    {
        self::assertSame(1, preg_match('/^---\n(.*?)\n---\n/s', (string) file_get_contents($arquivo), $m));

        return Yaml::parse($m[1], Yaml::PARSE_DATETIME);
    }

    /** @return list<string> caminhos relativos, em ordem */
    private function arquivos(string $pasta): array
    {
        $lista = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            $lista[] = substr((string) $arquivo, \strlen($pasta) + 1);
        }
        sort($lista);

        return $lista;
    }

    private function apagar(string $caminho): void
    {
        if (is_file($caminho) || is_link($caminho)) {
            unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }
        foreach (scandir($caminho) ?: [] as $nome) {
            if ($nome !== '.' && $nome !== '..') {
                $this->apagar($caminho . '/' . $nome);
            }
        }
        rmdir($caminho);
    }
}
