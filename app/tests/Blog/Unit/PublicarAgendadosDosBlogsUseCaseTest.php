<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Repository\ImagemDoArtigoRepository;
use App\Blog\Repository\PublicacaoDosBlogsRepository;
use App\Blog\Service\ExportadorDeArtigo;
use App\Blog\Service\PedirPublicacaoDosBlogs;
use App\Blog\Service\ValidadorDoArtigo;
use App\Blog\Service\VersaoPublicadaDoArtigo;
use App\Blog\UseCase\PublicarAgendadosDosBlogsUseCase;
use App\Blog\UseCase\PublicarArtigoDoBlogUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Blog\ArtigosDeTeste;
use App\Tests\Blog\Doubles\BusQueGuarda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/** O agendador (P5): publica só os agendados vencidos, um por vez, e a falha de um não trava os outros. */
#[CoversClass(PublicarAgendadosDosBlogsUseCase::class)]
final class PublicarAgendadosDosBlogsUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private PublicacaoDosBlogsRepository&MockObject $publicacoes;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private BusQueGuarda $bus;
    private MockClock $relogio;

    /** @var list<PublicacaoDosBlogs> */
    private array $gravadas = [];

    /** @var array<int, ArtigoDoBlog> */
    private array $porId = [];

    protected function setUp(): void
    {
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->bus = new BusQueGuarda();
        $this->relogio = new MockClock('2026-11-26 14:05:00', 'America/Sao_Paulo');
        $this->publicacoes = $this->createMock(PublicacaoDosBlogsRepository::class);
        $this->publicacoes->method('salvar')->willReturnCallback(function (PublicacaoDosBlogs $p): void {
            if ($p->getId() === null) {
                (new \ReflectionProperty(PublicacaoDosBlogs::class, 'id'))->setValue($p, 40 + \count($this->gravadas));
            }
            $this->gravadas[] = $p;
        });
        $this->publicacoes->method('naFilaSemPacote')->willReturnCallback(fn (): ?PublicacaoDosBlogs => $this->gravadas[0] ?? null);
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->artigos->method('slugsExistentes')->willReturn([]);
        $this->artigos->method('porId')->willReturnCallback(fn (int $id): ?ArtigoDoBlog => $this->porId[$id] ?? null);
        $this->artigos->method('salvar')->willReturnCallback(function (ArtigoDoBlog $a, bool $flush): void {
            self::assertTrue($flush);
            self::assertTrue($this->escopo->estaLigado());
        });
    }

    private function agendador(): PublicarAgendadosDosBlogsUseCase
    {
        $versao = new VersaoPublicadaDoArtigo($this->createStub(ImagemDoArtigoRepository::class), new ExportadorDeArtigo());
        $publicar = new PublicarArtigoDoBlogUseCase(
            $this->artigos,
            new ValidadorDoArtigo(),
            $versao,
            new PedirPublicacaoDosBlogs($this->publicacoes, $this->escopo, $this->bus),
            $this->relogio,
            new BusQueGuarda(),
        );

        return new PublicarAgendadosDosBlogsUseCase($this->artigos, $publicar, $this->escopo, $this->relogio, new NullLogger());
    }

    private function agendado(int $id, string $slug, string $quando): ArtigoDoBlog
    {
        $fuso = new \DateTimeZone('America/Sao_Paulo');
        $artigo = ArtigosDeTeste::rascunhoCompleto($slug);
        $artigo->agendar(new \DateTimeImmutable($quando, $fuso), new \DateTimeImmutable('2026-11-20 10:00', $fuso));
        (new \ReflectionProperty(ArtigoDoBlog::class, 'id'))->setValue($artigo, $id);
        $this->porId[$id] = $artigo;

        return $artigo;
    }

    #[TestDox('Publica só os vencidos que o repositório devolve, com motivo agendado e a data marcada como publicadoEm')]
    public function testPublicaOsVencidos(): void
    {
        $vencido = $this->agendado(1, 'vencido-um', '2026-11-26 14:00');
        $outroVencido = $this->agendado(2, 'vencido-dois', '2026-11-26 09:00');
        $futuro = $this->agendado(3, 'futuro', '2026-11-27 09:00');
        $this->artigos->method('idsAgendadosVencidos')->with($this->relogio->now())->willReturn([2, 1]);

        $resumo = $this->agendador()->executar();

        self::assertSame(['publicados' => 2, 'falhas' => []], $resumo);
        self::assertSame(SituacaoDoArtigo::Publicado, $vencido->getSituacao());
        self::assertSame(SituacaoDoArtigo::Publicado, $outroVencido->getSituacao());
        self::assertSame('2026-11-26 09:00', $outroVencido->getPublicadoEm()?->format('Y-m-d H:i'));
        self::assertSame(SituacaoDoArtigo::Agendado, $futuro->getSituacao(), 'o futuro não é tocado');
        self::assertNotNull($vencido->getVersaoPublicada(), 'D7: a versão no ar é congelada');
        self::assertSame(MotivoDaPublicacao::Agendado, $this->gravadas[0]->getMotivo());
        self::assertCount(1, $this->bus->mensagens, 'dois artigos, um pedido ao builder (o da fila é reaproveitado)');
    }

    #[TestDox('Nada vencido: não publica nem pede nada')]
    public function testNadaVencido(): void
    {
        $this->agendado(3, 'futuro', '2026-11-27 09:00');
        $this->artigos->method('idsAgendadosVencidos')->willReturn([]);

        self::assertSame(['publicados' => 0, 'falhas' => []], $this->agendador()->executar());
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('Artigo que falha volta a rascunho, é registrado, e o seguinte é publicado do mesmo jeito')]
    public function testFalhaNaoTravaOsOutros(): void
    {
        $ruim = $this->agendado(1, 'ruim', '2026-11-26 10:00');
        $ruim->editarTextos('', 'Curto', 'SEO', 'Descrição.', 'Linha.', 'Resumo.');
        $bom = $this->agendado(2, 'bom', '2026-11-26 11:00');
        $this->artigos->method('idsAgendadosVencidos')->willReturn([1, 2]);

        $resumo = $this->agendador()->executar();

        self::assertSame(1, $resumo['publicados']);
        self::assertCount(1, $resumo['falhas']);
        self::assertStringContainsString('Artigo 1 voltou a rascunho', $resumo['falhas'][0]);
        self::assertStringContainsString('Informe o título.', $resumo['falhas'][0]);
        self::assertSame(SituacaoDoArtigo::Rascunho, $ruim->getSituacao());
        self::assertSame(SituacaoDoArtigo::Publicado, $bom->getSituacao());
    }
}
