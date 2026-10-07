<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\UseCase\CriarNotaTecnicaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarNotaTecnicaUseCase::class)]
#[CoversClass(ResolucaoDeMencoesDoRegistro::class)]
final class CriarNotaTecnicaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;
    use CriaResolucaoDeMencoesDaNota;

    private const NUMERO = '07011345720258070007';

    private NotaTecnicaRepository&MockObject $repository;
    private CriarNotaTecnicaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;
    private Processo $processo;

    protected function setUp(): void
    {
        $this->tenant     = new Tenant();
        $this->montarMencoes();
        $this->repository = $this->createMock(NotaTecnicaRepository::class);
        $this->useCase    = new CriarNotaTecnicaUseCase($this->repository, $this->criarSanitizadorTextoRico(), $this->em, $this->resolucao);
        $this->autor      = $this->pessoa(1, 'Ana Advogada');
        $this->processo   = (new Processo())->setTenant($this->tenant)->setNumeroProcesso(self::NUMERO);
    }

    private function publicacao(Tenant $tenant, string $numero, ?Processo $processo = null): PublicacaoDjen
    {
        $pub = (new PublicacaoDjen())->setTenant($tenant)->setNumeroProcesso($numero)->setTipoComunicacao('Intimação');
        if ($processo !== null) {
            $pub->setProcesso($processo);
        }

        return $pub;
    }

    #[TestDox('cria a nota no processo, limpa, e devolve o DTO')]
    public function testCriaNotaNoProcesso(): void
    {
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static fn (NotaTecnica $n): bool => $n->getConteudo() === '<p><strong>Prazo:</strong> 15 dias</p>'
                && $n->getPublicacaoDjen() === null), true);

        $saida = $this->useCase->executar($this->processo, $this->autor, $this->tenant, '  <p><strong>Prazo:</strong> 15 dias</p>  ');

        self::assertSame('<p><strong>Prazo:</strong> 15 dias</p>', $saida->conteudo);
        self::assertSame('Ana Advogada', $saida->autorNome);
        self::assertNull($saida->publicacaoId);
        self::assertNull($saida->movimentacaoRotulo);
        self::assertNull($saida->editadaEm);
    }

    #[TestDox('script e atributo de evento não chegam ao banco')]
    public function testSanitizaAntesDePersistir(): void
    {
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static function (NotaTecnica $n): bool {
                $c = $n->getConteudo();

                return !str_contains(strtolower($c), '<script') && !str_contains(strtolower($c), 'onclick') && str_contains($c, 'Combinado');
            }), true);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p onclick="roubar()">Combinado</p><script>alert(1)</script>');
    }

    #[TestDox('conteúdo vazio é recusado')]
    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '   ');
    }

    #[TestDox('o parágrafo vazio do editor (<p><br></p>) conta como vazio')]
    public function testParagrafoVazioDoEditorLancaInvalidArgument(): void
    {
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p><br></p>');
    }

    #[TestDox('mais de 5000 caracteres visíveis é recusado; a marcação não conta')]
    public function testLimiteDe5000ContaSoOTextoVisivel(): void
    {
        $this->repository->expects($this->once())->method('salvar');

        // 5000 visíveis + marcação em volta: passa.
        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p><strong>' . str_repeat('a', 5000) . '</strong></p>');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->processo, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    #[TestDox('processo de outro escritório é recusado')]
    public function testProcessoDeOutroTenantLancaForaDoEscopo(): void
    {
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, new Tenant(), '<p>Nota</p>');
    }

    #[TestDox('publicação vinculada pela FK ao processo: a nota nasce pendurada nela')]
    public function testPublicacaoComFkDoProcesso(): void
    {
        $pub = $this->publicacao($this->tenant, self::NUMERO, $this->processo);
        $pub->setDataDisponibilizacao(new \DateTimeImmutable('2026-08-20'));
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static fn (NotaTecnica $n): bool => $n->getPublicacaoDjen() === $pub), true);

        $saida = $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);

        self::assertSame('Intimação · 20/08/2026', $saida->movimentacaoRotulo);
    }

    #[TestDox('publicação SEM FK mas com o mesmo número CNJ também vale (a FK só nasce na sincronização)')]
    public function testPublicacaoCasadaPeloNumero(): void
    {
        $pub = $this->publicacao($this->tenant, self::NUMERO);
        $this->repository->expects($this->once())->method('salvar')
            ->with(self::callback(static fn (NotaTecnica $n): bool => $n->getPublicacaoDjen() === $pub), true);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);
    }

    #[TestDox('publicação de OUTRO processo é recusada')]
    public function testPublicacaoDeOutroProcessoLancaForaDoEscopo(): void
    {
        $pub = $this->publicacao($this->tenant, '07099999999999999999');
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);
    }

    #[TestDox('publicação de outro escritório é recusada mesmo com o mesmo número')]
    public function testPublicacaoDeOutroTenantLancaForaDoEscopo(): void
    {
        $pub = $this->publicacao(new Tenant(), self::NUMERO);
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', $pub);
    }

    // ── @menções (PMC-019) ───────────────────────────────────────────────────

    /** Pasta 41 do escritório, vinculando o processo; o salvar dá id 700 à nota, como o banco. */
    private function pastaQueVincula(): Pasta
    {
        $pasta = $this->definirId((new Pasta())->setTenant($this->tenant)->setNup('1232'), 41);
        $pasta->vincularProcesso($this->processo);
        $this->repository->method('salvar')->willReturnCallback(function (NotaTecnica $n): void {
            if ($n->getId() === null) {
                $this->definirId($n, 700);
            }
        });

        return $pasta;
    }

    #[TestDox('menção a colega que VÊ a pasta: grava o nome do banco e avisa "Ana mencionou você na Pasta 1232 · Processo", link #nota-tecnica-<id>')]
    public function testMencaoNotificaQuemVeAPasta(): void
    {
        $pasta = $this->pastaQueVincula();
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(41, [2 => true]);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);
        $avisos = $this->capturarAvisos();

        $saida = $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Veja @[Bru](user:2), prazo amanhã</p>', null, $pasta);

        self::assertSame('<p>Veja @[Bruno Lima](user:2), prazo amanhã</p>', $saida->conteudo, 'o rótulo digitado não vale: fica o nome do banco');
        self::assertCount(1, $avisos);
        self::assertSame($bruno, $avisos[0]['usuario']);
        self::assertSame(Notificacao::TIPO_NOTA_TECNICA_MENCAO, $avisos[0]['tipo']);
        self::assertSame('Ana mencionou você na Pasta 1232 · Processo', $avisos[0]['titulo']);
        self::assertSame('Veja @Bruno Lima, prazo amanhã', $avisos[0]['texto']);
        self::assertSame('/pasta/41#nota-tecnica-700', $avisos[0]['notificacao']->getUrl());
        self::assertSame(['1'], $this->espiao->chavesConsumidas, 'a cota gasta é a do AUTOR');
    }

    #[TestDox('nota sobre uma movimentação: o aviso diz "· Push Processual" (rótulo do desenho para a aba)')]
    public function testMencaoEmNotaDaMovimentacaoDizPush(): void
    {
        $pasta = $this->pastaQueVincula();
        $pub   = $this->publicacao($this->tenant, self::NUMERO, $this->processo);
        $this->colegasDoEscritorio([$this->pessoa(2, 'Bruno Lima')]);
        $this->acessoAPasta(41, [2 => true]);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);
        $avisos = $this->capturarAvisos();

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>@[B](user:2)</p>', $pub, $pasta);

        self::assertCount(1, $avisos);
        self::assertSame('Ana mencionou você na Pasta 1232 · Push Processual', $avisos[0]['titulo']);
    }

    #[TestDox('quem não vê a pasta, o próprio autor e id de OUTRO escritório não recebem; o id alheio vira texto comum')]
    public function testMencaoNaoNotificaQuemNaoPode(): void
    {
        $pasta     = $this->pastaQueVincula();
        $semAcesso = $this->pessoa(3, 'Carla Sem Acesso');
        // O id 9 é de outro escritório: o banco não o devolve entre os colegas deste.
        $this->colegasDoEscritorio([$this->autor, $semAcesso]);
        $this->acessoAPasta(41, [3 => false]);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);
        $this->notificacaoService->expects($this->never())->method('criar');

        $saida = $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>@[C](user:3) @[Eu](user:1) @[Diretor de Lá](user:9)</p>', null, $pasta);

        self::assertStringContainsString('@Diretor de Lá', $saida->conteudo);
        self::assertStringNotContainsString('(user:9)', $saida->conteudo);
        self::assertSame([], $this->espiao->chavesConsumidas, 'aviso que não sai não gasta cota');
    }

    #[TestDox('sem pasta de contexto: o token é resolvido, mas ninguém é avisado (o link levaria a lugar nenhum)')]
    public function testSemPastaResolveMasNaoNotifica(): void
    {
        $this->colegasDoEscritorio([$this->pessoa(2, 'Bruno Lima')]);
        $this->repository->expects($this->once())->method('salvar');
        $this->notificacaoService->expects($this->never())->method('criar');
        $this->em->expects($this->never())->method('wrapInTransaction');

        $saida = $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>@[B](user:2)</p>');

        self::assertSame('<p>@[Bruno Lima](user:2)</p>', $saida->conteudo);
    }

    #[TestDox('pasta de OUTRO escritório como contexto é recusada e nada é gravado')]
    public function testPastaDeOutroEscritorioLancaForaDoEscopo(): void
    {
        $alheia = (new Pasta())->setTenant(new Tenant());
        $alheia->vincularProcesso($this->processo);
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', null, $alheia);
    }

    #[TestDox('pasta do escritório que NÃO vincula o processo é recusada')]
    public function testPastaQueNaoVinculaLancaForaDoEscopo(): void
    {
        $outra = (new Pasta())->setTenant($this->tenant);
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        $this->useCase->executar($this->processo, $this->autor, $this->tenant, '<p>Nota</p>', null, $outra);
    }
}
