<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Central\Service\MencoesDaCentral;
use App\Central\UseCase\AbrirConversaUseCase;
use App\Central\UseCase\EnviarMensagemUseCase;
use App\Central\UseCase\MarcarConversaComoLidaUseCase;
use App\Central\UseCase\MarcarConversaComoNaoLidaUseCase;
use App\Central\UseCase\ObterNovidadesDaCentralUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Repository\UserTenantRepository;
use App\Tests\Inteligencia\Support\DefineId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Regras dos UseCases da Central (A1 e A2) que o teste HTTP não isola. */
#[CoversClass(AbrirConversaUseCase::class)]
#[CoversClass(MarcarConversaComoNaoLidaUseCase::class)]
#[CoversClass(EnviarMensagemUseCase::class)]
#[CoversClass(MarcarConversaComoLidaUseCase::class)]
#[CoversClass(ObterNovidadesDaCentralUseCase::class)]
final class UseCasesDaCentralTest extends TestCase
{
    private function usuario(int $id): User
    {
        $u = new User();
        $u->setFullName('Pessoa ' . $id);
        DefineId::em($u, $id);

        return $u;
    }

    /** @param \Closure(User, Tenant): bool $vinculo */
    private function acesso(\Closure $vinculo, ?ParticipanteRepository $participantes = null): AcessoACentral
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturnCallback($vinculo);

