<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Controller\NotificacaoController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Isolamento do sino de notificações via HTTP (M4). Um colaborador vinculado aos escritórios
 * A e B, com uma notificação em cada, logado em B: o contador/lista mostra só B, o marcar-como-
 * lida de uma notificação de A responde 404 (o TenantFilter zera o find por id), e o "marcar
 * todas" em B não toca a notificação de A (escopo explícito no bulk, que escapa o filtro).
 */
#[CoversClass(NotificacaoController::class)]
final class NotificacaoIsolamentoControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Contador só conta as notificações do escritório ativo (logado em B não vê a de A)')]
    public function testContadorIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUsuario($tenantA);
        $this->vincular($user, $tenantB);

        $this->criarNotificacao($user, $tenantA, 'Notif de A');
        $this->criarNotificacao($user, $tenantB, 'Notif de B');

        $this->logarComTenant($client, $user, $tenantB);
        $client->request('GET', '/notificacoes/count');

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $dados['count'], 'logado em B, o contador deveria ver só a notificação de B');
    }

    #[TestDox('Índice lista só as notificações do escritório ativo')]
    public function testIndiceIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUsuario($tenantA);
        $this->vincular($user, $tenantB);

        $this->criarNotificacao($user, $tenantA, 'Segredo do escritorio A');
        $this->criarNotificacao($user, $tenantB, 'Coisa do escritorio B');

        $this->logarComTenant($client, $user, $tenantB);
        $client->request('GET', '/notificacoes');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Coisa do escritorio B', $html);
        self::assertStringNotContainsString('Segredo do escritorio A', $html, 'vazou notificação de outro escritório no índice');
    }

    #[TestDox('O dropdown do sino lista só as notificações do escritório ativo')]
    public function testListaDropdownIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUsuario($tenantA);
        $this->vincular($user, $tenantB);

        $this->criarNotificacao($user, $tenantA, 'Dropdown segredo de A');
        $this->criarNotificacao($user, $tenantB, 'Dropdown coisa de B');

        $this->logarComTenant($client, $user, $tenantB);
        $client->request('GET', '/notificacoes/lista-dropdown');

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $dados['count'], 'o contador do dropdown deveria ver só a de B');
        self::assertStringContainsString('Dropdown coisa de B', $dados['html']);
        self::assertStringNotContainsString('Dropdown segredo de A', $dados['html'], 'vazou notificação de A no dropdown');
    }

    #[TestDox('A aba Gestão (gate podeVerGestao + lista) é escopada por escritório')]
    public function testAbaGestaoIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUsuario($tenantA);
        $this->vincular($user, $tenantB);

        // Notificações de GESTÃO (TIPOS_GESTAO) em cada escritório.
        $this->criarNotificacaoGestao($user, $tenantA, 'Gestao de A');
        $this->criarNotificacaoGestao($user, $tenantB, 'Gestao de B');

        $this->logarComTenant($client, $user, $tenantB);
        $client->request('GET', '/notificacoes/lista-dropdown?categoria=gestao');

        // podeVerGestao (temNotificacaoGestao) é DQL → filtrado: em B o usuário TEM gestão (200),
        // mas só enxerga a de B.
        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(1, $dados['count'], 'a aba gestão deveria contar só a de B');
        self::assertStringContainsString('Gestao de B', $dados['html']);
        self::assertStringNotContainsString('Gestao de A', $dados['html'], 'vazou notificação de gestão de A');
    }

    #[TestDox('Marcar como lida uma notificação de outro escritório responde 404 (IDOR fechado)')]
    public function testMarcarComoLidaDeOutroTenantDa404(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUsuario($tenantA);
        $this->vincular($user, $tenantB);

        $notifA = $this->criarNotificacao($user, $tenantA, 'Notif de A');
        $idA = (int) $notifA->getId();

        $this->logarComTenant($client, $user, $tenantB);

        // Esvazia a identity map: numa request real a notificação não está em cache, então o
        // find por id do value resolver vai ao banco e o TenantFilter o transforma em 404.
        // (Sem isso, o teste funcional veria a entidade gerenciada e mascararia o filtro.)
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $client->request('POST', "/notificacoes/{$idA}/ler");

        self::assertResponseStatusCodeSame(404, 'a notificação de A não pode ser alcançada por id logado em B');
    }

    #[TestDox('Marcar todas como lidas em B não marca a notificação de A (bulk escopado por tenant)')]
    public function testMarcarTodasComoLidasNaoTocaOutroTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUsuario($tenantA);
        $this->vincular($user, $tenantB);

        $notifA = $this->criarNotificacao($user, $tenantA, 'Notif de A');
        $notifB = $this->criarNotificacao($user, $tenantB, 'Notif de B');
        $idA = (int) $notifA->getId();
        $idB = (int) $notifB->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenantB);

        $client->request('POST', '/notificacoes/marcar-todas-lidas', [
            '_token' => 'TOKEN_marcar_todas_lidas',
        ]);

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getFilters()->disable('tenant');
        $em->clear();

        self::assertTrue($em->find(Notificacao::class, $idB)->isLida(), 'a notificação de B deveria ter sido marcada lida');
        self::assertFalse(
            $em->find(Notificacao::class, $idA)->isLida(),
            'marcar todas logado em B NÃO pode marcar a notificação de A',
        );
    }

    // ----------------------------------------------------------------- helpers

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Iso Notif ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('notif_iso_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Notif Iso');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('dummy_hash');
        $em->persist($user);

        $this->vincular($user, $tenant);

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();
    }

    private function criarNotificacao(User $usuario, Tenant $tenant, string $titulo): Notificacao
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $notif = new Notificacao();
        $notif->setUsuario($usuario);
        $notif->setTenant($tenant);
        $notif->setTipo(Notificacao::TIPO_TAREFA_CRIADA);
        $notif->setTitulo($titulo);
        $em->persist($notif);
        $em->flush();

        return $notif;
    }

    private function criarNotificacaoGestao(User $usuario, Tenant $tenant, string $titulo): Notificacao
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $notif = new Notificacao();
        $notif->setUsuario($usuario);
        $notif->setTenant($tenant);
        $notif->setTipo(Notificacao::TIPO_PONTO_JUSTIFICATIVA_ENVIADA); // pertence a TIPOS_GESTAO
        $notif->setTitulo($titulo);
        $notif->setUrl('/tenant/' . $tenant->getId() . '/gestao');
        $em->persist($notif);
        $em->flush();

        return $notif;
    }
}
