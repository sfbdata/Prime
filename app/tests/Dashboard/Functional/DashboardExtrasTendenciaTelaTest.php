<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
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
 * DASH-010 — setas de tendência nas colunas extras com lastro por data (concluídas, taxa, tempo
 * médio, eventos) e pílula no Total delas, no MESMO formato e regra das colunas fixas
 * (`_tendencia.html.twig`: período anterior de mesma duração). Em revisão e Pastas urgentes são
 * estoque: sem seta.
 *
 * Massa: período 01–29/02/2024 (29 dias) → anterior 03–31/01/2024.
 *   fevereiro: 2 concluídas (2d e 4d), 1 pendente, 1 em revisão → 2 concluídas, 50%, 3d;
 *   janeiro:   1 concluída (1d), 1 pendente, 1 em revisão        → 1 concluída, 33%, 1d.
 * Ruído de outro escritório em janeiro (mesma pessoa): se entrasse, o "antes" mudaria.
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardExtrasTendenciaTelaTest extends DashboardWebTestCase
{
    use Factories;

    private const PERIODO = '/dashboard?data_de=2024-02-01&data_ate=2024-02-29';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function pasta(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne(['tenant' => $tenant, 'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00')])->_real();
    }

    private function meta(Pasta $pasta, User $responsavel, string $status, string $criadaEm, ?string $concluidaEm = null): void
    {
        $tarefa = TarefaFactory::createOne(['pasta' => $pasta, 'status' => $status])->_real();
        $tarefa->addResponsavel($responsavel);
        $tarefa->setDataConclusao($concluidaEm === null ? null : new \DateTimeImmutable($concluidaEm));
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        $this->em()->flush();
    }

    private function semear(): void
    {
        $client = static::getClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $pasta = $this->pasta($tenant);

        $this->meta($pasta, $gestora, Tarefa::STATUS_CONCLUIDA, '2024-02-05 09:00', '2024-02-07 09:00');
        $this->meta($pasta, $gestora, Tarefa::STATUS_CONCLUIDA, '2024-02-05 09:00', '2024-02-09 09:00');
        $this->meta($pasta, $gestora, Tarefa::STATUS_PENDENTE, '2024-02-06 09:00');
        $this->meta($pasta, $gestora, Tarefa::STATUS_EM_REVISAO, '2024-02-06 09:00');

        $this->meta($pasta, $gestora, Tarefa::STATUS_CONCLUIDA, '2024-01-10 09:00', '2024-01-11 09:00');
        $this->meta($pasta, $gestora, Tarefa::STATUS_PENDENTE, '2024-01-12 09:00');
        $this->meta($pasta, $gestora, Tarefa::STATUS_EM_REVISAO, '2024-01-12 09:00');

        $outro = $this->criarTenant();
        $this->em()->persist(new UserTenant($gestora, $outro));
        $this->em()->flush();
        $pastaFora = $this->pasta($outro);
        for ($i = 0; $i < 3; ++$i) {
            $this->meta($pastaFora, $gestora, Tarefa::STATUS_CONCLUIDA, '2024-01-15 09:00', '2024-01-25 09:00');
        }

        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)->gravar(
            $tenant,
            $gestora,
            CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS,
            ['metas_concluidas', 'taxa_conclusao', 'metas_revisao', 'tempo_medio', 'pastas_urgentes', 'eventos_agenda'],
        );
    }

    private function linhaDaGestora(Crawler $crawler): Crawler
    {
        $linha = $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', 'Gestora da Tela'),
        );
        self::assertCount(1, $linha);

        return $linha;
    }

    #[TestDox('Na linha: concluídas e taxa sobem (verde), tempo médio sobe (vermelho: sentido baixa), eventos 0→0 com o espaço reservado')]
    public function testSetasNaLinha(): void
    {
        static::createClient();
        $this->semear();

        $linha = $this->linhaDaGestora(static::getClient()->request('GET', self::PERIODO));

        $concl = $linha->filter('td[data-coluna="metas_concluidas"] > .db-cel.db-cel--seta > .db-seta.db-seta--bom[data-tendencia="sobe"]');
        self::assertCount(1, $concl);
        self::assertSame('antes: 1 → agora: 2', trim($concl->filter('.db-dica-de')->text()), 'o ruído do outro escritório não entrou no anterior');
        self::assertCount(1, $linha->filter('td[data-coluna="metas_concluidas"] > .db-cel > a.db-num-link + .db-seta'), 'número (link da DASH-008) + seta, como nas colunas fixas');

        $taxa = $linha->filter('td[data-coluna="taxa_conclusao"] > .db-cel.db-cel--seta > .db-seta.db-seta--bom[data-tendencia="sobe"]');
        self::assertCount(1, $taxa);
        self::assertSame('antes: 33 → agora: 50', trim($taxa->filter('.db-dica-de')->text()));

        $tempo = $linha->filter('td[data-coluna="tempo_medio"] > .db-cel.db-cel--seta > .db-seta.db-seta--ruim[data-tendencia="sobe"]');
        self::assertCount(1, $tempo, 'tempo médio subindo é ruim (desenho: sobeRuim)');
        self::assertSame('antes: 1 → agora: 3', trim($tempo->filter('.db-dica-de')->text()));

        self::assertCount(1, $linha->filter('td[data-coluna="eventos_agenda"] > .db-cel.db-cel--seta > .db-seta.db-seta--vazia'));

        foreach (['metas_revisao', 'pastas_urgentes'] as $estoque) {
            self::assertCount(0, $linha->filter('td[data-coluna="' . $estoque . '"] .db-seta'), $estoque . ' é estoque: sem seta');
            self::assertCount(0, $linha->filter('td[data-coluna="' . $estoque . '"] .db-cel--seta'));
        }
    }

    #[TestDox('No Total: pílula nas quatro com lastro (mesmo formato das fixas); Em revisão e Pastas urgentes com o espaçador')]
    public function testPilulasNoTotal(): void
    {
        static::createClient();
        $this->semear();

        $total = static::getClient()->request('GET', self::PERIODO)->filter('.db-table > tfoot > tr.db-total-row');

        $concl = $total->filter('td[data-coluna="metas_concluidas"] > .db-total-cel > .db-total-num + .db-tend.db-tend--bom[data-tendencia="sobe"]');
        self::assertCount(1, $concl);
        self::assertSame('+100%', trim($concl->text()));
        self::assertSame('Subiu 100% em relação ao período anterior (1 → 2)', $concl->attr('title'));
        self::assertCount(1, $total->filter('td[data-coluna="taxa_conclusao"] > .db-total-cel > .db-tend.db-tend--bom[data-tendencia="sobe"]'));
        self::assertCount(1, $total->filter('td[data-coluna="tempo_medio"] > .db-total-cel > .db-tend.db-tend--ruim[data-tendencia="sobe"]'));
        self::assertCount(1, $total->filter('td[data-coluna="eventos_agenda"] > .db-total-cel > .db-tend.db-tend--igual'));

        foreach (['metas_revisao', 'pastas_urgentes'] as $estoque) {
            self::assertCount(0, $total->filter('td[data-coluna="' . $estoque . '"] .db-tend'), $estoque);
            self::assertCount(1, $total->filter('td[data-coluna="' . $estoque . '"] > .db-total-cel > .db-total-espaco'), $estoque);
        }
    }

    #[TestDox('No celular: a seta vai ao lado do número no bloco da extra e a pílula no card de Total')]
    public function testCelular(): void
    {
        static::createClient();
        $this->semear();

        $crawler = static::getClient()->request('GET', self::PERIODO);

        self::assertGreaterThanOrEqual(1, $crawler->filter('.db-cel-bloco--extra[data-coluna="metas_concluidas"] > .db-cel-valor > .db-seta.db-seta--bom')->count());
        self::assertCount(1, $crawler->filter('.db-cel-card--total .db-cel-bloco--extra[data-coluna="tempo_medio"] > .db-cel-valor--total > .db-tend.db-tend--ruim'));
        self::assertCount(0, $crawler->filter('.db-cel-bloco--extra[data-coluna="metas_revisao"] .db-seta, .db-cel-bloco--extra[data-coluna="metas_revisao"] .db-tend'));
    }

    #[TestDox('Sem período completo não há anterior: nenhuma seta nem pílula nas extras, e o número sai sozinho')]
    public function testSemPeriodoSemSeta(): void
    {
        static::createClient();
        $this->semear();

        $crawler = static::getClient()->request('GET', '/dashboard?data_de=2024-02-01');

        self::assertCount(0, $crawler->filter('td.db-td-extra .db-seta, td.db-td-extra .db-tend, td.db-td-extra .db-cel--seta'));
        self::assertCount(0, $crawler->filter('.db-cel-bloco--extra .db-seta, .db-cel-bloco--extra .db-tend'));
        // irmã que prova o alvo: as extras estão lá
        self::assertSame('2', trim($this->linhaDaGestora($crawler)->filter('td[data-coluna="metas_concluidas"]')->text()));
    }
}
