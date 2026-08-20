<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Entity\Marcador;
use App\Ponto\Entity\Feriado;
use App\Tenant\Controller\EscritorioController;
use App\Tenant\UseCase\ExcluirEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(EscritorioController::class)]
final class EscritorioControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant Sair ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /** @return array{0: User, 1: UserTenant} */
    private function criarUsuarioComVinculo(Tenant $tenant, bool $admin = false): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('sair_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Sair');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        if ($admin) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador do Escritório');
            $role->setIsSystem(true);
            $em->persist($role);
            $vinculo->setTenantRole($role);
        }
        $em->persist($vinculo);
        $em->flush();

        return [$user, $vinculo];
    }

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

    private function gerarCsrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    #[TestDox('POST sair sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/escritorio/1/sair');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST sair com CSRF inválido retorna 403')]
    public function testCsrfInvalidoRetorna403(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        [$user]   = $this->criarUsuarioComVinculo($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => 'token_invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('POST sair de colaborador apaga o vínculo')]
    public function testSaiComSucesso(): void
    {
        $client           = static::createClient();
        $tenant           = $this->criarTenant();
        [$user, $vinculo] = $this->criarUsuarioComVinculo($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenant->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $atualizado = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNull($atualizado, 'o vínculo deveria ter sido apagado, não apenas inativado');
    }

    #[TestDox('POST sair sendo o único admin é bloqueado e mantém o vínculo ativo')]
    public function testUltimoAdminBloqueado(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        [$user]   = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenant->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');

        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $atualizado = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($atualizado);
        self::assertTrue($atualizado->isActive());
    }

    #[TestDox('Sair do escritório ATIVO limpa o tenant da sessão (RS07)')]
    public function testSaiDoAtivoLimpaSessao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$user] = $this->criarUsuarioComVinculo($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenant->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertNull($client->getSession()->get('current_tenant_id'));
    }

    #[TestDox('Sair de escritório NÃO-ativo mantém o tenant ativo na sessão e apaga só o vínculo certo')]
    public function testSaiDeNaoAtivoMantemSessao(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        [$user]  = $this->criarUsuarioComVinculo($tenantA);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserTenant($user, $tenantB));
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenantA);
        $client->request('POST', "/escritorio/{$tenantB->getId()}/sair", [
            '_token' => $this->gerarCsrf('sair_' . $tenantB->getId()),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertSame($tenantA->getId(), $client->getSession()->get('current_tenant_id'));

        $em2      = static::getContainer()->get(EntityManagerInterface::class);
        $vinculoB = $em2->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenantB]);
        self::assertNull($vinculoB, 'o vínculo com o tenant B deveria ter sido apagado');

        $vinculoA = $em2->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenantA]);
        self::assertNotNull($vinculoA, 'o vínculo com o tenant A não deveria ser tocado');
        self::assertTrue($vinculoA->isActive());
    }

    private function novoUsuario(bool $comOab): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('criar_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Criar');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        if ($comOab) {
            $user->setOabNumero('12345');
            $user->setOabUf('SP');
        }
        $em->persist($user);
        $em->flush();

        return $user;
    }

    #[TestDox('GET criar é acessível para usuário SEM escritório (bypass do validador de tenant)')]
    public function testCriarAcessivelSemTenant(): void
    {
        $client = static::createClient();
        $user   = $this->novoUsuario(comOab: false);

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/escritorio/criar');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('POST criar (de dentro de outro escritório) cria, torna o usuário dono e ativa o novo')]
    public function testCriarPostCriaEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        [$user]  = $this->criarUsuarioComVinculo($tenantA);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user->setOabNumero('12345');
        $user->setOabUf('SP');
        $em->flush();

        // current = A: o TenantFilter fica ativo em A durante a criação do B (valida o gotcha).
        $this->logarComTenant($client, $user, $tenantA);
        $crawler = $client->request('GET', '/escritorio/criar');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Criar escritório')->form();
        $form['criar_escritorio[nome]'] = 'Banca Nova';
        $client->submit($form);

        self::assertResponseRedirects();

        $novo = $em->getRepository(Tenant::class)->findOneBy(['name' => 'Banca Nova']);
        self::assertNotNull($novo);
        self::assertSame($user->getId(), $novo->getCriadoPor()?->getId());
        self::assertTrue($novo->isActive());

        // Poder de detecção do gotcha do filtro: o seed (13 marcadores + 9 feriados) tem que
        // pertencer ao NOVO tenant B, não ter sido escopado/perdido pelo filtro ativo em A.
        // Desligo o filtro (ligado em A durante o request) para contar sem escopo.
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        self::assertSame(13, $em->getRepository(Marcador::class)->count(['tenant' => $novo]));
        self::assertSame(9, $em->getRepository(Feriado::class)->count(['tenant' => $novo]));
    }

    #[TestDox('POST criar é bloqueado quando o limite de escritórios próprios foi atingido (RN08)')]
    public function testCriarBloqueiaQuandoLimiteAtingido(): void
    {
        $client = static::createClient();
        $user   = $this->novoUsuario(comOab: true);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < 3; $i++) {
            $t = new Tenant();
            $t->setName('Own ' . uniqid());
            $t->setCriadoPor($user);
            $em->persist($t);
        }
        $em->flush();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $crawler = $client->request('GET', '/escritorio/criar');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Criar escritório')->form();
        $form['criar_escritorio[nome]'] = 'Quarta Banca';
        $client->submit($form);

        // Bloqueado: re-renderiza o formulário (não redireciona) e nada é criado.
        self::assertResponseIsSuccessful();
        self::assertNull($em->getRepository(Tenant::class)->findOneBy(['name' => 'Quarta Banca']));
    }

    #[TestDox('POST criar com OAB inválida não cria e não grava OAB no usuário')]
    public function testCriarComOabInvalidaNaoCria(): void
    {
        $client = static::createClient();
        $user   = $this->novoUsuario(comOab: false);

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $crawler = $client->request('GET', '/escritorio/criar');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Criar escritório')->form();
        $form['criar_escritorio[nome]']      = 'Banca X';
        $form['criar_escritorio[oabNumero]'] = '12A3';
        $form['criar_escritorio[oabUf]']     = 'SP';
        $client->submit($form);

        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Tenant::class)->findOneBy(['name' => 'Banca X']));
        self::assertNull($em->getRepository(User::class)->find($user->getId())->getOabNumero());
    }

    #[TestDox('Colaborador sem OAB cria com OAB válida no form: grava a OAB no User e cria o escritório')]
    public function testColaboradorSemOabCriaEGravaOab(): void
    {
        $client = static::createClient();
        $user   = $this->novoUsuario(comOab: false);

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $crawler = $client->request('GET', '/escritorio/criar');

        $form = $crawler->selectButton('Criar escritório')->form();
        $form['criar_escritorio[nome]']      = 'Banca do Colaborador';
        $form['criar_escritorio[oabNumero]'] = '54321';
        $form['criar_escritorio[oabUf]']     = 'RJ';
        $client->submit($form);

        self::assertResponseRedirects();

        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $novo = $em->getRepository(Tenant::class)->findOneBy(['name' => 'Banca do Colaborador']);
        self::assertNotNull($novo);

        $u = $em->getRepository(User::class)->find($user->getId());
        self::assertSame('54321', $u->getOabNumero());
        self::assertSame('RJ', $u->getOabUf());
    }

    #[TestDox('Admin exclui o próprio escritório: soft delete + excluidoEm + sessão limpa')]
    public function testExcluirSoftDelete(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$user] = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/excluir", [
            '_token'         => $this->gerarCsrf('excluir_' . $tenant->getId()),
            'confirmar_nome' => $tenant->getName(),
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $t  = $em->getRepository(Tenant::class)->find($tenant->getId());
        self::assertFalse($t->isActive());
        self::assertNotNull($t->getExcluidoEm());
        self::assertNull($client->getSession()->get('current_tenant_id'));
    }

    #[TestDox('Colaborador não-admin não pode excluir o escritório (403) e nada muda')]
    public function testExcluirNegadoParaNaoAdmin(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$user] = $this->criarUsuarioComVinculo($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/excluir", [
            '_token'         => $this->gerarCsrf('excluir_' . $tenant->getId()),
            'confirmar_nome' => $tenant->getName(),
        ]);

        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->getRepository(Tenant::class)->find($tenant->getId())->isActive());
    }

    #[TestDox('Confirmação com nome errado não exclui e volta à página do escritório')]
    public function testExcluirConfirmacaoErradaNaoExclui(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$user] = $this->criarUsuarioComVinculo($tenant, admin: true);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/escritorio/{$tenant->getId()}/excluir", [
            '_token'         => $this->gerarCsrf('excluir_' . $tenant->getId()),
            'confirmar_nome' => 'nome errado',
        ]);

        self::assertResponseRedirects("/tenant/{$tenant->getId()}");

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->getRepository(Tenant::class)->find($tenant->getId())->isActive());
    }

    #[TestDox('Escritório ativo excluído "por fora" derruba a sessão do colaborador no próximo request (C1/RS07)')]
    public function testTenantExcluidoPorForaDerrubaSessao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        [$user] = $this->criarUsuarioComVinculo($tenant);

        $this->logarComTenant($client, $user, $tenant);

        // Exclui o tenant "por fora" (outro admin): soft delete desativa só o Tenant,
        // não o vínculo do colaborador — que continua ativo.
        static::getContainer()->get(ExcluirEscritorioUseCase::class)->executar($tenant);

        // Próximo request numa rota protegida → o validador derruba para a seleção.
        $client->request('GET', '/expediente');

        self::assertResponseRedirects('/escritorio/selecionar');
    }
}
