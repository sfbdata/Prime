<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Agenda\Evento;
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
 * DASH-010 — seta de tendência na extra com lastro por data (Eventos na agenda) e pílula no
 * Total dela, no MESMO formato e regra das colunas fixas (`_tendencia.html.twig`: período
 * anterior de mesma duração). Em revisão e Pastas urgentes são estoque: sem seta.
 *
 * DASH-010b — Metas concluídas, Taxa de conclusão e Tempo médio também ficam SEM seta: recortam
 * por data de criação e leem o status de hoje, o que enviesava a comparação (motivo em
 * ColunasExtrasDoDashboard::TENDENCIA). A massa abaixo daria seta nas três se ainda tivessem —
 * é a irmã que prova que a ausência é da regra, não de falta de dado.
 *
 * Massa: período 01–29/02/2024 (29 dias) → anterior 03–31/01/2024.
 *   fevereiro: 2 concluídas (2d e 4d), 1 pendente, 1 em revisão; 3 eventos da gestora;
 *   janeiro:   1 concluída (1d), 1 pendente, 1 em revisão;       1 evento da gestora.
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

    private function evento(Tenant $tenant, User $criador, string $inicio): void
    {
        $evento = new Evento();
        $evento->setTitulo('Compromisso');
        $evento->setTenant($tenant);
        $evento->setCriador($criador);
        $evento->setDataInicio(new \DateTimeImmutable($inicio));
        $evento->setDataFim((new \DateTimeImmutable($inicio))->modify('+1 hour'));
        $evento->setVisibilidade(Evento::VISIBILIDADE_TODOS);
        $evento->setStatus(Evento::STATUS_AGENDADO);
        $this->em()->persist($evento);
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

        $this->evento($tenant, $gestora, '2024-02-10 14:00');
        $this->evento($tenant, $gestora, '2024-02-15 14:00');
        $this->evento($tenant, $gestora, '2024-02-20 14:00');
        $this->evento($tenant, $gestora, '2024-01-20 14:00');

        $outro = $this->criarTenant();
        $this->em()->persist(new UserTenant($gestora, $outro));
        $this->em()->flush();
        $pastaFora = $this->pasta($outro);
        for ($i = 0; $i < 3; ++$i) {
            $this->meta($pastaFora, $gestora, Tarefa::STATUS_CONCLUIDA, '2024-01-15 09:00', '2024-01-25 09:00');
            $this->evento($outro, $gestora, '2024-01-22 14:00');
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

    /** Extras SEM tendência: estoque (DASH-010) e as três por criação + status de hoje (DASH-010b). */
    private const SEM_TENDENCIA = ['metas_concluidas', 'taxa_conclusao', 'tempo_medio', 'metas_revisao', 'pastas_urgentes'];

    #[TestDox('Na linha: eventos com seta (neutra, 1 → 3, sem o ruído do outro escritório); as outras cinco extras sem seta, como as de estoque')]
    public function testSetasNaLinha(): void
    {
        static::createClient();
        $this->semear();

        $linha = $this->linhaDaGestora(static::getClient()->request('GET', self::PERIODO));

        $eventos = $linha->filter('td[data-coluna="eventos_agenda"] > .db-cel.db-cel--seta > .db-seta.db-seta--igual[data-tendencia="sobe"]');
        self::assertCount(1, $eventos, 'eventos: neutro (fila), sempre cinza');
        self::assertSame('antes: 1 → agora: 3', trim($eventos->filter('.db-dica-de')->text()), 'o ruído do outro escritório não entrou no anterior');

        foreach (self::SEM_TENDENCIA as $coluna) {
            $cel = $linha->filter('td[data-coluna="' . $coluna . '"]');
            self::assertCount(1, $cel, $coluna . ': a coluna está na tabela');
            self::assertCount(0, $cel->filter('.db-seta'), $coluna . ': sem seta');
            self::assertCount(0, $cel->filter('.db-cel--seta'), $coluna . ': mesma célula das de estoque');
        }

        // irmã que prova o alvo: as três têm valor (2, 50%, 3d) — a seta sumiu pela regra
        self::assertSame('2', trim($linha->filter('td[data-coluna="metas_concluidas"]')->text()));
        self::assertStringContainsString('50', $linha->filter('td[data-coluna="taxa_conclusao"]')->text());
        self::assertStringContainsString('3', $linha->filter('td[data-coluna="tempo_medio"]')->text());
    }

    #[TestDox('No Total: pílula só em eventos (+200%, neutra); as outras cinco extras com o espaçador, como as de estoque')]
    public function testPilulasNoTotal(): void
    {
        static::createClient();
        $this->semear();

        $total = static::getClient()->request('GET', self::PERIODO)->filter('.db-table > tfoot > tr.db-total-row');

        $eventos = $total->filter('td[data-coluna="eventos_agenda"] > .db-total-cel > .db-total-num + .db-tend.db-tend--igual[data-tendencia="sobe"]');
        self::assertCount(1, $eventos);
        self::assertSame('+200%', trim($eventos->text()));
        self::assertSame('Subiu 200% em relação ao período anterior (1 → 3)', $eventos->attr('title'));

        foreach (self::SEM_TENDENCIA as $coluna) {
            self::assertCount(0, $total->filter('td[data-coluna="' . $coluna . '"] .db-tend'), $coluna);
            self::assertCount(1, $total->filter('td[data-coluna="' . $coluna . '"] > .db-total-cel > .db-total-espaco'), $coluna . ': espaço reservado');
        }
    }

    #[TestDox('No celular: seta e pílula só no bloco de eventos; concluídas, taxa e tempo médio sem nenhuma')]
    public function testCelular(): void
    {
        static::createClient();
        $this->semear();

        $crawler = static::getClient()->request('GET', self::PERIODO);

        self::assertGreaterThanOrEqual(1, $crawler->filter('.db-cel-bloco--extra[data-coluna="eventos_agenda"] > .db-cel-valor > .db-seta.db-seta--igual')->count());
        self::assertCount(1, $crawler->filter('.db-cel-card--total .db-cel-bloco--extra[data-coluna="eventos_agenda"] > .db-cel-valor--total > .db-tend.db-tend--igual'));
        foreach (self::SEM_TENDENCIA as $coluna) {
            self::assertGreaterThanOrEqual(1, $crawler->filter('.db-cel-bloco--extra[data-coluna="' . $coluna . '"]')->count(), $coluna . ': o bloco existe');
            self::assertCount(0, $crawler->filter('.db-cel-bloco--extra[data-coluna="' . $coluna . '"] .db-seta, .db-cel-bloco--extra[data-coluna="' . $coluna . '"] .db-tend'), $coluna);
        }
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
