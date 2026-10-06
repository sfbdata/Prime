<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * O "N de M" na TELA: entre as duas setas, filho direto do contêiner delas.
 *
 * O combinador de filho direto e a ordem dos irmãos são o que distingue "está entre as
 * setas" de "existe em algum lugar do cabeçalho".
 */
#[CoversClass(PastaController::class)]
final class PastaPosicaoNoAcervoTelaTest extends JusPrimeWebTestCase
{
    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Pos ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_pos_' . uniqid() . '@test.com');
        $user->setFullName('Admin Pos');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nup): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    #[TestDox('o contador "N de M" fica entre as setas, filho direto da navegação')]
    public function testContadorEntreAsSetas(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $this->criarPasta($tenant, '3001');
        $meio = $this->criarPasta($tenant, '3002');
        $this->criarPasta($tenant, '3003');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$meio->getId()}");
        self::assertResponseIsSuccessful();

        $pos = $crawler->filter('.ps-cab-linha1 > .ps-cab-nav > .ps-cab-pos');
        self::assertSame(1, $pos->count(), 'o contador saiu da navegação entre pastas');
        self::assertSame('2 de 3', trim($pos->text()));

        // Ordem dos filhos: seta ‹ · contador · seta ›.
        $filhos = $crawler->filter('.ps-cab-linha1 > .ps-cab-nav > *');
        self::assertSame(3, $filhos->count());
        self::assertSame('anterior', $filhos->eq(0)->attr('data-nav'));
        self::assertSame('ps-cab-pos', $filhos->eq(1)->attr('class'));
        self::assertSame('proxima', $filhos->eq(2)->attr('data-nav'));
    }

    #[TestDox('pasta sozinha mostra "1 de 1", como as setas inertes continuam no lugar')]
    public function testPastaSozinha(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $unica = $this->criarPasta($tenant, '3001');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$unica->getId()}");

        self::assertSame('1 de 1', trim($crawler->filter('.ps-cab-nav > .ps-cab-pos')->text()));
    }

    /**
     * Isolamento na tela: as pastas do outro escritório ficam entre e acima das minhas —
     * sem o filtro de tenant, a minha 3001 apareceria como "4 de 4".
     */
    #[TestDox('pastas de outro escritório não entram no "N de M" da tela')]
    public function testNaoContaOutroEscritorio(): void
    {
        $client            = static::createClient();
        [$user, $tenant]   = $this->criarUsuarioAdmin();
        [, $tenantVizinho] = $this->criarUsuarioAdmin();

        $minha = $this->criarPasta($tenant, '3001');
        $this->criarPasta($tenant, '3003');
        $this->criarPasta($tenantVizinho, '3002');
        $this->criarPasta($tenantVizinho, '3999');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$minha->getId()}");

        self::assertSame('2 de 2', trim($crawler->filter('.ps-cab-nav > .ps-cab-pos')->text()));
    }
}
