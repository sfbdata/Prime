<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

#[CoversClass(TarefaRepository::class)]
#[Group('tarefa')]
final class TarefaRepositoryAgregacoesTest extends KernelTestCase
{
    use Factories;

    private TarefaRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(TarefaRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
    }

    // ─── countMetasAtivas ────────────────────────────────────────────

    #[TestDox('countMetasAtivas retorna pendentes e em_revisao, exclui concluidas')]
    public function testCountMetasAtivasRetornaSoPendentesEEmRevisao(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();

        TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE]);
        TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_EM_REVISAO]);
        TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA]);

        self::assertSame(2, $this->repo->countMetasAtivas($tenant));
    }

    #[TestDox('countMetasAtivas não vaza tarefas do tenant B')]
    public function testCountMetasAtivasIsolaTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $pastaA  = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $pastaB  = PastaFactory::createOne(['tenant' => $tenantB])->_real();

        TarefaFactory::createOne(['pasta' => $pastaA, 'status' => Tarefa::STATUS_PENDENTE]);
        TarefaFactory::createMany(3, ['pasta' => $pastaB, 'status' => Tarefa::STATUS_PENDENTE]);

        self::assertSame(1, $this->repo->countMetasAtivas($tenantA));
    }

    // ─── countMetasGlobal ────────────────────────────────────────────

    #[TestDox('countMetasGlobal retorna concluidas e total separados')]
    public function testCountMetasGlobalRetornaConcluidasETotal(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();

        TarefaFactory::createMany(2, ['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE]);
        TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA]);

        $resultado = $this->repo->countMetasGlobal($tenant);

        self::assertSame(1, $resultado['concluidas']);
        self::assertSame(3, $resultado['total']);
    }

    #[TestDox('countMetasGlobal não vaza tarefas do tenant B')]
    public function testCountMetasGlobalIsolaTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $pastaA  = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $pastaB  = PastaFactory::createOne(['tenant' => $tenantB])->_real();

        TarefaFactory::createOne(['pasta' => $pastaA, 'status' => Tarefa::STATUS_CONCLUIDA]);
        TarefaFactory::createMany(3, ['pasta' => $pastaB, 'status' => Tarefa::STATUS_CONCLUIDA]);

        $resultado = $this->repo->countMetasGlobal($tenantA);

        self::assertSame(1, $resultado['concluidas']);
        self::assertSame(1, $resultado['total']);
    }

    // ─── countPorResponsavel ─────────────────────────────────────────

    #[TestDox('countPorResponsavel agrupa por responsável, exclui tarefas sem responsável')]
    public function testCountPorResponsavelAgrupaPorResponsavel(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $u1     = UserFactory::createOne()->_real();
        $u2     = UserFactory::createOne()->_real();

        $t1 = TarefaFactory::createOne(['pasta' => $pasta])->_real();
        $t1->addResponsavel($u1);
        $t2 = TarefaFactory::createOne(['pasta' => $pasta])->_real();
        $t2->addResponsavel($u1);
        $t3 = TarefaFactory::createOne(['pasta' => $pasta])->_real();
        $t3->addResponsavel($u2);
        TarefaFactory::createOne(['pasta' => $pasta]); // sem responsável
        $this->em->flush();

        $resultado = $this->repo->countPorResponsavel($tenant);

        self::assertSame(2, $resultado[$u1->getId()]);
        self::assertSame(1, $resultado[$u2->getId()]);
        self::assertCount(2, $resultado);
    }

    #[TestDox('countPorResponsavel: 1 tarefa com 2 responsáveis conta 1x no total mas 1x para cada responsável')]
    public function testCountPorResponsavelProvaManytomanyDivergencia(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $u1     = UserFactory::createOne()->_real();
        $u2     = UserFactory::createOne()->_real();

        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $tarefa->addResponsavel($u1);
        $tarefa->addResponsavel($u2);
        $this->em->flush();

        // A tarefa conta 1x no total do tenant
        self::assertSame(1, $this->repo->countMetasAtivas($tenant));

        // Mas contribui 1x para cada responsável (soma = 2 ≠ total = 1 — comportamento esperado:
        // uma tarefa com N responsáveis aparece N vezes na visão por responsável)
        $resultado = $this->repo->countPorResponsavel($tenant);
        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertSame(1, $resultado[$u2->getId()]);
        self::assertCount(2, $resultado);
    }

    #[TestDox('countPorResponsavel não vaza tarefas do tenant B')]
    public function testCountPorResponsavelIsolaTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $pastaA  = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $pastaB  = PastaFactory::createOne(['tenant' => $tenantB])->_real();
        $u1      = UserFactory::createOne()->_real();

        $tA = TarefaFactory::createOne(['pasta' => $pastaA])->_real();
        $tA->addResponsavel($u1);
        $tB1 = TarefaFactory::createOne(['pasta' => $pastaB])->_real();
        $tB1->addResponsavel($u1);
        $tB2 = TarefaFactory::createOne(['pasta' => $pastaB])->_real();
        $tB2->addResponsavel($u1);
        $this->em->flush();

        $resultado = $this->repo->countPorResponsavel($tenantA);

        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    // ─── countAtivasPorResponsavel ───────────────────────────────────

    #[TestDox('countAtivasPorResponsavel exclui tarefas concluídas do responsável')]
    public function testCountAtivasPorResponsavelExcluiConcluidas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $u1     = UserFactory::createOne()->_real();

        $t1 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $t1->addResponsavel($u1);
        $t2 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_EM_REVISAO])->_real();
        $t2->addResponsavel($u1);
        $t3 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA])->_real();
        $t3->addResponsavel($u1);
        $this->em->flush();

        $resultado = $this->repo->countAtivasPorResponsavel($tenant);

        self::assertSame(2, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    #[TestDox('countAtivasPorResponsavel não vaza tarefas do tenant B')]
    public function testCountAtivasPorResponsavelIsolaTenant(): void
    {
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $pastaA  = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $pastaB  = PastaFactory::createOne(['tenant' => $tenantB])->_real();
        $u1      = UserFactory::createOne()->_real();

        $tA = TarefaFactory::createOne(['pasta' => $pastaA, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $tA->addResponsavel($u1);
        $tB1 = TarefaFactory::createOne(['pasta' => $pastaB, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $tB1->addResponsavel($u1);
        $tB2 = TarefaFactory::createOne(['pasta' => $pastaB, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $tB2->addResponsavel($u1);
        $this->em->flush();

        $resultado = $this->repo->countAtivasPorResponsavel($tenantA);

        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    // ─── countVencidasPorResponsavel ─────────────────────────────────

    #[TestDox('countVencidasPorResponsavel conta ativas com prazo no passado, exclui concluidas e sem prazo')]
    public function testCountVencidasPorResponsavelRetornaVencidas(): void
    {
        $hoje   = new \DateTimeImmutable('2024-01-10 00:00:00');
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $u1     = UserFactory::createOne()->_real();

        // Conta: ativa, prazo vencido
        $t1 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-09')])->_real();
        $t1->addResponsavel($u1);
        // Não conta: concluida, mesmo prazo vencido
        $t2 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA, 'prazo' => new \DateTimeImmutable('2024-01-09')])->_real();
        $t2->addResponsavel($u1);
        // Não conta: ativa, prazo futuro
        $t3 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-13')])->_real();
        $t3->addResponsavel($u1);
        // Não conta: ativa, sem prazo
        $t4 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => null])->_real();
        $t4->addResponsavel($u1);
        $this->em->flush();

        $resultado = $this->repo->countVencidasPorResponsavel($tenant, $hoje);

        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    #[TestDox('countVencidasPorResponsavel não vaza tarefas do tenant B')]
    public function testCountVencidasPorResponsavelIsolaTenant(): void
    {
        $hoje    = new \DateTimeImmutable('2024-01-10 00:00:00');
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $pastaA  = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $pastaB  = PastaFactory::createOne(['tenant' => $tenantB])->_real();
        $u1      = UserFactory::createOne()->_real();

        $tA = TarefaFactory::createOne(['pasta' => $pastaA, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-09')])->_real();
        $tA->addResponsavel($u1);
        $tB1 = TarefaFactory::createOne(['pasta' => $pastaB, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-09')])->_real();
        $tB1->addResponsavel($u1);
        $tB2 = TarefaFactory::createOne(['pasta' => $pastaB, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-09')])->_real();
        $tB2->addResponsavel($u1);
        $this->em->flush();

        $resultado = $this->repo->countVencidasPorResponsavel($tenantA, $hoje);

        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    // ─── countPrazosProximosPorResponsavel ───────────────────────────

    #[TestDox('countPrazosProximosPorResponsavel conta ativas com prazo entre hoje e hoje+7, inclusive')]
    public function testCountPrazosProximosRetornaDentroJanela(): void
    {
        $hoje   = new \DateTimeImmutable('2024-01-10 00:00:00');
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $u1     = UserFactory::createOne()->_real();

        // Conta: prazo = hoje exato (>= referencia)
        $t1 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-10')])->_real();
        $t1->addResponsavel($u1);
        // Conta: prazo = hoje+3 (dentro da janela)
        $t2 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-13')])->_real();
        $t2->addResponsavel($u1);
        // Conta: prazo = hoje+7 = 2024-01-17 (limite inclusivo)
        $t3 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-17')])->_real();
        $t3->addResponsavel($u1);
        // Não conta: vencida (< referencia)
        $t4 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-09')])->_real();
        $t4->addResponsavel($u1);
        // Não conta: prazo bem além da janela (2024-02-09 = hoje+30)
        $t5 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-02-09')])->_real();
        $t5->addResponsavel($u1);
        // Não conta: concluida dentro da janela
        $t6 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA, 'prazo' => new \DateTimeImmutable('2024-01-13')])->_real();
        $t6->addResponsavel($u1);
        // Não conta: sem prazo
        $t7 = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => null])->_real();
        $t7->addResponsavel($u1);
        $this->em->flush();

        $resultado = $this->repo->countPrazosProximosPorResponsavel($tenant, $hoje);

        self::assertSame(3, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }

    #[TestDox('countPrazosProximosPorResponsavel não vaza tarefas do tenant B')]
    public function testCountPrazosProximosIsolaTenant(): void
    {
        $hoje    = new \DateTimeImmutable('2024-01-10 00:00:00');
        $tenantA = TenantFactory::createOne()->_real();
        $tenantB = TenantFactory::createOne()->_real();
        $pastaA  = PastaFactory::createOne(['tenant' => $tenantA])->_real();
        $pastaB  = PastaFactory::createOne(['tenant' => $tenantB])->_real();
        $u1      = UserFactory::createOne()->_real();

        $tA = TarefaFactory::createOne(['pasta' => $pastaA, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-13')])->_real();
        $tA->addResponsavel($u1);
        $tB1 = TarefaFactory::createOne(['pasta' => $pastaB, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-13')])->_real();
        $tB1->addResponsavel($u1);
        $tB2 = TarefaFactory::createOne(['pasta' => $pastaB, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => new \DateTimeImmutable('2024-01-13')])->_real();
        $tB2->addResponsavel($u1);
        $this->em->flush();

        $resultado = $this->repo->countPrazosProximosPorResponsavel($tenantA, $hoje);

        self::assertSame(1, $resultado[$u1->getId()]);
        self::assertCount(1, $resultado);
    }
}
