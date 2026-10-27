<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\PastasParaTarefaDoAlertaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Zenstruck\Foundry\Test\Factories;

/**
 * Botão "Criar tarefa" por alerta do Modo avançado (dc L967) e o seletor de pasta que ele abre:
 * tarefa exige pasta, então o botão pede a pasta primeiro. Só quem acessa o módulo de tarefas.
 */
#[CoversClass(PastasParaTarefaDoAlertaController::class)]
#[Group('dashboard')]
final class DashboardInteligenciaCriarTarefaTelaTest extends DashboardWebTestCase
{
    use Factories;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Dá ao papel do usuário a permissão do módulo de tarefas. */
    private function liberarTarefas(User $user, Tenant $tenant): void
    {
        $em   = $this->em();
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.tarefas.view']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode('modules.tarefas.view');
            $perm->setDescription('Módulo Tarefas');
            $perm->setGroup('modules');
            $em->persist($perm);
        }
        $papel = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant])?->getTenantRole();
        self::assertInstanceOf(TenantRole::class, $papel);
        $trp = new TenantRolePermission();
        $trp->setTenantRole($papel);
        $trp->setPermission($perm);
        $em->persist($trp);
        $papel->getTenantRolePermissions()->add($trp);
        $em->flush();
    }

    private function criarMetaVencida(Tenant $tenant, ?User &$bruno = null): void
    {
        $pasta  = PastaFactory::createOne(['tenant' => $tenant, 'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00')])->_real();
        $bruno  = $this->criarColaborador($tenant, 'Bruno Melo');
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('-5 days')])->_real();
        $tarefa->addResponsavel($bruno);
        $this->em()->flush();
    }

    #[TestDox('Com o módulo de tarefas, o alerta traz "Criar tarefa" com título, descrição e o responsável nomeado; o seletor de pasta existe')]
    public function testBotaoComTextoEResponsavel(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->liberarTarefas($user, $tenant);
        $this->criarMetaVencida($tenant, $bruno);

        $crawler = $client->request('GET', '/dashboard');
        $botao   = $crawler->filter('details.db-ia-alerta[data-alerta="vencidas"] .db-ia-alerta-acoes > button[data-ia-tarefa]');

        self::assertCount(1, $botao);
        self::assertSame('Criar tarefa', trim($botao->text()));
        self::assertStringContainsString('Priorizar a vencida', (string) $botao->attr('data-titulo'));
        self::assertStringContainsString('1 meta passou do prazo', (string) $botao->attr('data-descricao'));
        self::assertSame((string) $bruno->getId(), $botao->attr('data-responsavel'));
        self::assertCount(1, $crawler->filter('dialog[data-ia-pasta-dlg][data-url$="/dashboard/inteligencia/pastas-para-tarefa"]'));
        self::assertStringNotContainsString('—', (string) $crawler->filter('dialog[data-ia-pasta-dlg]')->outerHtml());
    }

    #[TestDox('Sem o módulo de tarefas não há botão nem seletor, e a busca responde 403')]
    public function testSemModuloDeTarefas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarMetaVencida($tenant);

        $crawler = $client->request('GET', '/dashboard');
        self::assertCount(0, $crawler->filter('[data-ia-tarefa]'));
        self::assertCount(0, $crawler->filter('[data-ia-pasta-dlg]'));

        $client->request('GET', '/dashboard/inteligencia/pastas-para-tarefa');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('A busca devolve só pastas do escritório da sessão, filtra pelo termo e não vaza pasta de outro escritório')]
    public function testBuscaSoDoEscritorio(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->liberarTarefas($user, $tenant);
        $minha  = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'Cliente Zeta Meu'])->_real();
        $outro  = $this->criarTenant();
        $alheia = PastaFactory::createOne(['tenant' => $outro, 'nomeCliente' => 'Cliente Zeta Alheio'])->_real();

        $client->request('GET', '/dashboard/inteligencia/pastas-para-tarefa?q=zeta');

        self::assertResponseIsSuccessful();
        $ids = array_column(json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR), 'id');
        self::assertContains($minha->getId(), $ids);
        self::assertNotContains($alheia->getId(), $ids);
    }
}
