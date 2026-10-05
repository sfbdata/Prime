<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * ARRANJO das abas Processo, Metas, Detalhes e Push no desenho "02 - EXPEDIENTES
 * 1.2.3" (padrão PJe): cada uma é um cartão com o cabeçalho na faixa; o Processo
 * é uma linha do tempo ("Vinculado em…" + cartão por processo), as Metas são uma
 * lista sobre fundo cinza com a borda colorida pelo estado REAL da meta, Detalhes
 * ganha os fatos da pasta e o título "Relatório inicial de Atendimento", e o Push
 * o título "Movimentações recebidas".
 *
 * Os contratos do JS continuam: `#processoTabContent` (re-renderizado por XHR, por
 * isso o cartão inteiro mora no parcial), `.js-ajax-processo-principal`,
 * `.js-ajax-desvincular-processo`, `#modalCriarTarefa`, `#formDetalhesObservacao`,
 * `#detalhesObsLista`, `.ps-push-lista`. Nenhum `.card-header` novo (o clearfix do
 * AdminLTE vira um terceiro item no flex).
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaAbasEmCartaoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_A = '07011345720258070007';
    private const NUMERO_B = '07022222220258070007';

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function criarMeta(Pasta $pasta, User $user, string $status, ?string $prazo): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Meta ' . $status . ' ' . ($prazo ?? 'sem prazo'));
        $meta->setDescricao('...');
        if ($prazo !== null) {
            $meta->setPrazo(new \DateTimeImmutable($prazo));
        }
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($user);
        $meta->addResponsavel($user);
        $meta->setStatus($status);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        return $meta;
    }

    // =========================================================================
    // Processo
    // =========================================================================

    #[TestDox('Processo: cartão com cabeçalho e, por processo, a pílula "Vinculado em" seguida do cartão; só o não principal oferece "tornar principal"')]
    public function testProcessoEmLinhaDoTempo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_A));
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_B));

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $cartao = $crawler->filter('#processo > #processoTabContent > .ps-processos');
        self::assertCount(1, $cartao, 'o cartão inteiro mora dentro do #processoTabContent (o XHR o re-renderiza)');
        self::assertSame('Processos vinculados', trim($cartao->filter('.ps-processos > .ps-card-cab--painel > h2')->text()));
        self::assertSame('2', trim($cartao->filter('.ps-card-cab--painel > .ps-contagem')->text()));
        self::assertCount(1, $cartao->filter('.ps-card-cab--painel > a.ps-btn--caps[href*="peticionar"]'), 'Peticionar continua');
        self::assertCount(1, $cartao->filter('.ps-card-cab--painel > button[data-bs-target="#modalVincularProcesso"]'));

        self::assertCount(2, $cartao->filter('.ps-processos > .ps-registro > .ps-dia + article.ps-processo'), 'cada processo vem logo depois da sua pílula');
        self::assertStringContainsString('Vinculado em', $cartao->filter('.ps-registro > .ps-dia > .ps-dia-pilula')->first()->text());

        self::assertCount(1, $cartao->filter('article.ps-processo--principal .ps-processo-principal'), 'um só principal');
        self::assertCount(1, $cartao->filter('article.ps-processo:not(.ps-processo--principal) form.js-ajax-processo-principal'), 'só o outro oferece "tornar principal"');
        self::assertCount(2, $cartao->filter('article.ps-processo form.js-ajax-desvincular-processo'));
        self::assertCount(2, $cartao->filter('article.ps-processo .ps-processo-topo > a.ps-processo-numero'));
        self::assertCount(0, $crawler->filter('#processo .card-header'));
    }

    #[TestDox('Processo: sem processo o cartão mostra o estado vazio, sem linha do tempo')]
    public function testProcessoVazio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('#processoTabContent > .ps-processos > .ps-vazio'));
        self::assertCount(0, $crawler->filter('#processoTabContent .ps-registro'));
    }

    // =========================================================================
    // Metas
    // =========================================================================

    #[TestDox('Metas: lista em cartão; concluída, atrasada e aberta recebem o tom e o rótulo do estado REAL')]
    public function testMetasPorEstado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $aberta    = $this->criarMeta($pasta, $user, Tarefa::STATUS_PENDENTE, '+5 days');
        $concluida = $this->criarMeta($pasta, $user, Tarefa::STATUS_CONCLUIDA, '+1 day');
        $atrasada  = $this->criarMeta($pasta, $user, Tarefa::STATUS_PENDENTE, '-3 days');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $cartao = $crawler->filter('#tarefas > .ps-metas');
        self::assertCount(1, $cartao);
        self::assertSame('Metas da pasta', trim($cartao->filter('.ps-metas > .ps-card-cab--painel > h2')->text()));
        self::assertSame('3', trim($cartao->filter('.ps-card-cab--painel > .ps-contagem')->text()));
        self::assertCount(1, $cartao->filter('.ps-card-cab--painel > button[data-bs-target="#modalCriarTarefa"]'), '"Nova meta" abre a mesma modal de sempre');

        $linhas = $cartao->filter('.ps-metas > .ps-metas-lista > a.ps-meta');
        self::assertCount(3, $linhas);
        self::assertCount(0, $crawler->filter('#tarefas .tarefa-card'), 'o cartão antigo saiu');

        $abertaEl = $crawler->filter('a.ps-meta[href$="/' . $aberta->getId() . '"]');
        self::assertStringContainsString('ps-meta--aberta', (string) $abertaEl->attr('class'));
        self::assertSame('Pendente', trim($abertaEl->filter('.ps-meta-direita > .ps-meta-status')->text()));
        self::assertStringContainsString('vence em', $abertaEl->filter('.ps-meta-linha > .ps-meta-prazo')->text());

        $concluidaEl = $crawler->filter('a.ps-meta[href$="/' . $concluida->getId() . '"]');
        self::assertStringContainsString('ps-meta--concluida', (string) $concluidaEl->attr('class'));
        self::assertSame('Concluída', trim($concluidaEl->filter('.ps-meta-direita > .ps-meta-status')->text()));
        self::assertCount(1, $concluidaEl->filter('.ps-meta-titulo > i.bi-check-circle-fill'));

        $atrasadaEl = $crawler->filter('a.ps-meta[href$="/' . $atrasada->getId() . '"]');
        self::assertStringContainsString('ps-meta--atrasada', (string) $atrasadaEl->attr('class'));
        self::assertSame('Atrasada', trim($atrasadaEl->filter('.ps-meta-direita > .ps-meta-status')->text()));
        self::assertCount(1, $atrasadaEl->filter('.ps-meta-linha > .ps-meta-prazo--atraso'));
        self::assertStringContainsString('atrasado', $atrasadaEl->filter('.ps-meta-prazo--atraso')->text());
    }

    #[TestDox('Metas: sem meta, estado vazio dentro do cartão')]
    public function testMetasVazio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('#tarefas > .ps-metas > .ps-vazio'));
        self::assertCount(0, $crawler->filter('#tarefas .ps-metas-lista'));
    }

    // =========================================================================
    // Detalhes e Push
    // =========================================================================

    #[TestDox('Detalhes: os fatos da pasta numa faixa e o painel "Relatório inicial de Atendimento" com o compositor e a lista de sempre')]
    public function testDetalhes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(3, $crawler->filter('#detalhes > .ps-fatos > .ps-cab-dados > .ps-cab-dado'), 'Criado em · Modificado em · Criado por');
        $painel = $crawler->filter('#detalhes > .ps-detalhes-obs');
        self::assertCount(1, $painel);
        self::assertSame('Relatório inicial de Atendimento', trim($painel->filter('.ps-detalhes-obs > .ps-card-cab--painel > h2')->text()));
        self::assertCount(1, $painel->filter('.ps-detalhes-obs > .ps-compositor > #formDetalhesObservacao'));
        self::assertCount(1, $painel->filter('#detalhesObsLista'));
        self::assertCount(0, $crawler->filter('#detalhes .card-header'), 'nenhum .card-header novo (clearfix do AdminLTE)');
    }

    #[TestDox('Push: o painel se chama "Movimentações recebidas", na faixa do desenho')]
    public function testPush(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertSame('Movimentações recebidas', trim($crawler->filter('#push > .ps-push > .ps-card-cab--faixa > h2')->text()));
    }
}
