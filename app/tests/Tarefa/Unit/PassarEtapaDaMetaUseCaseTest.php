<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\PassarEtapaInput;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Exception\PassagemRecusadaException;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Service\CodigoDoRegistro;
use App\Tarefa\Service\NomesDasEtapas;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\Service\SorteioDeCodigoDoDespacho;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\Service\TransacaoDaPassagem;
use App\Tarefa\UseCase\PassarEtapaDaMetaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Concluir etapa e passar adiante (desenhos 04 L174-182 e 04B L177-179; spec `minhas-metas-esteira-2210.md`, Fase 3,
 * D3, L13 e L14).
 */
#[CoversClass(PassarEtapaDaMetaUseCase::class)]
#[Group('tarefa')]
final class PassarEtapaDaMetaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const AGORA = '2026-10-22 15:00:00';

    private TarefaMensagemRepository&MockObject $mensagens;
    private NotificacaoService&MockObject $notificacoes;
    /** @var list<array{usuario: User, tipo: string, titulo: string, texto: ?string, meta: ?Tarefa, notificacao: Notificacao}> */
    private array $avisos = [];
    /** @var list<Tarefa> */
    private array $gravadas = [];
    /** Quantas transações rodaram até o fim (0 = nada gravado). */
    private int $transacoes = 0;
    private bool $codigoLivre = true;
    /** @var list<int> */
    private array $semVinculo = [];
    /** @var list<int> */
    private array $semVerAPasta = [];
    /** @var array<string, CaixaDaEsteira> */
    private array $caixas = [];
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private User $ana;
    private User $bia;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
        $this->mensagens->method('gravarCodigoSeLivre')->willReturnCallback(function (TarefaMensagem $m, string $codigo): bool {
            if ($this->codigoLivre) {
                (new \ReflectionProperty(TarefaMensagem::class, 'codigo'))->setValue($m, $codigo);
            }

            return $this->codigoLivre;
        });
        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->notificacoes->method('veAPastaDaMeta')->willReturnCallback(fn (Tarefa $t, User $u): bool => !in_array($u->getId(), $this->semVerAPasta, true));
        $this->notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $texto = null, ?Tarefa $meta = null): Notificacao {
            $n = (new Notificacao())->setTipo($tipo);
            $this->avisos[] = ['usuario' => $u, 'tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'meta' => $meta, 'notificacao' => $n];

            return $n;
        });

        $this->escritorio = self::comId((new Tenant())->setEstado('AL'), 7);
        $this->criador = self::pessoa(1, 'CARLA DIAS');
        $this->responsavel = self::pessoa(2, 'RUI PRADO');
        $this->ana = self::pessoa(3, 'ana souza lima');
        $this->bia = self::pessoa(4, 'Bia Reis');

        $pasta = self::comId((new Pasta())->setNup('828')->setTenant($this->escritorio), 40);
        $this->meta = self::comId(new Tarefa(), 30)
            ->setTitulo('Análise de pasta')
            ->setDescricao('...')
            ->setPasta($pasta)
            ->setTenant($this->escritorio)
            ->setCriadoPor($this->criador)
            ->setStatus(Tarefa::STATUS_PENDENTE)
            ->setPrioridade(PrioridadeDaMeta::Alta)
            ->setEtapa(EtapaDaPasta::Controladoria)
            ->setTipoDeDemanda(TipoDeDemanda::Judicial);
        $this->meta->addResponsavel($this->responsavel);
    }

    #[TestDox('Passa para a próxima: conclui a atual, cria a meta da etapa com título, herança, prazo de 2 dias úteis e número')]
    public function testPassaParaAProxima(): void
    {
        $saida = $this->passar($this->responsavel, new PassarEtapaInput('judicial', pendencias: "Falta procuração\nComprovante de residência"), $this->ana);

        self::assertSame(1, $this->transacoes, 'uma transação só');
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->meta->getStatus());
        self::assertSame(self::AGORA, $this->meta->getDataConclusao()?->format('Y-m-d H:i:s'));
        $nova = $this->unicaGravada();
        self::assertSame('Documentação: Falta procuração', $nova->getTitulo());
        self::assertSame("Falta procuração\nComprovante de residência", $nova->getDescricao());
        self::assertSame(EtapaDaPasta::Documentacao, $nova->getEtapa());
        self::assertSame(TipoDeDemanda::Judicial, $nova->getTipoDeDemanda());
        self::assertSame(PrioridadeDaMeta::Alta, $nova->getPrioridade());
        self::assertSame($this->meta->getPasta(), $nova->getPasta());
        self::assertSame($this->escritorio, $nova->getTenant());
        self::assertSame($this->responsavel, $nova->getCriadoPor());
        self::assertSame([$this->ana], $nova->getResponsaveis()->toArray());
        self::assertSame(Tarefa::STATUS_PENDENTE, $nova->getStatus());
        // 22/10/2026 é quinta: 2 dias úteis caem na segunda, 26/10.
        self::assertSame('2026-10-26', $nova->getPrazo()?->format('Y-m-d'));
        self::assertSame(481, $nova->getNumero());
        self::assertSame('Documentação', $saida->proxima);
        self::assertSame('828', $saida->pasta);
        self::assertSame(99, $saida->novaMetaId);
    }

    #[TestDox('Histórico da meta concluída: "X concluída → Y (Resp)", pendências e o que foi feito, como ato registrado com código')]
    public function testHistoricoRegistrado(): void
    {
        $this->passar($this->responsavel, new PassarEtapaInput('judicial', pendencias: 'Falta procuração', feito: 'Conferi os dados.'), $this->ana);

        $ato = $this->meta->getMensagens()->last();
        self::assertInstanceOf(TarefaMensagem::class, $ato);
        self::assertSame(AcaoDoDespacho::Etapa, $ato->getAcao());
        self::assertSame('<p>Controladoria concluída → Documentação (Ana Souza Lima)<br />Pendências: Falta procuração<br />O que foi feito: Conferi os dados.</p>', $ato->getMensagem());
        self::assertSame($this->ana, $ato->getDestinatario());
        self::assertSame($this->responsavel, $ato->getUsuario());
        self::assertSame('RUI PRADO', $ato->getRegistradoPorNome());
        self::assertSame('1234.5678', $ato->getCodigo());
    }

    #[TestDox('Avisa o responsável novo e os avisados da caixa destino, uma vez cada, nunca quem passou')]
    public function testAvisosSemDuplicata(): void
    {
        $this->caixaDe(EtapaDaPasta::Documentacao, 'CAIXA 4', [$this->ana, $this->bia, $this->responsavel]);

        $this->passar($this->responsavel, new PassarEtapaInput('judicial', pendencias: 'Falta procuração'), $this->ana);

        self::assertSame([$this->ana, $this->bia], array_column($this->avisos, 'usuario'), 'Ana só recebe o de responsável; quem passou não é avisado');
        self::assertSame(Notificacao::TIPO_TAREFA_CRIADA, $this->avisos[0]['tipo']);
        self::assertSame('Nova etapa: Documentação · Pasta 828', $this->avisos[0]['titulo']);
        self::assertSame('Controladoria concluída. Próximo passo: Documentos reunidos. Pendências: Falta procuração.', $this->avisos[0]['texto']);
        self::assertSame('Nova demanda na caixa CAIXA 4 · Pasta 828', $this->avisos[1]['titulo']);
        self::assertSame('Chegou uma nova demanda em Documentação (responsável: Ana Souza Lima).', $this->avisos[1]['texto']);
        self::assertSame('/tarefas/99', $this->avisos[1]['notificacao']->getUrl());
        self::assertSame($this->unicaGravada(), $this->avisos[1]['meta']);
    }

    #[TestDox('Aviso: quem saiu do escritório ou não vê a pasta fica de fora; sem responsável, o texto da caixa não o cita')]
    public function testAvisosFiltrados(): void
    {
        $this->caixaDe(EtapaDaPasta::Documentacao, null, [$this->ana, $this->bia]);
        $this->semVinculo = [3];
        $this->semVerAPasta = [4];

        $this->passar($this->criador, new PassarEtapaInput('judicial'), null);

        self::assertSame([], $this->avisos);
        self::assertSame([], $this->unicaGravada()->getResponsaveis()->toArray());
        self::assertSame('Documentação', $this->unicaGravada()->getTitulo());
        self::assertSame('<p>Controladoria concluída → Documentação</p>', $this->meta->getMensagens()->last()->getMensagem());
    }

    #[TestDox('Saltar: as etapas do meio ficam marcadas como saltadas na meta nova, junto das herdadas')]
    public function testSaltar(): void
    {
        $this->meta->setEtapasSaltadas([EtapaDaPasta::Abertura]);

        $this->passar($this->responsavel, new PassarEtapaInput('judicial', destino: 'protocolo'), $this->ana);

        $nova = $this->unicaGravada();
        self::assertSame(EtapaDaPasta::Protocolo, $nova->getEtapa());
        self::assertSame([EtapaDaPasta::Abertura, EtapaDaPasta::Documentacao, EtapaDaPasta::Juridico], $nova->getEtapasSaltadas());
    }

    #[TestDox('Voltar exige o motivo nas pendências; com ele, a meta volta e as saltadas depois do destino deixam de valer')]
    public function testVoltar(): void
    {
        $this->meta->setEtapasSaltadas([EtapaDaPasta::Abertura]);
        try {
            $this->passar($this->responsavel, new PassarEtapaInput('judicial', destino: 'comercial'), null);
            self::fail('Devia recusar.');
        } catch (PassagemRecusadaException $e) {
            self::assertSame(PassagemRecusadaException::voltaSemMotivo()->getMessage(), $e->getMessage());
        }
        self::assertSame(0, $this->transacoes);

        $this->passar($this->responsavel, new PassarEtapaInput('judicial', destino: 'comercial', pendencias: 'Contrato sem assinatura'), null);

        self::assertSame(EtapaDaPasta::Comercial, $this->unicaGravada()->getEtapa());
        self::assertSame([], $this->unicaGravada()->getEtapasSaltadas());
    }

    #[TestDox('Consultoria muda a sequência: depois do Jurídico vem o Protocolo e Documentação não é destino')]
    public function testTipoDeDemandaMudaASequencia(): void
    {
        $this->meta->setEtapa(EtapaDaPasta::Controladoria);
        $this->passar($this->responsavel, new PassarEtapaInput('consultoria'), null);
        self::assertSame(EtapaDaPasta::Juridico, $this->unicaGravada()->getEtapa());
        self::assertSame(TipoDeDemanda::Consultoria, $this->unicaGravada()->getTipoDeDemanda());

        $this->expectExceptionObject(PassagemRecusadaException::etapaInvalida());
        $this->meta->setStatus(Tarefa::STATUS_PENDENTE);
        $this->passar($this->responsavel, new PassarEtapaInput('consultoria', destino: 'documentacao'), null);
    }

    #[TestDox('Última etapa: só conclui ("pasta encerrada"), sem meta nova e sem aviso')]
    public function testUltimaEtapaEncerra(): void
    {
        $this->meta->setEtapa(EtapaDaPasta::Encerramento);

        $saida = $this->passar($this->responsavel, new PassarEtapaInput('judicial', feito: 'Arquivado.'), $this->ana);

        self::assertSame([], $this->gravadas);
        self::assertSame([], $this->avisos);
        self::assertNull($saida->proxima);
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->meta->getStatus());
        self::assertSame('<p>Encerramento concluída · pasta encerrada<br />O que foi feito: Arquivado.</p>', $this->meta->getMensagens()->last()->getMensagem());
    }

    #[TestDox('D3: quem não é responsável nem criador é recusado por falta de permissão, nada gravado')]
    public function testD3(): void
    {
        try {
            $this->passar($this->bia, new PassarEtapaInput('judicial'), null);
            self::fail('Devia recusar.');
        } catch (PassagemRecusadaException $e) {
            self::assertTrue($e->ehFaltaDePermissao());
        }
        self::assertSame(0, $this->transacoes);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());
    }

    #[TestDox('O criador também passa adiante (D3)')]
    public function testCriadorPassa(): void
    {
        $this->passar($this->criador, new PassarEtapaInput('judicial'), null);

        self::assertCount(1, $this->gravadas);
    }

    /** @return iterable<string, array{0: callable(self): void, 1: string}> */
    public static function recusas(): iterable
    {
        yield 'outro escritório' => [static fn (self $t) => $t->meta->setTenant(self::comId(new Tenant(), 8)), PassagemRecusadaException::foraDoEscritorio()->getMessage()];
        yield 'já concluída' => [static fn (self $t) => $t->meta->setStatus(Tarefa::STATUS_CONCLUIDA), PassagemRecusadaException::jaConcluida()->getMessage()];
    }

    /** @param callable(self): void $preparar */
    #[\PHPUnit\Framework\Attributes\DataProvider('recusas')]
    #[TestDox('Recusa ($_dataName), nada gravado')]
    public function testRecusas(callable $preparar, string $mensagem): void
    {
        $preparar($this);
        try {
            $this->passar($this->responsavel, new PassarEtapaInput('judicial'), null);
            self::fail('Devia recusar.');
        } catch (PassagemRecusadaException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
        self::assertSame(0, $this->transacoes);
    }

    /** @return iterable<string, array{PassarEtapaInput, string}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'tipo desconhecido' => [new PassarEtapaInput('trabalhista'), PassagemRecusadaException::tipoInvalido()->getMessage()];
        yield 'etapa fora do catálogo' => [new PassarEtapaInput('judicial', destino: 'financeiro'), PassagemRecusadaException::etapaInvalida()->getMessage()];
        yield 'destino = a atual' => [new PassarEtapaInput('judicial', destino: 'controladoria'), PassagemRecusadaException::etapaInvalida()->getMessage()];
        yield 'prazo antes de hoje' => [new PassarEtapaInput('judicial', prazo: '2026-10-21'), PassagemRecusadaException::prazoInvalido()->getMessage()];
        yield 'prazo ilegível' => [new PassarEtapaInput('judicial', prazo: '2026-02-30'), PassagemRecusadaException::prazoIlegivel()->getMessage()];
        yield 'pendências longas' => [new PassarEtapaInput('judicial', pendencias: str_repeat('a', 2001)), PassagemRecusadaException::textoLongo()->getMessage()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entradasInvalidas')]
    #[TestDox('Entrada inválida ($_dataName): recusa com o aviso, nada gravado')]
    public function testEntradasInvalidas(PassarEtapaInput $input, string $mensagem): void
    {
        try {
            $this->passar($this->responsavel, $input, null);
            self::fail('Devia recusar.');
        } catch (PassagemRecusadaException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
        self::assertSame(0, $this->transacoes);
    }

    #[TestDox('Prazo escolhido, hoje ou depois, vale como veio')]
    public function testPrazoEscolhido(): void
    {
        $this->passar($this->responsavel, new PassarEtapaInput('judicial', prazo: '2026-10-22'), null);

        self::assertSame('2026-10-22', $this->unicaGravada()->getPrazo()?->format('Y-m-d'));
    }

    #[TestDox('Sem código livre, a transação inteira falha (o erro sai de dentro dela)')]
    public function testSemCodigoLivre(): void
    {
        $this->codigoLivre = false;

        $this->expectExceptionObject(DespachoRecusadoException::semCodigoLivre());
        $this->passar($this->responsavel, new PassarEtapaInput('judicial'), null);
    }

    private function passar(User $quem, PassarEtapaInput $input, ?User $responsavel): \App\Tarefa\DTO\PassagemFeitaOutput
    {
        return $this->useCase()->executar($this->meta, $quem, $this->escritorio, $input, $responsavel, new \DateTimeImmutable(self::AGORA));
    }

    private function unicaGravada(): Tarefa
    {
        self::assertCount(1, $this->gravadas);

        return $this->gravadas[0];
    }

    /** @param list<User> $avisados */
    private function caixaDe(EtapaDaPasta $etapa, ?string $nomeDaCaixa, array $avisados): void
    {
        $caixa = new CaixaDaEsteira($this->escritorio, $etapa);
        $caixa->configurar($nomeDaCaixa, null, $avisados);
        $this->caixas[$etapa->value] = $caixa;
    }

    private function useCase(): PassarEtapaDaMetaUseCase
    {
        $transacao = new class ($this) implements TransacaoDaPassagem {
            public function __construct(private readonly PassarEtapaDaMetaUseCaseTest $teste)
            {
            }

            public function emTransacao(\Closure $trabalho): mixed
            {
                $resultado = $trabalho();
                $this->teste->transacaoTerminou();

                return $resultado;
            }

            public function gravarMetaNova(Tarefa $nova): void
            {
                $this->teste->gravou($nova);
            }
        };
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturnCallback(fn (User $u, Tenant $t): bool => $t === $this->escritorio && !in_array($u->getId(), $this->semVinculo, true));
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p = []): string => '/tarefas/' . $p['id']);
        $caixas = $this->createStub(CaixaDaEsteiraRepository::class);
        $caixas->method('configuradasDoEscritorio')->willReturnCallback(fn (): array => $this->caixas);
        $sorteio = $this->createStub(SorteioDeCodigoDoDespacho::class);
        $sorteio->method('sortear')->willReturn('1234.5678');

        return new PassarEtapaDaMetaUseCase(
            $transacao,
            $this->mensagens,
            new CodigoDoRegistro($this->mensagens, $sorteio),
            new NomesDasEtapas($caixas, self::tradutor()),
            $this->notificacoes,
            $vinculos,
            new DiasUteisDoEscritorio(new CalendarioDoSistema()),
            new TextoDoDespacho($this->criarSanitizadorTextoRico()),
            $urls,
            new QuemCuidaDaMeta(),
        );
    }

    /** @internal chamado pela transação falsa */
    public function transacaoTerminou(): void
    {
        ++$this->transacoes;
    }

    /** @internal chamado pela transação falsa: numera e dá id, como o banco */
    public function gravou(Tarefa $nova): void
    {
        $nova->numerar(481 + count($this->gravadas));
        self::comId($nova, 99 + count($this->gravadas));
        $this->gravadas[] = $nova;
    }

    public static function tradutor(): TranslatorInterface
    {
        return new class () implements TranslatorInterface {
            private const TEXTOS = [
                'metas.etapa.comercial.nome' => 'Comercial',
                'metas.etapa.controladoria.nome' => 'Controladoria',
                'metas.etapa.documentacao.nome' => 'Documentação',
                'metas.etapa.documentacao.setor' => 'Secretaria',
                'metas.etapa.documentacao.descricao' => 'Documentos reunidos',
                'metas.etapa.juridico.nome' => 'Jurídico',
                'metas.etapa.protocolo.nome' => 'Protocolo',
                'metas.etapa.encerramento.nome' => 'Encerramento',
            ];

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return self::TEXTOS[$id] ?? $id;
            }

            public function getLocale(): string
            {
                return 'pt_BR';
            }
        };
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
