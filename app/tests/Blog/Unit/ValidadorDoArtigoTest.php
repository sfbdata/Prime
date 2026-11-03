<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Service\CatalogoDoBlog;
use App\Blog\Service\ValidadorDoArtigo;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidadorDoArtigo::class)]
#[CoversClass(CatalogoDoBlog::class)]
final class ValidadorDoArtigoTest extends TestCase
{
    private const EXISTENTES = ['controle-de-prazos-processuais', 'distribuicao-de-tarefas-na-equipe'];

    /** @return array<string, list<string>> */
    private function validar(ArtigoDoBlog $artigo): array
    {
        return (new ValidadorDoArtigo())->validar($artigo, self::EXISTENTES);
    }

    #[TestDox('O artigo real do repositório dos sites passa sem erro')]
    public function testArtigoRealPassa(): void
    {
        self::assertSame([], $this->validar(ArtigosDeTeste::controleDePrazos()));
    }

    #[TestDox('Rascunho vazio: cada campo obrigatório, categoria, autor e data aparecem com a mensagem em português')]
    public function testObrigatorios(): void
    {
        $erros = $this->validar(new ArtigoDoBlog('novo', BlogsDoArtigo::Normal, null, new \DateTimeImmutable()));

        self::assertSame(
            ['titulo', 'tituloCurto', 'tituloSeo', 'descricao', 'linhaFina', 'resumo', 'leitura', 'corpo', 'categoria', 'autor', 'publicarEm'],
            array_keys($erros),
        );
        self::assertSame(['Informe o título.'], $erros['titulo']);
        self::assertSame(['Escolha uma das categorias do blog.'], $erros['categoria']);
    }

    #[TestDox('Título SEO até 60 e meta description até 160 caracteres, contando acento como um')]
    public function testTamanhos(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->editarTextos('t', 't', str_repeat('é', 60), str_repeat('ã', 160), 'l', 'r');
        self::assertSame([], $this->validar($artigo));

        $artigo->editarTextos('t', 't', str_repeat('é', 61), str_repeat('ã', 161), 'l', 'r');
        $erros = $this->validar($artigo);
        self::assertSame(['O título SEO pode ter até 60 caracteres.'], $erros['tituloSeo']);
        self::assertSame(['A meta description pode ter até 160 caracteres.'], $erros['descricao']);
    }

    #[TestDox('Categoria e autor precisam estar no catálogo copiado dos sites')]
    public function testCatalogo(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->classificar('culinaria', 'fulano', null, null, '9 min', false);

        $erros = $this->validar($artigo);

        self::assertArrayHasKey('categoria', $erros);
        self::assertSame(['Escolha um dos autores do blog.'], $erros['autor']);
        self::assertCount(12, CatalogoDoBlog::CATEGORIAS);
    }

    #[TestDox('Revisor: os dois campos ou nenhum')]
    public function testRevisor(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->classificar('prazos-processuais', 'equipe-editorial-bluejus', 'Dra. Ana', null, '9 min', false);
        self::assertArrayHasKey('revisor', $this->validar($artigo));

        $artigo->classificar('prazos-processuais', 'equipe-editorial-bluejus', 'Dra. Ana', 'OAB/DF 123', '9 min', false);
        self::assertSame([], $this->validar($artigo));
    }

    #[TestDox('Imagem de compartilhamento exige o texto alternativo')]
    public function testImagemExigeAlt(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->definirImagem('blog/imagens/1.png', null);

        self::assertSame(['A imagem de compartilhamento precisa do texto alternativo.'], $this->validar($artigo)['imagemAlt']);
    }

    #[TestDox('Referência exige texto, rótulo e url http(s) válida; endereço com acento vale, como no z.url() do site')]
    public function testReferencias(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->definirReferencias([
            ['texto' => 'ok', 'url' => 'https://planalto.gov.br', 'rotulo' => 'planalto'],
            ['texto' => '', 'url' => 'ftp://planalto.gov.br', 'rotulo' => 'x'],
            ['texto' => 'x', 'url' => 'não é url', 'rotulo' => 'x'],
            ['texto' => 'com acento', 'url' => 'https://www.gov.br/conselho-de-justiça/notícias', 'rotulo' => 'acento'],
        ]);

        self::assertSame([
            'A referência 2 precisa de texto e rótulo.',
            'A referência 2 precisa de um endereço que comece com http:// ou https://.',
            'A referência 3 precisa de um endereço que comece com http:// ou https://.',
        ], $this->validar($artigo)['referencias']);
    }

    #[TestDox('Relacionados precisam existir e não podem ser o próprio artigo')]
    public function testRelacionados(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->definirRelacionados(['distribuicao-de-tarefas-na-equipe', 'controle-de-prazos-processuais', 'nao-existe']);

        self::assertSame([
            'O artigo não pode ser relacionado a ele mesmo.',
            'O artigo relacionado "nao-existe" não existe.',
        ], $this->validar($artigo)['relacionados']);
    }

    #[TestDox('Marcadores: mais de 8 e mais de 40 caracteres aparecem no campo marcadores')]
    public function testMarcadores(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->definirMarcadores(['Prazos', 'Recesso']);
        self::assertSame([], $this->validar($artigo));

        $longo = str_repeat('m', 41);
        $artigo->definirMarcadores(['1', '2', '3', '4', '5', '6', '7', '8', $longo]);

        self::assertSame([
            'Use até 8 marcadores.',
            sprintf('O marcador "%s" passa de 40 caracteres.', $longo),
        ], $this->validar($artigo)['marcadores']);
    }

    #[TestDox('Uma chamada não leva os erros da anterior')]
    public function testSemEstadoEntreChamadas(): void
    {
        $sut = new ValidadorDoArtigo();
        $sut->validar(new ArtigoDoBlog('novo', BlogsDoArtigo::Normal, null, new \DateTimeImmutable()), []);

        self::assertSame([], $sut->validar(ArtigosDeTeste::controleDePrazos(), self::EXISTENTES));
    }
}
