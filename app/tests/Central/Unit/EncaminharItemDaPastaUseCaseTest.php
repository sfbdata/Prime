<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Contract\AuditoriaDoDespacho;
use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Central\DTO\EncaminharItemInput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Enum\PrioridadeDoDespacho;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeDespachoUrgente;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Central\Service\MencoesDaCentral;
use App\Central\UseCase\AbrirConversaUseCase;
use App\Central\UseCase\EncaminharItemDaPastaUseCase;
use App\Central\UseCase\EnviarMensagemUseCase;
use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Tests\Central\Support\MontaEncaminhamentoTrait;
use App\Tests\Inteligencia\Support\DefineId;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O despacho de um item da pasta para a Central: o texto montado pelo servidor, quem pode receber e o aviso do
 * "Urgente". As guardas, o leitor de itens, o abrir e o enviar são os de verdade, sobre repositórios de mentira.
 */
#[CoversClass(EncaminharItemDaPastaUseCase::class)]
#[CoversClass(EncaminharItemInput::class)]
#[CoversClass(AvisoDeDespachoUrgente::class)]
final class EncaminharItemDaPastaUseCaseTest extends TestCase
{
    use MontaEncaminhamentoTrait;

    private const INDIVIDUAL = 40;
    private const GRUPO = 41;

    private Tenant $tenant;
    private Pasta $pasta;
    private User $eu;
    private User $bruno;
    private User $carla;

    /** @var array<int, Conversa> */
    private array $conversas = [];

    /** @var array<int, list<User>> */
    private array $membros = [];

    /** @var list<string> o conteúdo de cada mensagem gravada */
    private array $enviadas = [];

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->pasta = $this->pastaDe($this->tenant);
        $this->eu = $this->pessoa(1, 'Ana Lima');
        $this->bruno = $this->pessoa(2, 'Bruno Reis');
        $this->carla = $this->pessoa(3, 'Carla Dias');

