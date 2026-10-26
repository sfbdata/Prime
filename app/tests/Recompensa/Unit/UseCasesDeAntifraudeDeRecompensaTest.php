<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\ApagarIndicadorDaListaInput;
use App\Recompensa\DTO\AprovarSuspeitasDoIndicadorInput;
use App\Recompensa\DTO\BloquearOuDesbloquearRecompensaInput;
use App\Recompensa\DTO\RemoverCondicaoIndividualInput;
use App\Recompensa\Entity\AprovacaoDeContaDeRecompensa;
use App\Recompensa\Entity\CondicaoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\AprovacaoDeContaDeRecompensaRepository;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Recompensa\UseCase\ApagarIndicadorDaListaUseCase;
use App\Recompensa\UseCase\AprovarSuspeitasDoIndicadorUseCase;
use App\Recompensa\UseCase\BloquearOuDesbloquearRecompensaUseCase;
use App\Recompensa\UseCase\RemoverCondicaoIndividualUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Indicacao\Support\Pessoas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AprovarSuspeitasDoIndicadorUseCase::class)]
#[CoversClass(BloquearOuDesbloquearRecompensaUseCase::class)]
#[CoversClass(ApagarIndicadorDaListaUseCase::class)]
#[CoversClass(RemoverCondicaoIndividualUseCase::class)]
#[CoversClass(CondicaoDeRecompensa::class)]
final class UseCasesDeAntifraudeDeRecompensaTest extends TestCase
{
    private const AGORA = '2026-10-26 12:00:00';

    private MockClock $relogio;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private User $master;
    /** @var list<bool> o escopo de plataforma estava ligado em cada gravação */
    private array $escopoNaGravacao = [];
    private ?CondicaoDeRecompensa $existente = null;
    /** @var list<CondicaoDeRecompensa> */
    private array $salvas = [];
    /** @var list<CondicaoDeRecompensa> */
    private array $removidas = [];
    /** @var list<AprovacaoDeContaDeRecompensa> */
    private array $aprovacoes = [];
    /** @var list<array<string, mixed>> */
    private array $contas = [];
    private int $travas = 0;

    protected function setUp(): void
    {
        $this->relogio = new MockClock(new \DateTimeImmutable(self::AGORA));
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = Pessoas::master();
    }

    private function condicoes(): CondicaoDeRecompensaRepository
    {
        $repo = $this->createStub(CondicaoDeRecompensaRepository::class);
        $repo->method('buscarDoIndicador')->willReturnCallback(fn (): ?CondicaoDeRecompensa => $this->existente);
        $repo->method('salvar')->willReturnCallback(function (CondicaoDeRecompensa $c, bool $flush): void {
            self::assertTrue($flush);
            $this->escopoNaGravacao[] = $this->escopo->estaLigado();
            $this->salvas[] = $c;
        });
        $repo->method('remover')->willReturnCallback(function (CondicaoDeRecompensa $c, bool $flush): void {
            self::assertTrue($flush);
            $this->escopoNaGravacao[] = $this->escopo->estaLigado();
            $this->removidas[] = $c;
        });

        return $repo;
    }

