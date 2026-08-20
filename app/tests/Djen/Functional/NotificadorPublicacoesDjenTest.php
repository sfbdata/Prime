<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Service\NotificadorPublicacoesDjen;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cobre a query REAL de destinatários do NotificadorPublicacoesDjen — nos demais testes o notificador
 * é mockado, então essa query (join arbitrário User↔UserTenant) só era exercitada em produção.
 *
 * Ponto sutil: para usuário comum, o PermissionChecker::canAccessModule já é tenant-scoped (resolve o
 * UserTenant por user+tenant e exige isActive), então um gestor de OUTRO escritório é barrado pela
 * permissão MESMO sem o filtro do join — um teste só com gestor comum seria falso-positivo para as
 * cláusulas do join. Quem NÃO é barrado pela permissão é o ROLE_SUPER_ADMIN (canAccessModule
 * curto-circuita para true sem olhar o tenant). Por isso os super-admins (de outro escritório e o
 * inativo do próprio) são os casos que travam de fato `ut.tenant = :tenant` e `ut.isActive = true` do
 * join — exatamente o comportamento que a troca WITH→ON precisa preservar.
 */
#[CoversClass(NotificadorPublicacoesDjen::class)]
final class NotificadorPublicacoesDjenTest extends KernelTestCase
{
    use CriaFixturesDjenTrait;

    #[Test]
    public function notificaSomenteMembrosAtivosDoEscritorioComAcessoAoModulo(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Tenant A — dono das publicações.
        $tenantA = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestor_a_' . uniqid() . '@test.com'); // ativo + acesso → DEVE receber
        $this->criarUsuarioComum($tenantA);                                            // sem modules.djen.view → NÃO
        // Super-admin INATIVO do tenant A: a permissão o deixaria passar (curto-circuito);
        // só a cláusula `ut.isActive = true` do join o barra.
        $this->criarSuperAdmin($tenantA, ativo: false);                                // inativo → NÃO

        // Tenant B — outro escritório.
        $tenantB = $this->criarTenant();
        $this->criarGestor($tenantB, 'gestor_b_' . uniqid() . '@test.com');            // outro tenant → NÃO (via permissão)
        // Super-admin ATIVO do tenant B: a permissão o deixaria passar (curto-circuito);
        // só a cláusula `ut.tenant = :tenant` do join o barra — cenário real de vazamento cross-tenant.
        $this->criarSuperAdmin($tenantB, ativo: true);                                 // outro tenant → NÃO (via join)

        $gestorAId = $gestorA->getId();
        $tenantAId = $tenantA->getId();

        $notificador = $container->get(NotificadorPublicacoesDjen::class);
        $notificados = $notificador->notificar($tenantA, 3);

        // Se o join afrouxar o filtro de tenant OU de isActive, algum super-admin entra e a conta passa de 1.
        self::assertSame(1, $notificados, 'só o gestor ativo do tenant A com acesso ao módulo deve ser notificado');

        $this->limparIdentityMap();
        $notificacoes = $em->getRepository(Notificacao::class)
            ->findBy(['tipo' => Notificacao::TIPO_DJEN_PUBLICACAO]);

        self::assertCount(1, $notificacoes, 'deveria existir exatamente uma notificação DJEN');
        self::assertSame($gestorAId, $notificacoes[0]->getUsuario()?->getId(), 'destinatário deve ser o gestor do tenant A');
        self::assertSame($tenantAId, $notificacoes[0]->getTenant()?->getId(), 'notificação deve pertencer ao tenant A');
    }

    /**
     * Super-admin global (ROLE_SUPER_ADMIN) vinculado a um escritório. O papel faz o PermissionChecker
     * liberar qualquer módulo sem olhar o tenant, então só as cláusulas do join controlam se ele entra
     * na lista de destinatários — é o caso que dá poder de detecção ao teste.
     */
    private function criarSuperAdmin(Tenant $tenant, bool $ativo): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('super_' . uniqid() . '@test.com');
        $user->setFullName('Super ' . uniqid());
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        if (!$ativo) {
            // O conceito de demissão não existe mais (UserTenant::demitir() foi enterrado) —
            // um vínculo inativo só sobra hoje como legado (spec §6.5/§6.6).
            (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($userTenant, false);
        }
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }
}
