<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\CotaDeNotificacoesDoRegistro;
use App\Pasta\Service\MencoesDoRegistro;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A `ResolucaoDeMencoesDoRegistro` REAL (é ela que confere ids e notifica), montada sobre dublês
 * do banco — para os unit dos UseCases da nota técnica (PMC-019). O EntityManager roda o callback
 * de `wrapInTransaction`, como o real.
 *
 * Quem usa precisa de `$this->tenant` (o escritório da sessão), do trait `CriaSanitizadorTextoRico`
 * e chama `montarMencoes()` no setUp.
 */
trait CriaResolucaoDeMencoesDaNota
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $userRepository;
    private PermissionChecker&MockObject $permissionChecker;
    private NotificacaoRepository&MockObject $notificacaoRepository;
    private NotificacaoService&MockObject $notificacaoService;
    private RateLimiterFactoryEspiao $espiao;
    private ResolucaoDeMencoesDoRegistro $resolucao;

    private function montarMencoes(): void
    {
        $this->em                    = $this->createMock(EntityManagerInterface::class);
        $this->userRepository        = $this->createMock(UserRepository::class);
        $this->permissionChecker     = $this->createMock(PermissionChecker::class);
        $this->notificacaoRepository = $this->createMock(NotificacaoRepository::class);
        $this->notificacaoService    = $this->createMock(NotificacaoService::class);
        $this->espiao                = new RateLimiterFactoryEspiao(aceita: true);

        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $fn): mixed => $fn($this->em));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $rota, array $params = []): string => '/pasta/' . $params['id'],
        );

        $this->resolucao = new ResolucaoDeMencoesDoRegistro(
            new MencoesDoRegistro($this->criarSanitizadorTextoRico()),
            $this->userRepository,
            $this->permissionChecker,
            $this->notificacaoRepository,
            $this->notificacaoService,
            $urlGenerator,
            new CotaDeNotificacoesDoRegistro($this->espiao, new NullLogger()),
        );
    }

    /** @param list<User> $colegas os colegas ativos DESTE escritório (o que o banco devolveria) */
    private function colegasDoEscritorio(array $colegas): void
    {
        $this->userRepository->method('findColaboradoresAtivosPorTenant')
            ->with($this->identicalTo($this->tenant))
            ->willReturn($colegas);
    }

    /** @param array<int, bool> $acesso userId => vê a pasta `$pastaId` */
    private function acessoAPasta(int $pastaId, array $acesso): void
    {
        $this->permissionChecker->method('canAccessResource')->willReturnCallback(
            function (User $u, ?Tenant $t, string $tipo, int $id, string $acao) use ($pastaId, $acesso): bool {
                self::assertSame($this->tenant, $t);
                self::assertSame([AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_VIEW], [$tipo, $id, $acao]);

                return $acesso[(int) $u->getId()] ?? false;
            },
        );
    }

    /**
     * Captura os avisos criados (destinatário, tipo, título, texto e o link posto depois).
     *
     * @return \ArrayObject<int, array{usuario: User, tipo: string, titulo: string, texto: ?string, notificacao: Notificacao}>
     */
    private function capturarAvisos(): \ArrayObject
    {
        $avisos = new \ArrayObject();
        $this->notificacaoService->method('criar')->willReturnCallback(
            static function (User $u, Tenant $t, string $tipo, string $titulo, ?string $texto = null) use ($avisos): Notificacao {
                $n = (new Notificacao())->setTipo($tipo);
                $avisos[] = ['usuario' => $u, 'tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'notificacao' => $n];

                return $n;
            },
        );

        return $avisos;
    }

    private function pessoa(int $id, string $nome): User
    {
        $u = (new User())->setEmail('p' . $id . '@test.com')->setFullName($nome);
        (new \ReflectionProperty($u, 'id'))->setValue($u, $id);

        return $u;
    }

    private function definirId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
