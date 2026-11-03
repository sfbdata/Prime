<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\ValidadorDoArtigo;
use App\Blog\UseCase\VerificarArtigoDoBlogUseCase;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerificarArtigoDoBlogUseCase::class)]
final class VerificarArtigoDoBlogUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->artigos->method('slugsExistentes')->willReturn(['controle-de-prazos-processuais', 'distribuicao-de-tarefas-na-equipe']);
    }

    private function sut(): VerificarArtigoDoBlogUseCase
    {
        return new VerificarArtigoDoBlogUseCase($this->artigos, new ValidadorDoArtigo());
    }

    #[TestDox('Rascunho vazio: os erros do zod, sem o da data de publicação (que ainda não tem campo na tela)')]
    public function testSemPublicarEm(): void
    {
        $artigo = new ArtigoDoBlog('novo', BlogsDoArtigo::Normal, null, new \DateTimeImmutable());
        $artigo->definirMarcadores(['!!!']);
        $this->artigos->method('porId')->willReturnMap([[5, $artigo]]);

        $erros = $this->sut()->executar(5);

        self::assertArrayNotHasKey('publicarEm', $erros);
        self::assertSame(['Informe o título.'], $erros['titulo']);
        self::assertSame(['O marcador "!!!" precisa ter letras ou números.'], $erros['marcadores']);
    }

    #[TestDox('Artigo completo: nenhum erro, e nada muda no artigo')]
    public function testCompleto(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $assinatura = $artigo->assinaturaDoConteudo();
        $this->artigos->method('porId')->willReturn($artigo);
        $this->artigos->expects(self::never())->method('salvar');

        self::assertSame([], $this->sut()->executar(5));
        self::assertSame($assinatura, $artigo->assinaturaDoConteudo());
    }

    #[TestDox('Id que não existe: não encontrado')]
    public function testIdInexistente(): void
    {
        $this->artigos->method('porId')->willReturn(null);

        $this->expectException(ArtigoDoBlogNaoEncontrado::class);
        $this->sut()->executar(99);
    }
}
