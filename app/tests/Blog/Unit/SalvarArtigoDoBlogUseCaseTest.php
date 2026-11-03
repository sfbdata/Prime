<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\DTO\SalvarArtigoDoBlogInput;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogRecusado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\UseCase\SalvarArtigoDoBlogUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(SalvarArtigoDoBlogUseCase::class)]
#[CoversClass(SalvarArtigoDoBlogInput::class)]
final class SalvarArtigoDoBlogUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private ImagemDoArtigoRepository&MockObject $imagens;
    private MockClock $relogio;
    private EscopoDeAuditoriaDePlataforma $escopo;
    /** @var list<ArtigoDoBlog> */
    private array $salvos = [];

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->imagens = $this->createMock(ImagemDoArtigoRepository::class);
        $this->relogio = new MockClock('2026-11-03 10:00:00');
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->artigos->method('salvar')->willReturnCallback(function (ArtigoDoBlog $artigo, bool $flush): void {
            self::assertTrue($flush, 'uma transação, com flush');
            self::assertTrue($this->escopo->estaLigado(), 'grava no escopo de plataforma');
            $this->salvos[] = $artigo;
        });
    }

    private function sut(): SalvarArtigoDoBlogUseCase
    {
        return new SalvarArtigoDoBlogUseCase($this->artigos, $this->imagens, new HtmlDoArtigoParaMarkdown(), $this->relogio, $this->escopo);
    }

    /** @param array<string, mixed> $campos */
    private function input(array $campos = [], ?int $id = null): SalvarArtigoDoBlogInput
    {
        return SalvarArtigoDoBlogInput::doJson(null, $campos + ['slug' => 'meu-artigo', 'blogs' => 'normal', 'titulo' => 'Meu artigo'], $id);
    }

    private function existente(int $id, SituacaoDoArtigo $situacao = SituacaoDoArtigo::Rascunho): ArtigoDoBlog
    {
        $artigo = new ArtigoDoBlog('meu-artigo', BlogsDoArtigo::Normal, null, new \DateTimeImmutable('2026-11-01'));
        (new \ReflectionProperty(ArtigoDoBlog::class, 'id'))->setValue($artigo, $id);
        if ($situacao === SituacaoDoArtigo::Publicado) {
            $artigo->publicar(new \DateTimeImmutable('2026-11-02'));
        }
        $this->artigos->method('porId')->willReturnMap([[$id, $artigo]]);

        return $artigo;
    }

    #[TestDox('Novo artigo: nasce rascunho, com o HTML sanitizado, o Markdown gerado e a leitura calculada')]
    public function testCriaRascunho(): void
    {
        $this->artigos->method('existeSlug')->with('meu-artigo', null)->willReturn(false);
        $this->imagens->expects(self::never())->method('extensoesDoArtigo');
        $html = '<p onclick="x()">' . str_repeat('palavra ', 401) . '</p><script>alert(1)</script><div data-bj="cta-meio"></div>';

        $saida = $this->sut()->executar($this->input(['corpoHtml' => $html, 'blogs' => 'ambos']));

        self::assertCount(1, $this->salvos);
        $artigo = $this->salvos[0];
        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
        self::assertSame(BlogsDoArtigo::Ambos, $artigo->getBlogs());
        self::assertStringNotContainsString('script', $artigo->getCorpoHtml());
        self::assertStringNotContainsString('onclick', $artigo->getCorpoHtml());
        self::assertStringEndsWith("palavra\n\n::cta-meio\n", $artigo->getCorpoMarkdown());
        self::assertSame('3 min', $artigo->getLeitura(), '401 palavras a 200 por minuto');
        self::assertSame('3 min', $saida->leitura);
        self::assertSame('rascunho', $saida->situacao);
    }

    #[TestDox('Leitura escrita pelo Master é mantida; corpo vazio dá 1 min')]
    public function testLeitura(): void
    {
        $this->artigos->method('existeSlug')->willReturn(false);

        self::assertSame('12 min', $this->sut()->executar($this->input(['leitura' => '12 min']))->leitura);
        self::assertSame('1 min', $this->sut()->executar($this->input())->leitura);
    }

    #[TestDox('Slug de outro artigo é recusado e nada é gravado')]
    public function testSlugRepetido(): void
    {
        $this->artigos->method('existeSlug')->willReturn(true);

        try {
            $this->sut()->executar($this->input());
            self::fail('slug repetido');
        } catch (ArtigoDoBlogRecusado $e) {
            self::assertSame('slug', $e->campo);
        }
        self::assertSame([], $this->salvos);
    }

    #[TestDox('Id que não existe responde não encontrado')]
    public function testIdInexistente(): void
    {
        $this->artigos->method('porId')->willReturn(null);

        $this->expectException(ArtigoDoBlogNaoEncontrado::class);
        $this->sut()->executar($this->input([], 99));
    }

    #[TestDox('Na edição, o slug é conferido sem o próprio artigo e as imagens viram ./imagens/{id}.{ext}')]
    public function testEdicaoComImagem(): void
    {
        $this->existente(7);
        $this->artigos->expects(self::once())->method('existeSlug')->with('meu-artigo', 7)->willReturn(false);
        $this->imagens->method('extensoesDoArtigo')->willReturn([4 => 'png']);

        $this->sut()->executar($this->input(['corpoHtml' => '<p><img src="/master/blog/imagens/4" alt="Tela"></p>'], 7));

        self::assertSame("![Tela](./imagens/4.png)\n", $this->salvos[0]->getCorpoMarkdown());
        self::assertStringNotContainsString('/master/', $this->salvos[0]->getCorpoMarkdown());
    }

    #[TestDox('Publicado: salvar texto novo mantém a situação e marca alterações não publicadas (D7)')]
    public function testPublicadoFicaPendente(): void
    {
        $artigo = $this->existente(7, SituacaoDoArtigo::Publicado);
        $this->artigos->method('existeSlug')->willReturn(false);

        $this->sut()->executar($this->input(['titulo' => 'Título novo'], 7));

        self::assertSame(SituacaoDoArtigo::Publicado, $artigo->getSituacao());
        self::assertTrue($artigo->temAlteracoesPendentes());
        self::assertEquals($this->relogio->now(), $artigo->getAlteradoEm());
    }

    #[TestDox('Publicado: salvar sem mudança nenhuma não marca alterações')]
    public function testPublicadoSemMudanca(): void
    {
        $artigo = $this->existente(7, SituacaoDoArtigo::Publicado);
        $this->artigos->method('existeSlug')->willReturn(false);
        $this->sut()->executar($this->input(['titulo' => 'Título'], 7));
        $artigo->publicar(new \DateTimeImmutable('2026-11-03 09:00'));

        $this->sut()->executar($this->input(['titulo' => 'Título'], 7));

        self::assertFalse($artigo->temAlteracoesPendentes());
    }

    #[TestDox('Trocar o slug de um artigo que já foi ao ar exige confirmação')]
    public function testTrocaDeSlugDePublicado(): void
    {
        $artigo = $this->existente(7, SituacaoDoArtigo::Publicado);
        $this->artigos->method('existeSlug')->willReturn(false);

        try {
            $this->sut()->executar($this->input(['slug' => 'outro-slug'], 7));
            self::fail('troca sem confirmar');
        } catch (ArtigoDoBlogRecusado $e) {
            self::assertStringContainsString('quebra o link antigo', $e->getMessage());
        }
        self::assertSame('meu-artigo', $artigo->getSlug());

        $this->sut()->executar($this->input(['slug' => 'outro-slug', 'confirmarTrocaDeSlug' => true], 7));
        self::assertSame('outro-slug', $artigo->getSlug());
    }

    #[TestDox('O Input do JSON ignora tipos errados e linhas de referência vazias')]
    public function testInputDoJson(): void
    {
        $input = SalvarArtigoDoBlogInput::doJson(null, [
            'slug' => ' meu-artigo ', 'blogs' => 'gratis', 'titulo' => ['x'], 'destaque' => 'sim',
            'referencias' => [['texto' => 'Lei', 'url' => 'https://a.b', 'rotulo' => 'a.b'], ['texto' => '', 'url' => '', 'rotulo' => ''], 'lixo'],
            'relacionados' => ['a', 3, ' '],
        ]);

        self::assertSame('meu-artigo', $input->slug);
        self::assertSame('', $input->titulo);
        self::assertFalse($input->destaque);
        self::assertSame([['texto' => 'Lei', 'url' => 'https://a.b', 'rotulo' => 'a.b']], $input->referencias);
        self::assertSame(['a'], $input->relacionados);
    }
}
