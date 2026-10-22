<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\NotificacaoRepository;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\LembreteRecusadoException;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\UseCase\LembrarMetaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lembrar da faixa Aguardando (desenho 04B L50, L131-133; spec `minhas-metas-esteira-2210.md`, L10).
 */
#[CoversClass(LembrarMetaUseCase::class)]
#[Group('tarefa')]
final class LembrarMetaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const AGORA = '2026-10-22 15:00:00';

    private TarefaMensagemRepository&MockObject $mensagens;
    /** @var list<int> metas cuja trava foi pedida, na ordem */
    private array $travas = [];
    /** Quantas vezes o trabalho rodou dentro da trava (0 = nada gravado). */
    private int $dentroDaTrava = 0;
    private NotificacaoService&MockObject $notificacoes;
    private NotificacaoRepository&MockObject $notificacaoRepository;
    /** @var list<int> ids sem vínculo ativo com o escritório */
    private array $semVinculo = [];
    /** @var list<int> ids que não veem a pasta da meta (cadeado) */
    private array $semVerAPasta = [];
    /** @var list<array{usuario: User, tipo: string, titulo: string, texto: ?string, url: ?string}> */
    private array $avisos = [];
    private Tenant $escritorio;
    private User $autor;
    private User $mariana;
    private User $thamara;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
        $this->mensagens->method('emTransacaoTravadaPelaMeta')->willReturnCallback(function (int $metaId, \Closure $trabalho): mixed {
            $this->travas[] = $metaId;
            ++$this->dentroDaTrava;

            return $trabalho();
        });
        $this->notificacaoRepository = $this->createMock(NotificacaoRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->notificacoes->method('veAPastaDaMeta')->willReturnCallback(fn (Tarefa $t, User $u): bool => !in_array($u->getId(), $this->semVerAPasta, true));
        $this->notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $texto = null): Notificacao {
            $n = (new Notificacao())->setTipo($tipo);
            $this->avisos[] = ['usuario' => $u, 'tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'notificacao' => $n];

            return $n;
        });

        $this->escritorio = self::comId(new Tenant(), 7);
        $this->autor = self::pessoa(1, 'FARLEI ASSIS DA ROCHA');
        $this->mariana = self::pessoa(2, 'MARIANA COSTA');
        $this->thamara = self::pessoa(3, 'Thâmara Thays Silva');

        $pasta = self::comId((new Pasta())->setNup('1180')->setTenant($this->escritorio), 40);
        $this->meta = self::comId(new Tarefa(), 30);
        $this->meta->setTitulo('Análise processual e providência');
        $this->meta->setDescricao('...');
        $this->meta->setPasta($pasta);
        $this->meta->setTenant($this->escritorio);
        $this->meta->setCriadoPor($this->autor);
        $this->meta->setStatus(Tarefa::STATUS_PENDENTE);
        $this->meta->addResponsavel($this->mariana);
        $this->meta->addResponsavel($this->thamara);
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($this->meta, new \DateTimeImmutable('2026-10-19 10:00'));
    }

    #[TestDox('Pendente: avisa cada responsável na Central (título e texto do desenho) e grava "Lembrete enviado para A, B." no histórico')]
    public function testPendenteAvisaOsResponsaveis(): void
    {
        $this->mensagens->expects(self::once())->method('salvar')->with(self::isInstanceOf(TarefaMensagem::class));

        $nomes = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, new \DateTimeImmutable(self::AGORA));

        self::assertSame(['Mariana Costa', 'Thâmara Thays Silva'], $nomes);
        self::assertSame([30], $this->travas, 'gravado sob a trava da meta');
        self::assertCount(2, $this->avisos);
        self::assertSame([$this->mariana, $this->thamara], array_column($this->avisos, 'usuario'));
        self::assertSame(LembrarMetaUseCase::TIPO_NOTIFICACAO, $this->avisos[0]['tipo']);
        self::assertSame('Lembrete: meta aguardando despacho · Pasta 1180', $this->avisos[0]['titulo']);
        // Criada em 19/10 (à 00:00) e agora 22/10 às 15h: 3 períodos de 24h, como a faixa.
        self::assertSame('Análise processual e providência está com você há 3 dia(s).', $this->avisos[0]['texto']);
        self::assertSame('/tarefas/30', $this->avisos[0]['notificacao']->getUrl());

        $lembrete = $this->meta->getMensagens()->last();
        self::assertInstanceOf(TarefaMensagem::class, $lembrete);
        self::assertSame('<p>Lembrete enviado para Mariana Costa, Thâmara Thays Silva.</p>', $lembrete->getMensagem());
        self::assertSame(AcaoDoDespacho::Lembrete, $lembrete->getAcao());
        self::assertSame($this->autor, $lembrete->getUsuario());
        self::assertSame($this->escritorio, $lembrete->getTenant());
        self::assertNull($lembrete->getCodigo(), 'lembrete não é ato do ciclo: sem código de registro');
        self::assertFalse($lembrete->podeSerEditado(), 'lembrete não se edita');
    }

    #[TestDox('Em revisão: avisa o autor da meta ("aguardando revisão"), não os responsáveis')]
    public function testEmRevisaoAvisaOAutor(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);

        $nomes = $this->useCase()->executar($this->meta, $this->mariana, $this->escritorio, new \DateTimeImmutable(self::AGORA));

        self::assertSame(['Farlei Assis Da Rocha'], $nomes);
        self::assertSame([$this->autor], array_column($this->avisos, 'usuario'));
        self::assertSame('Lembrete: meta aguardando revisão · Pasta 1180', $this->avisos[0]['titulo']);
    }

    #[TestDox('Quem pede não lembra a si mesmo; quem saiu do escritório ou não vê a pasta fica de fora')]
    public function testFiltraQuemPedeSemVinculoESemAcesso(): void
    {
        $this->meta->addResponsavel(self::pessoa(4, 'Ex Colega'));
        $this->meta->addResponsavel(self::pessoa(5, 'Sem Acesso'));
        $this->semVinculo = [4];
        $this->semVerAPasta = [5];

        $nomes = $this->useCase()->executar($this->meta, $this->mariana, $this->escritorio, new \DateTimeImmutable(self::AGORA));

        self::assertSame(['Thâmara Thays Silva'], $nomes);
    }

    #[TestDox('A meta só com quem pede: recusa com "a meta está só com você", nada gravado')]
    public function testSoComQuemPedeRecusa(): void
    {
        $this->meta->removeResponsavel($this->thamara);
        $this->mensagens->expects(self::never())->method('salvar');

        $this->assertRecusa('Não há a quem lembrar: a meta está só com você.', $this->mariana);
        self::assertSame(0, $this->dentroDaTrava);
    }

    #[TestDox('Os outros saíram do escritório ou não veem a pasta: a recusa diz isso, não "só com você"')]
    public function testOutrosFiltradosRecusaComOMotivoCerto(): void
    {
        $this->semVinculo = [2];
        $this->semVerAPasta = [3];
        $this->mensagens->expects(self::never())->method('salvar');

        $this->assertRecusa('Não há a quem lembrar: quem está com a meta saiu do escritório ou não vê a pasta.', $this->autor);
        self::assertSame([], $this->avisos);
    }

    #[TestDox('Meta sem responsável: a recusa diz isso')]
    public function testSemResponsavelRecusa(): void
    {
        $this->meta->removeResponsavel($this->mariana);
        $this->meta->removeResponsavel($this->thamara);

        $this->assertRecusa('Não há a quem lembrar: a meta está sem responsável.', $this->autor);
    }

    #[TestDox('Lembrete repetido em menos de uma hora para a mesma pessoa: recusa com a hora do anterior, nada gravado')]
    public function testUmPorHora(): void
    {
        $anterior = new Notificacao();
        (new \ReflectionProperty(Notificacao::class, 'criadaEm'))->setValue($anterior, new \DateTimeImmutable('2026-10-22 14:20'));
        $this->notificacaoRepository->method('ultimaDoTipoNaTarefa')->willReturnCallback(
            fn (Tarefa $t, User $u, string $tipo): ?Notificacao => $u === $this->thamara && $tipo === LembrarMetaUseCase::TIPO_NOTIFICACAO && $t === $this->meta ? $anterior : null,
        );
        $this->mensagens->expects(self::never())->method('salvar');

        try {
            $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, new \DateTimeImmutable(self::AGORA));
            self::fail('Devia recusar o lembrete repetido.');
        } catch (LembreteRecusadoException $e) {
            self::assertSame('Thâmara Thays Silva já recebeu um lembrete desta meta às 14:20. Aguarde uma hora para lembrar de novo.', $e->getMessage());
        }
        self::assertSame([30], $this->travas, 'o intervalo é lido DENTRO da trava: dois pedidos simultâneos não passam os dois');
        self::assertSame([], $this->avisos);
    }

    #[TestDox('Passada uma hora, lembra de novo')]
    public function testDepoisDeUmaHoraLembraDeNovo(): void
    {
        $anterior = new Notificacao();
        (new \ReflectionProperty(Notificacao::class, 'criadaEm'))->setValue($anterior, new \DateTimeImmutable('2026-10-22 13:59'));
        $this->notificacaoRepository->method('ultimaDoTipoNaTarefa')->willReturn($anterior);

        $nomes = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, new \DateTimeImmutable(self::AGORA));

        self::assertCount(2, $nomes);
    }

    #[TestDox('Meta concluída não recebe lembrete; meta de outro escritório é erro de programação')]
    public function testConcluidaEOutroEscritorio(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        try {
            $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, new \DateTimeImmutable(self::AGORA));
            self::fail('Devia recusar a concluída.');
        } catch (LembreteRecusadoException $e) {
            self::assertSame('Meta concluída não recebe lembrete.', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->useCase()->executar($this->meta, $this->autor, self::comId(new Tenant(), 8), new \DateTimeImmutable(self::AGORA));
    }

    private function useCase(): LembrarMetaUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturnCallback(fn (User $u, Tenant $t): bool => $t === $this->escritorio && !in_array($u->getId(), $this->semVinculo, true));
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p = []): string => '/tarefas/' . $p['id']);

        return new LembrarMetaUseCase(
            $this->mensagens,
            $this->notificacoes,
            $this->notificacaoRepository,
            $vinculos,
            new TextoDoDespacho($this->criarSanitizadorTextoRico()),
            $urls,
        );
    }

    private function assertRecusa(string $mensagem, User $quemPede): void
    {
        try {
            $this->useCase()->executar($this->meta, $quemPede, $this->escritorio, new \DateTimeImmutable(self::AGORA));
            self::fail('Devia recusar.');
        } catch (LembreteRecusadoException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
    }

    private static function pessoa(int $id, string $nome): User
    {
        return self::comId((new User())->setEmail('p' . $id . '@test.com')->setFullName($nome), $id);
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade::class, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
