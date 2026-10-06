<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Lote 2 do Dashboard, no servidor: busca por nome (`busca`), opção "Sem cargo"
 * (`cargo=__sem__`) e período anterior de mesma duração (tendência), sempre dentro
 * do escritório corrente.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(ObterDadosDashboardUseCase::class)]
#[Group('dashboard')]
final class DashboardBuscaCargoTendenciaTest extends DashboardWebTestCase
{
    use Factories;

    private function criarColaboradorComCargo(Tenant $tenant, string $nome, string $cargoNome): User
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $cargo = new Cargo();
        $cargo->setNome($cargoNome);
        $cargo->setTenant($tenant);
        $em->persist($cargo);

        $user = new User();
        $user->setEmail('dashboard_cargo_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setCargo($cargo);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    /** Meta com `dataCriacao` forçada (a entidade não tem setter: nasce "agora"). */
    private function criarMeta(Pasta $pasta, User $responsavel, string $criadaEm): void
    {
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => Tarefa::STATUS_PENDENTE])->_real();
        $tarefa->addResponsavel($responsavel);
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** @return string[] nomes nas linhas da tabela (só o tbody) */
    private function nomesNaTabela(Crawler $crawler): array
    {
        return $crawler->filter('table tbody tr')->each(static fn (Crawler $tr): string => $tr->text());
    }

    private function tabelaContem(Crawler $crawler, string $nome): bool
    {
        foreach ($this->nomesNaTabela($crawler) as $texto) {
            if (str_contains($texto, $nome)) {
                return true;
            }
        }

        return false;
    }

    #[TestDox('busca filtra a tabela pelo nome, sem acento e sem diferenciar maiúscula')]
    public function testBuscaFiltraTabela(): void
    {
        $client          = static::createClient();
        [, $tenant]      = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Élida Souza');
        $this->criarColaborador($tenant, 'Marcos Vieira');

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?busca=ELIDA');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->tabelaContem($crawler, 'Élida Souza'));
        self::assertFalse($this->tabelaContem($crawler, 'Marcos Vieira'));
        self::assertFalse($this->tabelaContem($crawler, 'Gestora da Tela'));
    }

    #[TestDox('busca não traz colaborador de outro escritório com o mesmo nome (isolamento)')]
    public function testBuscaNaoVazaOutroEscritorio(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Zuleica da Casa');

        $outro  = $this->criarTenant();
        $intrusa = $this->criarColaborador($outro, 'Zuleica Intrusa');
        PastaFactory::createMany(3, ['tenant' => $outro, 'criadoPor' => $intrusa, 'responsavel' => $intrusa]);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?busca=zuleica');

        self::assertResponseIsSuccessful();
        // O irmão do próprio escritório aparece: prova que a busca rodou e achou.
        self::assertTrue($this->tabelaContem($crawler, 'Zuleica da Casa'));
        self::assertFalse($this->tabelaContem($crawler, 'Zuleica Intrusa'));
    }

