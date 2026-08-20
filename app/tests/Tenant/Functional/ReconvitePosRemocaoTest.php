<?php
declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\RemoverColaboradorInput;
use App\Tenant\UseCase\RemoverColaboradorDoEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fecha o pedido do dono (spec §7 item 4): remover → re-convidar → a pessoa volta SEM
 * permissão item a item, SEM pedido de acesso pendente, SEM liberação de geocerca e SEM
 * notificação antigas — revogarAcessos() apaga as quatro tabelas (resource_access,
 * access_request, home_office_config, notificacao) na hora da remoção, então não há nada
 * para "sobreviver" ao reaceite do convite (que só cria um UserTenant novo).
 *
 * Cada asserção de zerado tem sua contraparte no escritório B, provando que o corte é
 * escopado por tenant_id — e uma asserção de que registro_ponto (dado histórico, spec §5)
 * continua no banco depois da remoção.
 */
#[CoversClass(RemoverColaboradorDoEscritorioUseCase::class)]
final class ReconvitePosRemocaoTest extends JusPrimeWebTestCase
{
    #[TestDox('apos remover e re-convidar, os acessos das 4 tabelas nao voltam, e o outro escritorio fica intacto')]
    public function testAcessosNaoSobrevivemAoReconvite(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenantA = new Tenant();
        $tenantA->setName('Escritório A ' . uniqid());
        $tenantB = new Tenant();
        $tenantB->setName('Escritório B ' . uniqid());
        $em->persist($tenantA);
        $em->persist($tenantB);

        $admin = new User();
        $admin->setEmail('admin_' . uniqid() . '@test.com');
        $admin->setFullName('Admin A');
        $em->persist($admin);

        // A mesma pessoa tem vínculo ativo nos dois escritórios — é o cenário que prova
        // isolamento de verdade: mesmo user_id nos dois lados, só o tenant_id muda.
        $colaborador = new User();
        $colaborador->setEmail('colab_' . uniqid() . '@test.com');
        $colaborador->setFullName('Colaborador Multi-Tenant');
        $em->persist($colaborador);

        $em->persist(new UserTenant($admin, $tenantA));
        $em->persist(new UserTenant($colaborador, $tenantA));
        $em->persist(new UserTenant($colaborador, $tenantB));
        $em->flush();

        // As quatro tabelas da revogação, em CADA escritório. Quem tem índice único que NÃO
        // inclui tenant_id é resource_access (uniq_resource_access_user_resource: user_id,
        // resource_type, resource_id) — ali o resourceId PRECISA diferir entre A e B, senão a
        // segunda gravação colide na constraint em vez de exercitar o isolamento.
        // access_request não tem índice único nenhum (medido no banco); o resourceId diferente
        // dela é só simetria com o vizinho.
        $this->concederResourceAccess($em, $colaborador, $tenantA, 1);
        $this->concederResourceAccess($em, $colaborador, $tenantB, 2);

        $this->criarAccessRequest($em, $colaborador, $tenantA, 1);
        $this->criarAccessRequest($em, $colaborador, $tenantB, 2);

        $this->concederHomeOffice($em, $colaborador, $tenantA);
        $this->concederHomeOffice($em, $colaborador, $tenantB);

        $this->criarNotificacao($em, $colaborador, $tenantA);
        $this->criarNotificacao($em, $colaborador, $tenantB);

        // registro_ponto: dado histórico que a spec §5 manda PRESERVAR — não faz parte da
        // revogação, e precisa continuar existindo depois da remoção.
        $registroPonto = $this->criarRegistroPonto($em, $colaborador, $tenantA);

        $em->flush();

        // Remove pelo UseCase, só no escritório A. Montado à mão para ficar preso ao MESMO
        // EntityManager/conexão que o teste usa para montar o cenário e para conferir as
        // contagens depois — o controller que hoje consome este UseCase é exercitado em
        // RemoverColaboradorControllerTest.
        $useCase = new RemoverColaboradorDoEscritorioUseCase(
            $em,
            static::getContainer()->get(UserTenantRepository::class),
        );
        $useCase->executar(new RemoverColaboradorInput($admin, $colaborador, $tenantA));

        // Simula o aceite do convite de volta: recria o vínculo em A. Não recria nada das
        // outras quatro tabelas — se algo delas ainda existir com (colaborador, tenantA),
        // é porque a revogação não limpou, não porque o reaceite trouxe de volta.
        $em->persist(new UserTenant($colaborador, $tenantA));
        $em->flush();

        $conn = $em->getConnection();
        $uid  = $colaborador->getId();
        $tidA = $tenantA->getId();
        $tidB = $tenantB->getId();

        // --- Escritório A: as quatro tabelas ficaram zeradas e não voltaram com o re-convite.
        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM resource_access WHERE user_id = ? AND tenant_id = ?',
            [$uid, $tidA]
        ), 'a permissão item a item sobreviveu ao re-convite');

        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM access_request WHERE user_id = ? AND tenant_id = ?',
            [$uid, $tidA]
        ), 'o pedido de acesso pendente sobreviveu ao re-convite');

        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM home_office_config WHERE user_id = ? AND tenant_id = ?',
            [$uid, $tidA]
        ), 'a liberação de geocerca sobreviveu ao re-convite');

        self::assertSame(0, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tenant_id = ?',
            [$uid, $tidA]
        ), 'a notificação sobreviveu ao re-convite');

        // --- Escritório B: nada foi tocado — mesmo user_id, tenant_id diferente.
        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM resource_access WHERE user_id = ? AND tenant_id = ?',
            [$uid, $tidB]
        ), 'a permissão item a item do escritório B NÃO deveria ter sido tocada');

        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM access_request WHERE user_id = ? AND tenant_id = ?',
            [$uid, $tidB]
        ), 'o pedido de acesso do escritório B NÃO deveria ter sido tocado');

        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM home_office_config WHERE user_id = ? AND tenant_id = ?',
            [$uid, $tidB]
        ), 'a liberação de geocerca do escritório B NÃO deveria ter sido tocada');

        self::assertSame(1, (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM notificacao WHERE usuario_id = ? AND tenant_id = ?',
            [$uid, $tidB]
        ), 'a notificação do escritório B NÃO deveria ter sido tocada');

        // --- registro_ponto (preservado, spec §5): a remoção não pode ter apagado histórico.
        $em->clear();
        self::assertNotNull(
            $em->find(RegistroPonto::class, $registroPonto->getId()),
            'registro_ponto é histórico/prova e a spec §5 manda preservá-lo — não pode sumir'
        );
    }

    private function concederResourceAccess(EntityManagerInterface $em, User $user, Tenant $tenant, int $resourceId): void
    {
        $ra = new ResourceAccess();
        $ra->setUser($user)
           ->setTenant($tenant)
           ->setResourceType(ResourceAccess::RESOURCE_PASTA)
           ->setResourceId($resourceId)
           ->setCanView(true);
        $em->persist($ra);
    }

    private function criarAccessRequest(EntityManagerInterface $em, User $user, Tenant $tenant, int $resourceId): void
    {
        $ar = new AccessRequest();
        $ar->setUser($user)
           ->setTenant($tenant)
           ->setResourceType(AccessRequest::RESOURCE_PASTA)
           ->setResourceId($resourceId)
           ->setAction(AccessRequest::ACTION_VIEW);
        $em->persist($ar);
    }

    private function concederHomeOffice(EntityManagerInterface $em, User $user, Tenant $tenant): void
    {
        $hoc = new HomeOfficeConfig();
        $hoc->setUser($user)
            ->setTenant($tenant)
            ->setDiasSemana([1, 2, 3, 4, 5]);
        $em->persist($hoc);
    }

    private function criarNotificacao(EntityManagerInterface $em, User $user, Tenant $tenant): void
    {
        $notif = new Notificacao();
        $notif->setUsuario($user)
              ->setTenant($tenant)
              ->setTipo(Notificacao::TIPO_TAREFA_CRIADA)
              ->setTitulo('Notificação de teste');
        $em->persist($notif);
    }

    private function criarRegistroPonto(EntityManagerInterface $em, User $user, Tenant $tenant): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setUser($user)
                  ->setTenant($tenant)
                  ->setDataHora(new \DateTime('2026-03-10 08:00:00'))
                  ->setTipo(RegistroPonto::TIPO_ENTRADA);
        $em->persist($registro);

        return $registro;
    }
}
