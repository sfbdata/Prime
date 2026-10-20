<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\Cadeado;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ConfigurarCadeadoInput;
use App\Pasta\DTO\PedirAcessoInput;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\EstadoDoPedidoDeAcesso;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use App\Pasta\Exception\CadeadoRecusadoException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PedidoDeAcessoAPastaRepository;
use App\Pasta\Service\AvisosDoCadeado;
use App\Pasta\Service\EquipeDoCadeado;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\AlternarTrancaDaPastaUseCase;
use App\Pasta\UseCase\ConfigurarCadeadoDaPastaUseCase;
use App\Pasta\UseCase\DecidirPedidoDeAcessoUseCase;
use App\Pasta\UseCase\PedirAcessoAPastaUseCase;
use App\Pasta\UseCase\RemoverCadeadoDaPastaUseCase;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

#[CoversClass(ConfigurarCadeadoDaPastaUseCase::class)]
#[CoversClass(RemoverCadeadoDaPastaUseCase::class)]
#[CoversClass(PedirAcessoAPastaUseCase::class)]
#[CoversClass(DecidirPedidoDeAcessoUseCase::class)]
#[CoversClass(AlternarTrancaDaPastaUseCase::class)]
#[CoversClass(AvisosDoCadeado::class)]
final class UseCasesDoCadeadoTest extends TestCase
{
    use CriaCenarioDoCadeadoTrait;

    private User $primario;
    private User $ana;
    private User $bia;
    private Pasta $pastaDoCaso;
    private EntityManagerInterface&MockObject $em;
    private PedidoDeAcessoAPastaRepository&MockObject $pedidos;
    private NotificacaoService&MockObject $notificacoes;
    private MockClock $relogio;

    /** @var list<PastaMensagem> cartões do sistema escritos no Registro da pasta */
    private array $historico = [];

    /** @var list<PedidoDeAcessoAPasta> */
    private array $pedidosGravados = [];

    /** @var list<array{0: User, 1: string, 2: string}> [destinatário, tipo, título] */
    private array $avisos = [];

