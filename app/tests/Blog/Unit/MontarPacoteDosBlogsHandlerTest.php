<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDaPublicacao;
use App\Blog\Message\MontarPacoteDosBlogs;
use App\Blog\MessageHandler\MontarPacoteDosBlogsHandler;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Blog\Service\ExportadorDeArtigo;
use App\Blog\Service\MontadorDoPacoteDosBlogs;
use App\Blog\Service\PastaDosPedidos;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MontarPacoteDosBlogsHandler::class)]
final class MontarPacoteDosBlogsHandlerTest extends TestCase
{
    private string $raiz;
    private PublicacaoDosBlogsRepository&MockObject $publicacoes;
    private ArtigoDoBlogRepository&MockObject $artigos;
    private EscopoDeAuditoriaDePlataforma $escopo;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/handler-blogs-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->publicacoes = $this->createMock(PublicacaoDosBlogsRepository::class);
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
    }

    protected function tearDown(): void
    {
        foreach ([$this->raiz . '/8/pedido.json', $this->raiz . '/8'] as $caminho) {
            if (is_file($caminho)) {
                unlink($caminho);
            } elseif (is_dir($caminho)) {
                rmdir($caminho);
            }
        }
        if (is_dir($this->raiz)) {
            rmdir($this->raiz);
        }
    }

    private function sut(): MontarPacoteDosBlogsHandler
    {
        $montador = new MontadorDoPacoteDosBlogs($this->artigos, $this->createStub(ImagemDoArtigoRepository::class), new ExportadorDeArtigo(), new ArmazenamentoEmMemoria(), new PastaDosPedidos($this->raiz));

        return new MontarPacoteDosBlogsHandler($this->publicacoes, $montador, $this->escopo, new MockClock('2026-11-03 14:06', 'America/Sao_Paulo'), new LoggerEmMemoria());
    }

    private function naFila(): PublicacaoDosBlogs
    {
        $p = new PublicacaoDosBlogs(new \DateTimeImmutable('2026-11-03 14:05'), null, MotivoDaPublicacao::Publicar);
        (new \ReflectionProperty(PublicacaoDosBlogs::class, 'id'))->setValue($p, 8);
        $this->publicacoes->method('porId')->with(8)->willReturn($p);

        return $p;
    }

    #[TestDox('Monta o pacote e guarda os slugs, no escopo de plataforma')]
    public function testMonta(): void
    {
        $p = $this->naFila();
        $this->artigos->method('publicados')->willReturn([]);
        $this->publicacoes->expects(self::once())->method('salvar')->willReturnCallback(function () {
            self::assertTrue($this->escopo->estaLigado());
        });

        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertSame(['blog' => [], 'blog-gratis' => []], $p->getArtigos());
        self::assertSame(SituacaoDaPublicacao::NaFila, $p->getSituacao());
        self::assertFileExists($this->raiz . '/8/pedido.json');
    }

    #[TestDox('Falha na montagem: o pedido vira falhou com o motivo, e a exceção não volta ao Messenger')]
    public function testFalhaMarcaFalhou(): void
    {
        $p = $this->naFila();
        $this->artigos->method('publicados')->willThrowException(new \RuntimeException('banco fora'));
        $this->publicacoes->expects(self::once())->method('salvar');

        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertSame(SituacaoDaPublicacao::Falhou, $p->getSituacao());
        self::assertSame('Não foi possível montar o pacote dos blogs: banco fora', $p->getLog());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 14:06'), $p->getTerminadaEm());
    }

    #[TestDox('Mensagem repetida (pacote já montado) ou de pedido que terminou: não faz nada')]
    public function testIgnoraPedidoMontadoOuTerminado(): void
    {
        $p = $this->naFila();
        $p->registrarPacote(['blog' => [], 'blog-gratis' => []]);
        $this->artigos->expects(self::never())->method('publicados');
        $this->publicacoes->expects(self::never())->method('salvar');

        ($this->sut())(new MontarPacoteDosBlogs(8));
        $p->falhar('x', new \DateTimeImmutable());
        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertFalse(is_dir($this->raiz . '/8'));
    }
}
