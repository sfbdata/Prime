<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\DTO\AtualizarConversaInput;
use App\ChatIa\DTO\ConversaNaListaOutput;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Exception\ConversaDeChatNaoEncontradaException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\ChatIa\UseCase\AtualizarConversaDoChatUseCase;
use App\ChatIa\UseCase\CriarConversaDoChatUseCase;
use App\ChatIa\UseCase\ListarConversasDoChatUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Os UseCases de lista, criação e atualização das conversas, sem banco: os repositórios são dublês. */
#[CoversClass(ListarConversasDoChatUseCase::class)]
#[CoversClass(CriarConversaDoChatUseCase::class)]
#[CoversClass(AtualizarConversaDoChatUseCase::class)]
final class UseCasesDasConversasDoChatTest extends TestCase
{
    private Tenant $tenant;
    private User $eu;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->eu = new User();
    }

    private function acesso(ConversaDeChatRepository $conversas, bool $vinculo = true, bool $modulo = true): AcessoAoChat
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturn($vinculo);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturn($modulo);

        return new AcessoAoChat($vinculos, $permissoes, $conversas);
    }

    private function transacao(): TransacaoDoChat
    {
        return new class () implements TransacaoDoChat {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }
        };
    }

    private function conversa(int $id, string $criada, ?string $titulo = null, bool $fixada = false): ConversaDeChat
    {
        $c = new ConversaDeChat($this->tenant, $this->eu, ModeloDeIa::PADRAO, new \DateTimeImmutable($criada));
        DefineId::em($c, $id);
        if ($titulo !== null) {
            $c->definirTituloAutomatico($titulo);
        }
        $c->fixar($fixada);

        return $c;
    }

    #[TestDox('Lista: título padrão para a sem resposta e grupos fixadas, hoje, últimos 7 dias e mais antigas')]
    public function testListarAgrupa(): void
    {
        $repo = $this->createStub(ConversaDeChatRepository::class);
        $repo->method('listarDoDono')->willReturn([
            $this->conversa(1, '2026-10-01 10:00:00', 'Fixa', true),
            $this->conversa(2, '2026-10-28 08:00:00'),
            $this->conversa(3, '2026-10-24 08:00:00', 'Semana'),
            $this->conversa(4, '2026-09-01 08:00:00', 'Velha'),
        ]);
        $caso = new ListarConversasDoChatUseCase($this->acesso($repo), $repo, new MockClock('2026-10-28 15:00:00'));

        $lista = $caso->executar($this->eu, $this->tenant);

        self::assertSame(
            [ConversaNaListaOutput::GRUPO_FIXADAS, ConversaNaListaOutput::GRUPO_HOJE, ConversaNaListaOutput::GRUPO_ULTIMOS_7_DIAS, ConversaNaListaOutput::GRUPO_MAIS_ANTIGAS],
            array_map(static fn (ConversaNaListaOutput $c): string => $c->grupo, $lista),
        );
        self::assertSame(ConversaNaListaOutput::TITULO_PADRAO, $lista[1]->titulo);
        self::assertSame('Fixa', $lista[0]->titulo);
    }

    #[TestDox('Lista: sem vínculo ativo ou sem o módulo da I.A, 403 antes de consultar')]
    public function testListarSemAcesso(): void
    {
        $repo = $this->createMock(ConversaDeChatRepository::class);
        $repo->expects(self::never())->method('listarDoDono');

        foreach ([[false, true], [true, false]] as [$vinculo, $modulo]) {
            try {
                (new ListarConversasDoChatUseCase($this->acesso($repo, $vinculo, $modulo), $repo, new MockClock()))->executar($this->eu, $this->tenant);
                self::fail('Deveria recusar.');
            } catch (SemAcessoAoChatException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(SemAcessoAoChatException::class);
        (new ListarConversasDoChatUseCase($this->acesso($repo), $repo, new MockClock()))->executar($this->eu, null);
    }

    #[TestDox('Criar: grava a conversa com o modelo padrão e devolve o id')]
    public function testCriar(): void
    {
        $repo = $this->createMock(ConversaDeChatRepository::class);
        $repo->method('contarVaziasDoDono')->willReturn(0);
        $repo->expects(self::once())->method('salvar')->willReturnCallback(static function (ConversaDeChat $c): void {
            DefineId::em($c, 77);
            self::assertSame(ModeloDeIa::PADRAO, $c->getModelo());
            self::assertNull($c->getTitulo());
        });

        $id = (new CriarConversaDoChatUseCase($this->acesso($repo), $repo, $this->transacao(), new MockClock()))->executar($this->eu, $this->tenant);

        self::assertSame(77, $id);
    }

    #[TestDox('Criar: com 20 conversas vazias, reaproveita a vazia mais recente e não grava outra')]
    public function testCriarReaproveitaAVazia(): void
    {
        $repo = $this->createMock(ConversaDeChatRepository::class);
        $repo->method('contarVaziasDoDono')->willReturn(CriarConversaDoChatUseCase::MAXIMO_DE_VAZIAS);
        $repo->method('vaziaMaisRecenteDoDono')->willReturn($this->conversa(55, '2026-10-28 10:00:00'));
        $repo->expects(self::never())->method('salvar');

        $id = (new CriarConversaDoChatUseCase($this->acesso($repo), $repo, $this->transacao(), new MockClock()))->executar($this->eu, $this->tenant);

        self::assertSame(55, $id);
    }

    #[TestDox('Criar: com 19 vazias ainda cria uma nova')]
    public function testCriarAbaixoDoTeto(): void
    {
        $repo = $this->createMock(ConversaDeChatRepository::class);
        $repo->method('contarVaziasDoDono')->willReturn(CriarConversaDoChatUseCase::MAXIMO_DE_VAZIAS - 1);
        $repo->expects(self::never())->method('vaziaMaisRecenteDoDono');
        $repo->expects(self::once())->method('salvar');

        (new CriarConversaDoChatUseCase($this->acesso($repo), $repo, $this->transacao(), new MockClock()))->executar($this->eu, $this->tenant);
    }

    #[TestDox('Atualizar: renomeia, fixa e troca o modelo só do que veio; conversa alheia é 404')]
    public function testAtualizar(): void
    {
        $conversa = $this->conversa(9, '2026-10-28 10:00:00', 'Antes');
        $repo = $this->createStub(ConversaDeChatRepository::class);
        $repo->method('doDono')->willReturnCallback(fn (int $id): ?ConversaDeChat => $id === 9 ? $conversa : null);
        $caso = new AtualizarConversaDoChatUseCase($this->acesso($repo), $this->transacao());

        $caso->executar($this->eu, $this->tenant, 9, new AtualizarConversaInput(fixada: true));
        self::assertTrue($conversa->estaFixada());
        self::assertSame('Antes', $conversa->getTitulo());

        $caso->executar($this->eu, $this->tenant, 9, new AtualizarConversaInput('Novo título', null, ModeloDeIa::from('claude-sonnet-5-5')));
        self::assertSame('Novo título', $conversa->getTitulo());
        self::assertTrue($conversa->temTituloManual());
        self::assertSame('claude-sonnet-5-5', $conversa->getModelo()->value);

        $this->expectException(ConversaDeChatNaoEncontradaException::class);
        $caso->executar($this->eu, $this->tenant, 10, new AtualizarConversaInput('x'));
    }
}
