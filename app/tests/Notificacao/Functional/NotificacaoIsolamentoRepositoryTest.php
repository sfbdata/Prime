<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Shared\Doctrine\Filter\TenantFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Valida o isolamento das notificações por escritório (M4) depois de Notificacao virar
 * TenantAware. Usa UM usuário com notificações em DOIS tenants (cenário do colaborador
 * multi-escritório) — o vetor que o guard de posse (só por usuario) NÃO fechava.
 *
 * As SELECTs do sino são fechadas pelo TenantFilter (find/findBy/find-by-id); os bulk
 * marcarTodasComoLidas (UPDATE) e excluirDoUsuario (DELETE) ESCAPAM o filtro → o escopo por
 * tenant é manual (parâmetro Tenant) e é testado explicitamente aqui.
 */
#[CoversClass(TenantFilter::class)]
#[CoversClass(NotificacaoRepository::class)]
final class NotificacaoIsolamentoRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private NotificacaoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(NotificacaoRepository::class);
    }

    #[TestDox('findNaoLidas/count/paginadas só retornam notificações do tenant ativo (usuário multi-escritório)')]
    public function testLeituraIsolaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUser();

        $this->criarNotificacao($usuario, $tenantA, 'De A');
        $this->criarNotificacao($usuario, $tenantB, 'De B');

        // sem filtro: vê as duas (estado de vazamento, pré-M4)
        self::assertCount(2, $this->repo->findNaoLidasByUsuario($usuario));

        $this->ligarFiltro((int) $tenantA->getId());
        $naoLidasA = $this->repo->findNaoLidasByUsuario($usuario);
        self::assertCount(1, $naoLidasA);
        self::assertSame('De A', $naoLidasA[0]->getTitulo());
        self::assertSame(1, $this->repo->countNaoLidasByUsuario($usuario));
        self::assertCount(1, $this->repo->findPaginadasByUsuario($usuario, null, 1, 20));

        $this->ligarFiltro((int) $tenantB->getId());
        $naoLidasB = $this->repo->findNaoLidasByUsuario($usuario);
        self::assertCount(1, $naoLidasB);
        self::assertSame('De B', $naoLidasB[0]->getTitulo());
    }

    #[TestDox('find() por id de notificação de outro tenant retorna null (fecha o IDOR do marcar-como-lida)')]
    public function testFindPorIdFechaIdor(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $notifB = $this->criarNotificacao($this->criarUser(), $tenantB, 'De B');
        $idB = (int) $notifB->getId();

        $this->ligarFiltro((int) $tenantA->getId());
        $this->em->clear();

        self::assertNull(
            $this->em->find(Notificacao::class, $idB),
            'IDOR aberto: a rota notificacao_marcar_lida carrega a notificação por id direto',
        );
    }

    #[TestDox('marcarTodasComoLidas escopa por tenant: marcar em A não lê as notificações de B')]
    public function testMarcarTodasComoLidasEscopaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUser();

        $notifA = $this->criarNotificacao($usuario, $tenantA, 'De A');
        $notifB = $this->criarNotificacao($usuario, $tenantB, 'De B');
        $idA = (int) $notifA->getId();
        $idB = (int) $notifB->getId();

        // bulk UPDATE escapa o TenantFilter → o escopo vem do parâmetro Tenant
        $this->repo->marcarTodasComoLidas($usuario, $tenantA);
        $this->em->clear();

        self::assertTrue($this->em->find(Notificacao::class, $idA)->isLida(), 'a de A deveria ter sido marcada lida');
        self::assertFalse(
            $this->em->find(Notificacao::class, $idB)->isLida(),
            'marcar todas em A NÃO pode tocar as notificações de B',
        );
    }

    #[TestDox('excluirDoUsuario escopa por tenant: excluir em A não remove a notificação de B mesmo com o id na lista')]
    public function testExcluirDoUsuarioEscopaPorTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $usuario = $this->criarUser();

        $notifA = $this->criarNotificacao($usuario, $tenantA, 'De A');
        $notifB = $this->criarNotificacao($usuario, $tenantB, 'De B');
        $idA = (int) $notifA->getId();
        $idB = (int) $notifB->getId();

        // bulk DELETE escapa o TenantFilter → o escopo vem do parâmetro Tenant
        $excluidas = $this->repo->excluirDoUsuario($usuario, $tenantA, [$idA, $idB]);
        $this->em->clear();

        self::assertSame(1, $excluidas, 'só a notificação do tenant A pode ser excluída');
        self::assertNull($this->em->find(Notificacao::class, $idA));
        self::assertNotNull(
            $this->em->find(Notificacao::class, $idB),
            'a notificação de B não pode ser excluída a partir do tenant A',
        );
    }

    // ----------------------------------------------------------------- helpers

    private function ligarFiltro(int $tenantId): void
    {
        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $tenantId, Types::INTEGER);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant NOTIF ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('notif_' . uniqid() . '@test.com');
        $user->setFullName('User Notif');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarNotificacao(User $user, Tenant $tenant, string $titulo): Notificacao
    {
        $notif = new Notificacao();
        $notif->setUsuario($user);
        $notif->setTenant($tenant);
        $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $notif->setTitulo($titulo);
        $this->em->persist($notif);
        $this->em->flush();

        return $notif;
    }
}
