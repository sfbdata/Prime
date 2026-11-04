<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDaPublicacao;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogNaoPublicavel;
use App\Blog\Message\MontarPacoteDosBlogs;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Blog\Service\PedirPublicacaoDosBlogs;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\Service\ExportadorDeArtigo;
use App\Blog\Service\ValidadorDoArtigo;
use App\Blog\Service\VersaoPublicadaDoArtigo;
use App\Blog\UseCase\DespublicarArtigoDoBlogUseCase;
use App\Blog\UseCase\PublicarArtigoDoBlogUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Blog\Doubles\BusQueGuarda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Publicar agora e Despublicar (spec painel-artigos-blogs, seções 1 e 6.1): o ciclo do artigo e o pedido de geração
 * dos blogs, com o reaproveitamento do pedido na fila.
 */
#[CoversClass(PublicarArtigoDoBlogUseCase::class)]
#[CoversClass(DespublicarArtigoDoBlogUseCase::class)]
#[CoversClass(PedirPublicacaoDosBlogs::class)]
final class PublicarArtigoDoBlogUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private PublicacaoDosBlogsRepository&MockObject $publicacoes;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private BusQueGuarda $bus;
    private MockClock $relogio;

    /** @var list<PublicacaoDosBlogs> gravadas pelo flush, em ordem */
    private array $gravadas = [];

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->artigos->method('slugsExistentes')->willReturn([]);
        $this->publicacoes = $this->createMock(PublicacaoDosBlogsRepository::class);
        $this->publicacoes->method('salvar')->willReturnCallback(function (PublicacaoDosBlogs $p, bool $flush): void {
            self::assertTrue($flush);
            self::assertTrue($this->escopo->estaLigado(), 'grava no escopo de plataforma');
            self::assertSame([], $this->bus->mensagens, 'a mensagem só sai depois do flush');
            if ($p->getId() === null) {
                (new \ReflectionProperty(PublicacaoDosBlogs::class, 'id'))->setValue($p, 40 + \count($this->gravadas));
            }
            $this->gravadas[] = $p;
        });
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->bus = new BusQueGuarda();
        $this->relogio = new MockClock('2026-11-03 14:05:00', 'America/Sao_Paulo');
    }

    private function publicar(): PublicarArtigoDoBlogUseCase
    {
        $versao = new VersaoPublicadaDoArtigo($this->createStub(ImagemDoArtigoRepository::class), new ExportadorDeArtigo());

        return new PublicarArtigoDoBlogUseCase($this->artigos, new ValidadorDoArtigo(), $versao, $this->pedir(), $this->relogio);
    }

    private function despublicar(): DespublicarArtigoDoBlogUseCase
    {
        return new DespublicarArtigoDoBlogUseCase($this->artigos, $this->pedir(), $this->relogio);
    }

    private function pedir(): PedirPublicacaoDosBlogs
    {
        return new PedirPublicacaoDosBlogs($this->publicacoes, $this->escopo, $this->bus);
    }

    private function comArtigo(ArtigoDoBlog $artigo): void
    {
        (new \ReflectionProperty(ArtigoDoBlog::class, 'id'))->setValue($artigo, 7);
        $this->artigos->method('porId')->with(7)->willReturn($artigo);
    }

    #[TestDox('Rascunho completo: vai ao ar com a data de agora, cria um pedido na fila e manda montar o pacote')]
    public function testPublicaRascunho(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto();
        $this->comArtigo($artigo);
        $this->publicacoes->method('naFilaSemPacote')->willReturn(null);

        $saida = $this->publicar()->executar(7, null);

        self::assertSame(SituacaoDoArtigo::Publicado, $artigo->getSituacao());
        self::assertEquals($this->relogio->now(), $artigo->getPublicadoEm());
        self::assertNull($artigo->getAtualizadoEm());
        $versao = $artigo->getVersaoPublicada();
        self::assertNotNull($versao, 'D7: a versão que vai ao ar fica congelada');
        self::assertSame('rascunho-completo', $versao['slug']);
        self::assertSame(['blog'], array_keys($versao['arquivos']));
        self::assertStringContainsString('publicadoEm: 2026-11-03', $versao['arquivos']['blog']);
        self::assertCount(1, $this->gravadas);
        self::assertSame(MotivoDaPublicacao::Publicar, $this->gravadas[0]->getMotivo());
        self::assertSame(SituacaoDaPublicacao::NaFila, $this->gravadas[0]->getSituacao());
        self::assertEquals([new MontarPacoteDosBlogs(40)], $this->bus->mensagens);
        self::assertSame('publicado', $saida->situacao);
        self::assertSame(40, $saida->publicacao->id);
        self::assertSame('na_fila', $saida->publicacao->situacao);
    }

    #[TestDox('Pedido na fila com o pacote ainda por montar: é reaproveitado, sem outra mensagem')]
    public function testReaproveitaPedidoNaFila(): void
    {
        $this->comArtigo(ArtigosDeTeste::rascunhoCompleto());
        $naFila = new PublicacaoDosBlogs(new \DateTimeImmutable('2026-11-03 14:04'), null, MotivoDaPublicacao::Manual);
        (new \ReflectionProperty(PublicacaoDosBlogs::class, 'id'))->setValue($naFila, 12);
        $this->publicacoes->method('naFilaSemPacote')->willReturn($naFila);

        $saida = $this->publicar()->executar(7, null);

        self::assertSame([$naFila], $this->gravadas, 'o flush grava o artigo junto, no mesmo pedido');
        self::assertSame([], $this->bus->mensagens);
        self::assertSame(12, $saida->publicacao->id);
    }

    #[TestDox('Artigo incompleto: recusa com os erros por campo (sem o da data) e não grava nada')]
    public function testRecusaComOsErrosDoZod(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto();
        $artigo->editarTextos('', 'Curto', 'SEO', 'Descrição.', 'Linha.', 'Resumo.');
        $this->comArtigo($artigo);
        $this->publicacoes->expects(self::never())->method('salvar');

        try {
            $this->publicar()->executar(7, null);
            self::fail('Deveria recusar.');
        } catch (ArtigoDoBlogNaoPublicavel $e) {
            self::assertSame(['titulo' => ['Informe o título.']], $e->erros);
        }
        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
        self::assertSame([], $this->bus->mensagens);
    }

    #[TestDox('Slug que é de outro artigo (ou da versão no ar de outro artigo): recusa sem publicar')]
    public function testRecusaSlugDeOutroArtigo(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto('slug-tomado');
        $this->comArtigo($artigo);
        $this->artigos->method('existeSlug')->with('slug-tomado', 7)->willReturn(true);
        $this->publicacoes->expects(self::never())->method('salvar');

        try {
            $this->publicar()->executar(7, null);
            self::fail('Deveria recusar.');
        } catch (ArtigoDoBlogNaoPublicavel $e) {
            self::assertSame(['slug' => ['O slug "slug-tomado" é de outro artigo (ou da versão que está no ar de outro artigo).']], $e->erros);
        }
        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
        self::assertNull($artigo->getVersaoPublicada());
    }

    #[TestDox('Republicar com texto novo: mantém publicadoEm e muda atualizadoEm; sem alterações, recusa')]
    public function testRepublicar(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $publicadoEm = $artigo->getPublicadoEm();
        $this->comArtigo($artigo);
        $this->publicacoes->method('naFilaSemPacote')->willReturn(null);

        try {
            $this->publicar()->executar(7, null);
            self::fail('Sem alterações não republica.');
        } catch (\DomainException $e) {
            self::assertSame('O artigo já está publicado e não tem alterações a publicar.', $e->getMessage());
        }

        $artigo->editarTextos('Título novo', $artigo->getTituloCurto(), $artigo->getTituloSeo(), $artigo->getDescricao(), $artigo->getLinhaFina(), $artigo->getResumo());
        $artigo->registrarAlteracao(null, new \DateTimeImmutable('2026-11-03 13:00'));
        $saida = $this->publicar()->executar(7, null);

        self::assertEquals($publicadoEm, $artigo->getPublicadoEm());
        self::assertEquals($this->relogio->now(), $artigo->getAtualizadoEm());
        self::assertStringContainsString('Título novo', $artigo->getVersaoPublicada()['arquivos']['blog'] ?? '');
        self::assertFalse($saida->alteracoesPendentes);
    }

    #[TestDox('Despublicar: o artigo sai do ar e um pedido com motivo despublicar vai para a fila')]
    public function testDespublicar(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $this->comArtigo($artigo);
        $this->publicacoes->method('naFilaSemPacote')->willReturn(null);

        $saida = $this->despublicar()->executar(7, null);

        self::assertSame(SituacaoDoArtigo::Despublicado, $artigo->getSituacao());
        self::assertNull($artigo->getVersaoPublicada(), 'Despublicar zera a versão publicada');
        self::assertSame('despublicado', $saida->situacao);
        self::assertSame(MotivoDaPublicacao::Despublicar, $this->gravadas[0]->getMotivo());
        self::assertCount(1, $this->bus->mensagens);
    }

    #[TestDox('Despublicar um rascunho: recusa (409 no controller) e não pede geração')]
    public function testDespublicarRascunho(): void
    {
        $this->comArtigo(ArtigosDeTeste::rascunhoCompleto());
        $this->publicacoes->expects(self::never())->method('salvar');

        $this->expectExceptionMessage('Só um artigo publicado pode ser despublicado.');
        $this->despublicar()->executar(7, null);
    }

    #[TestDox('Despublicado volta ao ar com Publicar, sem mudar as datas')]
    public function testPublicarDespublicado(): void
    {
        $artigo = ArtigosDeTeste::controleDePrazos();
        $atualizadoEm = $artigo->getAtualizadoEm();
        $artigo->despublicar();
        $this->comArtigo($artigo);
        $this->publicacoes->method('naFilaSemPacote')->willReturn(null);

        $this->publicar()->executar(7, null);

        self::assertSame(SituacaoDoArtigo::Publicado, $artigo->getSituacao());
        self::assertEquals($atualizadoEm, $artigo->getAtualizadoEm());
    }

    #[TestDox('Publicado antes da P4, sem versão guardada: Publicar de novo é aceito e congela a versão')]
    public function testPublicadoSemVersaoPodeSerPublicadoDeNovo(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto();
        $artigo->publicar(new \DateTimeImmutable('2026-10-01 10:00'));
        $this->comArtigo($artigo);
        $this->publicacoes->method('naFilaSemPacote')->willReturn(null);

        $this->publicar()->executar(7, null);

        self::assertNotNull($artigo->getVersaoPublicada());
        self::assertNull($artigo->getAtualizadoEm(), 'sem texto novo, atualizadoEm não muda');
        self::assertCount(1, $this->bus->mensagens);
    }

    #[TestDox('Id inexistente: ArtigoDoBlogNaoEncontrado (404)')]
    public function testInexistente(): void
    {
        $this->artigos->method('porId')->willReturn(null);

        $this->expectException(ArtigoDoBlogNaoEncontrado::class);
        $this->publicar()->executar(99, null);
    }
}
