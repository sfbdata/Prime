<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\DevolucaoFeitaOutput;
use App\Tarefa\DTO\DevolverMetaInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\PassagemRecusadaException;
use App\Tarefa\Service\CodigoDoRegistro;
use App\Tarefa\Service\SorteioDeCodigoDoDespacho;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\Service\TransacaoDaPassagem;
use App\Tarefa\UseCase\DevolverMetaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Devolver para quem enviou (desenho 04B L106, L165-167; spec `minhas-metas-esteira-2210.md`, D3, L15 e 2.2).
 */
#[CoversClass(DevolverMetaUseCase::class)]
#[Group('tarefa')]
final class DevolverMetaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private const AGORA = '2026-10-22 15:00:00';

    /** @var list<array{usuario: User, tipo: string, titulo: string, texto: ?string, notificacao: Notificacao}> */
    private array $avisos = [];
    private int $transacoes = 0;
    /** @var list<int> */
    private array $semVinculo = [];
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private User $colega;
    private Tarefa $meta;

    /** O que outra requisição já gravou no banco: a transação aplica na releitura da meta. */
    private \Closure $noBanco;

    protected function setUp(): void
    {
        $this->noBanco = static function (Tarefa $meta): void {
        };
        $this->escritorio = self::comId(new Tenant(), 7);
        $this->criador = self::pessoa(1, 'THÂMARA THAYS SILVA');
        $this->responsavel = self::pessoa(2, 'Rui Prado');
        $this->colega = self::pessoa(3, 'Olga Lima');
        $pasta = self::comId((new Pasta())->setNup('828')->setTenant($this->escritorio), 40);
        $this->meta = self::comId(new Tarefa(), 30)
            ->setTitulo('Análise de pasta')
            ->setDescricao('...')
            ->setPasta($pasta)
            ->setTenant($this->escritorio)
            ->setCriadoPor($this->criador)
            ->setStatus(Tarefa::STATUS_PENDENTE)
            ->setEtapa(EtapaDaPasta::Controladoria);
        $this->meta->addResponsavel($this->responsavel)->addResponsavel($this->colega);
    }

    #[TestDox('Devolve: o criador vira o único responsável, histórico "Motivo: o que falta" registrado, aviso ao criador')]
    public function testDevolve(): void
    {
        $feito = $this->devolver($this->responsavel, new DevolverMetaInput('Falta documento', "  Preciso do contrato\r\nassinado.  "));

        self::assertSame('Thâmara Thays Silva', $feito->para);
        self::assertTrue($feito->avisado);
        self::assertSame(1, $this->transacoes);
        self::assertSame([$this->criador], array_values($this->meta->getResponsaveis()->toArray()));
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());
        self::assertSame(EtapaDaPasta::Controladoria, $this->meta->getEtapa(), 'a etapa da pasta não muda');
        $ato = $this->meta->getMensagens()->last();
        self::assertInstanceOf(TarefaMensagem::class, $ato);
        self::assertSame(AcaoDoDespacho::Devolucao, $ato->getAcao());
        self::assertSame('<p>Falta documento: Preciso do contrato<br />assinado.</p>', $ato->getMensagem());
        self::assertSame($this->criador, $ato->getDestinatario());
        self::assertSame('1234.5678', $ato->getCodigo());
        self::assertCount(1, $this->avisos);
        self::assertSame($this->criador, $this->avisos[0]['usuario']);
        self::assertSame(Notificacao::TIPO_TAREFA_PENDENTE, $this->avisos[0]['tipo']);
        self::assertSame('Meta devolvida · Pasta 828', $this->avisos[0]['titulo']);
        self::assertSame("Rui Prado devolveu \"Análise de pasta\": Falta documento. Preciso do contrato\nassinado.", $this->avisos[0]['texto']);
        self::assertSame('/tarefas/30', $this->avisos[0]['notificacao']->getUrl());
    }

    #[TestDox('Criador que saiu do escritório não é avisado, mas a meta volta para ele')]
    public function testCriadorSemVinculo(): void
    {
        $this->semVinculo = [1];

        $feito = $this->devolver($this->responsavel, new DevolverMetaInput('Outro motivo', 'Sem condições.'));

        self::assertFalse($feito->avisado, 'a tela não diz que avisou');
        self::assertSame([], $this->avisos);
        self::assertSame([$this->criador], array_values($this->meta->getResponsaveis()->toArray()));
    }

    /** @return iterable<string, array{DevolverMetaInput, string}> */
    public static function entradasInvalidas(): iterable
    {
        yield 'sem motivo' => [new DevolverMetaInput('', 'Preciso do contrato.'), 'Escolha o motivo da devolução.'];
        yield 'motivo fora da lista' => [new DevolverMetaInput('Cansei', 'Preciso do contrato.'), 'Escolha o motivo da devolução.'];
        yield 'o que falta curto' => [new DevolverMetaInput('Falta documento', ' abcd '), 'Explique o que falta para conseguir avançar.'];
    }

    #[DataProvider('entradasInvalidas')]
    #[TestDox('Recusa ($_dataName) com o erro do desenho, nada gravado')]
    public function testEntradasInvalidas(DevolverMetaInput $input, string $mensagem): void
    {
        try {
            $this->devolver($this->responsavel, $input);
            self::fail('Devia recusar.');
        } catch (PassagemRecusadaException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
        self::assertSame(0, $this->transacoes);
        self::assertCount(2, $this->meta->getResponsaveis());
    }

    #[TestDox('D3: o criador não devolve a própria meta, nem quem não é responsável (403)')]
    public function testD3(): void
    {
        $this->meta->addResponsavel($this->criador);
        foreach ([$this->criador, self::pessoa(9, 'Fora')] as $quem) {
            try {
                $this->devolver($quem, new DevolverMetaInput('Falta documento', 'Preciso do contrato.'));
                self::fail('Devia recusar.');
            } catch (PassagemRecusadaException $e) {
                self::assertTrue($e->ehFaltaDePermissao());
            }
        }
        self::assertSame(0, $this->transacoes);
    }

    #[TestDox('Só a meta pendente volta; outro escritório é recusado')]
    public function testStatusEEscritorio(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);
        try {
            $this->devolver($this->responsavel, new DevolverMetaInput('Falta documento', 'Preciso do contrato.'));
            self::fail('Devia recusar.');
        } catch (PassagemRecusadaException $e) {
            self::assertSame(PassagemRecusadaException::devolucaoForaDePendente()->getMessage(), $e->getMessage());
        }

        $this->meta->setStatus(Tarefa::STATUS_PENDENTE)->setTenant(self::comId(new Tenant(), 8));
        $this->expectExceptionObject(PassagemRecusadaException::foraDoEscritorio());
        $this->devolver($this->responsavel, new DevolverMetaInput('Falta documento', 'Preciso do contrato.'));
    }

    #[TestDox('Corrida: a meta que outra aba concluiu enquanto esta tinha a versão velha em memória é recusada, nada gravado')]
    public function testCorridaComAMetaVelhaEmMemoria(): void
    {
        $this->noBanco = function (Tarefa $meta): void {
            $meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        };

        try {
            $this->devolver($this->responsavel, new DevolverMetaInput('Falta documento', 'Preciso do contrato.'));
            self::fail('Devia recusar.');
        } catch (PassagemRecusadaException $e) {
            self::assertSame(PassagemRecusadaException::jaConcluida()->getMessage(), $e->getMessage());
        }
        self::assertSame(0, $this->transacoes);
        self::assertSame([], $this->avisos);
        self::assertCount(0, $this->meta->getMensagens());
        self::assertCount(2, $this->meta->getResponsaveis());
    }

    private function devolver(User $quem, DevolverMetaInput $input): DevolucaoFeitaOutput
    {
        $transacao = $this->createStub(TransacaoDaPassagem::class);
        $transacao->method('travarERecarregar')->willReturnCallback(function (Tarefa $meta): void {
            ($this->noBanco)($meta);
        });
        $transacao->method('emTransacao')->willReturnCallback(function (\Closure $trabalho): mixed {
            $r = $trabalho();
            ++$this->transacoes;

            return $r;
        });
        $mensagens = $this->createStub(TarefaMensagemRepository::class);
        $mensagens->method('gravarCodigoSeLivre')->willReturnCallback(static function (TarefaMensagem $m, string $c): bool {
            (new \ReflectionProperty(TarefaMensagem::class, 'codigo'))->setValue($m, $c);

            return true;
        });
        $sorteio = $this->createStub(SorteioDeCodigoDoDespacho::class);
        $sorteio->method('sortear')->willReturn('1234.5678');
        $notificacoes = $this->createStub(NotificacaoService::class);
        $notificacoes->method('veAPastaDaMeta')->willReturn(true);
        $notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $texto = null): Notificacao {
            $n = (new Notificacao())->setTipo($tipo);
            $this->avisos[] = ['usuario' => $u, 'tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'notificacao' => $n];

            return $n;
        });
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturnCallback(fn (User $u): bool => !in_array($u->getId(), $this->semVinculo, true));
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p = []): string => '/tarefas/' . $p['id']);

        $useCase = new DevolverMetaUseCase($transacao, $mensagens, new CodigoDoRegistro($mensagens, $sorteio), $notificacoes, $vinculos, new TextoDoDespacho($this->criarSanitizadorTextoRico()), $urls);

        return $useCase->executar($this->meta, $quem, $this->escritorio, $input, new \DateTimeImmutable(self::AGORA));
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
