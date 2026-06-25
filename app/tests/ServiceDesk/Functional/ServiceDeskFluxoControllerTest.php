<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Controller\ServiceDeskController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoInteracao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rede de testes do comportamento ATUAL do ServiceDesk legado, antes da migração (E4.1).
 * Trava o contrato das actions show/interacao/atribuir/status para a migração não regredir.
 */
#[CoversClass(ServiceDeskController::class)]
final class ServiceDeskFluxoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Dashboard exige permissão de gestão (não-gestor recebe 403)')]
    public function testIndexExigeGestor(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $comum  = $this->criarComum($tenant, 'comum_' . uniqid() . '@test.com');

        $this->logarComTenant($client, $comum, $tenant);
        $client->request('GET', '/servicedesk');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Solicitante vê o próprio chamado; estranho recebe 403; gestor vê qualquer um')]
    public function testShowControlaAcesso(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $dono     = $this->criarComum($tenant, 'dono_' . uniqid() . '@test.com');
        $estranho = $this->criarComum($tenant, 'estr_' . uniqid() . '@test.com');
        $chamado  = $this->criarChamado($tenant, $dono);
        $id = (int) $chamado->getId();

        $this->logarComTenant($client, $dono, $tenant);
        $client->request('GET', "/servicedesk/{$id}");
        self::assertResponseIsSuccessful();

        // Reusa o mesmo client (o kernel só pode ser bootado uma vez): troca o usuário logado.
        $this->logarComTenant($client, $estranho, $tenant);
        $client->request('GET', "/servicedesk/{$id}");
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Interação do solicitante cria comentário e redireciona')]
    public function testInteracaoSolicitanteCriaComentario(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $dono    = $this->criarComum($tenant, 'dono_' . uniqid() . '@test.com');
        $chamado = $this->criarChamado($tenant, $dono);
        $id = (int) $chamado->getId();

        $this->logarComTenant($client, $dono, $tenant);
        $crawler = $client->request('GET', "/servicedesk/{$id}");
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enviar')->form([
            'chamado_interacao[mensagem]' => 'Alguma novidade sobre meu chamado?',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $interacoes = $em->getRepository(ChamadoInteracao::class)->findBy(['chamado' => $id]);
        // A interação de abertura (SISTEMA) + o comentário recém-criado.
        $comentarios = array_filter(
            $interacoes,
            static fn(ChamadoInteracao $i) => $i->getTipo() === ChamadoInteracao::TIPO_COMENTARIO
        );
        self::assertCount(1, $comentarios);
    }

    #[TestDox('Atribuição por gestor define responsável, move para em andamento e notifica')]
    public function testAtribuirPorGestor(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $dono    = $this->criarComum($tenant, 'dono_' . uniqid() . '@test.com');
        $tecnico = $this->criarComum($tenant, 'tec_' . uniqid() . '@test.com');
        $chamado = $this->criarChamado($tenant, $dono);
        $id = (int) $chamado->getId();
        $tecnicoId = (int) $tecnico->getId();

        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('POST', "/servicedesk/{$id}/atribuir", ['responsavel_id' => $tecnicoId]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $chamado = $em->find(Chamado::class, $id);
        self::assertSame($tecnicoId, $chamado->getResponsavel()?->getId());
        self::assertSame(Chamado::STATUS_EM_ANDAMENTO, $chamado->getStatus());

        $notifs = $em->getRepository(Notificacao::class)->findBy(['usuario' => $tecnico]);
        self::assertCount(1, $notifs);
        self::assertSame(Notificacao::TIPO_SERVICEDESK_ATRIBUICAO, $notifs[0]->getTipo());
    }

    #[TestDox('Atribuição por usuário comum é negada (403)')]
    public function testAtribuirPorComumNegado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $comum   = $this->criarComum($tenant, 'comum_' . uniqid() . '@test.com');
        $chamado = $this->criarChamado($tenant, $comum);
        $id = (int) $chamado->getId();

        $this->logarComTenant($client, $comum, $tenant);
        $client->request('POST', "/servicedesk/{$id}/atribuir", ['responsavel_id' => (int) $comum->getId()]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Mudança de status por gestor troca status, registra interação e notifica solicitante')]
    public function testAlterarStatusPorGestor(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $dono    = $this->criarComum($tenant, 'dono_' . uniqid() . '@test.com');
        $chamado = $this->criarChamado($tenant, $dono);
        $id = (int) $chamado->getId();

        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('POST', "/servicedesk/{$id}/status", ['status' => Chamado::STATUS_RESOLVIDO]);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $chamado = $em->find(Chamado::class, $id);
        self::assertSame(Chamado::STATUS_RESOLVIDO, $chamado->getStatus());

        $notifs = $em->getRepository(Notificacao::class)->findBy(['usuario' => $dono]);
        self::assertCount(1, $notifs);
        self::assertSame(Notificacao::TIPO_SERVICEDESK, $notifs[0]->getTipo());
    }

    #[TestDox('Status inválido não altera o chamado')]
    public function testAlterarStatusInvalido(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor  = $this->criarGestor($tenant, 'gestor_' . uniqid() . '@test.com');
        $dono    = $this->criarComum($tenant, 'dono_' . uniqid() . '@test.com');
        $chamado = $this->criarChamado($tenant, $dono);
        $id = (int) $chamado->getId();

        $this->logarComTenant($client, $gestor, $tenant);
        $client->request('POST', "/servicedesk/{$id}/status", ['status' => 'status_inexistente']);
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $chamado = $em->find(Chamado::class, $id);
        self::assertSame(Chamado::STATUS_ABERTO, $chamado->getStatus(), 'status inválido não deve alterar o chamado');
    }

    #[TestDox('Gestor não acessa chamado de outro tenant por ID (404)')]
    public function testChamadoDeOutroTenantRetorna404(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $donoB   = $this->criarComum($tenantB, 'donoB_' . uniqid() . '@test.com');
        $chamadoB = $this->criarChamado($tenantB, $donoB);
        $id = (int) $chamadoB->getId();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $client->request('GET', "/servicedesk/{$id}");
        self::assertResponseStatusCodeSame(404, 'show não pode revelar chamado de outro tenant');

        $client->request('POST', "/servicedesk/{$id}/status", ['status' => Chamado::STATUS_FECHADO]);
        self::assertResponseStatusCodeSame(404, 'status não pode alterar chamado de outro tenant');

        // O chamado do tenant B permanece intacto.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(Chamado::STATUS_ABERTO, $em->find(Chamado::class, $id)->getStatus());
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SD ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        return $this->criarUsuario($tenant, $email, true);
    }

    private function criarComum(Tenant $tenant, string $email): User
    {
        return $this->criarUsuario($tenant, $email, false);
    }

    private function criarUsuario(Tenant $tenant, string $email, bool $isSistema): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Usuário ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName(($isSistema ? 'Gestor ' : 'Colaborador ') . uniqid());
        $role->setIsSystem($isSistema);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarChamado(Tenant $tenant, User $solicitante): Chamado
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $chamado = new Chamado();
        $chamado->setTitulo('Chamado de teste ' . uniqid());
        $chamado->setDescricao('Descrição do problema');
        $chamado->setTenant($tenant);
        $chamado->setSolicitante($solicitante);

        // Interação de abertura, como no fluxo real do controller.
        $interacao = new ChamadoInteracao();
        $interacao->setChamado($chamado);
        $interacao->setAutor($solicitante);
        $interacao->setTipo(ChamadoInteracao::TIPO_SISTEMA);
        $interacao->setMensagem('Chamado aberto');
        $chamado->addInteracao($interacao);

        $em->persist($chamado);
        $em->persist($interacao);
        $em->flush();

        return $chamado;
    }
}
