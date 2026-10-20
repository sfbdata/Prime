<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Shared\Prazo\RegraDoPrazo;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\UseCase\ListarMetasDaEquipeUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As colunas "Vencidas" e "Prazos próximos" do Dashboard (e a lista "Metas da equipe" que elas abrem)
 * contam por DIA, como a Pasta (RegraDoPrazo): às 15h, a meta que vence hoje é prazo próximo, e não
 * vencida; a de daqui a 7 dias ainda é próxima; a de daqui a 8, não.
 */
#[CoversClass(TarefaRepository::class)]
#[CoversClass(RegraDoPrazo::class)]
#[Group('dashboard')]
final class DashboardPrazoNaBordaDoDiaTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Às 15h: ontem é vencida; hoje e daqui a 7 dias são prazos próximos; daqui a 8 dias, nenhum dos dois')]
    public function testBordaDoDia(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Borda ' . uniqid());
        $this->em->persist($tenant);

        $pessoa = new User();
        $pessoa->setEmail('borda_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa da Borda');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setIsActive(true);
        $this->em->persist($pessoa);
        $this->em->persist(new UserTenant($pessoa, $tenant));

        $hoje = new \DateTimeImmutable('today');
        foreach (['-1 day', '+0 days', '+7 days', '+8 days'] as $quando) {
            $this->criarMeta($tenant, $pessoa, $hoje->modify($quando));
        }
        $this->em->flush();

        $tarde = $hoje->setTime(15, 0);
        $linhas = static::getContainer()->get(ObterDadosDashboardUseCase::class)->executar($tenant, $tarde, [])->porAdvogado;
        $linha = array_values(array_filter($linhas, static fn (LinhaAdvogadoDashboardOutput $l): bool => $l->userId === $pessoa->getId()))[0];

        self::assertSame(1, $linha->metasVencidas);
        self::assertSame(2, $linha->prazosProximos);

        $lista = static::getContainer()->get(ListarMetasDaEquipeUseCase::class);
        self::assertSame(1, $lista->executar($tenant, $tarde, ['status' => 'vencidas', 'responsavel' => (string) $pessoa->getId()])->total);
        self::assertSame(2, $lista->executar($tenant, $tarde, ['status' => 'prazo_proximo', 'responsavel' => (string) $pessoa->getId()])->total);
    }

    private function criarMeta(Tenant $tenant, User $responsavel, \DateTimeImmutable $prazo): void
    {
        $pasta = new Pasta();
        $pasta->setNup('BORDA-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta da borda ' . uniqid());
        $tarefa->setDescricao('Prazo na borda do dia');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus(Tarefa::STATUS_PENDENTE);
        $tarefa->setPrazo($prazo);
        $tarefa->addResponsavel($responsavel);
        $this->em->persist($tarefa);
    }
}
