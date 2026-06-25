<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(NotificacaoService::class)]
final class NotificacaoServiceTest extends KernelTestCase
{
    use Factories;

    private NotificacaoService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = static::getContainer()->get(NotificacaoService::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('notificarNovoChamado notifica só gestores com admin.servicedesk.manage e exclui o solicitante')]
    public function testNotificaApenasGestoresExcluindoSolicitante(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $this->em->persist($tenant);

        // Papel COM a permissão de gestão de service desk (não-sistema, força o lookup).
        $permissao = $this->obterPermissao('admin.servicedesk.manage');
        $papelGestor = $this->criarPapel($tenant, 'Gestor TI', $permissao);

        // Papel SEM nenhuma permissão de service desk.
        $papelComum = $this->criarPapel($tenant, 'Colaborador', null);

        $gestor      = $this->criarUsuario($tenant, $papelGestor, 'gestor_');
        $comum       = $this->criarUsuario($tenant, $papelComum, 'comum_');
        // Solicitante também é gestor: deve ser excluído mesmo assim.
        $solicitante = $this->criarUsuario($tenant, $papelGestor, 'solic_');

        $chamado = new Chamado();
        $chamado->setTitulo('Impressora não funciona');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($solicitante);
        $this->em->persist($chamado);
        $this->em->flush();

        $tenantId      = (int) $tenant->getId();
        $chamadoId     = (int) $chamado->getId();
        $gestorId      = (int) $gestor->getId();
        $comumId       = (int) $comum->getId();
        $solicitanteId = (int) $solicitante->getId();

        // Recarrega tudo do banco: o lado inverso (coleção de permissões do papel) só é
        // carregado via lazy-load quando partimos de entidades frescas.
        $this->em->clear();

        $tenant  = $this->em->find(Tenant::class, $tenantId);
        $chamado = $this->em->find(Chamado::class, $chamadoId);
        $url     = '/servicedesk/' . $chamadoId;

        $this->service->notificarNovoChamado($chamado, $tenant, $url);

        self::assertCount(1, $this->notificacoesDe($gestorId), 'gestor com a permissão deve receber 1 notificação');
        self::assertCount(0, $this->notificacoesDe($comumId), 'usuário sem a permissão não recebe');
        self::assertCount(0, $this->notificacoesDe($solicitanteId), 'o solicitante é excluído mesmo sendo gestor');

        $notificacao = $this->notificacoesDe($gestorId)[0];
        self::assertSame(Notificacao::TIPO_SERVICEDESK_NOVO, $notificacao->getTipo());
        self::assertSame($url, $notificacao->getUrl());
        self::assertStringContainsString('Impressora não funciona', $notificacao->getTitulo());
        self::assertSame(Notificacao::CATEGORIA_GESTAO, $notificacao->getCategoria());
    }

    #[TestDox('notificarNovoChamado não vaza para gestores de outro tenant')]
    public function testNaoNotificaGestorDeOutroTenant(): void
    {
        $permissao = $this->obterPermissao('admin.servicedesk.manage');

        $tenantA = new Tenant();
        $tenantA->setName('Tenant A ' . uniqid());
        $this->em->persist($tenantA);

        $tenantB = new Tenant();
        $tenantB->setName('Tenant B ' . uniqid());
        $this->em->persist($tenantB);

        $gestorA = $this->criarUsuario($tenantA, $this->criarPapel($tenantA, 'Gestor A', $permissao), 'gestorA_');
        $gestorB = $this->criarUsuario($tenantB, $this->criarPapel($tenantB, 'Gestor B', $permissao), 'gestorB_');
        $solicitante = $this->criarUsuario($tenantA, $this->criarPapel($tenantA, 'Colab A', null), 'solic_');

        $chamado = new Chamado();
        $chamado->setTitulo('Chamado do tenant A');
        $chamado->setTenant($tenantA);
        $chamado->setSolicitante($solicitante);
        $this->em->persist($chamado);
        $this->em->flush();

        $tenantAId = (int) $tenantA->getId();
        $chamadoId = (int) $chamado->getId();
        $gestorAId = (int) $gestorA->getId();
        $gestorBId = (int) $gestorB->getId();
        $this->em->clear();

        $tenantA = $this->em->find(Tenant::class, $tenantAId);
        $chamado = $this->em->find(Chamado::class, $chamadoId);

        $this->service->notificarNovoChamado($chamado, $tenantA, '/servicedesk/' . $chamadoId);

        self::assertCount(1, $this->notificacoesDe($gestorAId), 'gestor do tenant do chamado recebe');
        self::assertCount(0, $this->notificacoesDe($gestorBId), 'gestor de outro tenant NÃO recebe (isolamento)');
    }

    #[TestDox('notificarNovoChamado não cria notificações quando não há gestores no tenant')]
    public function testSemGestoresNaoCriaNotificacoes(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $this->em->persist($tenant);

        $papelComum  = $this->criarPapel($tenant, 'Colaborador', null);
        $comum       = $this->criarUsuario($tenant, $papelComum, 'comum_');
        $solicitante = $this->criarUsuario($tenant, $papelComum, 'solic_');

        $chamado = new Chamado();
        $chamado->setTitulo('Sem gestores');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($solicitante);
        $this->em->persist($chamado);
        $this->em->flush();

        $tenantId  = (int) $tenant->getId();
        $chamadoId = (int) $chamado->getId();
        $comumId   = (int) $comum->getId();
        $this->em->clear();

        $tenant  = $this->em->find(Tenant::class, $tenantId);
        $chamado = $this->em->find(Chamado::class, $chamadoId);

        $this->service->notificarNovoChamado($chamado, $tenant, '/servicedesk/' . $chamadoId);

        self::assertCount(0, $this->notificacoesDe($comumId));
    }

    private function obterPermissao(string $code): Permission
    {
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => $code]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($code);
            $permissao->setDescription('Gestão de chamados Service Desk (TI)');
            $permissao->setGroup('admin');
            $this->em->persist($permissao);
        }

        return $permissao;
    }

    private function criarPapel(Tenant $tenant, string $nome, ?Permission $permissao): TenantRole
    {
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName($nome . ' ' . uniqid());
        $papel->setIsSystem(false);
        $this->em->persist($papel);

        if ($permissao !== null) {
            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($papel);
            $vinculo->setPermission($permissao);
            $this->em->persist($vinculo);
        }

        return $papel;
    }

    private function criarUsuario(Tenant $tenant, TenantRole $papel, string $prefixo): User
    {
        $user = new User();
        $user->setEmail($prefixo . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $this->em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($papel);
        $this->em->persist($userTenant);

        return $user;
    }

    /**
     * @return Notificacao[]
     */
    private function notificacoesDe(int $usuarioId): array
    {
        $usuario = $this->em->find(User::class, $usuarioId);

        return $this->em->getRepository(Notificacao::class)->findBy(['usuario' => $usuario]);
    }
}
