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

    #[TestDox('Meta de pasta excluída (lápide), meta em revisão vencida e meta de outro escritório não entram nas colunas nem nas listas')]
    public function testLapideEmRevisaoEOutroEscritorio(): void
    {
        $tenant = $this->novoEscritorio();
        $outro = $this->novoEscritorio();
        $pessoa = $this->novaPessoa([$tenant, $outro]);
        $hoje = new \DateTimeImmutable('today');

        $this->criarMeta($tenant, $pessoa, $hoje->modify('-2 days'));                        // conta: vencida
        $this->criarMeta($tenant, $pessoa, $hoje->modify('+2 days'));                        // conta: próxima
        $this->criarMeta($tenant, $pessoa, $hoje->modify('-2 days'), Tarefa::STATUS_EM_REVISAO); // fora da fila
        $this->criarMeta($tenant, $pessoa, $hoje->modify('+2 days'), Tarefa::STATUS_EM_REVISAO); // fora da fila
        $this->criarMeta($tenant, $pessoa, $hoje->modify('-2 days'), Tarefa::STATUS_PENDENTE, excluida: $pessoa);
        $this->criarMeta($tenant, $pessoa, $hoje->modify('+2 days'), Tarefa::STATUS_PENDENTE, excluida: $pessoa);
        $this->criarMeta($outro, $pessoa, $hoje->modify('-2 days'));                         // outro escritório
        $this->em->flush();

        $tarde = $hoje->setTime(15, 0);
        $linhas = static::getContainer()->get(ObterDadosDashboardUseCase::class)->executar($tenant, $tarde, [])->porAdvogado;
        $linha = array_values(array_filter($linhas, static fn (LinhaAdvogadoDashboardOutput $l): bool => $l->userId === $pessoa->getId()))[0];

        self::assertSame(1, $linha->metasVencidas, 'nem a em revisão, nem a da pasta excluída, nem a do outro escritório');
        self::assertSame(1, $linha->prazosProximos);
        self::assertSame(4, $linha->totalMetas, 'a pasta excluída sai também do total');

        $lista = static::getContainer()->get(ListarMetasDaEquipeUseCase::class);
        $filtro = ['responsavel' => (string) $pessoa->getId()];
        self::assertSame(1, $lista->executar($tenant, $tarde, $filtro + ['status' => 'vencidas'])->total);
        self::assertSame(1, $lista->executar($tenant, $tarde, $filtro + ['status' => 'prazo_proximo'])->total);
        self::assertSame(4, $lista->executar($tenant, $tarde, $filtro + ['status' => 'todas'])->total);
    }

    private function novoEscritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Borda ' . uniqid());
        $this->em->persist($tenant);

        return $tenant;
    }

    /** @param list<Tenant> $escritorios */
    private function novaPessoa(array $escritorios): User
    {
        $pessoa = new User();
        $pessoa->setEmail('borda_' . uniqid() . '@test.com');
        $pessoa->setFullName('Pessoa da Borda');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setIsActive(true);
        $this->em->persist($pessoa);
        foreach ($escritorios as $escritorio) {
            $this->em->persist(new UserTenant($pessoa, $escritorio));
        }

        return $pessoa;
    }

    private function criarMeta(Tenant $tenant, User $responsavel, \DateTimeImmutable $prazo, string $status = Tarefa::STATUS_PENDENTE, ?User $excluida = null): void
    {
        $pasta = new Pasta();
        $pasta->setNup('BORDA-' . uniqid());
        $pasta->setTenant($tenant);
        if ($excluida !== null) {
            $pasta->marcarExcluida($excluida, new \DateTimeImmutable());
        }
        $this->em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta da borda ' . uniqid());
        $tarefa->setDescricao('Prazo na borda do dia');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        $tarefa->setPrazo($prazo);
        $tarefa->addResponsavel($responsavel);
        $this->em->persist($tarefa);
    }
}
