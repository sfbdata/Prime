<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\HistoricoDoDashboardController;
use App\Dashboard\Repository\HistoricoDoDashboardRepository;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Dashboard\UseCase\ObterHistoricoDoCardUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * DASH-017: GET /dashboard/historico/{card} — a série de 12 meses da janela "Histórico".
 *
 * As pastas da massa caem no ano PASSADO (todo fechado), em meses conhecidos: a série tem de
 * ser exatamente essa, sem lápide, sem criador desligado e sem a pasta de outro escritório — e a
 * soma dos meses tem de dar o número do card Pastas criadas com o período 01/01 a 31/12.
 */
#[CoversClass(HistoricoDoDashboardController::class)]
#[CoversClass(ObterHistoricoDoCardUseCase::class)]
#[CoversClass(HistoricoDoDashboardRepository::class)]
#[Group('dashboard')]
final class HistoricoDoDashboardControllerTest extends DashboardWebTestCase
{
    #[TestDox('sem o módulo bi: 403 (a mesma guarda do Dashboard)')]
    public function testSemBiRetorna403(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $semBi  = $this->criarColaborador($tenant, 'Sem Painel');
        $this->logarComTenant($client, $semBi, $tenant);

        $client->request('GET', '/dashboard/historico/pastas');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('card desconhecido: 404 (a rota só aceita os 4 cards)')]
    public function testCardDesconhecido404(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $client->request('GET', '/dashboard/historico/vencidas');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Pastas criadas: série de 12 meses certa no ano passado; lápide, desligado e outro escritório fora; soma = card do ano')]
    public function testSerieDePastasCriadas(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ano = (int) (new \DateTimeImmutable())->format('Y') - 1;

        $ana   = $this->criarColaborador($tenant, 'Ana Historico');
        $bruno = $this->criarColaborador($tenant, 'Bruno Historico');
        $carla = $this->criarColaborador($tenant, 'Carla Desligada');
        $this->desligar($carla, $tenant);
        $outro = $this->criarTenant();
        $this->em()->persist(new UserTenant($ana, $outro));
        $this->em()->flush();

        // jan: 2 (Ana, Bruno) · mar: 1 · jul: 3 · dez: 1 (31/12 23:30 ainda é dezembro)
        $this->pasta($tenant, $ana, sprintf('%d-01-05 09:00', $ano));
        $this->pasta($tenant, $bruno, sprintf('%d-01-31 18:00', $ano));
        $this->pasta($tenant, $ana, sprintf('%d-03-10 10:00', $ano));
        $this->pasta($tenant, $ana, sprintf('%d-07-01 00:00', $ano));
        $this->pasta($tenant, $bruno, sprintf('%d-07-15 10:00', $ano));
        $this->pasta($tenant, $gestora, sprintf('%d-07-31 23:59', $ano));
        $this->pasta($tenant, $ana, sprintf('%d-12-31 23:30', $ano));
        // fora da conta
        $this->pasta($tenant, $ana, sprintf('%d-03-11 10:00', $ano))->marcarExcluida($gestora, new \DateTimeImmutable());
        $this->pasta($tenant, $carla, sprintf('%d-03-12 10:00', $ano));
        $this->pasta($tenant, null, sprintf('%d-03-13 10:00', $ano));
        $this->pasta($outro, $ana, sprintf('%d-03-14 10:00', $ano));
        $this->em()->flush();

        $d = $this->historico($client, 'pastas');

        self::assertTrue($d['disponivel']);
        self::assertSame('Pastas criadas', $d['titulo']);
        self::assertSame('Histórico mensal', $d['subtitulo']);
        self::assertSame((string) ($ano + 1), $d['inicial'], 'abre no ano corrente');
        self::assertSame([(string) ($ano + 1), (string) $ano, (string) ($ano - 1), 'todos'], array_column($d['opcoes'], 'chave'));

        $opcao = $this->opcao($d, (string) $ano);
        $serie = array_column($opcao['barras'], 'valor');
        self::assertSame([2, 0, 1, 0, 0, 0, 3, 0, 0, 0, 0, 1], $serie, 'série mês a mês do ano passado');
        self::assertSame(['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'], array_column($opcao['barras'], 'rotulo'));
        self::assertTrue($opcao['barras'][6]['melhor'], 'julho é o melhor mês (dourado)');
        self::assertTrue($opcao['barras'][11]['atual'], 'dezembro é o último mês com valor');

        // Soma dos meses = o card Pastas criadas do Dashboard para o ano inteiro.
        /** @var ObterDadosDashboardUseCase $painel */
        $painel = static::getContainer()->get(ObterDadosDashboardUseCase::class);
        $card   = $painel->executar($tenant, new \DateTimeImmutable(), [
            'data_de'  => sprintf('%d-01-01', $ano),
            'data_ate' => sprintf('%d-12-31', $ano),
        ])->totalPastasCriadas;
        self::assertSame(7, $card, 'pré-condição: o card conta 7 no ano');
        self::assertSame($card, array_sum($serie), 'soma dos meses = número do card');

        // "Todos": o ano passado é a barra do meio, com a soma.
        $todos = $this->opcao($d, 'todos');
        self::assertSame([(string) ($ano - 1), (string) $ano, (string) ($ano + 1)], array_column($todos['barras'], 'rotulo'));
        self::assertSame(7, $todos['barras'][1]['valor']);
    }

    #[TestDox('responsável de OUTRO escritório: universo vazio, série zerada e nenhum nome revelado')]
    public function testResponsavelDeOutroEscritorio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $ano    = (int) (new \DateTimeImmutable())->format('Y') - 1;
        $ana    = $this->criarColaborador($tenant, 'Ana Do Escritorio');
        $this->pasta($tenant, $ana, sprintf('%d-05-05 10:00', $ano));

        $outro   = $this->criarTenant();
        $intruso = $this->criarColaborador($outro, 'Intruso Alheio');
        $this->pasta($outro, $intruso, sprintf('%d-05-06 10:00', $ano));
        $this->em()->flush();

        $d = $this->historico($client, 'pastas', ['responsavel' => (string) $intruso->getId()]);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], array_column($this->opcao($d, (string) $ano)['barras'], 'valor'));
        self::assertStringNotContainsStringIgnoringCase('Intruso', (string) $client->getResponse()->getContent());

        // Irmão com dado: a mesma consulta com o responsável DO escritório traz a pasta dele.
        $d = $this->historico($client, 'pastas', ['responsavel' => (string) $ana->getId()]);
        self::assertSame(1, $this->opcao($d, (string) $ano)['barras'][4]['valor']);
        self::assertStringContainsStringIgnoringCase('Ana Do Escritorio', $d['subtitulo']);
    }

    #[TestDox('Metas ativas, Urgentes e Meta global: sem série (retrato do momento) — texto honesto e nenhum número')]
    public function testCardsSemSerieNaoInventamNumero(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        foreach (['metas' => 'Metas ativas', 'urgentes' => 'Demandas urgentes', 'global' => 'Meta global batida'] as $card => $titulo) {
            $d = $this->historico($client, $card);
            self::assertFalse($d['disponivel'], $card);
            self::assertSame($titulo, $d['titulo']);
            self::assertSame([], $d['opcoes'], 'nenhuma série inventada');
            self::assertNull($d['inicial']);
            self::assertStringContainsString('foto diária', $d['nota']);
        }
    }

    // ------------------------------------------------------------------ apoio

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function historico(KernelBrowser $client, string $card, array $query = []): array
    {
        $client->request('GET', '/dashboard/historico/' . $card . ($query === [] ? '' : '?' . http_build_query($query)));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function opcao(array $d, string $chave): array
    {
        foreach ($d['opcoes'] as $o) {
            if ($o['chave'] === $chave) {
                return $o;
            }
        }
        self::fail('opção ' . $chave . ' ausente');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function pasta(Tenant $tenant, ?User $criador, string $abertura): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('HIST-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criador);
        $pasta->setDataAbertura(new \DateTimeImmutable($abertura));
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** Desliga o vínculo (a entidade só tem `reativar()`; o desligamento real é do UseCase de remoção). */
    private function desligar(User $user, Tenant $tenant): void
    {
        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertInstanceOf(UserTenant::class, $ut);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($ut, false);
        $this->em()->flush();
    }
}
