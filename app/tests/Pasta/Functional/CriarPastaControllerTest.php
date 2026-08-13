<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(PastaController::class)]
final class CriarPastaControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Criar ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_criar_' . uniqid() . '@test.com');
        $user->setFullName('Admin Criar');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        return [$user, $tenant];
    }

    private function criarUsuarioSemModuloPastas(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Restrito ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_restrito_' . uniqid() . '@test.com');
        $user->setFullName('User Restrito');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // UserTenant sem TenantRole → canAccessModule retorna false
        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        return [$user, $tenant];
    }

    #[TestDox('POST /nova com NUP válido cria pasta e redireciona para pasta_show')]
    public function testSucessoCriaPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $nup             = 'NUP-OK-' . strtoupper(uniqid());

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', [
            'nup'          => $nup,
            'nome_cliente' => 'Cliente Teste',
            'nome_acao'    => 'Ação Teste',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/pasta/\d+$#', $location);

        preg_match('#/pasta/(\d+)$#', $location, $m);
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = $em->find(Pasta::class, (int) $m[1]);

        self::assertNotNull($pasta);
        self::assertSame($tenant->getId(), $pasta->getTenant()->getId());
        self::assertSame($user->getId(), $pasta->getCriadoPor()->getId());
        self::assertSame('CLIENTE TESTE', $pasta->getNomeCliente());
        self::assertSame('AÇÃO TESTE', $pasta->getNomeAcao());
    }

    #[TestDox('POST /nova com NUP em branco agora GERA o número automaticamente (R1)')]
    public function testNupEmBrancoGeraNumeroAutomatico(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nup' => '   ']);

        // Antes do R1 isto era erro ("O NUP é obrigatório") e voltava para o expediente sem
        // criar nada. Agora o sistema atribui o próximo número livre do escritório.
        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/pasta/\d+$#', $location);

        preg_match('#/pasta/(\d+)$#', $location, $m);
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = $em->find(Pasta::class, (int) $m[1]);

        self::assertNotNull($pasta);
        self::assertSame($tenant->getId(), $pasta->getTenant()->getId());
        // Escritório novo, sem nenhuma pasta: o primeiro número é 1.
        self::assertSame('1', $pasta->getNup());
    }

    #[TestDox('Números gerados não se repetem dentro do mesmo escritório')]
    public function testNumerosGeradosNaoSeRepetem(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();

        $this->logarComTenant($client, $user, $tenant);

        $nups = [];
        for ($i = 0; $i < 3; $i++) {
            $client->request('POST', '/pasta/nova', ['nup' => '']);
            self::assertResponseRedirects();
            preg_match('#/pasta/(\d+)$#', (string) $client->getResponse()->headers->get('Location'), $m);
            $em    = static::getContainer()->get(EntityManagerInterface::class);
            $pasta = $em->find(Pasta::class, (int) $m[1]);
            $nups[] = $pasta->getNup();
        }

        self::assertSame(['1', '2', '3'], $nups);
    }

    #[TestDox('POST /nova com NUP duplicado agora cria segunda pasta (NUP repetível)')]
    public function testNupDuplicadoCriaSegundaPasta(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $nup = 'NUP-DUP-' . strtoupper(uniqid());

        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', '/pasta/nova', ['nup' => $nup]);
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#/pasta/\d+$#', (string) $client->getResponse()->headers->get('Location'));

        $client->request('POST', '/pasta/nova', ['nup' => $nup]);
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#/pasta/\d+$#', (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(2, $em->getRepository(Pasta::class)->findBy(['nup' => $nup]));
    }

    #[TestDox('POST /nova sem módulo pastas redireciona para expediente_index sem criar pasta')]
    public function testSemModuloPastasRedireciona(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioSemModuloPastas();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', '/pasta/nova', ['nup' => 'NUP-SEM-MOD-' . uniqid()]);

        self::assertResponseRedirects();
        self::assertStringContainsString('expediente', (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant]));
    }
}