    protected function setUp(): void
    {
        $this->montarCenario();
        $this->primario    = $this->pessoa('Paula Primária', primario: true);
        $this->ana         = $this->pessoa('Ana Equipe');
        $this->bia         = $this->pessoa('Bia Equipe');
        $this->pastaDoCaso = $this->pasta('1500');
        $this->relogio     = new MockClock('2026-10-20 10:00:00');

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof PastaMensagem) {
                $this->historico[] = $o;
            }
        });

        $this->pedidos = $this->createMock(PedidoDeAcessoAPastaRepository::class);
        $this->pedidos->method('salvar')->willReturnCallback(function (PedidoDeAcessoAPasta $p): void {
            if (!in_array($p, $this->pedidosGravados, true)) {
                $this->pedidosGravados[] = $this->comId($p);
            }
        });
        $this->pedidos->method('pendenteDe')->willReturnCallback(fn (Pasta $pa, User $u): ?PedidoDeAcessoAPasta => $this->achar(static fn (PedidoDeAcessoAPasta $p): bool => $p->getSolicitante() === $u && $p->estaPendente()));
        $this->pedidos->method('reservarDecisao')->willReturnCallback(static fn (PedidoDeAcessoAPasta $p): bool => $p->estaPendente());
        $this->pedidos->method('ultimoDe')->willReturnCallback(fn (Pasta $pa, User $u): ?PedidoDeAcessoAPasta => $this->achar(static fn (PedidoDeAcessoAPasta $p): bool => $p->getSolicitante() === $u && !$p->estaPendente())
            ?? $this->achar(static fn (PedidoDeAcessoAPasta $p): bool => $p->getSolicitante() === $u));
        $this->pedidos->method('daPasta')->willReturnCallback(fn (int $id): ?PedidoDeAcessoAPasta => $this->achar(static fn (PedidoDeAcessoAPasta $p): bool => $p->getId() === $id));

        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->notificacoes->method('criarNotificacao')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo): Notificacao {
            $this->avisos[] = [$u, $tipo, $titulo];

            return new Notificacao();
        });

        // Quem é da equipe veria a pasta sem o cadeado (resources.pasta.view).
        $this->permissionChecker->method('canAccessResourceIgnoringPastaLock')->willReturn(true);
    }

    /** @param callable(PedidoDeAcessoAPasta): bool $criterio */
    private function achar(callable $criterio): ?PedidoDeAcessoAPasta
    {
        foreach ($this->pedidosGravados as $p) {
            if ($criterio($p)) {
                return $p;
            }
        }

        return null;
    }

    private function configurar(): ConfigurarCadeadoDaPastaUseCase
    {
        return new ConfigurarCadeadoDaPastaUseCase($this->cadeadoRepository, $this->acessoAPasta(), new EquipeDoCadeado($this->userTenantRepository, $this->regraDosPrimarios()), new RegistroDoSistemaNaPasta($this->em), $this->em, $this->relogio);
    }

    private function remover(): RemoverCadeadoDaPastaUseCase
    {
        return new RemoverCadeadoDaPastaUseCase($this->cadeadoRepository, $this->acessoAPasta(), new RegistroDoSistemaNaPasta($this->em), $this->em);
    }

    private function avisosDoCadeado(): AvisosDoCadeado
    {
        $rotas = new RouteCollection();
        $rotas->add('pasta_show', new Route('/pasta/{id}'));

        return new AvisosDoCadeado($this->userTenantRepository, $this->regraDosPrimarios(), $this->notificacoes, new UrlGenerator($rotas, new RequestContext()));
    }

    private function pedir(): PedirAcessoAPastaUseCase
    {
        return new PedirAcessoAPastaUseCase($this->pedidos, $this->acessoAPasta(), $this->avisosDoCadeado(), $this->relogio);
    }

    private function decidir(): DecidirPedidoDeAcessoUseCase
    {
        return new DecidirPedidoDeAcessoUseCase($this->pedidos, $this->cadeadoRepository, $this->acessoAPasta(), new EquipeDoCadeado($this->userTenantRepository, $this->regraDosPrimarios()), $this->avisosDoCadeado(), new RegistroDoSistemaNaPasta($this->em), $this->em, $this->relogio);
    }

    private function trancarParaTodos(): CadeadoDaPasta
    {
        return $this->gravarCadeado(new CadeadoDaPasta($this->tenant, $this->pastaDoCaso, ModoDoCadeado::Todos, $this->primario, $this->relogio->now()));
    }

    private function ultimoHistorico(): string
    {
        return strip_tags((string) end($this->historico)->getConteudo());
    }

    // ── Configurar ─────────────────────────────────────────────────────

    #[TestDox('configurar por pessoa: grava a grade, devolve o resumo e escreve no histórico')]
    public function testConfigurarPorPessoa(): void
    {
        $this->em->expects(self::once())->method('flush');

        $saida = $this->configurar()->executar(new ConfigurarCadeadoInput($this->pastaDoCaso, $this->primario, $this->tenant, ModoDoCadeado::PorPessoa, [
            (int) $this->ana->getId() => NivelDeAcesso::Editar,
            (int) $this->bia->getId() => NivelDeAcesso::Oculto,
        ]));

        self::assertSame('Restrito', $saida->selo);
        self::assertSame('1 editam, 0 só visualizam, 1 não veem. O usuário primário sempre vê e edita tudo.', $saida->resumo());
        self::assertSame(NivelDeAcesso::Editar, $this->cadeadosGravados[$this->pastaDoCaso->getId()]->nivelDe($this->ana));
        self::assertSame('Cadeado: Cadeado ativado (1 editam, 0 visualizam, 1 ocultos)', $this->ultimoHistorico());
    }

    #[TestDox('configurar de novo altera a regra do mesmo cadeado ("Cadeado alterado")')]
    public function testConfigurarDeNovo(): void
    {
        $antes = $this->trancarParaTodos();

        $saida = $this->configurar()->executar(new ConfigurarCadeadoInput($this->pastaDoCaso, $this->primario, $this->tenant, ModoDoCadeado::PorPessoa, [(int) $this->ana->getId() => NivelDeAcesso::Visualizar]));

        self::assertSame($antes, $this->cadeadosGravados[$this->pastaDoCaso->getId()]);
        self::assertSame(1, $saida->visualizam);
        self::assertStringStartsWith('Cadeado: Cadeado alterado', $this->ultimoHistorico());
    }

    #[TestDox('quem não configura (secundário) é recusado e nada é gravado')]
    public function testConfigurarSemSerPrimario(): void
    {
        $this->em->expects(self::never())->method('flush');
        $this->expectException(CadeadoRecusadoException::class);

        $this->configurar()->executar(new ConfigurarCadeadoInput($this->pastaDoCaso, $this->ana, $this->tenant, ModoDoCadeado::Todos));
    }

    #[TestDox('pasta de outro escritório: não encontrada, antes de qualquer outra regra')]
    public function testConfigurarOutroEscritorio(): void
    {
        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->configurar()->executar(new ConfigurarCadeadoInput($this->pastaDoCaso, $this->primario, $this->comId(new Tenant()), ModoDoCadeado::Todos));
    }

    #[TestDox('a grade não guarda nível de quem não é da equipe (o próprio primário, por exemplo)')]
    public function testConfigurarComPessoaForaDaEquipe(): void
    {
        $this->expectException(\DomainException::class);

        $this->configurar()->executar(new ConfigurarCadeadoInput($this->pastaDoCaso, $this->primario, $this->tenant, ModoDoCadeado::PorPessoa, [(int) $this->primario->getId() => NivelDeAcesso::Editar]));
    }

    // ── Remover e alternar (a tranca da Lista) ─────────────────────────

    #[TestDox('remover apaga o cadeado e escreve "Cadeado removido"; sem cadeado, é recusado')]
    public function testRemover(): void
    {
        $this->trancarParaTodos();

        self::assertFalse($this->remover()->executar($this->pastaDoCaso, $this->primario, $this->tenant)->temCadeado);
        self::assertArrayNotHasKey($this->pastaDoCaso->getId(), $this->cadeadosGravados);
        self::assertSame('Cadeado: Cadeado removido', $this->ultimoHistorico());

        $this->expectException(\DomainException::class);
        $this->remover()->executar($this->pastaDoCaso, $this->primario, $this->tenant);
    }

    #[TestDox('a tranca da Lista alterna entre livre e "Ocultar para todos"')]
    public function testAlternarTranca(): void
    {
        $alternar = fn (): AlternarTrancaDaPastaUseCase => new AlternarTrancaDaPastaUseCase($this->acessoAPasta(), $this->configurar(), $this->remover());

        self::assertSame('todos', $alternar()->executar($this->pastaDoCaso, $this->primario, $this->tenant)->modo);
        self::assertFalse($alternar()->executar($this->pastaDoCaso, $this->primario, $this->tenant)->temCadeado);
    }

    // ── Pedir ──────────────────────────────────────────────────────────

    #[TestDox('pedir: grava o pendente e avisa cada primário ativo, com o nível e o motivo')]
    public function testPedir(): void
    {
        $this->trancarParaTodos();
        $outroPrimario = $this->pessoa('Otávio Primário', primario: true);

        $saida = $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, 'preciso do contrato'));

        self::assertSame(2, $saida->avisados);
        self::assertSame('10:00', $saida->hora);
        self::assertCount(1, $this->pedidosGravados);
        self::assertSame([$this->primario, $outroPrimario], array_map(static fn (array $a): User => $a[0], $this->avisos));
        self::assertSame(Notificacao::TIPO_PASTA_PEDIDO_ACESSO, $this->avisos[0][1]);
        self::assertSame('Ana Equipe pediu acesso à Pasta 1500 às 10:00, para editar.', $this->avisos[0][2]);
    }

    #[TestDox('pedir de novo dentro de 24 h é recusado; depois de 24 h renova o mesmo pendente')]
    public function testPedidoDuplicadoEJanela(): void
    {
        $this->trancarParaTodos();
        $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));

        $this->relogio->modify('+23 hours');
        try {
            $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
            self::fail('aceitou o pedido duplicado');
        } catch (\DomainException $e) {
            self::assertSame('Você já pediu acesso a esta pasta hoje. Aguarde o usuário primário.', $e->getMessage());
        }

        $this->relogio->modify('+2 hours');
        $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Visualizar, null));
        self::assertCount(1, $this->pedidosGravados, 'um pendente por pasta e pessoa');
        self::assertSame(NivelDeAcesso::Visualizar, $this->pedidosGravados[0]->getNivelPedido());
    }

    #[TestDox('pedir: pasta sem cadeado, quem já edita e quem nem veria a pasta são recusados')]
    public function testPedirRecusas(): void
    {
        try {
            $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
            self::fail('pediu em pasta livre');
        } catch (\DomainException $e) {
            self::assertSame('Esta pasta não tem cadeado.', $e->getMessage());
        }

        $this->trancarParaTodos();
        try {
            $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->primario, $this->tenant, NivelDeAcesso::Editar, null));
            self::fail('o primário pediu acesso');
        } catch (\DomainException $e) {
            self::assertSame('Você já tem esse acesso a esta pasta.', $e->getMessage());
        }

        $semPermissao = $this->createMock(\App\Service\PermissionChecker::class);
        $semPermissao->method('canAccessResourceIgnoringPastaLock')->willReturn(false);
        $this->permissionChecker = $semPermissao;
        $this->expectException(SemPermissaoParaVerPastaException::class);
        $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
    }

    // ── Decidir ────────────────────────────────────────────────────────

    #[TestDox('liberar com o cadeado em Todos converte para PorPessoa, avisa quem pediu e escreve no histórico')]
    public function testLiberarConverteTodos(): void
    {
        $this->trancarParaTodos();
        $saida = $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
        $this->avisos = [];

        $estado = $this->decidir()->executar($saida->id, $this->pastaDoCaso, $this->primario, $this->tenant, DecidirPedidoDeAcessoUseCase::LIBERAR_VISUALIZAR);

        $cadeado = $this->cadeadosGravados[$this->pastaDoCaso->getId()];
        self::assertSame(ModoDoCadeado::PorPessoa, $cadeado->getModo());
        self::assertSame(NivelDeAcesso::Visualizar, $cadeado->nivelDe($this->ana));
        self::assertSame(NivelDeAcesso::Oculto, $cadeado->nivelDe($this->bia));
        self::assertSame(1, $estado->visualizam);
        self::assertSame(EstadoDoPedidoDeAcesso::LiberadoVisualizar, $this->pedidosGravados[0]->getEstado());
        self::assertSame([[$this->ana, Notificacao::TIPO_PASTA_ACESSO_DECIDIDO, 'O usuário primário liberou a Pasta 1500 para você visualizar.']], $this->avisos);
        self::assertSame('Cadeado: Acesso liberado para Ana Equipe (visualizar)', $this->ultimoHistorico());
    }

    #[TestDox('recusar fecha o pedido, mantém o cadeado e avisa quem pediu; decidir de novo é recusado')]
    public function testRecusar(): void
    {
        $this->trancarParaTodos();
        $saida = $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
        $this->avisos = [];

        $this->decidir()->executar($saida->id, $this->pastaDoCaso, $this->primario, $this->tenant, DecidirPedidoDeAcessoUseCase::RECUSAR);

        self::assertSame(ModoDoCadeado::Todos, $this->cadeadosGravados[$this->pastaDoCaso->getId()]->getModo());
        self::assertSame('O usuário primário não liberou o acesso à Pasta 1500 por enquanto.', $this->avisos[0][2]);
        self::assertSame('Cadeado: Pedido de acesso de Ana Equipe recusado', $this->ultimoHistorico());

        $this->expectExceptionMessage('Este pedido já foi recusado.');
        $this->decidir()->executar($saida->id, $this->pastaDoCaso, $this->primario, $this->tenant, DecidirPedidoDeAcessoUseCase::LIBERAR_EDITAR);
    }

    #[TestDox('só quem configura decide o pedido')]
    public function testDecidirSemSerPrimario(): void
    {
        $this->trancarParaTodos();
        $saida = $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));

        $this->expectException(CadeadoRecusadoException::class);
        $this->decidir()->executar($saida->id, $this->pastaDoCaso, $this->bia, $this->tenant, DecidirPedidoDeAcessoUseCase::LIBERAR_EDITAR);
    }

    #[TestDox('antispam: depois de recusado, pedido novo só 24 h depois do anterior, com a data no texto')]
    public function testRecusadoSeguraNovoPedidoPor24Horas(): void
    {
        $this->trancarParaTodos();
        $saida = $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
        $this->decidir()->executar($saida->id, $this->pastaDoCaso, $this->primario, $this->tenant, DecidirPedidoDeAcessoUseCase::RECUSAR);

        $this->relogio->modify('+2 hours');
        try {
            $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
            self::fail('pediu de novo logo depois da recusa');
        } catch (\DomainException $e) {
            self::assertSame('O usuário primário não liberou o pedido anterior. Você pode pedir de novo a partir de 21/10 às 10:00.', $e->getMessage());
        }

        $this->relogio->modify('+23 hours');
        $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
        self::assertCount(2, $this->pedidosGravados, 'depois de 24 h, um pedido novo');
    }

    #[TestDox('concorrência: se o banco já não tem o pedido pendente, a decisão não mexe no cadeado e é recusada')]
    public function testDecisaoConcorrente(): void
    {
        $this->trancarParaTodos();
        $saida = $this->pedir()->executar(new PedirAcessoInput($this->pastaDoCaso, $this->ana, $this->tenant, NivelDeAcesso::Editar, null));
        $outroRepo = $this->createMock(PedidoDeAcessoAPastaRepository::class);
        $outroRepo->method('daPasta')->willReturn($this->pedidosGravados[0]);
        $outroRepo->method('reservarDecisao')->willReturn(false);
        $decidir = new DecidirPedidoDeAcessoUseCase($outroRepo, $this->cadeadoRepository, $this->acessoAPasta(), new EquipeDoCadeado($this->userTenantRepository, $this->regraDosPrimarios()), $this->avisosDoCadeado(), new RegistroDoSistemaNaPasta($this->em), $this->em, $this->relogio);

        try {
            $decidir->executar($saida->id, $this->pastaDoCaso, $this->primario, $this->tenant, DecidirPedidoDeAcessoUseCase::LIBERAR_EDITAR);
            self::fail('decidiu duas vezes');
        } catch (\DomainException $e) {
            self::assertSame('Este pedido já foi atendido.', $e->getMessage());
        }
        self::assertSame(ModoDoCadeado::Todos, $this->cadeadosGravados[$this->pastaDoCaso->getId()]->getModo(), 'o cadeado não mudou');
    }
}
