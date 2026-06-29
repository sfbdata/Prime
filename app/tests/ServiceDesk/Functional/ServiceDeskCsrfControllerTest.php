<?php

declare(strict_types=1);

namespace App\Tests\ServiceDesk\Functional;

use App\Controller\ServiceDeskController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * M1 — as ações `atribuir` e `status` do ServiceDesk são POST de form HTML cru (não-Symfony-Form),
 * que liam o request direto sem CSRF (a frente C4 cobriu AJAX/JSON e forms Symfony, mas estes dois
 * escaparam). Agora exigem token por-intenção stateful (`servicedesk_atribuir_<id>`/`..._status_<id>`),
 * validado após os guards de tenant(404)/permissão(403). Sem token → 403; com token → sucesso.
 */
#[CoversClass(ServiceDeskController::class)]
final class ServiceDeskCsrfControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('atribuir: sem token CSRF 403, com token redireciona')]
    public function testAtribuirExigeCsrf(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarUsuario($tenant, true);
        $dono    = $this->criarUsuario($tenant, false);
        $tecnico = $this->criarUsuario($tenant, false);
        $chamado = $this->criarChamado($tenant, $dono);
        $id        = (int) $chamado->getId();
        $tecnicoId = (int) $tecnico->getId();

        $this->logarComTenant($client, $gestor, $tenant);

        $client->request('POST', "/servicedesk/{$id}/atribuir", ['responsavel_id' => $tecnicoId]);
        self::assertResponseStatusCodeSame(403, 'atribuir sem token CSRF deveria ser barrado');

        $client->request('POST', "/servicedesk/{$id}/atribuir", ['responsavel_id' => $tecnicoId, '_token' => 'TOKEN_servicedesk_atribuir_' . $id]);
        self::assertResponseRedirects();
    }

    #[TestDox('status: sem token CSRF 403, com token redireciona')]
    public function testStatusExigeCsrf(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarUsuario($tenant, true);
        $dono    = $this->criarUsuario($tenant, false);
        $chamado = $this->criarChamado($tenant, $dono);
        $id = (int) $chamado->getId();

        $this->logarComTenant($client, $gestor, $tenant);

        $client->request('POST', "/servicedesk/{$id}/status", ['status' => Chamado::STATUS_RESOLVIDO]);
        self::assertResponseStatusCodeSame(403, 'status sem token CSRF deveria ser barrado');

        $client->request('POST', "/servicedesk/{$id}/status", ['status' => Chamado::STATUS_RESOLVIDO, '_token' => 'TOKEN_servicedesk_status_' . $id]);
        self::assertResponseRedirects();
    }

    /**
     * Cobre os 5 forms de "Ações Rápidas" (form HTML cru) submetendo cada botão pelo CRAWLER, que
     * renderiza o template REAL. Se QUALQUER um perder o `_token`, o crawler submete sem token →
     * CSRF barra (403) → o caso correspondente fica RED. Fecha a CLASSE da regressão (não só 1 botão).
     */
    #[DataProvider('acoesRapidasProvider')]
    #[TestDox('ação rápida via crawler: "$botao" (de $statusInicial) renderiza o _token e muda o status')]
    public function testAcaoRapidaStatusViaCrawler(string $statusInicial, string $botao, string $statusEsperado): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $gestor  = $this->criarUsuario($tenant, true);
        $dono    = $this->criarUsuario($tenant, false);
        $chamado = $this->criarChamado($tenant, $dono, $statusInicial);
        $id = (int) $chamado->getId();

        $this->logarComTenant($client, $gestor, $tenant);
        $crawler = $client->request('GET', "/servicedesk/{$id}");
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton($botao)->form());
        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($statusEsperado, $em->find(Chamado::class, $id)->getStatus(), 'a ação rápida deveria ter mudado o status');
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function acoesRapidasProvider(): array
    {
        return [
            'aberto → iniciar atendimento' => [Chamado::STATUS_ABERTO, 'Iniciar Atendimento', Chamado::STATUS_EM_ANDAMENTO],
            'andamento → resolver'         => [Chamado::STATUS_EM_ANDAMENTO, 'Marcar como Resolvido', Chamado::STATUS_RESOLVIDO],
            'resolvido → fechar'           => [Chamado::STATUS_RESOLVIDO, 'Fechar Chamado', Chamado::STATUS_FECHADO],
            'resolvido → reabrir'          => [Chamado::STATUS_RESOLVIDO, 'Reabrir', Chamado::STATUS_EM_ANDAMENTO],
            'fechado → reabrir chamado'    => [Chamado::STATUS_FECHADO, 'Reabrir Chamado', Chamado::STATUS_ABERTO],
        ];
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
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant SD CSRF ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, bool $isSistema): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('u_' . uniqid() . '@test.com');
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

    private function criarChamado(Tenant $tenant, User $solicitante, string $status = Chamado::STATUS_ABERTO): Chamado
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $chamado = new Chamado();
        $chamado->setTitulo('Chamado CSRF ' . uniqid());
        $chamado->setDescricao('Descrição');
        $chamado->setCategoria(Chamado::CATEGORIA_SOFTWARE);
        $chamado->setPrioridade(Chamado::PRIORIDADE_MEDIA);
        $chamado->setStatus($status);
        $chamado->setSolicitante($solicitante);
        $chamado->setTenant($tenant);
        $em->persist($chamado);
        $em->flush();

        return $chamado;
    }
}