    #[TestDox('busca não altera o card Pastas criadas (calculado antes dela)')]
    public function testBuscaNaoAlteraCardPastasCriadas(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $ana   = $this->criarColaborador($tenant, 'Ana Lima');
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');
        PastaFactory::createMany(2, ['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana]);
        PastaFactory::createMany(3, ['tenant' => $tenant, 'criadoPor' => $bruno, 'responsavel' => $bruno]);

        $useCase  = static::getContainer()->get(ObterDadosDashboardUseCase::class);
        $semBusca = $useCase->executar($tenant, new \DateTimeImmutable(), []);
        $comBusca = $useCase->executar($tenant, new \DateTimeImmutable(), ['busca' => 'ana']);

        self::assertSame(5, $semBusca->totalPastasCriadas);
        self::assertSame(5, $comBusca->totalPastasCriadas);
        self::assertSame([$ana->getId()], array_map(static fn ($l): int => $l->userId, $comBusca->porAdvogado));
    }

    #[TestDox('cargo=__sem__ mostra só quem não tem cargo, e só do próprio escritório')]
    public function testSemCargoFiltraEIsola(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Sara Semcargo');
        $this->criarColaboradorComCargo($tenant, 'Carlos Comcargo', 'Advogado(a)');

        $outro = $this->criarTenant();
        $this->criarColaborador($outro, 'Bento Forasteiro');

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?cargo=__sem__');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->tabelaContem($crawler, 'Sara Semcargo'));
        self::assertFalse($this->tabelaContem($crawler, 'Carlos Comcargo'));
        self::assertFalse($this->tabelaContem($crawler, 'Bento Forasteiro'));
    }

    #[TestDox('cargo por nome continua funcionando (retrocompatível)')]
    public function testCargoPorNomeContinuaFuncionando(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Sara Semcargo');
        $this->criarColaboradorComCargo($tenant, 'Carlos Comcargo', 'Advogado(a)');

        $crawler = $client->xmlHttpRequest('GET', '/dashboard?cargo=' . rawurlencode('Advogado(a)'));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->tabelaContem($crawler, 'Carlos Comcargo'));
        self::assertFalse($this->tabelaContem($crawler, 'Sara Semcargo'));
    }

    #[TestDox('tendência: período anterior conta as pastas da janela anterior, só do escritório')]
    public function testPeriodoAnteriorContaJanelaAnteriorComTenant(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $ana        = $this->criarColaborador($tenant, 'Ana Lima');

        // Fevereiro/2024 (29 dias) → anterior = 03/01 a 31/01.
        PastaFactory::createMany(2, ['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-02-10 10:00')]);
        PastaFactory::createMany(3, ['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-01-20 10:00')]);
        // Antes da janela anterior: não entra em nenhuma das duas.
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-01-02 10:00')]);
        // Outro escritório, mesma pessoa e mesma janela: não pode contar.
        $outro = $this->criarTenant();
        PastaFactory::createMany(4, ['tenant' => $outro, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-01-20 10:00')]);

        // Metas (régua: dataCriacao). A pasta que as hospeda não tem responsável/criador e é
        // de 2023: não mexe em nenhuma das contagens de pasta acima.
        $hosp = PastaFactory::createOne(['tenant' => $tenant, 'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00')])->_real();
        $this->criarMeta($hosp, $ana, '2024-02-05 09:00');
        $this->criarMeta($hosp, $ana, '2024-02-20 09:00');
        $this->criarMeta($hosp, $ana, '2024-01-10 09:00');
        $this->criarMeta($hosp, $ana, '2024-01-02 09:00'); // antes da janela anterior
        // Outro escritório, mesma responsável, dentro da janela anterior: não pode contar.
        $hospOutro = PastaFactory::createOne(['tenant' => $outro, 'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00')])->_real();
        $this->criarMeta($hospOutro, $ana, '2024-01-10 09:00');
        $this->criarMeta($hospOutro, $ana, '2024-01-11 09:00');

        $useCase = static::getContainer()->get(ObterDadosDashboardUseCase::class);
        $output  = $useCase->executar($tenant, new \DateTimeImmutable(), ['data_de' => '2024-02-01', 'data_ate' => '2024-02-29']);

        self::assertSame(['data_de' => '2024-01-03', 'data_ate' => '2024-01-31'], $output->periodoAnterior);

        $linhaAna = array_values(array_filter(
            $output->porAdvogado,
            static fn (LinhaAdvogadoDashboardOutput $l): bool => $l->userId === $ana->getId(),
        ))[0];
        self::assertSame(2, $linhaAna->pastasCriadas);
        self::assertSame(3, $linhaAna->pastasCriadasAnterior);
        self::assertSame(3, $linhaAna->totalDemandasAnterior);
        self::assertSame(2, $linhaAna->totalMetas);
        self::assertSame(1, $linhaAna->totalMetasAnterior, 'Só a meta de 10/01 do próprio escritório');

        self::assertSame(3, $output->totalPastasCriadasAnterior);
        self::assertSame(['metas' => 1, 'demandas' => 3, 'pastas_criadas' => 3, 'metas_ativas' => null, 'metas_vencidas' => null, 'prazos' => null, 'demandas_ativas' => null], $output->totaisAnteriores);
    }

    #[TestDox('sem período, a tendência é null e a tela abre normalmente')]
    public function testSemPeriodoTendenciaNullETelaAbre(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);

        $output = static::getContainer()->get(ObterDadosDashboardUseCase::class)
            ->executar($tenant, new \DateTimeImmutable(), []);
        self::assertNull($output->totaisAnteriores);
        self::assertNull($output->periodoAnterior);
        self::assertNull($output->totalPastasCriadasAnterior);

        $client->request('GET', '/dashboard?busca=x&cargo=__sem__&data_de=2024-02-01&data_ate=2024-02-29');
        self::assertResponseIsSuccessful();
    }
}
