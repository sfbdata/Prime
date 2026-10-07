<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Preferencia\ColunasExtrasDoDashboard;
use App\Dashboard\Repository\MetricasExtrasDoDashboardRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
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
 * As consultas das colunas extras ("Adicionar coluna") contra o banco de verdade: o critério de
 * cada métrica, o período, a pessoa sem dado e — inegociável — o isolamento entre escritórios
 * (cada cenário tem um "outro" escritório com o MESMO tipo de dado, que não pode entrar).
 */
#[CoversClass(MetricasExtrasDoDashboardRepository::class)]
#[Group('dashboard')]
final class MetricasExtrasDoDashboardRepositoryTest extends KernelTestCase
{
    use Factories;

    private MetricasExtrasDoDashboardRepository $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(MetricasExtrasDoDashboardRepository::class);
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function pasta(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne([
            'tenant'       => $tenant,
            'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00'),
        ])->_real();
    }

    /** Meta com status, responsável, criação e (opcional) conclusão forçadas. */
    private function meta(Pasta $pasta, User $responsavel, string $status, string $criadaEm, ?string $concluidaEm = null): Tarefa
    {
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => $status])->_real();
        $tarefa->addResponsavel($responsavel);
        $tarefa->setDataConclusao($concluidaEm === null ? null : new \DateTimeImmutable($concluidaEm));
        // A entidade não tem setter de criação: nasce "agora" (mesmo recurso dos testes de tela).
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        $this->em->flush();

        return $tarefa;
    }

    /** @param list<User> $participantes */
    private function evento(
        Tenant $tenant,
        User $criador,
        string $inicio,
        array $participantes = [],
        string $visibilidade = Evento::VISIBILIDADE_TODOS,
        string $status = Evento::STATUS_AGENDADO,
    ): Evento {
        $evento = new Evento();
        $evento->setTitulo('Compromisso');
        $evento->setTenant($tenant);
        $evento->setCriador($criador);
        $evento->setDataInicio(new \DateTimeImmutable($inicio));
        $evento->setDataFim((new \DateTimeImmutable($inicio))->modify('+1 hour'));
        $evento->setVisibilidade($visibilidade);
        $evento->setStatus($status);
        foreach ($participantes as $p) {
            $evento->addParticipante($p);
        }
        $this->em->persist($evento);
        $this->em->flush();

        return $evento;
    }

    // ─── Em revisão ──────────────────────────────────────────────────

    #[TestDox('Em revisão: conta só status em_revisao, por responsável, e nada do outro escritório')]
    public function testEmRevisaoPorResponsavelIsolado(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();
        $pasta  = $this->pasta($tenant);

        $this->meta($pasta, $ana, Tarefa::STATUS_EM_REVISAO, '2024-01-10 10:00');
        $this->meta($pasta, $ana, Tarefa::STATUS_EM_REVISAO, '2024-01-11 10:00');
        $this->meta($pasta, $ana, Tarefa::STATUS_PENDENTE, '2024-01-12 10:00');
        $this->meta($pasta, $ana, Tarefa::STATUS_CONCLUIDA, '2024-01-12 10:00', '2024-01-13 10:00');
        // Ana também é responsável no outro escritório — não pode somar aqui.
        $this->meta($this->pasta($outro), $ana, Tarefa::STATUS_EM_REVISAO, '2024-01-10 10:00');

        $mapa = $this->repo->contarEmRevisaoPorResponsavel($tenant, []);

        self::assertSame(2, $mapa[$ana->getId()] ?? 0);
        self::assertArrayNotHasKey((int) $bruno->getId(), $mapa, 'pessoa sem meta em revisão não entra no mapa (o UseCase mostra 0)');
        self::assertSame(1, $this->repo->contarEmRevisaoPorResponsavel($outro, [])[$ana->getId()] ?? 0);
    }

    #[TestDox('Em revisão: o período filtra pela criação da meta, com data_ate inclusiva até o fim do dia')]
    public function testEmRevisaoRespeitaPeriodo(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $pasta  = $this->pasta($tenant);

        $this->meta($pasta, $ana, Tarefa::STATUS_EM_REVISAO, '2024-01-31 23:30');
        $this->meta($pasta, $ana, Tarefa::STATUS_EM_REVISAO, '2024-02-01 00:10');
        $this->meta($pasta, $ana, Tarefa::STATUS_EM_REVISAO, '2023-12-31 23:59');

        $mapa = $this->repo->contarEmRevisaoPorResponsavel($tenant, ['data_de' => '2024-01-01', 'data_ate' => '2024-01-31']);

        self::assertSame(1, $mapa[$ana->getId()] ?? 0);
    }

    // ─── Tempo médio ─────────────────────────────────────────────────

    #[TestDox('Tempo médio: soma de dias e quantidade só das concluídas COM data de conclusão; nada do outro escritório')]
    public function testTempoDeConclusao(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();
        $pasta  = $this->pasta($tenant);

        $this->meta($pasta, $ana, Tarefa::STATUS_CONCLUIDA, '2024-01-01 09:00', '2024-01-04 18:00'); // 3 dias
        $this->meta($pasta, $ana, Tarefa::STATUS_CONCLUIDA, '2024-01-10 09:00', '2024-01-11 08:00'); // 1 dia
        // concluída do legado, sem data: fica fora (não vira "0 dias")
        $this->meta($pasta, $ana, Tarefa::STATUS_CONCLUIDA, '2024-01-10 09:00');
        // pendente com data de conclusão esquecida (reaberta): fica fora
        $this->meta($pasta, $ana, Tarefa::STATUS_PENDENTE, '2024-01-10 09:00', '2024-01-20 09:00');
        // Bruno só tem concluída sem data: não há o que medir
        $this->meta($pasta, $bruno, Tarefa::STATUS_CONCLUIDA, '2024-01-10 09:00');
        $this->meta($this->pasta($outro), $ana, Tarefa::STATUS_CONCLUIDA, '2024-01-01 09:00', '2024-03-01 09:00');

        $mapa = $this->repo->tempoDeConclusaoPorResponsavel($tenant, []);

        self::assertSame(['dias' => 4, 'metas' => 2], $mapa[$ana->getId()] ?? null);
        self::assertArrayNotHasKey((int) $bruno->getId(), $mapa);
    }

    #[TestDox('Tempo médio: o período filtra pela criação da meta (mesma régua de Total metas)')]
    public function testTempoDeConclusaoRespeitaPeriodo(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $pasta  = $this->pasta($tenant);

        $this->meta($pasta, $ana, Tarefa::STATUS_CONCLUIDA, '2024-01-05 09:00', '2024-01-07 09:00'); // dentro, 2 dias
        $this->meta($pasta, $ana, Tarefa::STATUS_CONCLUIDA, '2023-12-20 09:00', '2024-01-05 09:00'); // criada antes

        $mapa = $this->repo->tempoDeConclusaoPorResponsavel($tenant, ['data_de' => '2024-01-01', 'data_ate' => '2024-01-31']);

        self::assertSame(['dias' => 2, 'metas' => 1], $mapa[$ana->getId()] ?? null);
    }

    // ─── Pastas urgentes ─────────────────────────────────────────────

    #[TestDox('Pastas urgentes: por responsável, só prioridade Urgente, período pela abertura, nada do outro escritório')]
    public function testUrgentesPorResponsavel(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();

        PastaFactory::createMany(2, ['tenant' => $tenant, 'responsavel' => $ana, 'prioridade' => PrioridadePasta::Urgente, 'dataAbertura' => new \DateTimeImmutable('2024-01-15 10:00')]);
        PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $ana, 'prioridade' => PrioridadePasta::Urgente, 'dataAbertura' => new \DateTimeImmutable('2023-11-15 10:00')]);
        PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $ana, 'prioridade' => PrioridadePasta::Prioridade, 'dataAbertura' => new \DateTimeImmutable('2024-01-15 10:00')]);
        // Bruno ABRIU uma urgente da Ana: conta para quem responde, não para quem criou.
        PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $ana, 'criadoPor' => $bruno, 'prioridade' => PrioridadePasta::Urgente, 'dataAbertura' => new \DateTimeImmutable('2024-01-16 10:00')]);
        PastaFactory::createMany(5, ['tenant' => $outro, 'responsavel' => $ana, 'prioridade' => PrioridadePasta::Urgente, 'dataAbertura' => new \DateTimeImmutable('2024-01-15 10:00')]);

        $tudo     = $this->repo->contarUrgentesPorResponsavel($tenant, []);
        $janeiro  = $this->repo->contarUrgentesPorResponsavel($tenant, ['data_de' => '2024-01-01', 'data_ate' => '2024-01-31']);

        self::assertSame(4, $tudo[$ana->getId()] ?? 0);
        self::assertSame(3, $janeiro[$ana->getId()] ?? 0);
        self::assertArrayNotHasKey((int) $bruno->getId(), $tudo);
    }

    // ─── Eventos na agenda ───────────────────────────────────────────

    #[TestDox('Eventos: criador e participante contam; quem é os dois conta uma vez; "somente eu", cancelado e outro escritório ficam fora')]
    public function testEventosPorPessoa(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();
        $carla  = UserFactory::createOne()->_real();

        $this->evento($tenant, $ana, '2024-01-10 09:00');                          // Ana cria
        $this->evento($tenant, $ana, '2024-01-11 09:00', [$ana, $bruno]);          // Ana cria e participa (1x) + Bruno
        $this->evento($tenant, $bruno, '2024-01-12 09:00', [$ana]);                // Bruno cria + Ana participa
        $this->evento($tenant, $ana, '2024-01-13 09:00', [], Evento::VISIBILIDADE_SOMENTE_EU);  // privado
        $this->evento($tenant, $ana, '2024-01-14 09:00', [], Evento::VISIBILIDADE_TODOS, Evento::STATUS_CANCELADO);
        $this->evento($outro, $ana, '2024-01-10 09:00', [$bruno]);                 // outro escritório

        $ids  = [(int) $ana->getId(), (int) $bruno->getId(), (int) $carla->getId()];
        $mapa = $this->repo->contarEventosPorPessoa($tenant, [], $ids);

        self::assertSame(3, $mapa[$ana->getId()] ?? 0);
        self::assertSame(2, $mapa[$bruno->getId()] ?? 0);
        self::assertArrayNotHasKey((int) $carla->getId(), $mapa, 'pessoa sem compromisso não entra no mapa');
    }

    #[TestDox('Eventos: o período usa a data de início; pessoa fora do universo da tabela não é contada; universo vazio não consulta')]
    public function testEventosPeriodoEUniverso(): void
    {
        $tenant = TenantFactory::createOne()->_real();
        $ana    = UserFactory::createOne()->_real();
        $bruno  = UserFactory::createOne()->_real();

        $this->evento($tenant, $ana, '2024-01-31 22:00', [$bruno]);
        $this->evento($tenant, $ana, '2024-02-01 08:00');
        $this->evento($tenant, $ana, '2023-12-31 23:00');

        $mapa = $this->repo->contarEventosPorPessoa($tenant, ['data_de' => '2024-01-01', 'data_ate' => '2024-01-31'], [(int) $ana->getId()]);

        self::assertSame([(int) $ana->getId() => 1], $mapa, 'Bruno participa, mas não está no universo pedido');
        self::assertSame([], $this->repo->contarEventosPorPessoa($tenant, [], []));
    }

    // ─── Desempenho ──────────────────────────────────────────────────

    #[TestDox('Desempenho: ligar as 6 extras custa exatamente 4 consultas a mais (uma por métrica com consulta; concluídas e taxa saem de graça), com qualquer número de pessoas')]
    public function testUmaConsultaPorMetrica(): void
    {
        $container = static::getContainer();
        if (!$container->has('doctrine.debug_data_holder')) {
            self::markTestSkipped('Sem o profiling do DBAL neste ambiente (doctrine.debug_data_holder): não há como contar consultas.');
        }
        $holder = $container->get('doctrine.debug_data_holder');

        $tenant = TenantFactory::createOne()->_real();
        $pasta  = $this->pasta($tenant);
        foreach (['Ana', 'Bruno', 'Carla', 'Davi', 'Edu'] as $nome) {
            $u = UserFactory::createOne(['fullName' => $nome])->_real();
            $this->em->persist(new UserTenant($u, $tenant));
            $this->em->flush();
            $this->meta($pasta, $u, Tarefa::STATUS_CONCLUIDA, '2024-01-01 09:00', '2024-01-03 09:00');
            $this->evento($tenant, $u, '2024-01-10 09:00');
        }

        /** @var ObterDadosDashboardUseCase $useCase */
        $useCase = $container->get(ObterDadosDashboardUseCase::class);
        $agora   = new \DateTimeImmutable('2024-02-01 12:00');

        $contar = function (array $extras) use ($holder, $useCase, $tenant, $agora): int {
            $this->em->clear();
            $holder->reset();
            $tenantGerenciado = $this->em->find(Tenant::class, $tenant->getId());
            $holder->reset();
            $useCase->executar($tenantGerenciado, $agora, [], $extras);

            $selects = 0;
            foreach ($holder->getData() as $consultas) {
                foreach ($consultas as $q) {
                    if (stripos(ltrim((string) $q['sql']), 'SELECT') === 0) {
                        ++$selects;
                    }
                }
            }

            return $selects;
        };

        $sem = $contar([]);
        $com = $contar(ColunasExtrasDoDashboard::chaves());

        self::assertSame(4, $com - $sem, sprintf('sem extras: %d consultas; com as 6: %d', $sem, $com));
        self::assertSame($sem, $contar([ColunasExtrasDoDashboard::METAS_CONCLUIDAS, ColunasExtrasDoDashboard::TAXA_CONCLUSAO]), 'concluídas e taxa não consultam o banco');
    }
}
