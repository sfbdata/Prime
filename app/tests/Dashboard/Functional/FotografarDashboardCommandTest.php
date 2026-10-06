<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Command\FotografarDashboardCommand;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Dashboard\UseCase\FotografarDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:dashboard:fotografar` — a foto diária do estoque do Dashboard.
 *
 * O que se prova: grava uma linha por colaborador ativo com as MESMAS contagens do painel
 * (metas ativas, demandas ativas, vencidas e prazos relativos ao fim do dia pedido); rodar de
 * novo regrava em vez de duplicar (idempotente); `--dry-run` não grava; `--tenant` fotografa só
 * aquele escritório e o que é de outro escritório — mesmo da MESMA pessoa — não entra na conta.
 */
#[CoversClass(FotografarDashboardCommand::class)]
#[CoversClass(FotografarDashboardUseCase::class)]
#[CoversClass(DashboardFotoRepository::class)]
#[Group('dashboard')]
final class FotografarDashboardCommandTest extends KernelTestCase
{
    use Factories;

    private const DIA = '2026-01-15';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conexao(): Connection
    {
        return $this->em()->getConnection();
    }

    private function criarTenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarColaborador(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('dashboard_foto_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();
    }

    private function criarMeta(Pasta $pasta, User $responsavel, string $status, ?string $prazo): Tarefa
    {
        $tarefa = TarefaFactory::createOne([
            'pasta'  => $pasta,
            'status' => $status,
            'prazo'  => $prazo === null ? null : new \DateTimeImmutable($prazo),
        ])->_real();
        $tarefa->addResponsavel($responsavel);
        $this->em()->flush();

        return $tarefa;
    }

    /**
     * Ana no escritório: 2 pastas ativas, 1 meta vencida (prazo 10/01), 1 com prazo próximo
     * (18/01, dentro dos 7 dias após 15/01) e 1 concluída (não é ativa).
     *
     * @return array{0: Tenant, 1: User, 2: User, 3: Tarefa} escritório, Ana, Beto (sem nada), a meta vencida
     */
    private function cenario(): array
    {
        $tenant = $this->criarTenant('Escritório Foto');
        $ana    = $this->criarColaborador($tenant, 'Ana Foto');
        $beto   = $this->criarColaborador($tenant, 'Beto Foto');

        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $ana])->_real();
        PastaFactory::createOne(['tenant' => $tenant, 'responsavel' => $ana]);

        $vencida = $this->criarMeta($pasta, $ana, Tarefa::STATUS_PENDENTE, '2026-01-10 00:00:00');
        $this->criarMeta($pasta, $ana, Tarefa::STATUS_PENDENTE, '2026-01-18 00:00:00');
        $this->criarMeta($pasta, $ana, Tarefa::STATUS_CONCLUIDA, '2026-01-05 00:00:00');

        return [$tenant, $ana, $beto, $vencida];
    }

    /** @param array<string, mixed> $opcoes */
    private function rodar(array $opcoes): CommandTester
    {
        $app    = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:dashboard:fotografar'));
        $tester->execute($opcoes);

        return $tester;
    }

    /** @return array<int, array{metas_ativas: int, demandas_ativas: int, metas_vencidas: int, prazos_proximos: int}> */
    private function fotos(int $tenantId, string $dia = self::DIA): array
    {
        $rows = $this->conexao()->fetchAllAssociative(
            'SELECT user_id, metas_ativas, demandas_ativas, metas_vencidas, prazos_proximos
               FROM dashboard_foto WHERE tenant_id = :tenant AND referencia = :dia',
            ['tenant' => $tenantId, 'dia' => $dia],
        );

        $mapa = [];
        foreach ($rows as $row) {
            $mapa[(int) $row['user_id']] = [
                'metas_ativas'    => (int) $row['metas_ativas'],
                'demandas_ativas' => (int) $row['demandas_ativas'],
                'metas_vencidas'  => (int) $row['metas_vencidas'],
                'prazos_proximos' => (int) $row['prazos_proximos'],
            ];
        }

        return $mapa;
    }

    private function totalDeLinhas(int $tenantId): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM dashboard_foto WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
    }

    #[TestDox('grava uma foto por colaborador ativo com as mesmas contagens do painel (zeros incluídos)')]
    public function testGravaAsContagensDoPainel(): void
    {
        self::bootKernel();
        [$tenant, $ana, $beto] = $this->cenario();
        $tenantId = (int) $tenant->getId();
        $anaId    = (int) $ana->getId();
        $betoId   = (int) $beto->getId();

        $tester = $this->rodar(['--tenant' => (string) $tenantId, '--data' => self::DIA]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('referencia=2026-01-15 modo=gravacao escritorios=1 colaboradores=2 gravadas=2 falhas=0', $tester->getDisplay());
        self::assertSame([
            $anaId  => ['metas_ativas' => 2, 'demandas_ativas' => 2, 'metas_vencidas' => 1, 'prazos_proximos' => 1],
            $betoId => ['metas_ativas' => 0, 'demandas_ativas' => 0, 'metas_vencidas' => 0, 'prazos_proximos' => 0],
        ], $this->fotos($tenantId));
    }

    #[TestDox('rodar o mesmo dia de novo regrava os números em vez de duplicar a linha')]
    public function testEhIdempotente(): void
    {
        self::bootKernel();
        [$tenant, $ana, , $vencida] = $this->cenario();
        $tenantId  = (int) $tenant->getId();
        $anaId     = (int) $ana->getId();
        $vencidaId = (int) $vencida->getId();

        $this->rodar(['--tenant' => (string) $tenantId, '--data' => self::DIA]);
        $this->rodar(['--tenant' => (string) $tenantId, '--data' => self::DIA]);
        self::assertSame(2, $this->totalDeLinhas($tenantId), 'duas pessoas, um dia: duas linhas, por mais que rode');

        // A meta vencida foi concluída: a refoto do mesmo dia reflete isso.
        $this->conexao()->executeStatement('UPDATE tarefa SET status = :s WHERE id = :id', ['s' => Tarefa::STATUS_CONCLUIDA, 'id' => $vencidaId]);
        $tester = $this->rodar(['--tenant' => (string) $tenantId, '--data' => self::DIA]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(2, $this->totalDeLinhas($tenantId));
        self::assertSame(['metas_ativas' => 1, 'demandas_ativas' => 2, 'metas_vencidas' => 0, 'prazos_proximos' => 1], $this->fotos($tenantId)[$anaId]);
    }

    #[TestDox('--dry-run calcula e mostra, mas não grava nada')]
    public function testDryRunNaoGrava(): void
    {
        self::bootKernel();
        [$tenant] = $this->cenario();
        $tenantId = (int) $tenant->getId();

        $tester = $this->rodar(['--tenant' => (string) $tenantId, '--data' => self::DIA, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('modo=simulacao escritorios=1 colaboradores=2 gravadas=0', $tester->getDisplay());
        self::assertSame(0, $this->totalDeLinhas($tenantId));
    }

    #[TestDox('--tenant fotografa só aquele escritório; o que a mesma pessoa tem em outro escritório não entra')]
    public function testOutroEscritorioIsolado(): void
    {
        self::bootKernel();
        [$tenant, $ana] = $this->cenario();

        // A mesma Ana também trabalha noutro escritório, com metas vencidas e pastas lá.
        $outro = $this->criarTenant('Escritório Vizinho');
        $this->vincular($ana, $outro);
        $pastaOutro = PastaFactory::createOne(['tenant' => $outro, 'responsavel' => $ana])->_real();
        $this->criarMeta($pastaOutro, $ana, Tarefa::STATUS_PENDENTE, '2026-01-02 00:00:00');
        $this->criarMeta($pastaOutro, $ana, Tarefa::STATUS_PENDENTE, '2026-01-03 00:00:00');

        $tenantId = (int) $tenant->getId();
        $outroId  = (int) $outro->getId();
        $anaId    = (int) $ana->getId();

        $this->rodar(['--tenant' => (string) $tenantId, '--data' => self::DIA]);

        self::assertSame(0, $this->totalDeLinhas($outroId), 'o vizinho não foi pedido: nenhuma foto lá');
        self::assertSame(
            ['metas_ativas' => 2, 'demandas_ativas' => 2, 'metas_vencidas' => 1, 'prazos_proximos' => 1],
            $this->fotos($tenantId)[$anaId],
            'as duas metas e a pasta do vizinho não entram na foto deste escritório',
        );

        // Fotografando o vizinho: só o que é de lá, e a foto do primeiro fica como estava.
        $this->rodar(['--tenant' => (string) $outroId, '--data' => self::DIA]);

        self::assertSame(
            [$anaId => ['metas_ativas' => 2, 'demandas_ativas' => 1, 'metas_vencidas' => 2, 'prazos_proximos' => 0]],
            $this->fotos($outroId),
        );
        self::assertSame(2, $this->fotos($tenantId)[$anaId]['metas_ativas']);
    }

    #[TestDox('a leitura da foto fora de request (sem TenantFilter) devolve só a do escritório pedido')]
    public function testLeituraDaFotoFiltraOEscritorioSemTenantFilter(): void
    {
        self::bootKernel();
        $tenant = $this->criarTenant('Escritório Leitura');
        $outro  = $this->criarTenant('Escritório Vizinho');
        $ana    = $this->criarColaborador($tenant, 'Ana Leitura');
        $this->vincular($ana, $outro);
        $anaId  = (int) $ana->getId();

        $repo = static::getContainer()->get(DashboardFotoRepository::class);
        $dia  = new \DateTimeImmutable(self::DIA);
        $repo->gravar($tenant, $dia, [$anaId => ['metas_ativas' => 1, 'demandas_ativas' => 1, 'metas_vencidas' => 1, 'prazos_proximos' => 1]]);
        $repo->gravar($outro, $dia, [$anaId => ['metas_ativas' => 9, 'demandas_ativas' => 9, 'metas_vencidas' => 9, 'prazos_proximos' => 9]]);

        self::assertSame(
            [$anaId => ['metas_ativas' => 1, 'demandas_ativas' => 1, 'metas_vencidas' => 1, 'prazos_proximos' => 1]],
            $repo->buscarPorReferencia($tenant, $dia, [$anaId]),
        );
        self::assertSame([], $repo->buscarPorReferencia($tenant, $dia->modify('-1 day'), [$anaId]), 'outro dia: nada');
    }

    #[TestDox('data inválida, data no futuro e escritório inexistente falham sem gravar')]
    public function testEntradasInvalidasFalham(): void
    {
        self::bootKernel();
        [$tenant] = $this->cenario();
        $tenantId = (int) $tenant->getId();

        $amanha = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        self::assertSame(Command::FAILURE, $this->rodar(['--tenant' => (string) $tenantId, '--data' => '2026-02-30'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->rodar(['--tenant' => (string) $tenantId, '--data' => $amanha])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->rodar(['--tenant' => '999999999', '--data' => self::DIA])->getStatusCode());
        self::assertSame(0, $this->totalDeLinhas($tenantId));
    }
}
