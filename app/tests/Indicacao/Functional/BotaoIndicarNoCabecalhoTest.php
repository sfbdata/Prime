<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Desenho 05 Perfis 1.2 VS 25.10.26 (bj-compartilhar.js): botão "Indicar o BlueJus" no cabeçalho, antes do sino.
 */
#[CoversClass(DashboardController::class)]
final class BotaoIndicarNoCabecalhoTest extends JusPrimeWebTestCase
{
    #[TestDox('o botão Indicar abre a janela de indicação e fica logo antes do sino')]
    public function testBotaoAntesDoSino(): void
    {
        $client = static::createClient();
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Indicar ' . uniqid());
        $em->persist($tenant);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $user = new User();
        $user->setEmail('indicar_' . uniqid() . '@test.com');
        $user->setFullName('Usuario Que Indica');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $botao = $crawler->filter('.navtop-right > .navtop-action > button.bj-share[data-bj-share][data-ind-abrir]');
        self::assertCount(1, $botao, 'o botão é filho direto da área de ações do cabeçalho');
        self::assertCount(1, $botao->filter('i.bi.bi-share'));
        self::assertSame('Indicar o BlueJus', $botao->attr('aria-label'));
        self::assertSame('Indique o BlueJus e ganhe recompensa', $botao->attr('data-tip'));

        $irmaoSeguinte = $botao->ancestors()->first()->nextAll()->first();
        self::assertCount(1, $irmaoSeguinte->filter('#notificacaoDropdownToggle'), 'logo antes do sino');
        self::assertCount(1, $crawler->filter('#modalIndicacao'), 'a janela que o botão abre está na página');
    }
}
