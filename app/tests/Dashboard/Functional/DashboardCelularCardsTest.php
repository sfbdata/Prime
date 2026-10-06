<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tarefa\TarefaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Lote 6 do Dashboard (F30): abaixo de 768px a tabela "Desempenho" vira lista de
 * cards por pessoa. O teste lê HTML, não largura: prova que a lista existe no
 * lugar certo (filho direto do card da tabela), que tem um card por linha da
 * tabela, na mesma ordem, com os MESMOS links e as mesmas tendências, e que o
 * select "Ordenar" oferece as colunas ordenáveis da tabela.
 *
 * A tabela continua no DOM (contrato dos outros testes e do filtro-tabela.js):
 * quem esconde uma ou outra é o CSS.
 *
 * Nomes: compare com o getter, nunca com o literal (setters podem normalizar).
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardCelularCardsTest extends DashboardWebTestCase
{
    use Factories;

    private const PERIODO = 'data_de=2024-02-01&data_ate=2024-02-29';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function pastaHospedeira(Tenant $tenant): Pasta
    {
        return PastaFactory::createOne([
            'tenant'       => $tenant,
            'dataAbertura' => new \DateTimeImmutable('2023-06-01 10:00'),
        ])->_real();
    }

    private function criarMeta(Pasta $pasta, User $responsavel, string $criadaEm, ?\DateTimeImmutable $prazo = null): void
    {
        $tarefa = TarefaFactory::createOne([
            'pasta'  => $pasta,
            'status' => Tarefa::STATUS_PENDENTE,
            'prazo'  => $prazo,
        ])->_real();
        $tarefa->addResponsavel($responsavel);
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($tarefa, new \DateTimeImmutable($criadaEm));
        $this->em()->flush();
    }

    /**
     * Ana: 3 metas em fev (1 vencida, 1 com prazo próximo) × 2 em jan; 2 pastas em fev × 3 em jan.
     * Bruno: 1 meta em fev. Outro escritório: colaboradora "Zélia Outro Escritório" com meta e pasta.
     *
     * @return array{0: User, 1: User, 2: User, 3: User} gestora, Ana, Bruno, a de fora
     */
    private function montarCenario(KernelBrowser $client): array
    {
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana   = $this->criarColaborador($tenant, 'Ana Lima');
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');

        PastaFactory::createMany(2, ['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-02-10 10:00')]);
        PastaFactory::createMany(3, ['tenant' => $tenant, 'criadoPor' => $ana, 'responsavel' => $ana, 'dataAbertura' => new \DateTimeImmutable('2024-01-20 10:00')]);

        $hosp = $this->pastaHospedeira($tenant);
        $this->criarMeta($hosp, $ana, '2024-02-05 09:00', new \DateTimeImmutable('2024-02-20 18:00'));
        $this->criarMeta($hosp, $ana, '2024-02-06 09:00', new \DateTimeImmutable('+3 days'));
        $this->criarMeta($hosp, $ana, '2024-02-07 09:00');
        $this->criarMeta($hosp, $ana, '2024-01-15 09:00');
        $this->criarMeta($hosp, $ana, '2024-01-16 09:00');
        $this->criarMeta($hosp, $bruno, '2024-02-08 09:00');

        $outro = $this->criarTenant();
        $fora  = $this->criarColaborador($outro, 'Zélia Outro Escritório');
        $this->criarMeta($this->pastaHospedeira($outro), $fora, '2024-02-09 09:00', new \DateTimeImmutable('2024-02-15 18:00'));
        PastaFactory::createMany(2, ['tenant' => $outro, 'criadoPor' => $fora, 'responsavel' => $fora, 'dataAbertura' => new \DateTimeImmutable('2024-02-10 10:00')]);

        return [$gestora, $ana, $bruno, $fora];
    }

    private function cardsDePessoa(Crawler $crawler): Crawler
    {
        return $crawler->filter('.db-table-card > .db-cel-lista > .db-cel-card:not(.db-cel-card--total)');
    }

    private function linhasDaTabela(Crawler $crawler): Crawler
    {
        return $crawler->filter('.db-table-card table > tbody > tr');
    }

    /** @return list<string> hrefs dos números-link, na ordem em que aparecem */
    private function hrefs(Crawler $escopo, string $seletor): array
    {
        return $escopo->filter($seletor)->each(static fn (Crawler $a): string => (string) $a->attr('href'));
    }

    // ── Arranjo ───────────────────────────────────────────────────────────

    #[TestDox('A lista de cards é filha direta do card "Desempenho", não é <table>, e a tabela continua no DOM')]
    public function testListaExisteAoLadoDaTabela(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        $lista = $crawler->filter('.db-table-card > .db-cel-lista');
        self::assertCount(1, $lista, 'A lista é filha direta do card da tabela');
        self::assertCount(0, $lista->filter('table'), 'A lista não pode ser <table>: os testes contam table > tbody > tr');
        self::assertCount(1, $crawler->filter('.db-table-card > .db-table-scroll > table.db-table'), 'A tabela continua no DOM');
        // ordem: cabeçalho → lista → tabela
        self::assertSame('db-cel-lista', $crawler->filter('.db-table-card > .db-table-header + .db-cel-lista')->attr('class'));
        self::assertCount(1, $crawler->filter('.db-table-card > .db-cel-lista + .db-table-scroll'));
    }

    #[TestDox('Um card por linha da tabela, na mesma ordem, e só gente do escritório')]
    public function testUmCardPorLinha(): void
    {
        $client = static::createClient();
        [$gestora, $ana, $bruno, $fora] = $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        $linhas = $this->linhasDaTabela($crawler);
        $cards  = $this->cardsDePessoa($crawler);
        self::assertCount(3, $linhas);
        self::assertCount(3, $cards, 'Um card por linha do tbody');

        $nomesTabela = $linhas->each(static fn (Crawler $tr): string => (string) $tr->filter('.db-colab-nome')->attr('title'));
        $nomesCards  = $cards->each(static fn (Crawler $c): string => trim($c->filter('.db-cel-card > .db-cel-cab > .db-cel-ident > .db-cel-nome')->text()));
        self::assertSame($nomesTabela, $nomesCards, 'Mesma ordem da tabela');

        $esperados = [$gestora->getFullName(), $ana->getFullName(), $bruno->getFullName()];
        sort($esperados);
        $vistos = $nomesCards;
        sort($vistos);
        self::assertSame($esperados, $vistos);

        // isolamento: a colaboradora do outro escritório não vira card
        self::assertStringNotContainsString(
            (string) $fora->getFullName(),
            $crawler->filter('.db-table-card > .db-cel-lista')->text(),
        );

        // card de Total, um só, depois das pessoas
        self::assertCount(1, $crawler->filter('.db-table-card > .db-cel-lista > .db-cel-card.db-cel-card--total'));
        self::assertCount(1, $crawler->filter('.db-cel-lista > .db-cel-card:not(.db-cel-card--total) + .db-cel-card--total'));
    }

    #[TestDox('Cada card tem os 7 blocos da tabela: 4 de metas e 3 de demandas/pastas')]
    public function testSeteBlocosPorCard(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        foreach ($this->cardsDePessoa($crawler) as $no) {
            $card = new Crawler($no);
            self::assertCount(4, $card->filter('.db-cel-card > .db-cel-grade > .db-cel-bloco.db-cel-bloco--meta'));
            self::assertCount(3, $card->filter('.db-cel-card > .db-cel-grade > .db-cel-bloco.db-cel-bloco--pasta'));
        }

        $rotulos = $this->cardsDePessoa($crawler)->first()
            ->filter('.db-cel-grade > .db-cel-bloco > .db-cel-rotulo')
            ->each(static fn (Crawler $r): string => trim($r->text()));
        self::assertSame(['Total metas', 'Metas ativas', 'Vencidas', 'Prazos próximos', 'Total demandas', 'Demandas ativas', 'Pastas criadas'], $rotulos);
    }

    // ── Números e links ───────────────────────────────────────────────────

    #[TestDox('Os números do card levam os MESMOS hrefs da linha correspondente da tabela')]
    public function testMesmosLinksDaTabela(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        foreach (['/dashboard?' . self::PERIODO, '/dashboard'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();

            $linhas = $this->linhasDaTabela($crawler);
            $cards  = $this->cardsDePessoa($crawler);
            self::assertSame($linhas->count(), $cards->count());

            $algumLink = false;
            for ($i = 0; $i < $linhas->count(); ++$i) {
                $daTabela = $this->hrefs($linhas->eq($i), 'a.db-num-link');
                $doCard   = $this->hrefs($cards->eq($i), '.db-cel-bloco > .db-cel-valor > a.db-num-link');
                self::assertSame($daTabela, $doCard, $url . ' · linha ' . $i);
                $algumLink = $algumLink || $daTabela !== [];

                // zeros: mesma quantidade, e sem link
                self::assertSame(
                    $linhas->eq($i)->filter('.db-num--zero')->count(),
                    $cards->eq($i)->filter('.db-cel-valor > .db-num--zero')->count(),
                    $url . ' · zeros da linha ' . $i,
                );
            }
            self::assertTrue($algumLink, 'O cenário precisa ter número > 0 para o teste provar algo');
        }
    }

    #[TestDox('Números por pessoa no card: metas abrem /tarefas/equipe e pastas abrem o Acervo geral')]
    public function testDestinosDosNumerosDoCard(): void
    {
        $client = static::createClient();
        [, $ana] = $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        $card = $this->cardsDePessoa($crawler)->reduce(
            static fn (Crawler $c): bool => trim($c->filter('.db-cel-nome')->text()) === $ana->getFullName(),
        );
        self::assertCount(1, $card);

        $blocos = $card->filter('.db-cel-grade > .db-cel-bloco');
        $total  = $blocos->eq(0)->filter('.db-cel-valor > a.db-num.db-num-link');
        self::assertSame('3', trim($total->text()));
        self::assertStringStartsWith('/tarefas/equipe?', (string) $total->attr('href'));

        self::assertCount(1, $blocos->eq(2)->filter('.db-cel-valor > a.db-pill.db-pill--venc.db-num-link'), 'Vencidas em pílula');
        self::assertCount(1, $blocos->eq(3)->filter('.db-cel-valor > a.db-pill.db-pill--praz.db-num-link'), 'Prazos em pílula');

        $criadas = $blocos->eq(6)->filter('.db-cel-valor > a.db-num.db-num-link');
        self::assertSame('2', trim($criadas->text()));
        self::assertStringStartsWith('/expediente?', (string) $criadas->attr('href'));
        self::assertStringContainsString('criado_por=' . (string) $ana->getId(), (string) $criadas->attr('href'));
    }

    #[TestDox('Tendência no card: setas iguais às da linha e pílulas no card de Total, sem link no Total')]
    public function testTendenciaNosCards(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        $linhas = $this->linhasDaTabela($crawler);
        $cards  = $this->cardsDePessoa($crawler);
        for ($i = 0; $i < $linhas->count(); ++$i) {
            self::assertSame(
                $linhas->eq($i)->filter('.db-seta')->each(static fn (Crawler $s): string => (string) $s->attr('class')),
                $cards->eq($i)->filter('.db-cel-valor > .db-seta')->each(static fn (Crawler $s): string => (string) $s->attr('class')),
                'Setas da linha ' . $i,
            );
        }

        $total = $crawler->filter('.db-cel-lista > .db-cel-card--total');
        $pilulasCard   = $total->filter('.db-cel-bloco > .db-cel-valor--total > .db-tend')->each(static fn (Crawler $p): string => trim($p->text()) . '|' . $p->attr('title'));
        $pilulasTabela = $crawler->filter('.db-table-card table > tfoot .db-tend')->each(static fn (Crawler $p): string => trim($p->text()) . '|' . $p->attr('title'));
        self::assertCount(3, $pilulasCard);
        self::assertSame($pilulasTabela, $pilulasCard);

        $numsCard   = $total->filter('.db-cel-valor--total > .db-total-num')->each(static fn (Crawler $n): string => trim($n->text()));
        $numsTabela = $crawler->filter('.db-table-card table > tfoot .db-total-num')->each(static fn (Crawler $n): string => trim($n->text()));
        self::assertSame($numsTabela, $numsCard, 'Mesmas somas do Total da tabela');
        self::assertCount(0, $total->filter('a'), 'O Total não é link');
    }

    #[TestDox('Sem período, nenhum card desenha tendência')]
    public function testSemPeriodoSemTendencia(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.db-cel-lista .db-tend'));
        self::assertCount(0, $crawler->filter('.db-cel-lista .db-seta'));
    }

    // ── Ordenação ─────────────────────────────────────────────────────────

    #[TestDox('Select "Ordenar" com as 9 colunas ordenáveis da tabela, sem name, padrão Total metas decrescente')]
    public function testSelectDeOrdenacao(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $sel = $crawler->filter('.db-table-card > .db-cel-lista > .db-cel-ordem > .db-cel-ordem-campo > select.js-filtro-ordenar');
        self::assertCount(1, $sel);
        self::assertNull($sel->attr('name'), 'Fora da query: quem vai no XHR são os hidden ordenar/direcao');
        self::assertCount(0, $crawler->filter('form[data-filtro-form] .db-cel-ordem'), 'Fora do form');
        self::assertSame('Ordenar', trim($crawler->filter('.db-cel-ordem > label.db-cel-ordem-rotulo')->text()));
        self::assertSame($sel->attr('id'), $crawler->filter('.db-cel-ordem > label.db-cel-ordem-rotulo')->attr('for'));

        $opcoes  = $sel->filter('select > option');
        $colunas = $opcoes->each(static fn (Crawler $o): string => explode('|', (string) $o->attr('value'))[0]);
        $doTh    = $crawler->filter('.db-table-card table > thead th[data-ordenar]')->each(static fn (Crawler $th): string => (string) $th->attr('data-ordenar'));
        self::assertCount(9, $opcoes);
        self::assertSame($doTh, $colunas, 'As mesmas colunas, na ordem da tabela');
        self::assertSame(
            ['Colaborador', 'Cargo', 'Total metas', 'Metas ativas', 'Vencidas', 'Prazos próximos', 'Total demandas', 'Demandas ativas', 'Pastas criadas'],
            $opcoes->each(static fn (Crawler $o): string => trim($o->text())),
        );
        // direção ao escolher: texto sobe, número desce
        self::assertSame('advogado|asc', $opcoes->eq(0)->attr('value'));
        self::assertSame('cargo|asc', $opcoes->eq(1)->attr('value'));
        self::assertSame('pastas_criadas|desc', $opcoes->eq(8)->attr('value'));

        // sem ordenar: o UseCase ordena por Total metas decrescente
        $escolhida = $sel->filter('option[selected]');
        self::assertCount(1, $escolhida);
        self::assertSame('metas|desc', $escolhida->attr('value'));

        $inv = $crawler->filter('.db-cel-lista > .db-cel-ordem > button.db-cel-ordem-inverter.js-db-ordem-inverter');
        self::assertCount(1, $inv);
        self::assertSame('button', $inv->attr('type'));
        self::assertSame('metas|asc', $inv->attr('data-ordem'));
        self::assertSame('Ordem decrescente', $inv->attr('aria-label'));
        self::assertCount(1, $inv->filter('i.bi-sort-down'));
    }

    #[TestDox('Ordenação pedida reflete no select, no botão de inverter e na ordem dos cards (XHR)')]
    public function testOrdenacaoPedidaNoXhr(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $xhr = $client->xmlHttpRequest('GET', '/dashboard?ordenar=advogado&direcao=asc');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $xhr->filter('.db-table-card > .db-cel-lista'), 'O fragmento do XHR traz a lista');
        self::assertSame('advogado|asc', $xhr->filter('select.js-filtro-ordenar > option[selected]')->attr('value'));
        $inv = $xhr->filter('.js-db-ordem-inverter');
        self::assertSame('advogado|desc', $inv->attr('data-ordem'));
        self::assertSame('Ordem crescente', $inv->attr('aria-label'));
        self::assertCount(1, $inv->filter('i.bi-sort-up'));

        $nomes = $this->cardsDePessoa($xhr)->each(static fn (Crawler $c): string => trim($c->filter('.db-cel-nome')->text()));
        $tabela = $this->linhasDaTabela($xhr)->each(static fn (Crawler $tr): string => (string) $tr->filter('.db-colab-nome')->attr('title'));
        self::assertSame($tabela, $nomes);
        self::assertCount(3, $nomes);
    }

    #[TestDox('Coluna desconhecida cai no padrão do UseCase (Total metas decrescente)')]
    public function testOrdenacaoDesconhecida(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?ordenar=nao_existe&direcao=asc');
        self::assertResponseIsSuccessful();
        self::assertSame('metas|desc', $crawler->filter('select.js-filtro-ordenar > option[selected]')->attr('value'));
        self::assertSame('metas|asc', $crawler->filter('.js-db-ordem-inverter')->attr('data-ordem'));
    }

    // ── Vazio ─────────────────────────────────────────────────────────────

    #[TestDox('Sem ninguém: nenhum card (nem Total), um só aviso de vazio, e o select continua')]
    public function testVazio(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $xhr = $client->xmlHttpRequest('GET', '/dashboard?busca=ninguem-com-esse-nome');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $xhr->filter('.db-cel-lista > .db-cel-card'));
        self::assertCount(1, $xhr->filter('.db-table-card .db-empty'));
        self::assertCount(1, $xhr->filter('.db-cel-lista > .db-cel-ordem select.js-filtro-ordenar'));
    }
}
