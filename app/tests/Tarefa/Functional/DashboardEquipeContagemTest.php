<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\UseCase\ListarMetasDaEquipeUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Paridade "número clicado = total da lista": para cada colaborador e cada coluna de metas da
 * tabela Desempenho, o número que o ObterDadosDashboardUseCase devolve tem de ser o total da
 * lista /tarefas/equipe para o mesmo responsável/cargo/status/período e a MESMA referência.
 *
 * Se um dos count*PorResponsavel mudar de critério sem o findMetasDaEquipePaginado mudar junto
 * (ou vice-versa), este teste quebra.
 */
#[CoversClass(ListarMetasDaEquipeUseCase::class)]
#[CoversClass(TarefaRepository::class)]
#[Group('dashboard')]
final class DashboardEquipeContagemTest extends KernelTestCase
{
    /** coluna do Dashboard => status da lista */
    private const COLUNAS = [
        'totalMetas'     => 'todas',
        'metasAtivas'    => 'ativas',
        'metasVencidas'  => 'vencidas',
        'prazosProximos' => 'prazo_proximo',
    ];

    private EntityManagerInterface $em;
    private ObterDadosDashboardUseCase $dashboard;
    private ListarMetasDaEquipeUseCase $lista;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em        = static::getContainer()->get(EntityManagerInterface::class);
        $this->dashboard = static::getContainer()->get(ObterDadosDashboardUseCase::class);
        $this->lista     = static::getContainer()->get(ListarMetasDaEquipeUseCase::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Paridade ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarColaborador(Tenant $tenant, string $nome, ?string $cargo = null): User
    {
        $user = new User();
        $user->setEmail('paridade_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $this->em->persist($user);

        $ut = new UserTenant($user, $tenant);
        if ($cargo !== null) {
            $c = new Cargo();
            $c->setNome($cargo);
            $c->setTenant($tenant);
            $this->em->persist($c);
            $ut->setCargo($c);
        }
        $this->em->persist($ut);
        $this->em->flush();

        return $user;
    }

    /** @param list<User> $responsaveis */
    private function criarMeta(Tenant $tenant, array $responsaveis, string $status, ?string $prazo, ?string $dataCriacao = null): void
    {
        $pasta = new Pasta();
        $pasta->setNup('PAR-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo('Meta ' . uniqid());
        $tarefa->setDescricao('—');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        $tarefa->setPrazo($prazo === null ? null : new \DateTimeImmutable($prazo));
        foreach ($responsaveis as $r) {
            $tarefa->addResponsavel($r);
        }
        $this->em->persist($tarefa);
        $this->em->flush();

        if ($dataCriacao !== null) {
            $this->em->getConnection()->executeStatement(
                'UPDATE tarefa SET data_criacao = :d WHERE id = :id',
                ['d' => $dataCriacao, 'id' => $tarefa->getId()],
            );
        }
    }

    /** Massa com todos os casos de borda das quatro colunas, espalhada entre dois colaboradores. */
    private function semear(): Tenant
    {
        $tenant = $this->criarTenant();
        $ana    = $this->criarColaborador($tenant, 'Ana Paridade', 'Advogado');
        $bruno  = $this->criarColaborador($tenant, 'Bruno Paridade', 'Estagiário');

        $antiga = (new \DateTimeImmutable('-400 days'))->format('Y-m-d 10:00:00');

        $this->criarMeta($tenant, [$ana], Tarefa::STATUS_PENDENTE, null);
        $this->criarMeta($tenant, [$ana], Tarefa::STATUS_CONCLUIDA, '-3 days');
        $this->criarMeta($tenant, [$ana], Tarefa::STATUS_EM_REVISAO, '+2 days');
        $this->criarMeta($tenant, [$ana], Tarefa::STATUS_PENDENTE, '-5 days', $antiga);
        $this->criarMeta($tenant, [$ana, $bruno], Tarefa::STATUS_PENDENTE, '+4 days');
        $this->criarMeta($tenant, [$bruno], Tarefa::STATUS_PENDENTE, '+30 days');
        $this->criarMeta($tenant, [$bruno], Tarefa::STATUS_PENDENTE, '-1 day', $antiga);
        $this->criarMeta($tenant, [$bruno], Tarefa::STATUS_CONCLUIDA, null, $antiga);

        // Ruído de outro escritório com o mesmo colaborador: não pode entrar em nenhum dos lados.
        $outro = $this->criarTenant();
        $this->em->persist(new UserTenant($ana, $outro));
        $this->em->flush();
        $this->criarMeta($outro, [$ana], Tarefa::STATUS_PENDENTE, '-2 days');

        return $tenant;
    }

    /** @param array<string, string> $filtros */
    private function assertParidade(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros): void
    {
        $linhas = $this->dashboard->executar($tenant, $referencia, $filtros)->porAdvogado;
        self::assertNotSame([], $linhas, 'A massa precisa gerar linhas para o teste provar algo.');

        $algumNaoZero = false;
        foreach ($linhas as $linha) {
            self::assertInstanceOf(LinhaAdvogadoDashboardOutput::class, $linha);
            foreach (self::COLUNAS as $coluna => $status) {
                $esperado = $linha->$coluna;
                $algumNaoZero = $algumNaoZero || $esperado > 0;

                $total = $this->lista->executar($tenant, $referencia, $filtros + [
                    'status'      => $status,
                    'responsavel' => (string) $linha->userId,
                ])->total;

                self::assertSame(
                    $esperado,
                    $total,
                    sprintf('%s de %s: Dashboard mostra %d, lista tem %d (filtros %s).', $coluna, $linha->nomeAdvogado, $esperado, $total, json_encode($filtros)),
                );
            }
        }

        self::assertTrue($algumNaoZero, 'Paridade só entre zeros não prova nada.');
    }

    #[TestDox('Sem período: cada número de metas do Dashboard = total da lista do mesmo responsável/status')]
    public function testParidadeSemPeriodo(): void
    {
        $tenant = $this->semear();

        $this->assertParidade($tenant, new \DateTimeImmutable(), []);
    }

    #[TestDox('Com período: todas/ativas recortam por criação, vencidas/prazos não — e ainda batem')]
    public function testParidadeComPeriodo(): void
    {
        $tenant = $this->semear();
        $filtros = [
            'data_de'  => (new \DateTimeImmutable('-30 days'))->format('Y-m-d'),
            'data_ate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ];

        $this->assertParidade($tenant, new \DateTimeImmutable(), $filtros);
    }

    #[TestDox('Com cargo: as linhas que sobram no Dashboard batem com a lista filtrada pelo mesmo cargo')]
    public function testParidadeComCargo(): void
    {
        $tenant = $this->semear();

        $this->assertParidade($tenant, new \DateTimeImmutable(), ['cargo' => 'Advogado']);
    }

    #[TestDox('Número da Ana em "vencidas" é 1, e não 2: a meta vencida do outro escritório não conta')]
    public function testValorAbsolutoDeControle(): void
    {
        $tenant     = $this->semear();
        $referencia = new \DateTimeImmutable();
        $linhas     = $this->dashboard->executar($tenant, $referencia, [])->porAdvogado;
        $ana        = array_values(array_filter($linhas, static fn (LinhaAdvogadoDashboardOutput $l): bool => $l->nomeAdvogado === 'Ana Paridade'))[0];

        self::assertSame(1, $ana->metasVencidas);
        self::assertSame(1, $this->lista->executar($tenant, $referencia, ['status' => 'vencidas', 'responsavel' => (string) $ana->userId])->total);
    }
}
