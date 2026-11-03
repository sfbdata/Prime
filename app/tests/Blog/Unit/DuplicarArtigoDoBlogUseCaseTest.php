<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\UseCase\DuplicarArtigoDoBlogUseCase;
use App\Entity\Auth\User;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DuplicarArtigoDoBlogUseCase::class)]
final class DuplicarArtigoDoBlogUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private EscopoDeAuditoriaDePlataforma $escopo;
    /** @var list<ArtigoDoBlog> */
    private array $salvos = [];

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->artigos->method('salvar')->willReturnCallback(function (ArtigoDoBlog $artigo, bool $flush): void {
            self::assertTrue($flush);
            self::assertTrue($this->escopo->estaLigado(), 'grava no escopo de plataforma');
            $this->salvos[] = $artigo;
        });
    }

    private function sut(): DuplicarArtigoDoBlogUseCase
    {
        return new DuplicarArtigoDoBlogUseCase($this->artigos, new MockClock('2026-11-03 10:00:00'), $this->escopo);
    }

    /** O artigo publicado, em destaque, com capa, imagens no corpo e marcadores. */
    private function origem(): ArtigoDoBlog
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->definirImagem('5', 'Painel de prazos');
        $artigo->definirMarcadores(['Prazo em dobro', 'Recesso']);
        $artigo->definirRelacionados(['distribuicao-de-tarefas']);
        $artigo->definirCorpo(
            '<p>Antes</p><img src="/master/blog/imagens/5" alt="x"><p>Depois <IMG src="y"></p>',
            "Antes\n\n![x](./imagens/5.png)\n\nDepois\n",
        );
        $this->artigos->method('porId')->willReturnMap([[7, $artigo]]);

        return $artigo;
    }

    #[TestDox('Cópia: rascunho novo com slug -copia, textos, classificação e marcadores; sem destaque, sem capa e sem imagens no corpo')]
    public function testCopia(): void
    {
        $origem = $this->origem();
        $this->artigos->method('existeSlug')->willReturn(false);
        $ator = new User();

        $copia = $this->sut()->executar(7, $ator);

        self::assertSame([$copia], $this->salvos);
        self::assertNotSame($origem, $copia);
        self::assertSame('controle-de-prazos-processuais-copia', $copia->getSlug());
        self::assertSame(SituacaoDoArtigo::Rascunho, $copia->getSituacao());
        self::assertNull($copia->getPublicadoEm());
        self::assertFalse($copia->isDestaque());
        self::assertNull($copia->getImagemChave());
        self::assertNull($copia->getImagemAlt());
        self::assertSame('<p>Antes</p><p>Depois </p>', $copia->getCorpoHtml());
        self::assertSame("Antes\n\n\n\nDepois\n", $copia->getCorpoMarkdown());
        self::assertSame(['Prazo em dobro', 'Recesso'], $copia->getMarcadores());
        self::assertSame($origem->getTitulo(), $copia->getTitulo());
        self::assertSame($origem->getResumo(), $copia->getResumo());
        self::assertSame($origem->getCategoria(), $copia->getCategoria());
        self::assertSame($origem->getAutor(), $copia->getAutor());
        self::assertSame($origem->getLeitura(), $copia->getLeitura());
        self::assertSame($origem->getReferencias(), $copia->getReferencias());
        self::assertSame(['distribuicao-de-tarefas'], $copia->getRelacionados());
        self::assertSame($origem->getBlogs(), $copia->getBlogs());
        self::assertSame($ator, $copia->getCriadoPor());
        self::assertSame('2026-11-03 10:00', $copia->getCriadoEm()->format('Y-m-d H:i'), 'a hora do relógio');
        self::assertSame(SituacaoDoArtigo::Publicado, $origem->getSituacao(), 'a origem não muda');
        self::assertTrue($origem->isDestaque());
    }

    #[TestDox('Slug -copia já usado: tenta -copia-2, -copia-3...')]
    public function testSlugComNumero(): void
    {
        $this->origem();
        $usados = ['controle-de-prazos-processuais-copia', 'controle-de-prazos-processuais-copia-2'];
        $this->artigos->method('existeSlug')->willReturnCallback(static fn (string $slug): bool => \in_array($slug, $usados, true));

        self::assertSame('controle-de-prazos-processuais-copia-3', $this->sut()->executar(7, null)->getSlug());
    }

    #[TestDox('Só -copia usado: fica -copia-2')]
    public function testSlugCopia2(): void
    {
        $this->origem();
        $this->artigos->method('existeSlug')->willReturnCallback(static fn (string $slug): bool => $slug === 'controle-de-prazos-processuais-copia');

        self::assertSame('controle-de-prazos-processuais-copia-2', $this->sut()->executar(7, null)->getSlug());
    }

    #[TestDox('Slug longo cortado em cima de um hífen: a cópia não fica com hífen duplo')]
    public function testSlugLongoCortadoNoHifen(): void
    {
        $origem = new ArtigoDoBlog(str_repeat('a', 109) . '-' . str_repeat('b', 10), BlogsDoArtigo::Normal, null, new \DateTimeImmutable('2026-11-01'));
        $this->artigos->method('porId')->willReturnMap([[8, $origem]]);
        $this->artigos->method('existeSlug')->willReturn(false);

        self::assertSame(str_repeat('a', 109) . '-copia', $this->sut()->executar(8, null)->getSlug());
    }

    #[TestDox('Id que não existe: não encontrado, nada gravado')]
    public function testIdInexistente(): void
    {
        $this->artigos->method('porId')->willReturn(null);

        try {
            $this->sut()->executar(99, null);
            self::fail('id inexistente');
        } catch (ArtigoDoBlogNaoEncontrado) {
        }
        self::assertSame([], $this->salvos);
    }
}
