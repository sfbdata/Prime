<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * O selo "Nº 0001" (número da meta no escritório, desenho 02 dc L1559) fica só na aba Metas da pasta: o cartão de
 * Minhas Metas (desenho 04) e o detalhe da meta (desenho 04B) não o têm (decisão do dono de 22/10).
 */
#[CoversClass(TarefaController::class)]
final class MetaNumeroGlobalTelaTest extends JusPrimeWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{User, Tenant} */
    private function autenticar(KernelBrowser $client): array
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Nº ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('numero_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Número');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel isSystem: passa pelo PermissionChecker do módulo de metas.
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    private function criarMeta(Tenant $tenant, User $responsavel, string $titulo, ?int $numero, ?Pasta $pasta = null): Tarefa
    {
        $em = $this->em();

        if ($pasta === null) {
            $pasta = new Pasta();
            $pasta->setNup('NUM-' . uniqid());
            $pasta->setNomeCliente('Cliente');
            $pasta->setResponsavel($responsavel);
            $pasta->setTenant($tenant);
            $em->persist($pasta);
        }

        $meta = new Tarefa();
        $meta->setTitulo($titulo);
        $meta->setDescricao('...');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($responsavel);
        $meta->addResponsavel($responsavel);
        if ($numero !== null) {
            $meta->numerar($numero);
        }
        $em->persist($meta);
        $em->flush();

        return $meta;
    }

    #[TestDox('Minhas Metas: sem o selo "Nº 0008" no cartão (o desenho 04 de 22/10 não tem; decisão do dono de 22/10)')]
    public function testSemSeloNoCartaoDeMinhasMetas(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $primeira = $this->criarMeta($tenant, $usuario, 'Juntar procuração', 7);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso', 8, $primeira->getPasta());

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();

        self::assertCount(2, $client->getCrawler()->filter('article.mm-meta'));
        self::assertCount(0, $client->getCrawler()->filter('.meta-num-global'));
        self::assertStringNotContainsString('Nº 0008', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Minhas Metas: meta sem número não tem selo')]
    public function testSemNumeroSemSeloEmMinhasMetas(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta antiga', null);

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $client->getCrawler()->filter('.meta-num-global'));
        self::assertStringNotContainsString('Nº 0000', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Detalhe da meta: sem o selo "Nº 0003" (o desenho 04B L49 não tem; decisão do dono de 22/10); o número fica no "Meta #3" do cabeçalho')]
    public function testSemSeloNoDetalheDaMeta(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta = $this->criarMeta($tenant, $usuario, 'Elaborar parecer', 3);

        $crawler = $client->request('GET', '/tarefas/' . $meta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.meta-num-global'));
        self::assertStringNotContainsString('Nº 0003', (string) $client->getResponse()->getContent());
        self::assertSame('Elaborar parecer', trim($crawler->filter('.meta-principal > .meta-principal-titulo > b')->text()));
        self::assertSame('Meta #3', trim($crawler->filter('.meta-cabeca > h1.meta-titulo')->text()));
        self::assertCount(0, $crawler->filter('link[rel="stylesheet"][href$="/css/meta-numero-global.css"]'));
    }

    #[TestDox('Detalhe da meta sem número: sem selo')]
    public function testSemNumeroSemSeloNoDetalhe(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta = $this->criarMeta($tenant, $usuario, 'Meta antiga', null);

        $crawler = $client->request('GET', '/tarefas/' . $meta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.meta-num-global'));
    }
}
