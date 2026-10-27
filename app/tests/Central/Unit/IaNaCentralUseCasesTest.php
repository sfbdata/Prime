<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\EnvioNaConversaComAIaException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\MensagemRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeMencaoNaCentral;
use App\Central\Service\MencoesDaCentral;
use App\Central\UseCase\AbrirConversaComAIaUseCase;
use App\Central\UseCase\ConsultarStatusDaPerguntaDaIaUseCase;
use App\Central\UseCase\EnviarMensagemUseCase;
use App\Central\UseCase\ListarConversasUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tests\Central\Support\MontaLeituraDasMensagensTrait;
use App\Tests\Inteligencia\Support\DefineId;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** O BlueJus IA na Central (F3, PR 3a) nos UseCases da lista, do envio comum e do status da pergunta, sem banco. */
#[CoversClass(ListarConversasUseCase::class)]
#[CoversClass(EnviarMensagemUseCase::class)]
#[CoversClass(ConsultarStatusDaPerguntaDaIaUseCase::class)]
final class IaNaCentralUseCasesTest extends TestCase
{
    use MontaLeituraDasMensagensTrait;

    private Tenant $tenant;
    private User $eu;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->eu = new User();
        $this->eu->setFullName('Ana Souza');
        DefineId::em($this->eu, 9);
    }

    private function acesso(?ParticipanteRepository $participantes = null): AcessoACentral
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturn(true);
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findColaboradoresAtivosPorTenant')->willReturn([$this->eu]);

        return new AcessoACentral($vinculos, $participantes ?? $this->createStub(ParticipanteRepository::class), $usuarios);
    }

    private function relogio(): ClockInterface
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-27 10:00'));

        return $clock;
    }

    private function conversaIa(int $id = 300): Conversa
    {
        $conversa = Conversa::comAIa($this->tenant, $this->eu, new \DateTimeImmutable('2026-10-27 09:00'));
        DefineId::em($conversa, $id);

        return $conversa;
    }

    // ---------------------------------------------------------------- envio comum

    #[TestDox('Enviar mensagem comum na conversa IA: recusa explícita, nada gravado, nada notificado')]
    public function testEnviarNaConversaIaERecusado(): void
    {
        $participacao = new Participante($this->tenant, $this->conversaIa(), $this->eu, new \DateTimeImmutable());
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('daConversa')->willReturn($participacao);
        $mensagens = $this->createMock(MensagemRepository::class);
        $mensagens->expects(self::never())->method('salvar');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('wrapInTransaction');

        $aviso = new AvisoDeMencaoNaCentral(
            $this->createStub(NotificacaoService::class),
            $this->createStub(NotificacaoRepository::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(TravaDosAvisosDeMencao::class),
        );
        $useCase = new EnviarMensagemUseCase($this->acesso($participantes), $mensagens, $em, $this->relogio(), new MencoesDaCentral(), $aviso, $this->leituraDasMensagens());

        $this->expectException(EnvioNaConversaComAIaException::class);

        $useCase->executar($this->eu, $this->tenant, 300, 'oi IA');
    }

    // ---------------------------------------------------------------- lista

    private function listar(ParticipanteRepository $participantes, AbrirConversaComAIaUseCase $abrir): ListarConversasUseCase
    {
        $mensagens = $this->createStub(MensagemRepository::class);
        $mensagens->method('ultimaDeCada')->willReturn([]);
        $mensagens->method('naoLidasPorConversa')->willReturn([300 => 2]);

        return new ListarConversasUseCase($this->acesso(), $participantes, $mensagens, $this->leituraDasMensagens(), $abrir);
    }

    private function abrirQueNaoPodeRodar(): AbrirConversaComAIaUseCase
    {
        $conversas = $this->createMock(ConversaRepository::class);
        $conversas->expects(self::never())->method('daIaDe');
        $conversas->expects(self::never())->method('salvar');

        return new AbrirConversaComAIaUseCase($this->acesso(), $conversas, $this->createStub(ParticipanteRepository::class), $this->createStub(EntityManagerInterface::class), $this->relogio());
    }

    #[TestDox('Lista com a IA já criada: vem como tipo ia, sem "outro", nome BlueJus IA, e não abre outra')]
    public function testListaComAIaExistente(): void
    {
        $ia = $this->conversaIa();
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('doUsuario')->willReturn([new Participante($this->tenant, $ia, $this->eu, new \DateTimeImmutable())]);
        $participantes->method('ativosPorConversa')->willReturn([300 => 1]);

        $lista = $this->listar($participantes, $this->abrirQueNaoPodeRodar())->executar($this->eu, $this->tenant);

        self::assertCount(1, $lista);
        self::assertSame(300, $lista[0]->id);
        self::assertSame('ia', $lista[0]->tipo);
        self::assertTrue($lista[0]->ia);
        self::assertNull($lista[0]->outro);
        self::assertSame('BlueJus IA', $lista[0]->nome);
        self::assertSame(2, $lista[0]->naoLidas);
        self::assertTrue($lista[0]->outroAtivo);
        self::assertFalse($lista[0]->souAdministrador);
    }

    #[TestDox('Lista sem a conversa IA: abre uma (idempotente), busca de novo e devolve com ela')]
    public function testListaCriaAIaQuandoNaoExiste(): void
    {
        $ia = $this->conversaIa();
        $participacaoIa = new Participante($this->tenant, $ia, $this->eu, new \DateTimeImmutable());
        $chamadas = 0;
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('doUsuario')->willReturnCallback(function () use (&$chamadas, $participacaoIa): array {
            return ++$chamadas === 1 ? [] : [$participacaoIa];
        });
        $participantes->method('ativosPorConversa')->willReturn([300 => 1]);

        $conversas = $this->createMock(ConversaRepository::class);
        $conversas->expects(self::once())->method('daIaDe')->willReturn(null);
        $conversas->expects(self::once())->method('salvar');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $abrir = new AbrirConversaComAIaUseCase($this->acesso(), $conversas, $this->createStub(ParticipanteRepository::class), $em, $this->relogio());

        $lista = $this->listar($participantes, $abrir)->executar($this->eu, $this->tenant);

        self::assertSame(2, $chamadas);
        self::assertCount(1, $lista);
        self::assertTrue($lista[0]->ia);
    }

    #[TestDox('Lista: conversa IA limpa ("Excluir") continua na lista, ao contrário da individual')]
    public function testIaLimpaNaoSome(): void
    {
        $ia = $this->conversaIa();
        $participacao = new Participante($this->tenant, $ia, $this->eu, new \DateTimeImmutable());
        (new \ReflectionProperty(Participante::class, 'limpaAteId'))->setValue($participacao, 50);
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('doUsuario')->willReturn([$participacao]);
        $participantes->method('ativosPorConversa')->willReturn([300 => 1]);

        $lista = $this->listar($participantes, $this->abrirQueNaoPodeRodar())->executar($this->eu, $this->tenant);

        self::assertCount(1, $lista);
    }

    #[TestDox('Lista: a corrida da primeira abertura sobe ConversaCriadaAoMesmoTempoException (a rota responde 409 e a tela repete)')]
    public function testCorridaDaPrimeiraAbertura(): void
    {
        $participantes = $this->createStub(ParticipanteRepository::class);
        $participantes->method('doUsuario')->willReturn([]);
        $conversas = $this->createStub(ConversaRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($this->createStub(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class));
        $abrir = new AbrirConversaComAIaUseCase($this->acesso(), $conversas, $this->createStub(ParticipanteRepository::class), $em, $this->relogio());

        $this->expectException(ConversaCriadaAoMesmoTempoException::class);

        $this->listar($participantes, $abrir)->executar($this->eu, $this->tenant);
    }

    // ---------------------------------------------------------------- status da pergunta

    private function analise(?User $solicitante = null, TipoDeAnalise $tipo = TipoDeAnalise::ChatDaCentral): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia($this->tenant, $solicitante ?? $this->eu, $tipo, AnaliseDeInteligencia::ALVO_CONVERSA_IA, 300, 'chat-central-v1', 'h', ['conversaId' => 300, 'mensagemId' => 7, 'historico' => 0, 'total' => 1]);
        DefineId::em($analise, 480);

        return $analise;
    }

    private function casoDeStatus(?Conversa $conversa, ?AnaliseDeInteligencia $analise): ConsultarStatusDaPerguntaDaIaUseCase
    {
        $conversas = $this->createStub(ConversaRepository::class);
        $conversas->method('daIaDe')->willReturn($conversa);
        $analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $analises->method('findOneDoTenantEAlvo')->willReturn($analise);

        return new ConsultarStatusDaPerguntaDaIaUseCase($this->acesso(), $conversas, $analises);
    }

    #[TestDox('Status: pendente é em andamento, não terminal e sem mensagem')]
    public function testStatusPendente(): void
    {
        $saida = $this->casoDeStatus($this->conversaIa(), $this->analise())->executar($this->eu, $this->tenant, 480);

        self::assertSame(480, $saida->analiseId);
        self::assertSame(300, $saida->conversaId);
        self::assertSame('pendente', $saida->status);
        self::assertTrue($saida->emAndamento);
        self::assertFalse($saida->terminal);
        self::assertNull($saida->mensagem);
    }

    #[TestDox('Status: falhou devolve a mensagem FIXA, nunca o motivo técnico')]
    public function testStatusFalhouComMensagemFixa(): void
    {
        $analise = $this->analise();
        $analise->falhar('erro do provedor com detalhe sensível', 'texto bruto');

        $saida = $this->casoDeStatus($this->conversaIa(), $analise)->executar($this->eu, $this->tenant, 480);

        self::assertSame('falhou', $saida->status);
        self::assertTrue($saida->terminal);
        self::assertSame(ConsultarStatusDaPerguntaDaIaUseCase::MENSAGEM_FALHA, $saida->mensagem);
        self::assertStringNotContainsString('sensível', (string) json_encode($saida));
    }

    #[TestDox('Status: indisponível devolve a mensagem fixa da disponibilidade')]
    public function testStatusIndisponivel(): void
    {
        $analise = $this->analise();
        $analise->marcarIndisponivel('sem provedor');

        $saida = $this->casoDeStatus($this->conversaIa(), $analise)->executar($this->eu, $this->tenant, 480);

        self::assertSame('indisponivel', $saida->status);
        self::assertSame('IA não configurada nesta instalação', $saida->mensagem);
    }

    #[TestDox('Status: sem conversa IA minha, análise inexistente, de outro tipo, de outra pessoa ou excluída dão o mesmo "não encontrada"')]
    public function testStatusNaoEncontrada(): void
    {
        $outra = new User();
        DefineId::em($outra, 77);
        $excluida = $this->analise();
        $excluida->excluir($this->eu);

        $casos = [
            'sem conversa' => $this->casoDeStatus(null, $this->analise()),
            'inexistente' => $this->casoDeStatus($this->conversaIa(), null),
            'de outra pessoa' => $this->casoDeStatus($this->conversaIa(), $this->analise($outra)),
            'de outro tipo' => $this->casoDeStatus($this->conversaIa(), $this->analise(null, TipoDeAnalise::ResumoPush)),
            'excluída' => $this->casoDeStatus($this->conversaIa(), $excluida),
        ];
        foreach ($casos as $rotulo => $useCase) {
            try {
                $useCase->executar($this->eu, $this->tenant, 480);
                self::fail("Devia dar 404: $rotulo");
            } catch (AnaliseNaoEncontradaException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
