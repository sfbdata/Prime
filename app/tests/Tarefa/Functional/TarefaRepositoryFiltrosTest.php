<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\PrioridadePasta;
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
final class TarefaRepositoryFiltrosTest extends KernelTestCase
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

    #[TestDox('Sem filtros retorna só as tarefas do usuário (responsável), sem vazar as de colegas')]
    public function testBaseEscopadaAoUsuario(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();
        $colega = UserFactory::createOne()->_real();

        $minha = TarefaFactory::createOne(['pasta' => $pasta])->_real();
        $minha->addResponsavel($eu);
        $doColega = TarefaFactory::createOne(['pasta' => $pasta])->_real();
        $doColega->addResponsavel($colega);
        $this->em->flush();

        $resultado = $this->repo->findByResponsavelComFiltros($eu, []);

        self::assertCount(1, $resultado);
        self::assertSame($minha->getId(), $resultado[0]->getId());
    }

    #[TestDox('Busca filtra por título, mantendo o escopo do usuário')]
    public function testBuscaPorTitulo(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();

        $achavel = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Protocolar recurso urgente'])->_real();
        $achavel->addResponsavel($eu);
        $outra = TarefaFactory::createOne(['pasta' => $pasta, 'titulo' => 'Agendar reunião'])->_real();
        $outra->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findByResponsavelComFiltros($eu, ['busca' => 'recurso']);

        self::assertCount(1, $resultado);
        self::assertSame($achavel->getId(), $resultado[0]->getId());
    }

    #[TestDox('Faceta de status estreita para o status pedido')]
    public function testFacetaStatus(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();

        $pendente = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $pendente->addResponsavel($eu);
        $concluida = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_CONCLUIDA])->_real();
        $concluida->addResponsavel($eu);
        $this->em->flush();

        self::assertCount(1, $this->repo->findByResponsavelComFiltros($eu, ['status' => Tarefa::STATUS_CONCLUIDA]));
        self::assertCount(1, $this->repo->findByResponsavelComFiltros($eu, ['status' => Tarefa::STATUS_PENDENTE]));
    }

    #[TestDox('Faceta de prioridade filtra pela prioridade da pasta vinculada')]
    public function testFacetaPrioridadeDaPasta(): void
    {
        $tenant   = TenantFactory::createOne()->_real();
        $urgente  = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Urgente])->_real();
        $normal   = PastaFactory::createOne(['tenant' => $tenant, 'prioridade' => PrioridadePasta::Normal])->_real();
        $eu       = UserFactory::createOne()->_real();

        $tUrg = TarefaFactory::createOne(['pasta' => $urgente])->_real();
        $tUrg->addResponsavel($eu);
        $tNor = TarefaFactory::createOne(['pasta' => $normal])->_real();
        $tNor->addResponsavel($eu);
        $this->em->flush();

        $resultado = $this->repo->findByResponsavelComFiltros($eu, ['prioridade' => 'urgente']);

        self::assertCount(1, $resultado);
        self::assertSame($tUrg->getId(), $resultado[0]->getId());
    }

    #[TestDox('Faceta de prazo separa vencidas, próximas e sem prazo')]
    public function testFacetaPrazo(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $eu     = UserFactory::createOne()->_real();

        $ontem   = new \DateTimeImmutable('-1 day');
        $em3dias = new \DateTimeImmutable('+3 days');

        $vencida = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => $ontem])->_real();
        $vencida->addResponsavel($eu);
        $proxima = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => $em3dias])->_real();
        $proxima->addResponsavel($eu);
        $semPrazo = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE, 'prazo' => null])->_real();
        $semPrazo->addResponsavel($eu);
        $this->em->flush();

        $vencidas = $this->repo->findByResponsavelComFiltros($eu, ['prazo' => 'vencidas']);
        self::assertCount(1, $vencidas);
        self::assertSame($vencida->getId(), $vencidas[0]->getId());

        $proximas = $this->repo->findByResponsavelComFiltros($eu, ['prazo' => 'proximas']);
        self::assertCount(1, $proximas);
        self::assertSame($proxima->getId(), $proximas[0]->getId());

        $sem = $this->repo->findByResponsavelComFiltros($eu, ['prazo' => 'sem']);
        self::assertCount(1, $sem);
        self::assertSame($semPrazo->getId(), $sem[0]->getId());
    }
}
