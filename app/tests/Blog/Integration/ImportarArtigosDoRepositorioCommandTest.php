<?php

declare(strict_types=1);

namespace App\Tests\Blog\Integration;

use App\Blog\Command\ImportarArtigosDoRepositorioCommand;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `blog:importar-do-repositorio` contra o banco de verdade (DAMA desfaz cada teste), com uma cópia do repositório dos
 * sites montada numa pasta temporária: o artigo real no blog e no grátis (vira "os dois"), um só no grátis, um rascunho
 * escondido por `_` e um arquivo quebrado.
 */
#[CoversClass(ImportarArtigosDoRepositorioCommand::class)]
final class ImportarArtigosDoRepositorioCommandTest extends KernelTestCase
{
    private string $pasta;
    private CommandTester $comando;
    private ArtigoDoBlogRepository $artigos;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->artigos = static::getContainer()->get(ArtigoDoBlogRepository::class);
        $this->comando = new CommandTester((new Application(self::$kernel))->find('blog:importar-do-repositorio'));

        $this->pasta = sys_get_temp_dir() . '/importar-blog-' . bin2hex(random_bytes(6));
        $real = (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.md');
        $soGratis = str_replace(
            ['slug: controle-de-prazos-processuais', 'destaque: true'],
            ['slug: so-no-gratis', 'destaque: false'],
            $real,
        );
        $fs = new Filesystem();
        $fs->dumpFile($this->pasta . '/blog/src/content/artigos/controle-de-prazos-processuais.md', $real);
        $fs->dumpFile($this->pasta . '/blog-gratis/src/content/artigos/controle-de-prazos-processuais.md', $real);
        $fs->dumpFile($this->pasta . '/blog-gratis/src/content/artigos/sub/so-no-gratis.md', $soGratis);
        $fs->dumpFile($this->pasta . '/blog-gratis/src/content/artigos/_rascunho.md', str_replace('slug: controle-de-prazos-processuais', 'slug: escondido', $real));
        $fs->dumpFile($this->pasta . '/blog-gratis/src/content/artigos/LEIAME.txt', 'não é artigo');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->pasta);
        parent::tearDown();
    }

    #[TestDox('--simular mostra o que faria e não grava nada')]
    public function testSimular(): void
    {
        $codigo = $this->comando->execute(['pasta' => $this->pasta, '--simular' => true]);
        $saida = $this->comando->getDisplay();

        self::assertSame(Command::SUCCESS, $codigo, $saida);
        self::assertStringContainsString('Simulação: nada será gravado.', $saida);
        self::assertStringContainsString('Seria importado: controle-de-prazos-processuais (blog/src/content/artigos/controle-de-prazos-processuais.md e blog-gratis/src/content/artigos/controle-de-prazos-processuais.md), blog: Os dois, situação: Publicado.', $saida);
        self::assertStringContainsString('Seria importado: so-no-gratis', $saida);
        self::assertStringContainsString('Seriam importados: 2. Já existiam: 0. Recusados: 0.', $saida);
        self::assertStringNotContainsString('escondido', $saida);
        self::assertSame([], $this->artigos->slugsExistentes());
    }

    #[TestDox('Grava os artigos, junta o mesmo slug dos dois blogs em "os dois" e a segunda rodada não duplica')]
    public function testImportaENaoDuplica(): void
    {
        self::assertSame(Command::SUCCESS, $this->comando->execute(['pasta' => $this->pasta]), $this->comando->getDisplay());
        self::assertStringContainsString('Importados: 2. Já existiam: 0. Recusados: 0.', $this->comando->getDisplay());

        self::assertSame(['controle-de-prazos-processuais', 'so-no-gratis'], $this->artigos->slugsExistentes());
        $real = $this->artigos->porSlug('controle-de-prazos-processuais');
        self::assertSame(BlogsDoArtigo::Ambos, $real?->getBlogs());
        self::assertSame(SituacaoDoArtigo::Publicado, $real->getSituacao());
        self::assertSame(BlogsDoArtigo::Gratis, $this->artigos->porSlug('so-no-gratis')?->getBlogs());

        self::assertSame(Command::SUCCESS, $this->comando->execute(['pasta' => $this->pasta]));
        $saida = $this->comando->getDisplay();
        self::assertStringContainsString('Já existe no banco, pulado: controle-de-prazos-processuais', $saida);
        self::assertStringContainsString('Importados: 0. Já existiam: 2. Recusados: 0.', $saida);
        self::assertCount(2, $this->artigos->slugsExistentes());
    }

    #[TestDox('Arquivo recusado é informado, os outros entram e o comando termina com falha')]
    public function testRecusadoNaoParaOsOutros(): void
    {
        (new Filesystem())->dumpFile($this->pasta . '/blog/src/content/artigos/quebrado.md', "sem frontmatter\n");
        (new Filesystem())->dumpFile(
            $this->pasta . '/blog/src/content/artigos/com-imagem.md',
            str_replace('slug: controle-de-prazos-processuais', 'slug: com-imagem', (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.md')) . "\n![x](./x.png)\n",
        );

        $codigo = $this->comando->execute(['pasta' => $this->pasta]);
        $saida = $this->comando->getDisplay();

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('Recusado: blog/src/content/artigos/quebrado.md. O arquivo não começa com o frontmatter', $saida);
        self::assertStringContainsString('Recusado: com-imagem (blog/src/content/artigos/com-imagem.md). O texto tem imagem.', $saida);
        self::assertStringContainsString('Importados: 2. Já existiam: 0. Recusados: 2.', $saida);
        self::assertSame(['controle-de-prazos-processuais', 'so-no-gratis'], $this->artigos->slugsExistentes());
    }

    #[TestDox('Dois arquivos com o mesmo slug no mesmo blog: os dois são recusados, nenhum some calado')]
    public function testSlugRepetidoNoMesmoBlog(): void
    {
        (new Filesystem())->dumpFile(
            $this->pasta . '/blog-gratis/src/content/artigos/outro/copia-do-gratis.md',
            str_replace('Painel', 'Quadro', (string) file_get_contents($this->pasta . '/blog-gratis/src/content/artigos/sub/so-no-gratis.md')),
        );

        $codigo = $this->comando->execute(['pasta' => $this->pasta]);
        $saida = $this->comando->getDisplay();

        self::assertSame(Command::FAILURE, $codigo);
        self::assertStringContainsString('O slug "so-no-gratis" já aparece em', $saida);
        self::assertStringContainsString('Corrija o slug repetido e rode de novo.', $saida);
        self::assertSame(['controle-de-prazos-processuais'], $this->artigos->slugsExistentes());
    }

    #[TestDox('O mesmo slug com textos diferentes nos dois blogs é recusado')]
    public function testTextosDiferentesNosDoisBlogs(): void
    {
        $caminho = $this->pasta . '/blog-gratis/src/content/artigos/controle-de-prazos-processuais.md';
        (new Filesystem())->appendToFile($caminho, "\nParágrafo só do grátis.\n");

        $this->comando->execute(['pasta' => $this->pasta]);

        self::assertStringContainsString('está nos dois blogs com textos diferentes', $this->comando->getDisplay());
        self::assertSame(['so-no-gratis'], $this->artigos->slugsExistentes());
    }

    #[TestDox('Pasta sem blog/ nem blog-gratis/ é recusada')]
    public function testPastaErrada(): void
    {
        self::assertSame(Command::INVALID, $this->comando->execute(['pasta' => $this->pasta . '/nao-existe']));
        self::assertStringContainsString('não tem blog/src/content/artigos nem blog-gratis/src/content/artigos', $this->comando->getDisplay());
    }
}
