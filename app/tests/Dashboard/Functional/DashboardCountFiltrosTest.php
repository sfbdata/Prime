<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\Repository\PastaRepository;
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

/**
 * Cobre o plumbing SQL dos filtros globais do Dashboard (período por data + responsável)
 * nas contagens de TarefaRepository e PastaRepository.
 */
#[CoversClass(TarefaRepository::class)]
#[CoversClass(PastaRepository::class)]
#[Group('dashboard')]
final class DashboardCountFiltrosTest extends KernelTestCase
{
    use Factories;

    private TarefaRepository $tarefaRepo;
    private PastaRepository $pastaRepo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tarefaRepo = static::getContainer()->get(TarefaRepository::class);
        $this->pastaRepo  = static::getContainer()->get(PastaRepository::class);
        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Período (por dataCriacao) restringe countMetasAtivas')]
    public function testPeriodoRestringeCountMetasAtivas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $user   = UserFactory::createOne()->_real();

        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $tarefa->addResponsavel($user);
        $this->em->flush();

        $ontem  = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $amanha = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        self::assertSame(1, $this->tarefaRepo->countMetasAtivas($tenant, []));
        self::assertSame(0, $this->tarefaRepo->countMetasAtivas($tenant, ['data_de' => $amanha]));
        self::assertSame(0, $this->tarefaRepo->countMetasAtivas($tenant, ['data_ate' => $ontem]));
        self::assertSame(1, $this->tarefaRepo->countMetasAtivas($tenant, ['data_de' => $ontem, 'data_ate' => $amanha]));
    }

    #[TestDox('Responsável restringe countMetasAtivas ao colaborador escolhido')]
    public function testResponsavelRestringeCountMetasAtivas(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $u1     = UserFactory::createOne()->_real();
        $u2     = UserFactory::createOne()->_real();

        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $tarefa->addResponsavel($u1);
        $this->em->flush();

        self::assertSame(1, $this->tarefaRepo->countMetasAtivas($tenant, ['responsavel' => (string) $u1->getId()]));
        self::assertSame(0, $this->tarefaRepo->countMetasAtivas($tenant, ['responsavel' => (string) $u2->getId()]));
    }

    #[TestDox('Responsável restringe countUrgentes (Pasta) ao colaborador escolhido')]
    public function testResponsavelRestringeCountUrgentes(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $u1     = UserFactory::createOne()->_real();
        $u2     = UserFactory::createOne()->_real();

        PastaFactory::createOne([
            'tenant'      => $tenant,
            'prioridade'  => PrioridadePasta::Urgente,
            'responsavel' => $u1,
        ]);

        self::assertSame(1, $this->pastaRepo->countUrgentes($tenant, []));
        self::assertSame(1, $this->pastaRepo->countUrgentes($tenant, ['responsavel' => (string) $u1->getId()]));
        self::assertSame(0, $this->pastaRepo->countUrgentes($tenant, ['responsavel' => (string) $u2->getId()]));
    }
}