        $agora = new \DateTimeImmutable('2026-10-19 09:00');
        $this->conversas[self::INDIVIDUAL] = Conversa::individual($this->tenant, $this->eu, $this->bruno, $agora);
        $this->conversas[self::GRUPO] = Conversa::grupo($this->tenant, $this->eu, 'Equipe', $agora);
        DefineId::em($this->conversas[self::INDIVIDUAL], self::INDIVIDUAL);
        DefineId::em($this->conversas[self::GRUPO], self::GRUPO);
        $this->membros = [self::INDIVIDUAL => [$this->eu, $this->bruno], self::GRUPO => [$this->eu, $this->bruno, $this->carla]];
    }

    #[TestDox('Urgente para um colega: prefixo, título e resumo do servidor, instrução, um aviso no sino e a auditoria')]
    public function testUrgenteParaColega(): void
    {
        $avisos = $this->createMock(NotificacaoService::class);
        $avisos->expects(self::once())->method('criar')
            ->with($this->bruno, $this->tenant, Notificacao::TIPO_CENTRAL_DESPACHO, 'Despacho urgente de Ana')
            ->willReturn(new Notificacao());
        $auditoria = $this->createMock(AuditoriaDoDespacho::class);
        $auditoria->expects(self::once())->method('registrar')
            ->with($this->eu, $this->tenant, $this->pasta, TipoDeItemEncaminhado::RegistroDados, 31, self::INDIVIDUAL, PrioridadeDoDespacho::Urgente);
        $registro = $this->registro('<p>Cobrar a guia &#64;&#91;Zé](user:3)</p>');

        $saida = $this->montar([1, 2, 3], avisos: $avisos, auditoria: $auditoria, registro: $registro)
            ->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::RegistroDados, 31, usuarioId: 2, instrucao: '  favor concluir até sexta  ', prioridade: 'urgente'));

        self::assertSame(self::INDIVIDUAL, $saida->conversaId);
        self::assertSame('Bruno Reis', $saida->destinoNome);
        self::assertSame(
            ["🔴 URGENTE · Despacho: Registro de Carla Dias · {$registro->getCriadaEm()->format('d/m/Y H:i')}\nCobrar a guia @ [Zé](user:3)\n\nfavor concluir até sexta"],
            $this->enviadas,
            'o "@[" que veio do registro chega desarmado: não vira menção',
        );
    }

    #[TestDox('Normal, sem instrução: só "Despacho: TÍTULO", sem prefixo e sem aviso no sino')]
    public function testNormalSemInstrucao(): void
    {
        $avisos = $this->createMock(NotificacaoService::class);
        $avisos->expects(self::never())->method('criar');

        $this->montar([1, 2], avisos: $avisos)->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, usuarioId: 2));

        self::assertSame(['Despacho: Pasta PASTA-50'], $this->enviadas);
    }

    #[TestDox('Para hoje: só o prefixo, sem aviso; sem resumo, a instrução vem depois de uma linha em branco')]
    public function testParaHoje(): void
    {
        $avisos = $this->createMock(NotificacaoService::class);
        $avisos->expects(self::never())->method('criar');

        $this->montar([1, 2], avisos: $avisos)
            ->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, usuarioId: 2, instrucao: 'ver hoje', prioridade: 'para_hoje'));

        self::assertSame(["🟠 PARA HOJE · Despacho: Pasta PASTA-50\n\nver hoje"], $this->enviadas);
    }

    #[TestDox('Colega que não vê a pasta: recusa com o motivo, nada é gravado nem auditado')]
    public function testColegaQueNaoVe(): void
    {
        $auditoria = $this->createMock(AuditoriaDoDespacho::class);
        $auditoria->expects(self::never())->method('registrar');

        try {
            $this->montar([1], auditoria: $auditoria)->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, usuarioId: 2));
            self::fail('devia recusar');
        } catch (PedidoInvalidoNaCentralException $e) {
            self::assertSame('Este colega não vê esta pasta.', $e->getMessage());
        }
        self::assertSame([], $this->enviadas);
    }

    #[TestDox('Grupo com um membro que não vê a pasta: o grupo inteiro é recusado, com o motivo')]
    public function testGrupoComMembroQueNaoVe(): void
    {
        try {
            $this->montar([1, 2])->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, conversaId: self::GRUPO));
            self::fail('devia recusar');
        } catch (PedidoInvalidoNaCentralException $e) {
            self::assertSame('Nem todos deste grupo veem esta pasta.', $e->getMessage());
        }
        self::assertSame([], $this->enviadas);
    }

    #[TestDox('Grupo urgente: um aviso para cada membro, menos quem despachou; o destino é o nome do grupo')]
    public function testGrupoUrgenteAvisaCadaMembro(): void
    {
        $avisados = [];
        $avisos = $this->createMock(NotificacaoService::class);
        $avisos->expects(self::exactly(2))->method('criar')->willReturnCallback(function (User $u) use (&$avisados): Notificacao {
            $avisados[] = $u->getId();

            return new Notificacao();
        });

        $saida = $this->montar([1, 2, 3], avisos: $avisos)
            ->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, conversaId: self::GRUPO, prioridade: 'urgente'));

        self::assertSame([2, 3], $avisados);
        self::assertSame('Equipe', $saida->destinoNome);
        self::assertSame(self::GRUPO, $saida->conversaId);
    }

    #[TestDox('Membro do grupo que saiu do escritório não conta: nem trava o despacho, nem ganha aviso')]
    public function testMembroQueSaiuDoEscritorio(): void
    {
        $avisos = $this->createMock(NotificacaoService::class);
        $avisos->expects(self::once())->method('criar')->with($this->bruno)->willReturn(new Notificacao());

        $this->montar([1, 2], ativos: [$this->eu, $this->bruno], avisos: $avisos)
            ->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, conversaId: self::GRUPO, prioridade: 'urgente'));

        self::assertCount(1, $this->enviadas);
    }

    #[TestDox('Conversa de que não participo é 404; pasta que eu não vejo é 404')]
    public function testConversaAlheiaEPastaQueNaoVejo(): void
    {
        try {
            $this->montar([1, 2])->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, conversaId: 99));
            self::fail('conversa alheia devia ser 404');
        } catch (ConversaNaoEncontradaException) {
        }

        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $this->montar([2])->executar($this->eu, $this->tenant, $this->pedido(TipoDeItemEncaminhado::Pasta, null, usuarioId: 2));
    }

    #[TestDox('Destino: um colega OU uma conversa; os dois juntos ou nenhum é recusado')]
    public function testDestinoExclusivo(): void
    {
        foreach ([[2, self::GRUPO], [null, null]] as [$usuarioId, $conversaId]) {
            try {
                new EncaminharItemInput(50, TipoDeItemEncaminhado::Pasta, null, $usuarioId, $conversaId, null, PrioridadeDoDespacho::Normal);
                self::fail('devia recusar o destino');
            } catch (PedidoInvalidoNaCentralException $e) {
                self::assertSame('Escolha um destino: um colega ou uma conversa.', $e->getMessage());
            }
        }
    }

    #[TestDox('Pedido: instrução aparada e com teto de 1000; tipo, prioridade e id tortos são recusados')]
    public function testValidacaoDoPedido(): void
    {
        $pedido = EncaminharItemInput::fromArray(['pastaId' => 50, 'tipo' => 'push', 'itemId' => 8, 'usuarioId' => 2, 'instrucao' => '   ']);
        self::assertNull($pedido->instrucao, 'só brancos vira sem instrução');
        self::assertSame(PrioridadeDoDespacho::Normal, $pedido->prioridade, 'sem prioridade, normal');
        self::assertSame(TipoDeItemEncaminhado::Push, $pedido->tipo);

        $base = ['pastaId' => 50, 'tipo' => 'pasta', 'usuarioId' => 2];
        $tortos = [
            [...$base, 'instrucao' => str_repeat('x', 1001)],
            [...$base, 'tipo' => 'outro'],
            [...$base, 'prioridade' => 'agora'],
            [...$base, 'pastaId' => '50'],
            [...$base, 'itemId' => -1],
            [...$base, 'instrucao' => ['x']],
        ];
        foreach ($tortos as $dados) {
            try {
                EncaminharItemInput::fromArray($dados);
                self::fail('devia recusar ' . json_encode($dados));
            } catch (PedidoInvalidoNaCentralException) {
            }
        }
        self::assertSame(1000, mb_strlen((string) EncaminharItemInput::fromArray([...$base, 'instrucao' => str_repeat('é', 1000)])->instrucao));
    }

    private function pedido(
        TipoDeItemEncaminhado $tipo,
        ?int $itemId,
        ?int $usuarioId = null,
        ?int $conversaId = null,
        ?string $instrucao = null,
        string $prioridade = 'normal',
    ): EncaminharItemInput {
        return new EncaminharItemInput(50, $tipo, $itemId, $usuarioId, $conversaId, $instrucao, PrioridadeDoDespacho::from($prioridade));
    }

    private function registro(string $conteudo): PastaMensagem
    {
        return (new PastaMensagem())->setTenant($this->tenant)->setPasta($this->pasta)->setAutor($this->carla)->setConteudo($conteudo);
    }

    /**
     * @param list<int>       $quemVe ids de quem vê a pasta
     * @param list<User>|null $ativos quem tem vínculo ativo (padrão: os três)
     */
    private function montar(
        array $quemVe,
        ?array $ativos = null,
        ?NotificacaoService $avisos = null,
        ?AuditoriaDoDespacho $auditoria = null,
        ?PastaMensagem $registro = null,
    ): EncaminharItemDaPastaUseCase {
        $participantes = $this->participantes();
        $acesso = $this->acessoACentral($ativos ?? [$this->eu, $this->bruno, $this->carla], $participantes);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturnCallback(fn (int $id): ?User => [1 => $this->eu, 2 => $this->bruno, 3 => $this->carla][$id] ?? null);
        $registros = $this->createStub(PastaMensagemRepository::class);
        $registros->method('findByIdAndPastaAndTenant')->willReturn($registro);

        return new EncaminharItemDaPastaUseCase(
            $acesso,
            $this->guarda($this->pasta, $this->acessoAPasta($quemVe), $participantes),
            $this->itens($registros),
            $usuarios,
            $this->abrir($acesso, $usuarios, $participantes),
            $this->enviar($acesso),
            new AvisoDeDespachoUrgente($avisos ?? $this->createStub(NotificacaoService::class), $this->urls()),
            $auditoria ?? $this->createStub(AuditoriaDoDespacho::class),
        );
    }

    private function participantes(): ParticipanteRepository
    {
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('daConversa')->willReturnCallback(fn (int $id, User $u): ?Participante => isset($this->conversas[$id]) && in_array($u, $this->membros[$id], true)
            ? new Participante($this->tenant, $this->conversas[$id], $u, new \DateTimeImmutable())
            : null);
        $participantes->method('daConversaInteira')->willReturnCallback(
            fn (Conversa $c): array => $this->participacoes($this->tenant, $c, $this->membros[(int) $c->getId()]),
        );

        return $participantes;
    }

    private function abrir(AcessoACentral $acesso, UserRepository $usuarios, ParticipanteRepository $participantes): AbrirConversaUseCase
    {
        $conversas = $this->createStub(ConversaRepository::class);
        $conversas->method('individualEntre')->willReturn($this->conversas[self::INDIVIDUAL]);

        return new AbrirConversaUseCase($acesso, $usuarios, $conversas, $participantes, $this->createStub(EntityManagerInterface::class), $this->relogio());
    }

    private function enviar(AcessoACentral $acesso): EnviarMensagemUseCase
    {
        $mensagens = $this->createStub(MensagemRepository::class);
        $mensagens->method('salvar')->willReturnCallback(function (Mensagem $m): void {
            $this->enviadas[] = $m->getConteudo();
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f): mixed => $f($em));
        $aviso = new AvisoDeMencaoNaCentral(
            $this->createStub(NotificacaoService::class),
            $this->createStub(NotificacaoRepository::class),
            $this->urls(),
            $this->createStub(TravaDosAvisosDeMencao::class),
        );

        return new EnviarMensagemUseCase($acesso, $mensagens, $em, $this->relogio(), new MencoesDaCentral(), $aviso);
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p): string => '/central?conversa=' . ($p['conversa'] ?? ''));

        return $urls;
    }

    private function relogio(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-19 10:00'));

        return $clock;
    }
}
