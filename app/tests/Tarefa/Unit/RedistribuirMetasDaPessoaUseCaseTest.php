<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\NivelNoCadeado;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Tarefa\DTO\RedistribuirMetasInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\RedistribuicaoForaDoEscritorioException;
use App\Tarefa\Exception\RedistribuicaoNaoPermitidaException;
use App\Tarefa\Exception\RedistribuicaoRecusadaException;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\AcessoAMeta;
use App\Tarefa\Service\AuditoriaDaRedistribuicao;
use App\Tarefa\Service\GuardaDaRedistribuicao;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\UseCase\RedistribuirMetasDaPessoaUseCase;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Confirmação do Redistribuir (spec painel-acompanhamento-redistribuir, fatia B): troca o responsável sob a trava da
 * meta, nunca deixa meta sem responsável, pula a que mudou, grava histórico e auditoria e avisa uma vez por pessoa.
 * O cadeado e a posse (404 de pasta oculta e de outro escritório) são provados no functional, com banco.
 */
#[CoversClass(RedistribuirMetasDaPessoaUseCase::class)]
#[CoversClass(GuardaDaRedistribuicao::class)]
#[Group('tarefa')]
final class RedistribuirMetasDaPessoaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private UserRepository&MockObject $users;
    private TarefaRepository&MockObject $tarefas;
    private TarefaMensagemRepository&MockObject $mensagens;
    private NotificacaoService&MockObject $notificacoes;
    private AuditoriaDaRedistribuicao&MockObject $auditoria;
    private TransacaoDaPassagemFalsa $transacao;
    private Tenant $tenant;
    private User $gestor;
    private User $ana;
    private User $bia;
    private User $caio;
    private User $master;
    /** @var array<int, Tarefa> metas do escritório por id */
    private array $metas = [];
    /** @var list<int> ids que não veem a pasta (cadeado) */
    private array $naoVe = [];
    /** @var list<array{usuario: User, titulo: string, texto: ?string, tarefa: ?Tarefa, url: ?string}> */
    private array $avisos = [];
    /** @var list<array{string, int, int}> */
    private array $auditoriaMeta = [];
    /** @var list<array{int, int}> */
    private array $auditoriaOperacao = [];
    /** @var list<TarefaMensagem> */
    private array $historicos = [];

    protected function setUp(): void
    {
        $this->tenant = self::comId(new Tenant(), 7);
        $this->gestor = self::pessoa(1, 'Gestora Geral', ['ROLE_SUPER_ADMIN']);
        $this->ana    = self::pessoa(10, 'Ana Origem');
        $this->bia    = self::pessoa(11, 'Bia Destino');
        $this->caio   = self::pessoa(12, 'Caio Destino');
        $this->master = self::pessoa(99, 'Conta Master', ['ROLE_SUPER_ADMIN']);

        $todos = [$this->gestor, $this->ana, $this->bia, $this->caio, $this->master];
        $this->users = $this->createMock(UserRepository::class);
        $this->users->method('findColaboradorAtivoPorIdETenant')->willReturnCallback(
            static fn (int $id): ?User => array_values(array_filter($todos, static fn (User $u): bool => $u->getId() === $id))[0] ?? null,
        );
        $this->users->method('findColaboradoresAtivosPorTenant')->willReturn($todos);

        $this->tarefas = $this->createMock(TarefaRepository::class);
        $this->tarefas->method('findPorIdsDoEscritorio')->willReturnCallback(
            fn (array $ids): array => array_intersect_key($this->metas, array_flip($ids)),
        );

        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
        $this->mensagens->method('salvar')->willReturnCallback(function (TarefaMensagem $m): void {
            $this->historicos[] = $m;
        });

        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->notificacoes->method('veAPastaDaMeta')->willReturnCallback(fn (Tarefa $t, User $u): bool => !in_array($u->getId(), $this->naoVe, true));
        $this->notificacoes->method('criar')->willReturnCallback(function (User $u, Tenant $t, string $tipo, string $titulo, ?string $texto = null, ?Tarefa $tarefa = null): Notificacao {
            self::assertSame(RedistribuirMetasDaPessoaUseCase::TIPO_NOTIFICACAO, $tipo);
            $n = (new Notificacao())->setTipo($tipo);
            $this->avisos[] = ['usuario' => $u, 'titulo' => $titulo, 'texto' => $texto, 'tarefa' => $tarefa, 'notificacao' => $n];

            return $n;
        });

        $this->auditoria = $this->createMock(AuditoriaDaRedistribuicao::class);
        $this->auditoria->method('meta')->willReturnCallback(function (Tenant $t, User $a, Tarefa $m, int $origem, int $destino): void {
            $this->auditoriaMeta[] = [(string) $m->getId(), $origem, $destino];
        });
        $this->auditoria->method('operacao')->willReturnCallback(function (Tenant $t, User $a, int $pessoaId, int $total): void {
            $this->auditoriaOperacao[] = [$pessoaId, $total];
        });

        $this->transacao = new TransacaoDaPassagemFalsa();
        foreach ([101, 102, 103] as $id) {
            $this->metas[$id] = $this->meta($id);
        }
    }

    private function meta(int $id, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $pasta = self::comId((new Pasta())->setNup('P' . $id)->setTenant($this->tenant)->setCriadoPor($this->gestor), 500 + $id);
        $meta  = self::comId(new Tarefa(), $id);
        $meta->setTitulo('Meta ' . $id)->setDescricao('...')->setPasta($pasta)->setTenant($this->tenant)->setCriadoPor($this->gestor)->setStatus($status);
        $meta->addResponsavel($this->ana);

        return $meta;
    }

    private function useCase(?User $ator = null): RedistribuirMetasDaPessoaUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturn(true);
        $regra  = new RegraDosPrimarios($vinculos);
        $nivel  = new NivelNoCadeado($this->createStub(CadeadoDaPastaRepository::class), $vinculos, $regra, new TenantContext(new RequestStack(), $this->createStub(Security::class), $this->createStub(EntityManagerInterface::class), new BloqueioPorPedidoDeExclusao($this->createStub(PedidoDeExclusaoDeContaRepository::class), $regra)));
        $urls   = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p = []): string => $rota === 'tarefa_show' ? '/tarefas/' . $p['id'] : '/tarefas/minhas');

        return new RedistribuirMetasDaPessoaUseCase(
            new GuardaDaRedistribuicao($this->users, $regra, $this->notificacoes),
            $this->tarefas,
            new AcessoAMeta($vinculos, new AcessoAPasta($nivel, $this->createStub(PermissionChecker::class))),
            $this->transacao,
            $this->mensagens,
            new TextoDoDespacho($this->criarSanitizadorTextoRico()),
            $this->notificacoes,
            $this->auditoria,
            $urls,
        );
    }

    /** @param list<array{int, int}> $pares meta, destino */
    private function pedido(array $pares, int $origem = 10, ?User $ator = null): RedistribuirMetasInput
    {
        return new RedistribuirMetasInput(
            $this->tenant,
            $ator ?? $this->gestor,
            $origem,
            array_map(static fn (array $p): array => ['meta_id' => $p[0], 'destino_id' => $p[1]], $pares),
            new \DateTimeImmutable('2026-10-27 10:00'),
        );
    }

    /** @return list<int> */
    private function responsaveis(int $metaId): array
    {
        return array_values(array_map(static fn (User $u): int => (int) $u->getId(), $this->metas[$metaId]->getResponsaveis()->toArray()));
    }

    #[TestDox('Troca o responsável de cada meta, grava histórico e auditoria por meta e por operação, sem deixar meta sem responsável')]
    public function testRedistribui(): void
    {
        $resultado = $this->useCase()->executar($this->pedido([[101, 11], [102, 11], [103, 12]]));

        self::assertSame(['passadas' => 3, 'puladas' => 0, 'total' => 3], $resultado->paraArray());
        self::assertSame([11], $this->responsaveis(101));
        self::assertSame([11], $this->responsaveis(102));
        self::assertSame([12], $this->responsaveis(103));
        self::assertSame(3, $this->transacao->concluidas - 1, 'uma transação (com trava) por meta, mais a final dos avisos');
        self::assertSame([['101', 10, 11], ['102', 10, 11], ['103', 10, 12]], $this->auditoriaMeta);
        self::assertSame([[10, 3]], $this->auditoriaOperacao);

        self::assertCount(3, $this->historicos);
        self::assertSame(AcaoDoDespacho::Redistribuicao, $this->historicos[0]->getAcao());
        self::assertSame('<p>Responsável alterado de Ana Origem para Bia Destino por Gestora Geral.</p>', $this->historicos[0]->getMensagem());
        self::assertNull($this->historicos[0]->getCodigo());
        self::assertFalse($this->historicos[0]->podeSerEditado());
        self::assertSame($this->metas[101]->getMensagens()->first(), $this->historicos[0]);
    }

    #[TestDox('Um aviso por destinatário (com o total dele) e um à origem, em texto neutro, sem citar desempenho nem carga')]
    public function testAvisosUmPorPessoa(): void
    {
        $this->useCase()->executar($this->pedido([[101, 11], [102, 11], [103, 12]]));

        self::assertCount(3, $this->avisos);
        [$bia, $caio, $ana] = $this->avisos;
        self::assertSame($this->bia, $bia['usuario']);
        self::assertSame('2 metas passaram para você.', $bia['texto']);
        self::assertSame('/tarefas/minhas', $bia['notificacao']->getUrl());
        self::assertNull($bia['tarefa']);
        self::assertSame($this->caio, $caio['usuario']);
        self::assertSame('1 meta passou para você.', $caio['texto']);
        self::assertSame('/tarefas/103', $caio['notificacao']->getUrl(), 'com uma só, o aviso leva à meta');
        self::assertSame($this->metas[103], $caio['tarefa']);
        self::assertSame($this->ana, $ana['usuario']);
        self::assertSame('3 metas suas passaram para outras pessoas.', $ana['texto']);
        foreach ($this->avisos as $aviso) {
            self::assertDoesNotMatchRegularExpression('/desempenho|carga|acompanhamento|média/i', $aviso['titulo'] . ' ' . $aviso['texto']);
        }
    }

    #[TestDox('Destino que já é responsável: só sai a origem, sem duplicar')]
    public function testDestinoJaResponsavel(): void
    {
        $this->metas[101]->addResponsavel($this->bia);

        $this->useCase()->executar($this->pedido([[101, 11]]));

        self::assertSame([11], $this->responsaveis(101));
    }

    #[TestDox('Meta que mudou antes da confirmação (concluída ou origem já saiu) é pulada e contada; nada é avisado sobre ela')]
    public function testMetaQueMudouEPulada(): void
    {
        $this->transacao->noBanco = function (Tarefa $meta): void {
            if ($meta->getId() === 101) {
                $meta->setStatus(Tarefa::STATUS_CONCLUIDA);
            }
            if ($meta->getId() === 102) {
                $meta->removeResponsavel($this->ana);
                $meta->addResponsavel($this->caio);
            }
        };

        $resultado = $this->useCase()->executar($this->pedido([[101, 11], [102, 11], [103, 11]]));

        self::assertSame(['passadas' => 1, 'puladas' => 2, 'total' => 3], $resultado->paraArray());
        self::assertSame([10], $this->responsaveis(101), 'concluída não muda');
        self::assertSame([12], $this->responsaveis(102), 'quem a tomou antes continua');
        self::assertSame([11], $this->responsaveis(103));
        self::assertCount(1, $this->historicos);
        self::assertSame([['103', 10, 11]], $this->auditoriaMeta);
        self::assertSame('1 meta passou para você.', $this->avisos[0]['texto']);
    }

    #[TestDox('Se nenhuma meta passou, não há auditoria da operação nem aviso')]
    public function testNadaPassouNadaAvisa(): void
    {
        $this->transacao->noBanco = static fn (Tarefa $m) => $m->setStatus(Tarefa::STATUS_CONCLUIDA);

        $resultado = $this->useCase()->executar($this->pedido([[101, 11]]));

        self::assertSame(1, $resultado->puladas);
        self::assertSame([], $this->avisos);
        self::assertSame([], $this->auditoriaOperacao);
    }

    #[TestDox('A origem não é avisada do que não vê (cadeado)')]
    public function testOrigemNaoRecebeAvisoDoQueNaoVe(): void
    {
        $this->naoVe = [10];

        $this->useCase()->executar($this->pedido([[101, 11]]));

        self::assertSame([$this->bia], array_column($this->avisos, 'usuario'));
    }

    #[TestDox('Sem permissão (nem Master nem primário): 403, nada gravado')]
    public function testSemPermissao(): void
    {
        $this->expectException(RedistribuicaoNaoPermitidaException::class);
        try {
            $this->useCase()->executar($this->pedido([[101, 11]], 10, self::pessoa(3, 'Secundária')));
        } finally {
            self::assertSame([], $this->historicos);
        }
    }

    #[TestDox('Pessoa de origem fora do escritório: 404')]
    public function testOrigemForaDoEscritorio(): void
    {
        $this->expectException(RedistribuicaoForaDoEscritorioException::class);
        $this->useCase()->executar($this->pedido([[101, 11]], 555));
    }

    #[TestDox('A conta do Master não tem as metas redistribuídas aqui: 400')]
    public function testOrigemMaster(): void
    {
        $this->expectException(RedistribuicaoRecusadaException::class);
        $this->useCase()->executar($this->pedido([[101, 11]], 99));
    }

    #[TestDox('Meta de outro escritório (id que o repositório não devolve): 404 e o lote inteiro não grava')]
    public function testMetaDeOutroEscritorio(): void
    {
        try {
            $this->useCase()->executar($this->pedido([[101, 11], [9999, 11]]));
            self::fail('Devia dar 404.');
        } catch (RedistribuicaoForaDoEscritorioException) {
            self::assertSame([10], $this->responsaveis(101), 'a primeira não foi movida');
            self::assertSame([], $this->historicos);
            self::assertSame([], $this->avisos);
        }
    }

    /** @return iterable<string, array{list<array{int, int}>}> */
    public static function lotesInvalidos(): iterable
    {
        yield 'vazio' => [[]];
        yield 'destino igual à origem' => [[[101, 10]]];
        yield 'destino é a conta do Master' => [[[101, 99]]];
        yield 'destino de outro escritório' => [[[101, 4040]]];
        yield 'meta repetida' => [[[101, 11], [101, 12]]];
        yield 'mais de 50 metas' => [array_map(static fn (int $i): array => [1000 + $i, 11], range(1, 51))];
    }

    /** @param list<array{int, int}> $pares */
    #[DataProvider('lotesInvalidos')]
    #[TestDox('Lote inválido é recusado inteiro com 400 e nada é gravado')]
    public function testLoteInvalido(array $pares): void
    {
        try {
            $this->useCase()->executar($this->pedido($pares));
            self::fail('Devia recusar.');
        } catch (RedistribuicaoRecusadaException) {
            self::assertSame([10], $this->responsaveis(101));
            self::assertSame(0, $this->transacao->concluidas);
        }
    }

    #[TestDox('Exatamente 50 metas passam (o limite é por confirmação)')]
    public function testCinquentaPassam(): void
    {
        $pares = [];
        foreach (range(1, 50) as $i) {
            $this->metas[2000 + $i] = $this->meta(2000 + $i);
            $pares[] = [2000 + $i, 11];
        }

        self::assertSame(50, $this->useCase()->executar($this->pedido($pares))->passadas);
    }

    #[TestDox('Destino que não vê a pasta da meta (cadeado): 400')]
    public function testDestinoSemAcessoAPasta(): void
    {
        $this->naoVe = [11];

        $this->expectException(RedistribuicaoRecusadaException::class);
        $this->useCase()->executar($this->pedido([[101, 11]]));
    }

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