        return new AcessoACentral($vinculos, $participantes ?? $this->createStub(ParticipanteRepository::class), $this->createStub(UserRepository::class));
    }

    private function aviso(?NotificacaoService $notificacoes = null): AvisoDeMencaoNaCentral
    {
        return new AvisoDeMencaoNaCentral(
            $notificacoes ?? $this->createStub(NotificacaoService::class),
            $this->createStub(NotificacaoRepository::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(TravaDosAvisosDeMencao::class),
        );
    }

    private function relogio(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-14 10:00'));

        return $clock;
    }

    #[TestDox('Abrir: destinatário sem vínculo neste escritório é recusado, sem gravar nada')]
    public function testAbrirDestinatarioDeFora(): void
    {
        $tenant = new Tenant();
        $eu = $this->usuario(1);
        $estranho = $this->usuario(2);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturn($estranho);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $useCase = new AbrirConversaUseCase(
            $this->acesso(static fn (User $u): bool => $u === $eu),
            $usuarios, $this->createStub(ConversaRepository::class), $this->createStub(ParticipanteRepository::class), $em, $this->relogio(),
        );

        $this->expectException(PedidoInvalidoNaCentralException::class);
        $useCase->executar($eu, $tenant, 2);
    }

    #[TestDox('Abrir: conversa que já existe é devolvida, sem criar outra')]
    public function testAbrirIdempotente(): void
    {
        $tenant = new Tenant();
        [$eu, $outro] = [$this->usuario(1), $this->usuario(2)];
        $existente = Conversa::individual($tenant, $outro, $eu, new \DateTimeImmutable());
        DefineId::em($existente, 40);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturn($outro);
        $conversas = $this->createMock(ConversaRepository::class);
        $conversas->method('individualEntre')->with($tenant, $eu, $outro)->willReturn($existente);
        $conversas->expects(self::never())->method('salvar');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $saida = (new AbrirConversaUseCase(
            $this->acesso(static fn (): bool => true), $usuarios, $conversas, $this->createStub(ParticipanteRepository::class), $em, $this->relogio(),
        ))->executar($eu, $tenant, 2);

        self::assertSame(40, $saida->id);
        self::assertSame(2, $saida->outro->id);
    }

    #[TestDox('Abrir: conversa nova grava a conversa e as DUAS participações numa transação só')]
    public function testAbrirCriaComDoisParticipantes(): void
    {
        $tenant = new Tenant();
        [$eu, $outro] = [$this->usuario(1), $this->usuario(2)];
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturn($outro);
        $conversas = $this->createMock(ConversaRepository::class);
        $conversas->method('individualEntre')->willReturn(null);
        $conversas->expects(self::once())->method('salvar')->willReturnCallback(static fn (Conversa $c) => DefineId::em($c, 41));
        $gravados = [];
        $participantes = $this->createMock(ParticipanteRepository::class);
        $participantes->expects(self::exactly(2))->method('salvar')->willReturnCallback(function (Participante $p) use (&$gravados): void {
            $gravados[] = $p->getUsuario()->getId();
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new AbrirConversaUseCase($this->acesso(static fn (): bool => true), $usuarios, $conversas, $participantes, $em, $this->relogio()))
            ->executar($eu, $tenant, 2);

        sort($gravados);
        self::assertSame([1, 2], $gravados);
    }

    #[TestDox('Abrir: a corrida dos dois lados vira "tente de novo", não erro 500')]
    public function testAbrirCorrida(): void
    {
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturn($this->usuario(2));
        $conversas = $this->createStub(ConversaRepository::class);
        $conversas->method('individualEntre')->willReturn(null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        $this->expectException(ConversaCriadaAoMesmoTempoException::class);

        (new AbrirConversaUseCase($this->acesso(static fn (): bool => true), $usuarios, $conversas, $this->createStub(ParticipanteRepository::class), $em, $this->relogio()))
            ->executar($this->usuario(1), new Tenant(), 2);
    }

    #[TestDox('Enviar: colega que saiu não recebe mensagem nova, e nada é gravado')]
    public function testEnviarParaQuemSaiu(): void
    {
        $tenant = new Tenant();
        [$eu, $saiu] = [$this->usuario(1), $this->usuario(2)];
        $conversa = Conversa::individual($tenant, $eu, $saiu, new \DateTimeImmutable());
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('daConversa')->willReturn(new Participante($tenant, $conversa, $eu, new \DateTimeImmutable()));
        $mensagens = $this->createMock(MensagemRepository::class);
        $mensagens->expects(self::never())->method('salvar');

        $this->expectException(PedidoInvalidoNaCentralException::class);

        (new EnviarMensagemUseCase(
            $this->acesso(static fn (User $u): bool => $u === $eu, $participantes), $mensagens, $this->createStub(EntityManagerInterface::class), $this->relogio(),
            new MencoesDaCentral(), $this->aviso(),
        ))->executar($eu, $tenant, 9, 'oi');
    }

    #[TestDox('Enviar: responder mensagem que não é desta conversa é 422, e nada é gravado nem notificado')]
    public function testEnviarRespostaDeOutraConversa(): void
    {
        $tenant = new Tenant();
        [$eu, $outro] = [$this->usuario(1), $this->usuario(2)];
        $conversa = Conversa::individual($tenant, $eu, $outro, new \DateTimeImmutable());
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('daConversa')->willReturn(new Participante($tenant, $conversa, $eu, new \DateTimeImmutable()));
        $mensagens = $this->createMock(MensagemRepository::class);
        $mensagens->method('daConversa')->willReturn(null);
        $mensagens->expects(self::never())->method('salvar');
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::never())->method('criar');

        $this->expectException(PedidoInvalidoNaCentralException::class);

        (new EnviarMensagemUseCase(
            $this->acesso(static fn (): bool => true, $participantes), $mensagens, $this->createStub(EntityManagerInterface::class), $this->relogio(),
            new MencoesDaCentral(), $this->aviso($notificacoes),
        ))->executar($eu, $tenant, 9, 'oi @[Pessoa 2](user:2)', 555);
    }

    #[TestDox('Marcar como não lida: liga a marca do participante sem mexer no cursor')]
    public function testMarcarNaoLida(): void
    {
        $tenant = new Tenant();
        [$eu, $outro] = [$this->usuario(1), $this->usuario(2)];
        $participacao = new Participante($tenant, Conversa::individual($tenant, $eu, $outro, new \DateTimeImmutable()), $eu, new \DateTimeImmutable());
        $participacao->marcarLidaAte(30);
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('daConversa')->willReturn($participacao);
        $mensagens = $this->createStub(MensagemRepository::class);
        $mensagens->method('ultimoIdDa')->willReturn(30);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new MarcarConversaComoNaoLidaUseCase($this->acesso(static fn (): bool => true, $participantes), $mensagens, $em))->executar($eu, $tenant, 9);

        self::assertTrue($participacao->isMarcadaNaoLida());
        self::assertSame(30, $participacao->getUltimaLidaId());
    }

    #[TestDox('Marcar como não lida: conversa sem mensagem nenhuma é 422, sem gravar')]
    public function testMarcarNaoLidaSemMensagem(): void
    {
        $tenant = new Tenant();
        [$eu, $outro] = [$this->usuario(1), $this->usuario(2)];
        $participacao = new Participante($tenant, Conversa::individual($tenant, $eu, $outro, new \DateTimeImmutable()), $eu, new \DateTimeImmutable());
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('daConversa')->willReturn($participacao);
        $mensagens = $this->createStub(MensagemRepository::class);
        $mensagens->method('ultimoIdDa')->willReturn(0);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        try {
            (new MarcarConversaComoNaoLidaUseCase($this->acesso(static fn (): bool => true, $participantes), $mensagens, $em))->executar($eu, $tenant, 9);
            self::fail('Devia recusar a conversa vazia.');
        } catch (PedidoInvalidoNaCentralException) {
            self::assertFalse($participacao->isMarcadaNaoLida());
        }
    }

    #[TestDox('Marcar como lida: o alvo é limitado à última mensagem e o avanço é feito no banco (não no objeto)')]
    public function testMarcarLidaTeto(): void
    {
        $tenant = new Tenant();
        [$eu, $outro] = [$this->usuario(1), $this->usuario(2)];
        $conversa = Conversa::individual($tenant, $eu, $outro, new \DateTimeImmutable());
        $participacao = new Participante($tenant, $conversa, $eu, new \DateTimeImmutable());
        $participantes = $this->createMock(ParticipanteRepository::class);
        $participantes->method('daConversa')->willReturn($participacao);
        $participantes->expects(self::once())->method('avancarLeitura')->with($participacao, 15)->willReturn(15);
        $participantes->expects(self::once())->method('desmarcarNaoLida')->with($participacao);
        $mensagens = $this->createStub(MensagemRepository::class);
        $mensagens->method('ultimoIdDa')->willReturn(15);

        $cursor = (new MarcarConversaComoLidaUseCase($this->acesso(static fn (): bool => true, $participantes), $mensagens, $participantes, $this->aviso()))
            ->executar($eu, $tenant, 9, 9999);

        self::assertSame(15, $cursor);
    }

    #[TestDox('Novidades: a primeira chamada (sem desde) não despeja histórico, só dá o cursor')]
    public function testNovidadesPrimeiraChamada(): void
    {
        $mensagens = $this->createMock(MensagemRepository::class);
        $mensagens->expects(self::never())->method('novidades');
        $mensagens->method('ultimoIdVisivelPara')->willReturn(77);
        $mensagens->method('naoLidasPorConversa')->willReturn([3 => 2, 5 => 1]);

        $saida = (new ObterNovidadesDaCentralUseCase($this->acesso(static fn (): bool => true), $mensagens, $this->relogio()))
            ->executar($this->usuario(1), new Tenant(), null);

        self::assertSame([], $saida->mensagens);
        self::assertSame(77, $saida->cursor);
        self::assertSame(3, $saida->naoLidas);
    }

    #[TestDox('Novidades: desde=0 é cursor de verdade — quem não tinha mensagem recebe a primeira')]
    public function testNovidadesDesdeZero(): void
    {
        $tenant = new Tenant();
        $eu = $this->usuario(1);
        $mensagens = $this->createMock(MensagemRepository::class);
        $mensagens->expects(self::once())->method('novidades')->with($eu, $tenant, 0, ObterNovidadesDaCentralUseCase::TETO, new \DateTimeImmutable('2026-10-14 09:59:30'))->willReturn([]);
        $mensagens->expects(self::never())->method('ultimoIdVisivelPara');
        $mensagens->method('naoLidasPorConversa')->willReturn([]);

        $saida = (new ObterNovidadesDaCentralUseCase($this->acesso(static fn (): bool => true), $mensagens, $this->relogio()))->executar($eu, $tenant, 0);

        self::assertSame(0, $saida->cursor);
        self::assertTrue($saida->completo);
    }
}
