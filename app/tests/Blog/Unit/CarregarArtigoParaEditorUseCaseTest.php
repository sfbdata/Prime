<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\DTO\ArtigoParaEditorOutput;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\CatalogoDoBlog;
use App\Blog\UseCase\CarregarArtigoParaEditorUseCase;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CarregarArtigoParaEditorUseCase::class)]
#[CoversClass(ArtigoParaEditorOutput::class)]
final class CarregarArtigoParaEditorUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
    }

    private function sut(): CarregarArtigoParaEditorUseCase
    {
        return new CarregarArtigoParaEditorUseCase($this->artigos);
    }

    #[TestDox('Sem id: o rascunho em branco, blog normal e o primeiro autor do catálogo, sem consultar o banco')]
    public function testVazio(): void
    {
        $this->artigos->expects(self::never())->method('porId');

        $saida = $this->sut()->executar(null);

        self::assertNull($saida->id);
        self::assertSame('', $saida->slug);
        self::assertSame('normal', $saida->blogs);
        self::assertSame(array_key_first(CatalogoDoBlog::AUTORES), $saida->autor);
        self::assertSame('rascunho', $saida->situacao);
        self::assertSame('Rascunho', $saida->situacaoRotulo);
        self::assertSame([], $saida->marcadores);
        self::assertSame([], $saida->relacionados);
        self::assertFalse($saida->jaFoiAoAr);
    }

    #[TestDox('Com id: o artigo, com o título de cada relacionado (o que não existe mais fica com o slug) e os marcadores')]
    public function testComRelacionados(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        (new \ReflectionProperty($artigo::class, 'id'))->setValue($artigo, 4);
        $artigo->definirRelacionados(['distribuicao-de-tarefas', 'apagado']);
        $artigo->definirMarcadores(['Prazos']);
        $this->artigos->method('porId')->willReturnMap([[4, $artigo]]);
        $this->artigos->expects(self::once())->method('titulosPorSlug')
            ->with(['distribuicao-de-tarefas', 'apagado'])
            ->willReturn(['distribuicao-de-tarefas' => 'Distribuição de tarefas']);

        $saida = $this->sut()->executar(4);

        self::assertSame(4, $saida->id);
        self::assertSame('controle-de-prazos-processuais', $saida->slug);
        self::assertSame([
            ['slug' => 'distribuicao-de-tarefas', 'titulo' => 'Distribuição de tarefas'],
            ['slug' => 'apagado', 'titulo' => 'apagado'],
        ], $saida->relacionados);
        self::assertSame(['Prazos'], $saida->marcadores);
        self::assertSame('publicado', $saida->situacao);
        self::assertTrue($saida->jaFoiAoAr);
    }

    #[TestDox('Id que não existe: não encontrado')]
    public function testIdInexistente(): void
    {
        $this->artigos->method('porId')->willReturn(null);

        $this->expectException(ArtigoDoBlogNaoEncontrado::class);
        $this->sut()->executar(99);
    }
}
