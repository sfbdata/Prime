<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\Exception\RedistribuicaoForaDoEscritorioException;
use App\Tarefa\Exception\RedistribuicaoNaoPermitidaException;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\GuardaDaRedistribuicao;
use App\Tarefa\UseCase\ListarMetasParaRedistribuirUseCase;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A lista do Redistribuir: metas ativas da pessoa com o destino sugerido por regras fixas (menos metas ativas, empate
 * pelo menor id, a contagem sobe a cada sugestão), sem a origem e sem a conta do Master (spec
 * painel-acompanhamento-redistribuir, fatia B).
 */
#[CoversClass(ListarMetasParaRedistribuirUseCase::class)]
#[Group('tarefa')]
final class ListarMetasParaRedistribuirUseCaseTest extends TestCase
{
    private UserRepository&MockObject $users;
    private TarefaRepository&MockObject $tarefas;
    private NotificacaoService&MockObject $notificacoes;
    private Tenant $tenant;
    private User $gestor;
    private User $ana;
    private User $bia;
    private User $caio;
    private User $duda;
    /** @var list<int> ids que não veem a pasta */
    private array $naoVe = [];

    protected function setUp(): void
    {
        $this->tenant = self::comId(new Tenant(), 7);
        $this->gestor = self::pessoa(1, 'Gestora', ['ROLE_SUPER_ADMIN']);
        $this->ana    = self::pessoa(10, 'Ana Origem');
        $this->bia    = self::pessoa(11, 'Bia');
        $this->caio   = self::pessoa(12, 'Caio');
        $this->duda   = self::pessoa(13, 'Duda');

        $todos = [$this->duda, $this->caio, $this->gestor, $this->bia, $this->ana];
        $this->users = $this->createMock(UserRepository::class);
        $this->users->method('findColaboradorAtivoPorIdETenant')->willReturnCallback(
            static fn (int $id): ?User => array_values(array_filter($todos, static fn (User $u): bool => $u->getId() === $id))[0] ?? null,
        );
        $this->users->method('findColaboradoresAtivosPorTenant')->willReturn($todos);

        $this->tarefas = $this->createMock(TarefaRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->notificacoes->method('veAPastaDaMeta')->willReturnCallback(fn (Tarefa $t, User $u): bool => !in_array($u->getId(), $this->naoVe, true));
    }

    private function useCase(): ListarMetasParaRedistribuirUseCase
    {
        $regra = new RegraDosPrimarios($this->createStub(UserTenantRepository::class));

        return new ListarMetasParaRedistribuirUseCase(new GuardaDaRedistribuicao($this->users, $regra, $this->notificacoes), $this->tarefas);
    }

    private function meta(int $id): Tarefa
    {
        $pasta = self::comId((new Pasta())->setNup('P' . $id)->setTenant($this->tenant), 500 + $id);

        return self::comId((new Tarefa())->setTitulo('Meta ' . $id)->setDescricao('...')->setPasta($pasta)->setTenant($this->tenant), $id);
    }

    #[TestDox('Sugere quem tem menos metas ativas; a contagem sobe a cada sugestão e o empate vai ao menor id')]
    public function testSugestaoPorFilaMenor(): void
    {
        $this->tarefas->method('countAtivasPorResponsavel')->willReturn([10 => 9, 11 => 2, 12 => 0, 13 => 1]);
        $this->tarefas->method('ativasDaPessoa')->willReturn([$this->meta(1), $this->meta(2), $this->meta(3), $this->meta(4)]);

        $lista = $this->useCase()->executar($this->tenant, $this->gestor, 10, []);

        // Caio (0) sobe a 1 e ainda empata com Duda (1): menor id, Caio, sobe a 2; Duda (1) sobe a 2; todos em 2: menor id, Bia.
        self::assertSame([12, 12, 13, 11], array_map(static fn ($m): ?int => $m->destinoSugeridoId, $lista->metas));
        self::assertSame('Ana Origem', $lista->origemNome);
        self::assertSame([11, 12, 13], array_map(static fn ($d): int => $d->id, $lista->destinos), 'sem a origem nem a conta do Master, por id');
        self::assertSame([2, 0, 1], array_map(static fn ($d): int => $d->ativas, $lista->destinos));
    }

    #[TestDox('Quem não vê a pasta da meta não é sugerido nem listado como destino possível; sem ninguém, vem sem sugestão')]
    public function testCadeadoNoDestino(): void
    {
        $this->naoVe = [12];
        $this->tarefas->method('countAtivasPorResponsavel')->willReturn([11 => 5, 12 => 0, 13 => 5]);
        $this->tarefas->method('ativasDaPessoa')->willReturn([$this->meta(1)]);

        $lista = $this->useCase()->executar($this->tenant, $this->gestor, 10, []);

        self::assertSame(11, $lista->metas[0]->destinoSugeridoId);
        self::assertSame([11, 13], $lista->metas[0]->destinosPossiveis);
    }

    #[TestDox('Ninguém elegível vê a pasta: a meta vem sem sugestão e sem destinos')]
    public function testSemDestinoElegivel(): void
    {
        $this->naoVe = [11, 12, 13];
        $this->tarefas->method('countAtivasPorResponsavel')->willReturn([]);
        $this->tarefas->method('ativasDaPessoa')->willReturn([$this->meta(1)]);

        $meta = $this->useCase()->executar($this->tenant, $this->gestor, 10, [])->metas[0];

        self::assertNull($meta->destinoSugeridoId);
        self::assertSame([], $meta->destinosPossiveis);
    }

    #[TestDox('O cadeado de quem olha é repassado ao repositório (pasta oculta não aparece) e a pessoa sem meta vem vazia')]
    public function testPastasOcultasRepassadas(): void
    {
        $this->tarefas->method('countAtivasPorResponsavel')->willReturn([]);
        $this->tarefas->expects(self::once())->method('ativasDaPessoa')->with($this->tenant, $this->ana, [501, 502])->willReturn([]);

        $lista = $this->useCase()->executar($this->tenant, $this->gestor, 10, [501, 502]);

        self::assertSame([], $lista->metas);
    }

    #[TestDox('Sem permissão: 403. Pessoa fora do escritório: 404')]
    public function testGuardas(): void
    {
        try {
            $this->useCase()->executar($this->tenant, self::pessoa(3, 'Secundária'), 10, []);
            self::fail('Devia dar 403.');
        } catch (RedistribuicaoNaoPermitidaException) {
        }

        $this->expectException(RedistribuicaoForaDoEscritorioException::class);
        $this->useCase()->executar($this->tenant, $this->gestor, 4040, []);
    }

    /** @param list<string> $papeis */
    private static function pessoa(int $id, string $nome, array $papeis = ['ROLE_USER']): User
    {
        return self::comId((new User())->setEmail('p' . $id . '@test.com')->setFullName($nome)->setRoles($papeis), $id);
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
