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
use App\Tarefa\Controller\TarefaEquipeController;
use App\Tarefa\UseCase\ListarMetasDaEquipeUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * DASH-008 — os números das extras "Metas concluídas" e "Em revisão" viram link para
 * /tarefas/equipe com o status correspondente (`concluidas` / `em_revisao`), a pessoa e o período.
 *
 * A regra que importa: o número da célula = total da lista de destino. O teste segue o link de
 * cada célula de cada linha, com e sem período, e compara com as metas listadas. Massa com ruído
 * de outro escritório (mesmo colaborador vinculado aos dois): não entra em nenhum dos lados.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(TarefaEquipeController::class)]
#[CoversClass(ListarMetasDaEquipeUseCase::class)]
#[Group('dashboard')]
final class DashboardExtrasLinkEquipeTelaTest extends DashboardWebTestCase
{
    /** coluna extra => [status da lista, rótulo do título] */
    private const COLUNAS = [
        'metas_concluidas' => ['concluidas', 'Metas concluídas'],
        'metas_revisao'    => ['em_revisao', 'Metas em revisão'],
    ];

    /** O número da célula: direto no td, ou dentro de .db-cel quando a coluna tem seta. */
    private const NUMERO = 'td > .db-num, td > .db-cel > .db-num';
    private const LINK   = 'td > a.db-num-link, td > .db-cel > a.db-num-link';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<User> $responsaveis */
    private function meta(Tenant $tenant, array $responsaveis, string $status, string $criadaEm, string $titulo = ''): Tarefa
    {
        $pasta = new Pasta();
        $pasta->setNup('EXL-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo !== '' ? $titulo : 'Meta ' . uniqid());
        $tarefa->setDescricao('—');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        foreach ($responsaveis as $r) {
            $tarefa->addResponsavel($r);
        }
        $this->em()->persist($tarefa);
        $this->em()->flush();

        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :d WHERE id = :id',
            ['d' => $criadaEm, 'id' => $tarefa->getId()],
        );

        return $tarefa;
    }

    /**
     * Gestora (logada) e Bruno no escritório; metas em fevereiro/2024 e fora dele; ruído no
     * outro escritório com a própria gestora como responsável.
     *
     * @return array{0: User, 1: Tenant, 2: User}
     */
    private function semear(KernelBrowser $client): array
    {
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $bruno = $this->criarColaborador($tenant, 'Bruno Extra');

        $this->meta($tenant, [$gestora], Tarefa::STATUS_CONCLUIDA, '2024-02-10 09:00:00');
        $this->meta($tenant, [$gestora], Tarefa::STATUS_CONCLUIDA, '2024-01-15 09:00:00'); // fora do período
        $this->meta($tenant, [$gestora, $bruno], Tarefa::STATUS_CONCLUIDA, '2024-02-20 09:00:00');
        $this->meta($tenant, [$gestora], Tarefa::STATUS_EM_REVISAO, '2024-02-11 09:00:00');
        $this->meta($tenant, [$bruno], Tarefa::STATUS_EM_REVISAO, '2024-03-05 09:00:00'); // fora do período
        $this->meta($tenant, [$gestora], Tarefa::STATUS_PENDENTE, '2024-02-12 09:00:00');

        $outro = $this->criarTenant();
        $this->em()->persist(new UserTenant($gestora, $outro));
        $this->em()->flush();
        $this->meta($outro, [$gestora], Tarefa::STATUS_CONCLUIDA, '2024-02-10 09:00:00', 'Concluída do outro escritório');
        $this->meta($outro, [$gestora], Tarefa::STATUS_CONCLUIDA, '2024-02-10 09:00:00', 'Concluída do outro escritório');
        $this->meta($outro, [$gestora], Tarefa::STATUS_EM_REVISAO, '2024-02-10 09:00:00', 'Revisão do outro escritório');

        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)->gravar(
            $tenant,
            $gestora,
            CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS,
            ['metas_concluidas', 'metas_revisao'],
        );

