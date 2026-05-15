<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\DemandasController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(DemandasController::class)]
final class DemandasControllerTest extends JusPrimeWebTestCase
{
    private function criarUsuarioComTenant(KernelBrowser $client): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Demandas ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_demandas_' . uniqid() . '@test.com');
        $user->setFullName('Funcionário Teste');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $em->persist($userTenant);

        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    private function criarPastaParaResponsavel(User $responsavel): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup('DEMAND-' . uniqid());
        $pasta->setNomeCliente('Cliente Teste');
        $pasta->setResponsavel($responsavel);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    #[TestDox('GET /demandas sem autenticação redireciona para login')]
    public function testSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/demandas');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /demandas autenticado retorna 200')]
    public function testAutenticadoRetorna200(): void
    {
        $client = static::createClient();
        $this->criarUsuarioComTenant($client);

        $client->request('GET', '/demandas');

        self::assertResponseIsSuccessful();
    }

    #[TestDox('GET /demandas exibe pasta onde usuário é responsável')]
    public function testExibePastaDoResponsavel(): void
    {
        $client           = static::createClient();
        [$usuario]        = $this->criarUsuarioComTenant($client);
        $pasta            = $this->criarPastaParaResponsavel($usuario);

        $client->request('GET', '/demandas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $pasta->getNup(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('GET /demandas com filtro de cliente retorna 200')]
    public function testComFiltroClienteRetorna200(): void
    {
        $client = static::createClient();
        $this->criarUsuarioComTenant($client);

        $client->request('GET', '/demandas', ['cliente' => 'João']);

        self::assertResponseIsSuccessful();
    }

    #[TestDox('GET /demandas com filtro de prioridade retorna 200')]
    public function testComFiltroPrioridadeRetorna200(): void
    {
        $client = static::createClient();
        $this->criarUsuarioComTenant($client);

        $client->request('GET', '/demandas', ['prioridade' => 'urgente']);

        self::assertResponseIsSuccessful();
    }
}
