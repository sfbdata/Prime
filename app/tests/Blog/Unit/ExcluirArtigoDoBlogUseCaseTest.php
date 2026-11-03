<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\ImagemDoArtigo;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\UseCase\ExcluirArtigoDoBlogUseCase;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirArtigoDoBlogUseCase::class)]
final class ExcluirArtigoDoBlogUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private ImagemDoArtigoRepository&MockObject $imagens;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->imagens = $this->createMock(ImagemDoArtigoRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger = new LoggerEmMemoria();
    }

    private function sut(): ExcluirArtigoDoBlogUseCase
    {
        return new ExcluirArtigoDoBlogUseCase($this->artigos, $this->imagens, $this->escopo, new RemocaoAposTransacao($this->armazenamento, $this->logger));
    }

    private function imagem(ArtigoDoBlog $artigo, int $id): ImagemDoArtigo
    {
        $imagem = new ImagemDoArtigo($artigo, 'arquivo-' . $id . '.webp', 'foto.webp', 'image/webp', 10, 10, 99, new \DateTimeImmutable());
        (new \ReflectionProperty(ImagemDoArtigo::class, 'id'))->setValue($imagem, $id);
        $this->armazenamento->semear(ChavesDoBlog::imagem($imagem));

        return $imagem;
    }

    #[TestDox('As imagens do artigo saem: linhas no mesmo flush, arquivos só DEPOIS do COMMIT')]
    public function testApagaAsImagensDepoisDoCommit(): void
    {
        $artigo = new ArtigoDoBlog('rascunho', BlogsDoArtigo::Normal, null, new \DateTimeImmutable());
        $imagens = [$this->imagem($artigo, 1), $this->imagem($artigo, 2)];
        $this->artigos->method('porId')->willReturn($artigo);
        $this->imagens->method('doArtigo')->with($artigo)->willReturn($imagens);
        $ordem = [];
        $this->imagens->expects(self::exactly(2))->method('remover')->willReturnCallback(function () use (&$ordem): void {
            $ordem[] = 'linha';
        });
        $this->artigos->method('remover')->willReturnCallback(function () use (&$ordem): void {
            self::assertTrue($this->escopo->estaLigado(), 'exclui no escopo de plataforma');
            self::assertSame([], $this->armazenamento->excluidas, 'nenhum arquivo sai antes do COMMIT');
            $ordem[] = 'flush';
        });
        $this->armazenamento->aoExcluir = function () use (&$ordem): void {
            $ordem[] = 'arquivo';
        };

        $this->sut()->executar(3);

        self::assertSame(['linha', 'linha', 'flush', 'arquivo', 'arquivo'], $ordem);
        self::assertFalse($this->armazenamento->existe(ChavesDoBlog::imagem($imagens[0])));
        self::assertFalse($this->armazenamento->existe(ChavesDoBlog::imagem($imagens[1])));
    }

    #[TestDox('Flush que falha: a exceção sobe e nenhum arquivo é apagado')]
    public function testFlushQueFalhaNaoApagaArquivo(): void
    {
        $artigo = new ArtigoDoBlog('rascunho', BlogsDoArtigo::Normal, null, new \DateTimeImmutable());
        $imagem = $this->imagem($artigo, 1);
        $this->artigos->method('porId')->willReturn($artigo);
        $this->imagens->method('doArtigo')->willReturn([$imagem]);
        $this->artigos->method('remover')->willThrowException(new \RuntimeException('banco fora'));

        try {
            $this->sut()->executar(3);
            self::fail('deveria lançar');
        } catch (\RuntimeException $e) {
            self::assertSame('banco fora', $e->getMessage());
        }
        self::assertTrue($this->armazenamento->existe(ChavesDoBlog::imagem($imagem)));
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
        $this->imagens->expects(self::never())->method('remover');

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
