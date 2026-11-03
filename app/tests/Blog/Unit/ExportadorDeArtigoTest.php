<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Service\ExportadorDeArtigo;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ExportadorDeArtigo::class)]
#[CoversClass(BlogsDoArtigo::class)]
final class ExportadorDeArtigoTest extends TestCase
{
    #[TestDox('O frontmatter sai com os mesmos campos, valores e ordem do controle-de-prazos-processuais.md, e o corpo logo abaixo')]
    public function testFrontmatterIgualAoModelo(): void
    {
        $modelo = (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.md');
        [$frontmatterDoModelo, $corpo] = self::separar($modelo);

        $gerado = (new ExportadorDeArtigo())->exportar(ArtigosDeTeste::controleDePrazos($corpo));
        [$frontmatterGerado, $corpoGerado] = self::separar($gerado);

        $esperado = Yaml::parse($frontmatterDoModelo, Yaml::PARSE_DATETIME);
        $obtido = Yaml::parse($frontmatterGerado, Yaml::PARSE_DATETIME);
        self::assertEquals($esperado, $obtido);
        self::assertSame(array_keys($esperado), array_keys($obtido), 'mesma ordem dos campos');
        self::assertSame($corpo, $corpoGerado);
        self::assertStringContainsString("\npublicadoEm: 2026-10-28\natualizadoEm: 2026-10-30\n", $frontmatterGerado, 'datas sem aspas, como no modelo');
        self::assertStringEndsWith("\nrelacionados: []", $frontmatterGerado, 'lista vazia é lista, não objeto');
    }

    #[TestDox('Revisor, imagem e alt saem quando existem; destaque falso não sai')]
    public function testOpcionais(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->classificar('prazos-processuais', 'equipe-editorial-bluejus', 'Dra. Ana', 'OAB/DF 123', '9 min', false);
        $artigo->definirImagem('5', 'Painel de prazos');

        [$frontmatter] = self::separar((new ExportadorDeArtigo())->exportar($artigo, './imagens/5.png'));
        $dados = Yaml::parse($frontmatter);

        self::assertSame(['nome' => 'Dra. Ana', 'oab' => 'OAB/DF 123'], $dados['revisor']);
        self::assertSame('./imagens/5.png', $dados['imagem']);
        self::assertSame('Painel de prazos', $dados['imagemAlt']);
        self::assertArrayNotHasKey('destaque', $dados);
        self::assertArrayNotHasKey('rascunho', $dados);
    }

    #[TestDox('Marcadores saem logo depois de categoria, na ordem em que foram escritos')]
    public function testMarcadores(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->definirMarcadores(['Prazo em dobro', 'Recesso forense', 'Intimação']);

        [$frontmatter] = self::separar((new ExportadorDeArtigo())->exportar($artigo));
        $dados = Yaml::parse($frontmatter, Yaml::PARSE_DATETIME);
        $chaves = array_keys($dados);

        self::assertSame(['Prazo em dobro', 'Recesso forense', 'Intimação'], $dados['marcadores']);
        self::assertSame(['slug', 'categoria', 'marcadores', 'autor'], \array_slice($chaves, array_search('slug', $chaves, true), 4));
    }

    #[TestDox('Sem marcadores a chave não sai (o modelo dos sites não tem a chave)')]
    public function testSemMarcadoresNaoSai(): void
    {
        [$frontmatter] = self::separar((new ExportadorDeArtigo())->exportar(ArtigosDeTeste::controleDePrazos()));

        self::assertArrayNotHasKey('marcadores', Yaml::parse($frontmatter, Yaml::PARSE_DATETIME));
        self::assertStringNotContainsString('marcadores', $frontmatter);
    }

    #[TestDox('Artigo sem data de publicação não exporta')]
    public function testSemDataNaoExporta(): void
    {
        $this->expectException(\LogicException::class);
        (new ExportadorDeArtigo())->exportar(new ArtigoDoBlog('novo', BlogsDoArtigo::Normal, null, new \DateTimeImmutable()));
    }

    #[TestDox('D5: "os dois" vai para as pastas blog e blog-gratis do repositório dos sites')]
    public function testBlogsDeDestino(): void
    {
        $sut = new ExportadorDeArtigo();
        $artigo = new ArtigoDoBlog('novo', BlogsDoArtigo::Ambos, null, new \DateTimeImmutable());

        self::assertSame(['blog', 'blog-gratis'], $sut->blogsDeDestino($artigo));
        $artigo->definirBlogs(BlogsDoArtigo::Gratis);
        self::assertSame(['blog-gratis'], $sut->blogsDeDestino($artigo));
    }

    /** @return array{string, string} frontmatter (sem os ---) e corpo */
    private static function separar(string $md): array
    {
        self::assertSame(1, preg_match('/^---\n(.*?)\n---\n\n(.*)$/s', $md, $m));

        return [$m[1], $m[2]];
    }
}
