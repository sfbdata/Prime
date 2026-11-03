<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\UseCase\ExcluirArtigoDoBlogUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirArtigoDoBlogUseCase::class)]
final class ExcluirArtigoDoBlogUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private EscopoDeAuditoriaDePlataforma $escopo;

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
    }

    private function sut(): ExcluirArtigoDoBlogUseCase
    {
        return new ExcluirArtigoDoBlogUseCase($this->artigos, $this->escopo);
    }

    #[TestDox('Rascunho: remove com flush, no escopo de plataforma')]
    public function testExcluiRascunho(): void
    {
        $artigo = new ArtigoDoBlog('rascunho', BlogsDoArtigo::Normal, null, new \DateTimeImmutable());
        $this->artigos->method('porId')->willReturnMap([[3, $artigo]]);
        $this->artigos->expects(self::once())->method('remover')->willReturnCallback(function (ArtigoDoBlog $a, bool $flush) use ($artigo): void {
            self::assertSame($artigo, $a);
            self::assertTrue($flush);
            self::assertTrue($this->escopo->estaLigado());
        });

        $this->sut()->executar(3);
    }

    #[TestDox('Despublicado pode ser excluído')]
    public function testExcluiDespublicado(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $artigo->despublicar();
        $this->artigos->method('porId')->willReturn($artigo);
        $this->artigos->expects(self::once())->method('remover');

        $this->sut()->executar(3);
    }

    #[TestDox('Publicado: recusa com DomainException e não remove')]
    public function testPublicadoRecusa(): void
    {
        $this->artigos->method('porId')->willReturn(ArtigosDeTeste::controleDePrazos());
        $this->artigos->expects(self::never())->method('remover');

        try {
            $this->sut()->executar(3);
            self::fail('publicado não exclui');
        } catch (\DomainException $e) {
            self::assertNotInstanceOf(ArtigoDoBlogNaoEncontrado::class, $e);
            self::assertSame('Despublique o artigo antes de excluir.', $e->getMessage());
        }
    }

    #[TestDox('Id que não existe: não encontrado')]
    public function testIdInexistente(): void
    {
        $this->artigos->method('porId')->willReturn(null);
        $this->artigos->expects(self::never())->method('remover');

        $this->expectException(ArtigoDoBlogNaoEncontrado::class);
        $this->sut()->executar(99);
    }
}
