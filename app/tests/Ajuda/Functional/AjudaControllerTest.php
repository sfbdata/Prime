<?php

declare(strict_types=1);

namespace App\Tests\Ajuda\Functional;

use App\Ajuda\Controller\AjudaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(AjudaController::class)]
final class AjudaControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('logado: /ajuda abre o manual inteiro no leitor e traz o botão Baixar manual com o nome do arquivo')]
    public function testPaginaMostraOManual(): void
    {
        $client = static::createClient();
        $this->logar($client);

        $crawler = $client->request('GET', '/ajuda');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.aj-pagina h1', 'Manual do usuário');
        self::assertCount(1, $crawler->filter('.aj-pagina > object.aj-leitor[type="application/pdf"][data="/manual/manual-do-usuario.pdf"]'));
        $baixar = $crawler->filter('.aj-pagina > .aj-cabeca > a[data-aj-baixar]');
        self::assertCount(1, $baixar);
        self::assertSame('/manual/manual-do-usuario.pdf', $baixar->attr('href'));
        self::assertSame(AjudaController::NOME_DO_DOWNLOAD, $baixar->attr('download'));
        self::assertStringContainsString('Baixar manual', $baixar->text());
    }

    #[TestDox('o PDF do manual existe em public/ (o servidor web entrega direto) e é um PDF de verdade')]
    public function testPdfServido(): void
    {
        $caminho = self::getContainer()->getParameter('kernel.project_dir') . '/public/' . AjudaController::MANUAL;

        self::assertFileExists($caminho);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($caminho, false, null, 0, 4));
        self::assertGreaterThan(100_000, (int) filesize($caminho), 'o manual inteiro, não um arquivo vazio');
    }

    #[TestDox('sem login, /ajuda vai para o login')]
    public function testSemLoginRedireciona(): void
    {
        $client = static::createClient();
        $client->request('GET', '/ajuda');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('o "?" fica no cabeçalho junto do sino, antes do Indicar (o link do Service Desk está no ServiceDeskControllerTest)')]
    public function testCabecalho(): void
    {
        $client = static::createClient();
        $this->logar($client);

        $crawler = $client->request('GET', '/novidades');
        self::assertResponseIsSuccessful();
        $ajuda = $crawler->filter('.navtop-action > a.navtop-action-btn[data-ajuda-topo][href="/ajuda"]');
        self::assertCount(1, $ajuda);
        self::assertCount(1, $ajuda->filter('i.bi-question-circle'));
        // Ordem: "?", "Indicar" (o desenho o põe colado no sino) e o sino.
        $depois = $ajuda->ancestors()->first()->nextAll();
        self::assertCount(1, $depois->eq(0)->filter('[data-bj-share]'), 'o "Indicar" vem logo depois do "?"');
        self::assertCount(1, $depois->eq(1)->filter('#notificacaoDropdownToggle'), 'e o sino logo depois do "Indicar"');
    }

    private function logar(KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Escritório Ajuda ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('ajuda_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Ajuda');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha-de-teste-123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
    }
}
