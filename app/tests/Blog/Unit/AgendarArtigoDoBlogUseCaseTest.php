<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogNaoPublicavel;
use App\Blog\Exception\ArtigoDoBlogRecusado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\ValidadorDoArtigo;
use App\Blog\UseCase\AgendarArtigoDoBlogUseCase;
use App\Blog\UseCase\CancelarAgendamentoDoArtigoDoBlogUseCase;
use App\Blog\UseCase\VerificarArtigoDoBlogUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Agendar e Cancelar agendamento (spec painel-artigos-blogs, P5): mesmas regras de Publicar, só data futura. */
#[CoversClass(AgendarArtigoDoBlogUseCase::class)]
#[CoversClass(CancelarAgendamentoDoArtigoDoBlogUseCase::class)]
final class AgendarArtigoDoBlogUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private MockClock $relogio;
    private int $gravacoes = 0;

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->artigos->method('slugsExistentes')->willReturn([]);
        $this->artigos->method('salvar')->willReturnCallback(function (ArtigoDoBlog $a, bool $flush): void {
            self::assertTrue($flush);
            self::assertTrue($this->escopo->estaLigado(), 'grava no escopo de plataforma');
            ++$this->gravacoes;
        });
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->relogio = new MockClock('2026-11-26 14:00:00', 'America/Sao_Paulo');
    }

    private function agendar(): AgendarArtigoDoBlogUseCase
    {
        return new AgendarArtigoDoBlogUseCase($this->artigos, new VerificarArtigoDoBlogUseCase($this->artigos, new ValidadorDoArtigo()), $this->escopo, $this->relogio);
    }

    private function cancelar(): CancelarAgendamentoDoArtigoDoBlogUseCase
    {
        return new CancelarAgendamentoDoArtigoDoBlogUseCase($this->artigos, $this->escopo);
    }

    private function comArtigo(ArtigoDoBlog $artigo): void
    {
        (new \ReflectionProperty(ArtigoDoBlog::class, 'id'))->setValue($artigo, 7);
        $this->artigos->method('porId')->with(7)->willReturn($artigo);
    }

    private function em(string $data): \DateTimeImmutable
    {
        return new \DateTimeImmutable($data, new \DateTimeZone('America/Sao_Paulo'));
    }

    #[TestDox('Rascunho completo com data futura: fica agendado e grava')]
    public function testAgendaRascunho(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto();
        $this->comArtigo($artigo);

        $saida = $this->agendar()->executar(7, $this->em('2026-11-27 09:30'));

        self::assertSame(SituacaoDoArtigo::Agendado, $artigo->getSituacao());
        self::assertSame('2026-11-27T09:30', $saida->publicarEm);
        self::assertSame('27/11/2026 09:30', $saida->publicarEmRotulo);
        self::assertSame('Agendado', $saida->situacaoRotulo);
        self::assertSame(1, $this->gravacoes);
    }

    #[TestDox('Data no passado (ou agora): recusa no campo publicarEm e não grava')]
    public function testRecusaPassado(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto();
        $this->comArtigo($artigo);

        foreach (['2026-11-26 13:59', '2026-11-26 14:00'] as $data) {
            try {
                $this->agendar()->executar(7, $this->em($data));
                self::fail('Deveria recusar.');
            } catch (ArtigoDoBlogRecusado $e) {
                self::assertSame('publicarEm', $e->campo);
            }
        }
        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
        self::assertSame(0, $this->gravacoes);
    }

    #[TestDox('Artigo incompleto: recusa com os erros por campo, como Publicar')]
    public function testRecusaIncompleto(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto();
        $artigo->editarTextos('', 'Curto', 'SEO', 'Descrição.', 'Linha.', 'Resumo.');
        $this->comArtigo($artigo);

        try {
            $this->agendar()->executar(7, $this->em('2026-12-01 09:00'));
            self::fail('Deveria recusar.');
        } catch (ArtigoDoBlogNaoPublicavel $e) {
            self::assertSame(['titulo' => ['Informe o título.']], $e->erros);
        }
        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
        self::assertSame(0, $this->gravacoes);
    }

    #[TestDox('Slug que é de outro artigo: recusa no campo slug')]
    public function testRecusaSlugDeOutro(): void
    {
        $this->comArtigo(ArtigosDeTeste::rascunhoCompleto('slug-tomado'));
        $this->artigos->method('existeSlug')->with('slug-tomado', 7)->willReturn(true);

        try {
            $this->agendar()->executar(7, $this->em('2026-12-01 09:00'));
            self::fail('Deveria recusar.');
        } catch (ArtigoDoBlogNaoPublicavel $e) {
            self::assertArrayHasKey('slug', $e->erros);
        }
    }

    #[TestDox('Id inexistente: ArtigoDoBlogNaoEncontrado')]
    public function testNaoEncontrado(): void
    {
        $this->artigos->method('porId')->willReturn(null);

        $this->expectException(ArtigoDoBlogNaoEncontrado::class);
        $this->agendar()->executar(9, $this->em('2026-12-01 09:00'));
    }

    #[TestDox('Artigo publicado não pode ser agendado (DomainException da entidade)')]
    public function testPublicadoNaoAgenda(): void
    {
        $this->comArtigo(ArtigosDeTeste::controleDePrazos());

        $this->expectException(\DomainException::class);
        $this->agendar()->executar(7, $this->em('2026-12-01 09:00'));
    }

    #[TestDox('Cancelar agendamento: volta a rascunho; rascunho não pode ser cancelado')]
    public function testCancelar(): void
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto();
        $artigo->agendar(new \DateTimeImmutable('2026-11-27 09:30'), new \DateTimeImmutable('2026-11-26 14:00'));
        $this->comArtigo($artigo);

        $saida = $this->cancelar()->executar(7);

        self::assertSame(SituacaoDoArtigo::Rascunho, $artigo->getSituacao());
        self::assertSame('rascunho', $saida->situacao);
        self::assertNull($saida->publicarEm);
        self::assertNull($artigo->getPublicarEm());
        self::assertSame(1, $this->gravacoes);

        $this->expectException(\DomainException::class);
        $this->cancelar()->executar(7);
    }
}