    private function indicacoes(int $quantas = 3): IndicacaoRepository
    {
        $repo = $this->createStub(IndicacaoRepository::class);
        $repo->method('contarDoUsuario')->willReturn($quantas);
        $repo->method('contasParaRecompensas')->willReturnCallback(fn (): array => $this->contas);
        $repo->method('emTransacaoTravada')->willReturnCallback(function (int $id, \Closure $trabalho): mixed {
            ++$this->travas;

            return $trabalho();
        });

        return $repo;
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $classe, int $id): ?User => $classe === User::class && $id < 100000 ? Pessoas::usuario($id) : null);

        return $em;
    }

    private function bloquear(int $indicacoes = 3): BloquearOuDesbloquearRecompensaUseCase
    {
        return new BloquearOuDesbloquearRecompensaUseCase($this->condicoes(), $this->indicacoes($indicacoes), $this->em(), $this->relogio, $this->escopo);
    }

    private function apagar(int $indicacoes = 3): ApagarIndicadorDaListaUseCase
    {
        return new ApagarIndicadorDaListaUseCase($this->condicoes(), $this->indicacoes($indicacoes), $this->em(), $this->relogio, $this->escopo);
    }

    #[TestDox('bloquear: cria a linha com o bloqueio, quem alterou e a data, no escopo de plataforma')]
    public function testBloqueia(): void
    {
        $this->bloquear()->executar(new BloquearOuDesbloquearRecompensaInput($this->master, 7, true));

        self::assertCount(1, $this->salvas);
        self::assertTrue($this->salvas[0]->isBloqueada());
        self::assertSame(7, $this->salvas[0]->getUserId());
        self::assertSame($this->master, $this->salvas[0]->getAlteradaPor());
        self::assertSame(self::AGORA, $this->salvas[0]->getBloqueadoEm()?->format('Y-m-d H:i:s'));
        self::assertSame([true], $this->escopoNaGravacao);
        self::assertFalse($this->salvas[0]->temCondicaoPropria(), 'bloquear não cria condição individual');
    }

    #[TestDox('bloquear de novo quem já está bloqueado não grava nada nem mexe na data (idempotente)')]
    public function testBloqueioIdempotente(): void
    {
        $this->existente = new CondicaoDeRecompensa(Pessoas::usuario(7));
        $this->existente->bloquear(new \DateTimeImmutable('2026-10-01 08:00:00'));

        $this->bloquear()->executar(new BloquearOuDesbloquearRecompensaInput($this->master, 7, true));

        self::assertSame([], $this->salvas);
        self::assertSame('2026-10-01 08:00:00', $this->existente->getBloqueadoEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('desbloquear: a linha sem mais nada some; com condição individual ela fica; sem linha não faz nada')]
    public function testDesbloqueia(): void
    {
        $this->bloquear()->executar(new BloquearOuDesbloquearRecompensaInput($this->master, 7, false));
        self::assertSame([], [...$this->salvas, ...$this->removidas], 'sem linha, nada a desbloquear');

        $this->existente = new CondicaoDeRecompensa(Pessoas::usuario(7));
        $this->existente->bloquear(new \DateTimeImmutable(self::AGORA));
        $this->bloquear()->executar(new BloquearOuDesbloquearRecompensaInput($this->master, 7, false));
        self::assertSame([$this->existente], $this->removidas);

        $this->removidas = [];
        $this->existente = new CondicaoDeRecompensa(Pessoas::usuario(7));
        $this->existente->definir(3000, null, null, false, null, null);
        $this->existente->bloquear(new \DateTimeImmutable(self::AGORA));
        $this->bloquear()->executar(new BloquearOuDesbloquearRecompensaInput($this->master, 7, false));
        self::assertSame([], $this->removidas);
        self::assertSame([$this->existente], $this->salvas);
        self::assertFalse($this->existente->isBloqueada());
        self::assertSame(3000, $this->existente->getValorCentavos());
    }

    #[TestDox('bloquear: quem nunca indicou é 404 e nada é gravado')]
    public function testBloquearInexistente(): void
    {
        $this->expectException(IndicadorNaoEncontrado::class);
        try {
            $this->bloquear(0)->executar(new BloquearOuDesbloquearRecompensaInput($this->master, 7, true));
        } finally {
            self::assertSame([], $this->salvas);
        }
    }

    #[TestDox('bloqueio: o corpo precisa de um booleano de verdade')]
    public function testCorpoDoBloqueio(): void
    {
        self::assertTrue(BloquearOuDesbloquearRecompensaInput::doJson($this->master, 1, ['bloqueado' => true])->bloqueado);
        foreach ([[], ['bloqueado' => 'sim'], ['bloqueado' => 1], ['bloqueado' => null]] as $corpo) {
            try {
                BloquearOuDesbloquearRecompensaInput::doJson($this->master, 1, $corpo);
                self::fail('devia recusar');
            } catch (RecompensaRecusada) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('apagar: com a palavra (qualquer caixa, espaços nas pontas) marca o indicador como apagado no escopo de plataforma')]
    public function testApaga(): void
    {
        $this->apagar()->executar(new ApagarIndicadorDaListaInput($this->master, 7, '  apagar '));

        self::assertCount(1, $this->salvas);
        self::assertTrue($this->salvas[0]->isApagada());
        self::assertSame(self::AGORA, $this->salvas[0]->getApagadoEm()?->format('Y-m-d H:i:s'));
        self::assertSame($this->master, $this->salvas[0]->getAlteradaPor());
        self::assertSame([true], $this->escopoNaGravacao);
    }

    #[TestDox('apagar sem a palavra APAGAR (vazia, outra, parecida) recusa com a mensagem do desenho e não grava')]
    public function testApagarSemPalavra(): void
    {
        foreach (['', 'apague', 'APAGAR TUDO', 'sim'] as $i => $texto) {
            try {
                $this->apagar()->executar(new ApagarIndicadorDaListaInput($this->master, 7, $texto));
                self::fail('devia recusar o caso ' . $i);
            } catch (RecompensaRecusada $e) {
                self::assertSame('Digite APAGAR para confirmar.', $e->getMessage());
            }
        }
        self::assertSame([], $this->salvas);
    }

    #[TestDox('apagar: quem nunca indicou é 404; quem já está apagado não regrava (idempotente)')]
    public function testApagarInexistenteEIdempotente(): void
    {
        try {
            $this->apagar(0)->executar(new ApagarIndicadorDaListaInput($this->master, 7, 'APAGAR'));
            self::fail('devia lançar 404');
        } catch (IndicadorNaoEncontrado) {
            self::assertSame([], $this->salvas);
        }

        $this->existente = new CondicaoDeRecompensa(Pessoas::usuario(7));
        $this->existente->apagar(new \DateTimeImmutable('2026-10-01 08:00:00'));
        $this->apagar()->executar(new ApagarIndicadorDaListaInput($this->master, 7, 'APAGAR'));

        self::assertSame([], $this->salvas);
        self::assertSame('2026-10-01 08:00:00', $this->existente->getApagadoEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('voltar à regra geral não desfaz o bloqueio nem o apagado: a linha fica só com eles')]
    public function testVoltarMantemBloqueio(): void
    {
        $this->existente = new CondicaoDeRecompensa(Pessoas::usuario(7));
        $this->existente->definir(3000, 2, 5, false, null, 'Parceria');
        $this->existente->bloquear(new \DateTimeImmutable(self::AGORA));

        (new RemoverCondicaoIndividualUseCase($this->condicoes(), $this->escopo))->executar(new RemoverCondicaoIndividualInput($this->master, 7));

        self::assertSame([], $this->removidas);
        self::assertSame([$this->existente], $this->salvas);
        self::assertFalse($this->existente->temCondicaoPropria());
        self::assertNull($this->existente->getMotivo());
        self::assertTrue($this->existente->isBloqueada());
    }

    /** @param array<string, mixed> $sinais */
    private function conta(int $indicador, int $contaId, bool $aprovada, array $sinais): void
    {
        $this->contas[] = $sinais + [
            'indicador_id' => $indicador, 'conta_id' => $contaId, 'conta_criada_em' => '2026-08-01 10:00:00', 'tem_pasta' => true, 'aprovada' => $aprovada,
            'escritorios_conta' => [], 'escritorios_indicador' => [], 'cnpjs_conta' => [], 'cnpjs_indicador' => [],
            'dominio_conta' => 'a.com', 'dominio_indicador' => 'b.com',
        ];
    }

    private function tradutor(): TranslatorInterface
    {
        $t = $this->createStub(TranslatorInterface::class);
        $t->method('trans')->willReturnArgument(0);

        return $t;
    }

    private function aprovar(): AprovarSuspeitasDoIndicadorUseCase
    {
        $repo = $this->createStub(AprovacaoDeContaDeRecompensaRepository::class);
        $repo->method('salvar')->willReturnCallback(function (AprovacaoDeContaDeRecompensa $a): void {
            $this->escopoNaGravacao[] = $this->escopo->estaLigado();
            $this->aprovacoes[] = $a;
        });

        return new AprovarSuspeitasDoIndicadorUseCase($this->indicacoes(), $repo, $this->em(), $this->relogio, $this->escopo, $this->tradutor());
    }

    #[TestDox('aprovar: aprova só as contas suspeitas pendentes do indicador (não as limpas, não as já aprovadas, não as de outro)')]
    public function testAprovaSoAsPendentes(): void
    {
        $this->conta(7, 101, false, ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br']);
        $this->conta(7, 102, false, ['escritorios_conta' => [3], 'escritorios_indicador' => [3]]);
        $this->conta(7, 103, false, []);
        $this->conta(7, 104, true, ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br']);
        $this->conta(8, 105, false, ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br']);

        $n = $this->aprovar()->executar(new AprovarSuspeitasDoIndicadorInput($this->master, 7, 2));

        self::assertSame(2, $n);
        self::assertSame([101, 102], array_map(static fn (AprovacaoDeContaDeRecompensa $a): int => (int) $a->getConta()->getId(), $this->aprovacoes));
        self::assertSame(7, $this->aprovacoes[0]->getIndicador()->getId());
        self::assertSame($this->master, $this->aprovacoes[0]->getAprovadaPor());
        self::assertSame(self::AGORA, $this->aprovacoes[0]->getAprovadaEm()->format('Y-m-d H:i:s'));
        self::assertSame([true, true], $this->escopoNaGravacao);
    }

    #[TestDox('aprovar sem nada pendente devolve zero e não grava (idempotente); quem nunca indicou é 404')]
    public function testAprovarIdempotenteE404(): void
    {
        $this->conta(7, 104, true, ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br']);

        self::assertSame(0, $this->aprovar()->executar(new AprovarSuspeitasDoIndicadorInput($this->master, 7, 0)));
        self::assertSame([], $this->aprovacoes);

        $this->expectException(IndicadorNaoEncontrado::class);
        (new AprovarSuspeitasDoIndicadorUseCase($this->indicacoes(0), $this->createStub(AprovacaoDeContaDeRecompensaRepository::class), $this->em(), $this->relogio, $this->escopo, $this->tradutor()))
            ->executar(new AprovarSuspeitasDoIndicadorInput($this->master, 7, 1));
    }

    #[TestDox('aprovar: quantidade pendente diferente da vista (mais ou menos) recusa com a mensagem do catálogo e não aprova nada')]
    public function testListaMudou(): void
    {
        $this->conta(7, 101, false, ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br']);
        $this->conta(7, 102, false, ['dominio_conta' => 'banca.adv.br', 'dominio_indicador' => 'banca.adv.br']);

        foreach ([1, 3] as $vistas) {
            try {
                $this->aprovar()->executar(new AprovarSuspeitasDoIndicadorInput($this->master, 7, $vistas));
                self::fail('devia recusar vistas=' . $vistas);
            } catch (RecompensaRecusada $e) {
                self::assertSame('master.recompensas.suspeitas_mudou', $e->getMessage());
            }
        }
        self::assertSame([], $this->aprovacoes);
    }

    #[TestDox('bloquear, desbloquear e apagar rodam sob a trava das indicações do usuário (sem duas linhas em corrida)')]
    public function testSobTrava(): void
    {
        $this->bloquear()->executar(new BloquearOuDesbloquearRecompensaInput($this->master, 7, true));
        $this->apagar()->executar(new ApagarIndicadorDaListaInput($this->master, 7, 'APAGAR'));

        self::assertSame(2, $this->travas);
    }
}