        return [$gestora, $tenant, $bruno];
    }

    private function linhaDe(Crawler $crawler, string $nome): Crawler
    {
        $linha = $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'Linha de ' . $nome);

        return $linha;
    }

    /**
     * Para cada linha e cada coluna: o número na célula é o total da lista que o link abre.
     *
     * @return array<string, array<string, int>> nome => coluna => número
     */
    private function assertParidade(KernelBrowser $client, string $query, array $nomes): array
    {
        $dashboard = $client->request('GET', '/dashboard' . $query);
        self::assertResponseIsSuccessful();

        $vistos       = [];
        $algumNaoZero = false;
        foreach ($nomes as $nome) {
            $linha = $this->linhaDe($dashboard, $nome);
            foreach (self::COLUNAS as $coluna => [$status, $rotulo]) {
                $celula = $linha->filter('td[data-coluna="' . $coluna . '"]');
                // O número é o .db-num da célula; com período, a coluna ganha a seta de
                // tendência (DASH-010) e o número passa a morar em .db-cel, ao lado dela.
                $num = $celula->filter(self::NUMERO);
                self::assertCount(1, $num, sprintf('%s de %s tem um só número', $coluna, $nome));
                $numero = (int) trim($num->text());
                $vistos[$nome][$coluna] = $numero;

                $link = $celula->filter(self::LINK);
                if ($numero === 0) {
                    self::assertCount(0, $link, 'zero não é link');
                    continue;
                }
                $algumNaoZero = true;
                self::assertCount(1, $link, sprintf('%s de %s (%d) é link', $coluna, $nome, $numero));
                $href = (string) $link->attr('href');
                self::assertSame('/tarefas/equipe', parse_url($href, PHP_URL_PATH));
                parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
                self::assertSame($status, $q['status'] ?? null);
                self::assertSame(sprintf('Abrir: %s de %s', $rotulo, $nome), $link->attr('aria-label'));

                $lista = $client->request('GET', $href);
                self::assertResponseIsSuccessful();
                self::assertSame(
                    $numero,
                    $lista->filter('[data-equipe-lista] > .mm-linha[data-equipe-meta]')->count(),
                    sprintf('%s de %s: célula %d × lista', $coluna, $nome, $numero),
                );
                self::assertStringStartsWith($rotulo . ' — ' . $nome, trim($lista->filter('[data-equipe-titulo]')->text()));
                $corpo = (string) $client->getResponse()->getContent();
                self::assertStringNotContainsStringIgnoringCase('do outro escritório', $corpo);
            }
        }
        self::assertTrue($algumNaoZero, 'paridade só entre zeros não prova nada');

        return $vistos;
    }

    #[TestDox('Sem período: o número de Metas concluídas e de Em revisão de cada linha = total da lista que o link abre')]
    public function testParidadeSemPeriodo(): void
    {
        $client = static::createClient();
        $this->semear($client);

        $vistos = $this->assertParidade($client, '', ['Gestora da Tela', 'Bruno Extra']);

        // valores absolutos: as 2 concluídas e a revisão do outro escritório não contam
        self::assertSame(['metas_concluidas' => 3, 'metas_revisao' => 1], $vistos['Gestora da Tela']);
        self::assertSame(['metas_concluidas' => 1, 'metas_revisao' => 1], $vistos['Bruno Extra']);
    }

    #[TestDox('Com período: link leva data_de/data_ate e a lista recorta pela criação, como a célula')]
    public function testParidadeComPeriodo(): void
    {
        $client = static::createClient();
        $this->semear($client);

        $vistos = $this->assertParidade($client, '?data_de=2024-02-01&data_ate=2024-02-29', ['Gestora da Tela', 'Bruno Extra']);

        self::assertSame(['metas_concluidas' => 2, 'metas_revisao' => 1], $vistos['Gestora da Tela']);
        self::assertSame(['metas_concluidas' => 1, 'metas_revisao' => 0], $vistos['Bruno Extra']);

        $dash = $client->request('GET', '/dashboard?data_de=2024-02-01&data_ate=2024-02-29');
        $href = (string) $this->linhaDe($dash, 'Gestora da Tela')->filter('td[data-coluna="metas_concluidas"]')->filter(self::LINK)->attr('href');
        parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
        self::assertSame('2024-02-01', $q['data_de'] ?? null);
        self::assertSame('2024-02-29', $q['data_ate'] ?? null);
    }

    #[TestDox('Responsável de OUTRO escritório na URL de concluidas/em_revisao: lista vazia, sem nome nem meta alheia')]
    public function testResponsavelDeOutroEscritorio(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);
        $outro    = $this->criarTenant();
        $estranho = $this->criarColaborador($outro, 'Pessoa De Fora');
        $this->meta($outro, [$estranho], Tarefa::STATUS_CONCLUIDA, '2024-02-10 09:00:00', 'Concluída do outro escritório');
        $this->meta($outro, [$estranho], Tarefa::STATUS_EM_REVISAO, '2024-02-10 09:00:00', 'Revisão do outro escritório');

        foreach (['concluidas', 'em_revisao'] as $status) {
            $crawler = $client->request('GET', '/tarefas/equipe', ['status' => $status, 'responsavel' => $estranho->getId()]);

            self::assertResponseIsSuccessful();
            self::assertSame(1, $crawler->filter('[data-equipe-vazio]')->count(), $status);
            $corpo = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsStringIgnoringCase('Pessoa De Fora', $corpo);
            self::assertStringNotContainsStringIgnoringCase('do outro escritório', $corpo);
        }
    }

    #[TestDox('Os status novos mantêm a guarda do módulo bi: sem permissão, 403')]
    public function testSemModuloBi(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $comum  = $this->criarColaborador($tenant, 'Sem BI');
        $this->logarComTenant($client, $comum, $tenant);

        foreach (['concluidas', 'em_revisao'] as $status) {
            $client->request('GET', '/tarefas/equipe', ['status' => $status]);
            self::assertResponseStatusCodeSame(403, $status);
        }
    }
}
