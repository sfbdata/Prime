<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\BlogsDoArtigo;
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
use App\Blog\Service\VersaoPublicadaDoArtigo;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Blog\ArtigosDeTeste;
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
    private ImagemDoArtigoRepository $imagens;
    private EscopoDeAuditoriaDePlataforma $escopo;

    /** @var list<array<string, list<string>>|null> o `artigos` do pedido a cada flush */
    private array $gravacoes = [];

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/handler-blogs-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->publicacoes = $this->createMock(PublicacaoDosBlogsRepository::class);
        $this->publicacoes->method('salvar')->willReturnCallback(function (PublicacaoDosBlogs $p, bool $flush): void {
            self::assertTrue($flush);
            self::assertTrue($this->escopo->estaLigado(), 'grava no escopo de plataforma');
            $this->gravacoes[] = $p->getArtigos();
        });
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->imagens = $this->createStub(ImagemDoArtigoRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
    }

    protected function tearDown(): void
    {
        $this->apagar($this->raiz);
    }

    private function sut(): MontarPacoteDosBlogsHandler
    {
        $pasta = new PastaDosPedidos($this->raiz);
        $montador = new MontadorDoPacoteDosBlogs($this->artigos, $this->imagens, new ArmazenamentoEmMemoria(), $pasta);

        return new MontarPacoteDosBlogsHandler($this->publicacoes, $montador, $pasta, $this->escopo, new MockClock('2026-11-03 14:06', 'America/Sao_Paulo'), new LoggerEmMemoria());
    }

    private function naFila(): PublicacaoDosBlogs
    {
        $p = new PublicacaoDosBlogs(new \DateTimeImmutable('2026-11-03 14:05'), null, MotivoDaPublicacao::Publicar);
        (new \ReflectionProperty(PublicacaoDosBlogs::class, 'id'))->setValue($p, 8);
        $this->publicacoes->method('porId')->with(8)->willReturn($p);

        return $p;
    }

    #[TestDox('Marca o pedido como em montagem (flush) ANTES de ler os artigos, monta e guarda os slugs')]
    public function testMonta(): void
    {
        $p = $this->naFila();
        $artigo = ArtigosDeTeste::rascunhoCompleto('no-ar', BlogsDoArtigo::Normal);
        $artigo->publicar(new \DateTimeImmutable('2026-11-02 10:00'));
        (new VersaoPublicadaDoArtigo($this->imagens, new ExportadorDeArtigo()))->congelar($artigo);
        $this->artigos->method('publicados')->willReturnCallback(function () use ($artigo): array {
            self::assertSame([[]], $this->gravacoes, 'a marca de montagem já foi gravada quando os artigos são lidos');

            return [$artigo];
        });

        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertSame([[], ['blog' => ['no-ar'], 'blog-gratis' => []]], $this->gravacoes);
        self::assertSame(SituacaoDaPublicacao::NaFila, $p->getSituacao());
        self::assertFileExists($this->raiz . '/8/pedido.json');
    }

    #[TestDox('Falha na montagem: o pedido vira falhou com o motivo, e a exceção não volta ao Messenger')]
    public function testFalhaMarcaFalhou(): void
    {
        $p = $this->naFila();
        $this->artigos->method('publicados')->willThrowException(new \RuntimeException('banco fora'));

        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertSame(SituacaoDaPublicacao::Falhou, $p->getSituacao());
        self::assertSame('Não foi possível montar o pacote dos blogs: banco fora', $p->getLog());
        self::assertEquals(new \DateTimeImmutable('2026-11-03 14:06'), $p->getTerminadaEm());
    }

    #[TestDox('Nenhum artigo publicado: falhou com "Nenhum artigo publicado: o pacote tiraria todos os artigos do ar."')]
    public function testSemPublicados(): void
    {
        $p = $this->naFila();
        $this->artigos->method('publicados')->willReturn([]);

        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertSame(SituacaoDaPublicacao::Falhou, $p->getSituacao());
        self::assertSame('Nenhum artigo publicado: o pacote tiraria todos os artigos do ar.', $p->getLog());
        self::assertDirectoryDoesNotExist($this->raiz . '/8');
    }

    #[TestDox('Pasta {id} já existe (o flush anterior caiu depois do rename): dá o pedido como montado com os slugs do pedido.json')]
    public function testIdempotenteComAPastaJaMontada(): void
    {
        $p = $this->naFila();
        $p->marcarEmMontagem();
        mkdir($this->raiz . '/8', 0o775, true);
        file_put_contents($this->raiz . '/8/pedido.json', (string) json_encode(['versao' => 1, 'id' => 8, 'artigos' => ['blog' => ['a'], 'blog-gratis' => ['b']]]));
        $this->artigos->expects(self::never())->method('publicados');

        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertSame(['blog' => ['a'], 'blog-gratis' => ['b']], $p->getArtigos());
        self::assertSame([['blog' => ['a'], 'blog-gratis' => ['b']]], $this->gravacoes);
    }

    #[TestDox('Mensagem repetida (pacote já montado) ou de pedido que terminou: não faz nada')]
    public function testIgnoraPedidoMontadoOuTerminado(): void
    {
        $p = $this->naFila();
        $p->registrarPacote(['blog' => ['a'], 'blog-gratis' => []]);
        $this->artigos->expects(self::never())->method('publicados');

        ($this->sut())(new MontarPacoteDosBlogs(8));
        $p->falhar('x', new \DateTimeImmutable());
        ($this->sut())(new MontarPacoteDosBlogs(8));

        self::assertSame([], $this->gravacoes);
        self::assertDirectoryDoesNotExist($this->raiz . '/8');
    }

    private function apagar(string $caminho): void
    {
        if (is_file($caminho)) {
            unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }
        foreach (scandir($caminho) ?: [] as $nome) {
            if ($nome !== '.' && $nome !== '..') {
                $this->apagar($caminho . '/' . $nome);
            }
        }
        rmdir($caminho);
    }
}
