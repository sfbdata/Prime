<?php

declare(strict_types=1);

namespace App\Tests\Notificacao\Functional;

use App\Controller\PastaController;
use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * FPN-035: o sino do topo (base.html.twig) é `bi-bell`, de contorno — é o que os
 * desenhos mostram no topo de TODAS as telas (Dashboard dc:134, Expediente/pasta
 * dc:760, Agenda, Carteira, Objeto). Como a base é de todo o sistema, o teste
 * confere a pasta e duas telas de fora dela, e que o resto do sino não mexeu
 * (id do botão e badge, de que o JS das notificações depende).
 */
#[CoversClass(PastaController::class)]
#[CoversClass(DashboardController::class)]
#[CoversClass(ExpedienteController::class)]
final class SinoDoTopoIconeTelaTest extends JusPrimeWebTestCase
{
    #[TestDox('o sino do topo é bi-bell (contorno) na pasta, no Dashboard e no Expediente, com badge e id intactos')]
    public function testSinoDeContornoEmTodasAsTelas(): void
    {
        $client                   = static::createClient();
        [$user, $tenant, $pasta]  = $this->criarCenario();
        $this->logarComTenant($client, $user, $tenant);

        foreach (['/pasta/' . $pasta->getId(), '/dashboard', '/expediente'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $this->assertSinoDeContorno($crawler, $url);
        }
    }

    private function assertSinoDeContorno(Crawler $crawler, string $url): void
    {
        $botao = $crawler->filter('.navtop-action > a#notificacaoDropdownToggle.navtop-action-btn');
        self::assertCount(1, $botao, $url . ': o botão do sino continua com o id que o JS usa');
        self::assertCount(1, $botao->filter('a > i.bi.bi-bell'), $url . ': o ícone do topo é bi-bell');
        self::assertCount(0, $botao->filter('i.bi-bell-fill'), $url . ': o glifo cheio saiu do topo');
        self::assertCount(1, $botao->filter('a > span.navtop-badge#notificacaoBadge'), $url . ': o badge segue no lugar');
    }

    /** @return array{0: User, 1: Tenant, 2: Pasta} */
    private function criarCenario(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Sino ' . uniqid());
        $em->persist($tenant);

        // Papel de sistema: passa nas permissões de Dashboard, Expediente e Pasta.
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $user = new User();
        $user->setEmail('sino_' . uniqid() . '@test.com');
        $user->setFullName('Usuario Do Sino');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $pasta = new Pasta();
        $pasta->setNup('NUP-SINO-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $em->persist($pasta);

        $em->flush();

        return [$user, $tenant, $pasta];
    }
}
