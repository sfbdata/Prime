<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * As opções dos filtros Responsável e Cargo do /dashboard saem da equipe que o `ObterDadosDashboardUseCase` já
 * leu (colaboradores, cargo e foto), sem o controller consultar de novo. Quando o UseCase não leu (filtro que
 * esvazia a tabela: ele pula a foto), o controller consulta, como antes. As opções são as mesmas nos dois casos.
 */
#[CoversClass(DashboardController::class)]
final class DashboardEquipeNumaLeituraTest extends DashboardWebTestCase
{
    /** Formas das 3 consultas de equipe (UserRepository): colaboradores ativos, cargo e foto. */
    private const CONSULTAS_DE_EQUIPE = [
        'colaboradores' => '/^SELECT .* FROM "user" u0_ INNER JOIN user_tenant u2_ ON \(u2_\.user_id = u0_\.id AND u2_\.tenant_id = \? AND u2_\.is_active = true\)/s',
        'cargo'         => '/^SELECT u0_\.id AS id_\d+, c1_\.nome AS nome_\d+ FROM user_tenant u2_ INNER JOIN "user" u0_/s',
        'foto'          => '/^SELECT u0_\.id AS id_\d+, u1_\.foto_url AS foto_url_\d+ FROM user_tenant u2_ INNER JOIN "user" u0_/s',
    ];

    /** @return iterable<string, array{0: string, 1: int}> filtro da URL e quantas vezes cada consulta de equipe roda */
    public static function filtros(): iterable
    {
        yield 'sem filtro: tudo do UseCase' => ['', 1];
        yield 'responsável de outro escritório: tabela vazia, o controller lê a foto' => ['responsavel=intruso', 1];
        yield 'cargo: a tabela encolhe, as opções não' => ['cargo=Advogado', 1];
    }

    #[DataProvider('filtros')]
    #[TestDox('$_dataName')]
    public function testUmaLeituraDeCadaEOpcoesIguais(string $filtro, int $vezes): void
    {
        $client = static::createClient();
        $c = $this->montarCenario($client);
        $url = '/dashboard' . ($filtro === '' ? '' : '?' . str_replace('intruso', (string) $c['intruso']->getId(), $filtro));

        $client->enableProfiler();
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $db = $client->getProfile()->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $db);
        $sqls = array_column($db->getQueries()['default'] ?? [], 'sql');
        foreach (self::CONSULTAS_DE_EQUIPE as $nome => $forma) {
            $rodou = count(array_filter($sqls, static fn (string $sql): bool => preg_match($forma, $sql) === 1));
            // A foto só roda quando há linha na tabela (UseCase) ou quando ele não leu (controller): 1 nos dois.
            self::assertSame($vezes, $rodou, "consulta de $nome");
        }

        $ids = static fn (User ...$u): array => array_map(static fn (User $x): string => (string) $x->getId(), $u);
        // Ordem do repositório: nome.
        self::assertSame($ids($c['ana'], $c['bruno'], $c['carla'], $c['gestora']), $this->valores($crawler, 'responsavel'));
        // A gestora não tem cargo: entra a opção "Sem cargo".
        self::assertSame(['Advogado', 'Estagiário', '__sem__'], $this->valores($crawler, 'cargo'));
        $ana = $this->opcao($crawler, 'responsavel', (string) $c['ana']->getId());
        self::assertStringContainsString('foto_ana_equipe.jpg', $ana->html(), 'foto da Ana');
        self::assertStringContainsString('Advogado', $ana->text(), 'cargo da Ana');
        self::assertStringContainsString('Estagiário', $this->opcao($crawler, 'responsavel', (string) $c['carla']->getId())->text());
        self::assertStringNotContainsString('foto_intruso', $crawler->filter('.db-dd[data-db-dd="responsavel"]')->html());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Gestora (sem cargo), Ana e Bruno (Advogado, Ana com foto), Carla (Estagiário); intruso de outro escritório.
     *
     * @return array{gestora: User, ana: User, bruno: User, carla: User, intruso: User}
     */
    private function montarCenario(KernelBrowser $client): array
    {
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana = $this->criarColaborador($tenant, 'Ana Lima');
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');
        $carla = $this->criarColaborador($tenant, 'Carla Souza');
        $advogado = $this->criarCargo($tenant, 'Advogado');
        $this->darCargo($ana, $tenant, $advogado);
        $this->darCargo($bruno, $tenant, $advogado);
        $this->darCargo($carla, $tenant, $this->criarCargo($tenant, 'Estagiário'));
        $this->darFoto($ana, 'foto_ana_equipe.jpg');

        $outro = $this->criarTenant();
        $intruso = $this->criarColaborador($outro, 'Intruso Externo');
        $this->darFoto($intruso, 'foto_intruso_equipe.jpg');
        $this->em()->clear();

        return compact('gestora', 'ana', 'bruno', 'carla', 'intruso');
    }

    private function criarCargo(Tenant $tenant, string $nome): Cargo
    {
        $cargo = new Cargo();
        $cargo->setNome($nome);
        $cargo->setTenant($tenant);
        $this->em()->persist($cargo);
        $this->em()->flush();

        return $cargo;
    }

    private function darCargo(User $user, Tenant $tenant, Cargo $cargo): void
    {
        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($ut);
        $ut->setCargo($cargo);
        $this->em()->flush();
    }

    private function darFoto(User $user, string $arquivo): void
    {
        $this->em()->persist((new UserProfile($user))->setFotoUrl($arquivo));
        $this->em()->flush();
    }

    /** @return list<string> valores das opções do select próprio, sem a "Todos" */
    private function valores(Crawler $crawler, string $name): array
    {
        return array_values(array_filter(
            $crawler->filter('.db-dd[data-db-dd="' . $name . '"] [role="listbox"] > [role="option"]')->each(
                static fn (Crawler $o): string => (string) $o->attr('data-valor'),
            ),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function opcao(Crawler $crawler, string $name, string $valor): Crawler
    {
        $op = $crawler->filter('.db-dd[data-db-dd="' . $name . '"] [role="listbox"] > [role="option"][data-valor="' . $valor . '"]');
        self::assertCount(1, $op, $name . '=' . $valor);

        return $op;
    }
}
