<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Entity\Tenant\Tenant;
use App\Tests\Processo\Unit\CriaResolucaoDeMencoesDaNota;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EnviarObservacaoFinanceiraUseCase;
use App\Pasta\Service\CotaDeNotificacoesDoRegistro;
use App\Pasta\Service\MencoesDoRegistro;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnviarObservacaoFinanceiraUseCase::class)]
final class EnviarObservacaoFinanceiraUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;
    use CriaResolucaoDeMencoesDaNota;

    private EnviarObservacaoFinanceiraUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;
    /** O último registro passado ao `persist` — o dublê do flush dá id a ele, como o banco. */
    private ?PastaObservacaoFinanceira $persistido = null;
    private int $proximoId = 950;

    protected function setUp(): void
    {
        $this->tenant = $this->definirId(new Tenant(), 1);
        $this->montarMencoes();

        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof PastaObservacaoFinanceira) {
                $this->persistido = $o;
            }
        });
        $this->em->method('flush')->willReturnCallback(function (): void {
            if ($this->persistido !== null && $this->persistido->getId() === null) {
                $this->definirId($this->persistido, $this->proximoId++);
            }
        });

        $this->useCase = new EnviarObservacaoFinanceiraUseCase($this->em, $this->criarSanitizadorTextoRico(), $this->resolucao);

        $this->autor = $this->pessoa(1, 'Ana Souza');
        $this->pasta = $this->definirId((new Pasta())->setTenant($this->tenant)->setNup('0001/2026'), 77);
    }

    private function registro(User $autor, ?Pasta $pasta = null, ?Tenant $tenant = null, int $id = 500): PastaObservacaoFinanceira
    {
        return $this->definirId(
            (new PastaObservacaoFinanceira())
                ->setPasta($pasta ?? $this->pasta)
                ->setAutor($autor)
                ->setTenant($tenant ?? $this->tenant)
                ->setConteudo('<p>Honorários em 20%</p>'),
            $id,
        );
    }

    public function testEnviarObservacaoCriaEntidade(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaObservacaoFinanceira::class));
        $this->em->expects($this->never())->method('wrapInTransaction');

        $obs = $this->useCase->executar($this->pasta, $this->autor, 'Observação de teste', $this->tenant);

        self::assertSame('Observação de teste', $obs->getConteudo());
        self::assertSame($this->pasta, $obs->getPasta());
        self::assertSame($this->autor, $obs->getAutor());
        self::assertSame($this->tenant, $obs->getTenant());
        self::assertNull($obs->getRespostaA());
        self::assertFalse($obs->isResposta());
    }

    public function testConteudoVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    public function testConteudoAcimaDe5000CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 5001), $this->tenant);
    }

    #[TestDox('EDT-010r: mencionar um colega grava o nome do banco e o avisa, com o link do cartão e a aba')]
    public function testMencaoAvisa(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $avisos = $this->capturarAvisos();
        $this->em->expects($this->once())->method('wrapInTransaction');

        $obs = $this->useCase->executar($this->pasta, $this->autor, '<p>Ver com @[Bru](user:2)</p>', $this->tenant);

        self::assertStringContainsString('@[Bruno Lima](user:2)', $obs->getConteudo());
        self::assertCount(1, $avisos);
        self::assertSame(Notificacao::TIPO_PASTA_MENCAO_REGISTRO, $avisos[0]['tipo']);
        self::assertSame('Ana mencionou você na Pasta 0001/2026 · Financeiro', $avisos[0]['titulo']);
        self::assertSame('/pasta/77#financeiro-obs-' . $obs->getId(), $avisos[0]['notificacao']->getUrl());
    }

    #[TestDox('EDT-010r: menção a quem não vê a pasta, ou de outro escritório, não avisa')]
    public function testMencaoSemAcessoOuDeForaNaoAvisa(): void
    {
        $carla = $this->pessoa(3, 'Carla Dias');
        $this->colegasDoEscritorio([$this->autor, $carla]);
        $this->acessoAPasta(77, [3 => false]);
        $this->notificacaoService->expects($this->never())->method('criar');

        $obs = $this->useCase->executar($this->pasta, $this->autor, '<p>@[Carla](user:3) e @[Zé](user:99)</p>', $this->tenant);

        self::assertStringNotContainsString('(user:99)', $obs->getConteudo(), 'quem não é colega ativo vira texto');
    }

    #[TestDox('C6-SEC-02: menção no Relatório financeiro só avisa quem VÊ O FINANCEIRO — ver a pasta não basta')]
    public function testMencaoAQuemNaoVeOFinanceiroNaoAvisa(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $carla = $this->pessoa(3, 'Carla Dias');
        $this->colegasDoEscritorio([$this->autor, $bruno, $carla]);
        // As duas veem a pasta; só a Carla não vê o financeiro dela.
        $this->acessoAPasta(77, [2 => true, 3 => true]);
        $this->semVerOFinanceiro = [3];
        $avisos = $this->capturarAvisos();

        $obs = $this->useCase->executar($this->pasta, $this->autor, '<p>@[Bruno](user:2) e @[Carla](user:3)</p>', $this->tenant);

        // A marcação continua no texto (quem é mencionável não muda); só o sino é filtrado.
        self::assertStringContainsString('@[Carla Dias](user:3)', $obs->getConteudo());
        self::assertCount(1, $avisos);
        self::assertSame($bruno, $avisos[0]['usuario']);
    }

    #[TestDox('C6-SEC-02: resposta no Relatório financeiro não avisa o autor da raiz que não vê o financeiro')]
    public function testRespostaAQuemNaoVeOFinanceiroNaoAvisa(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $this->semVerOFinanceiro = [2];
        $this->notificacaoService->expects($this->never())->method('criar');

        $obs = $this->useCase->executar($this->pasta, $this->autor, '<p>Recibo emitido</p>', $this->tenant, $this->registro($bruno));

        // A resposta é gravada normalmente; só o aviso não sai.
        self::assertNotNull($obs->getRespostaA());
    }

    #[TestDox('EDT-010r: resposta que menciona o autor respondido gera UM aviso só — o de resposta')]
    public function testRespostaComMencaoAoRespondidoAvisaUmaVez(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $carla = $this->pessoa(3, 'Carla Dias');
        $this->colegasDoEscritorio([$this->autor, $bruno, $carla]);
        $this->acessoAPasta(77, [2 => true, 3 => true]);
        $avisos = $this->capturarAvisos();

        $this->useCase->executar($this->pasta, $this->autor, '<p>@[Bruno](user:2) e @[Carla](user:3)</p>', $this->tenant, $this->registro($bruno));

        $porPessoa = [];
        foreach ($avisos as $a) {
            $porPessoa[] = [$a['usuario']->getId(), $a['tipo']];
        }
        self::assertSame([[2, Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO], [3, Notificacao::TIPO_PASTA_MENCAO_REGISTRO]], $porPessoa);
    }

    #[TestDox('EDT-010r: o serviço não avisa menção de observação de OUTRA pasta ou de OUTRO escritório')]
    public function testServicoRecusaObservacaoAlheia(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $this->notificacaoService->expects($this->never())->method('criar');

        $outraPasta = $this->definirId((new Pasta())->setTenant($this->tenant), 99);
        $daOutra    = $this->registro($this->autor, pasta: $outraPasta);
        $deFora     = $this->registro($this->autor, tenant: $this->definirId(new Tenant(), 2), id: 501);

        $this->resolucao->notificarNaObservacao([$bruno], $daOutra, $this->pasta, $this->autor, $this->tenant);
        $this->resolucao->notificarNaObservacao([$bruno], $deFora, $this->pasta, $this->autor, $this->tenant);
    }

    #[TestDox('Pasta de outro escritório: recusa e nada é gravado')]
    public function testPastaDeOutroEscritorioRecusa(): void
    {
        $outro = $this->definirId((new Pasta())->setTenant($this->definirId(new Tenant(), 2)), 78);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($outro, $this->autor, 'x', $this->tenant);
    }

    #[TestDox('FPN-005: responder a um registro desta pasta grava a resposta ligada a ele')]
    public function testRespostaLigaARaiz(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $raiz  = $this->registro($bruno);
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $this->capturarAvisos();

        $obs = $this->useCase->executar($this->pasta, $this->autor, 'Combinado', $this->tenant, $raiz);

        self::assertSame($raiz, $obs->getRespostaA());
        self::assertTrue($obs->isResposta());
    }

    #[TestDox('FPN-005: responder a uma resposta liga à RAIZ dela (um nível só)')]
    public function testRespostaAUmaRespostaLigaARaiz(): void
    {
        $bruno    = $this->pessoa(2, 'Bruno Lima');
        $raiz     = $this->registro($bruno);
        $resposta = $this->registro($this->pessoa(3, 'Carla Dias'), id: 501)->setRespostaA($raiz);
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $avisos = $this->capturarAvisos();

        $obs = $this->useCase->executar($this->pasta, $this->autor, 'Combinado', $this->tenant, $resposta);

        self::assertSame($raiz, $obs->getRespostaA());
        // Quem recebe é o autor da RAIZ, sob a qual a resposta fica pendurada.
        self::assertCount(1, $avisos);
        self::assertSame($bruno, $avisos[0]['usuario']);
    }

    #[TestDox('FPN-005: responder a registro de OUTRA pasta é recusado e nada é gravado')]
    public function testRespostaAOutraPastaRecusa(): void
    {
        $outraPasta = $this->definirId((new Pasta())->setTenant($this->tenant), 99);
        $alheio     = $this->registro($this->pessoa(2, 'Bruno Lima'), pasta: $outraPasta);
        $this->em->expects($this->never())->method('persist');
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'x', $this->tenant, $alheio);
    }

    #[TestDox('FPN-005: responder a registro de OUTRO escritório é recusado e nada é gravado')]
    public function testRespostaAOutroEscritorioRecusa(): void
    {
        $alheio = $this->registro($this->pessoa(2, 'Bruno Lima'), tenant: $this->definirId(new Tenant(), 2));
        $this->em->expects($this->never())->method('persist');
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'x', $this->tenant, $alheio);
    }

    #[TestDox('FPN-005: resposta cuja RAIZ é de outra pasta (vínculo antigo corrompido) também é recusada')]
    public function testRaizDeOutraPastaRecusa(): void
    {
        $outraPasta = $this->definirId((new Pasta())->setTenant($this->tenant), 99);
        $raiz       = $this->registro($this->pessoa(2, 'Bruno Lima'), pasta: $outraPasta);
        $resposta   = $this->registro($this->pessoa(3, 'Carla Dias'), id: 501)->setRespostaA($raiz);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'x', $this->tenant, $resposta);
    }

    #[TestDox('FPN-005: o autor da raiz recebe "<Primeiro nome> respondeu seu comentário" com link para a resposta')]
    public function testNotificaOAutorDaRaiz(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $raiz  = $this->registro($bruno);
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $avisos = $this->capturarAvisos();

        $obs = $this->useCase->executar($this->pasta, $this->autor, '<p>Recibo emitido</p>', $this->tenant, $raiz);

        self::assertCount(1, $avisos);
        self::assertSame($bruno, $avisos[0]['usuario']);
        self::assertSame(Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO, $avisos[0]['tipo']);
        self::assertSame('Ana respondeu seu comentário', $avisos[0]['titulo']);
        self::assertStringStartsWith('"Recibo emitido" · em Financeiro da Pasta 0001/2026 · ', (string) $avisos[0]['texto']);
        self::assertStringEndsWith('. Seu comentário: "Honorários em 20%"', (string) $avisos[0]['texto']);
        self::assertSame('/pasta/77#financeiro-obs-' . $obs->getId(), $avisos[0]['notificacao']->getUrl());
    }

    #[TestDox('FPN-005: responder o próprio registro não notifica')]
    public function testRespostaAoProprioNaoNotifica(): void
    {
        $raiz = $this->registro($this->autor);
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->useCase->executar($this->pasta, $this->autor, 'Eu mesma', $this->tenant, $raiz);
    }

    #[TestDox('FPN-005: autor da raiz sem acesso à pasta, ou sem vínculo ativo, não recebe')]
    public function testSemAcessoOuSemVinculoNaoNotifica(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $carla = $this->pessoa(3, 'Carla Dias');
        // Bruno não está entre os colegas ativos (ex-colaborador); Carla está, mas não vê a pasta.
        $this->colegasDoEscritorio([$this->autor, $carla]);
        $this->acessoAPasta(77, [2 => true, 3 => false]);
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->useCase->executar($this->pasta, $this->autor, 'a', $this->tenant, $this->registro($bruno));
        $this->useCase->executar($this->pasta, $this->autor, 'b', $this->tenant, $this->registro($carla, id: 600));
    }

    #[TestDox('FPN-005: a mesma resposta não gera o aviso duas vezes (anti-duplicata por link)')]
    public function testAntiDuplicata(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $this->notificacaoRepository->method('findOneBy')->willReturn(new Notificacao());
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->useCase->executar($this->pasta, $this->autor, 'a', $this->tenant, $this->registro($bruno));
    }

    #[TestDox('FPN-005: cota do autor estourada — a resposta é gravada, o aviso não sai')]
    public function testCotaEstouradaGravaSemAvisar(): void
    {
        // A MESMA resolução, com a cota recusando (SEC-008): o resto dos dublês é o do setUp.
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $r, array $p = []): string => '/pasta/' . $p['id']);
        $resolucao = new ResolucaoDeMencoesDoRegistro(
            new MencoesDoRegistro($this->criarSanitizadorTextoRico()),
            $this->userRepository,
            $this->permissionChecker,
            $this->notificacaoRepository,
            $this->notificacaoService,
            $urlGenerator,
            new CotaDeNotificacoesDoRegistro(new RateLimiterFactoryEspiao(aceita: false), new NullLogger()),
            $this->visibilidadeDoFinanceiro,
        );
        $useCase = new EnviarObservacaoFinanceiraUseCase($this->em, $this->criarSanitizadorTextoRico(), $resolucao);

        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $this->notificacaoService->expects($this->never())->method('criar');
        $this->em->expects($this->once())->method('persist');

        $obs = $useCase->executar($this->pasta, $this->autor, 'a', $this->tenant, $this->registro($bruno));

        self::assertNotNull($obs->getId());
    }
}
