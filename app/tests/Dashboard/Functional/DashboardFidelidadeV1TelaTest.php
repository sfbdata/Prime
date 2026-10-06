<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Lote V1 da auditoria de fidelidade do Dashboard × dc 1.2.2, na TELA: Total
 * empilhado nas 7 colunas, Total metas pintado como ordem padrão, cabeçalho da
 * tabela mantido sem linhas, ícone de "Advogado sócio", boneco nos tons pastel do
 * select e "Busca:" no cabeçalho do PDF.
 *
 * Arranjo por filho direto (`A > B`), como o resto da pasta. Estilo (cor, tamanho)
 * segue invisível para o teste.
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardFidelidadeV1TelaTest extends DashboardWebTestCase
{
    private const PERIODO = 'data_de=2024-02-01&data_ate=2024-02-29';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function darCargo(User $user, Tenant $tenant, string $nome): void
    {
        $cargo = new Cargo();
        $cargo->setNome($nome);
        $cargo->setTenant($tenant);
        $this->em()->persist($cargo);
        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        $ut->setCargo($cargo);
        $this->em()->flush();
    }

    private function linhaDe(Crawler $crawler, string $nome): Crawler
    {
        $linha = $crawler->filter('.db-table-card table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'Linha de ' . $nome);

        return $linha;
    }

    private function cardDe(Crawler $crawler, string $nome): Crawler
    {
        $card = $crawler->filter('.db-cel-lista > .db-cel-card')->reduce(
            static fn (Crawler $c): bool => str_contains($c->text(), $nome),
        );
        self::assertCount(1, $card, 'Card de ' . $nome);

        return $card;
    }

    // ── Item 1: Total empilhado ───────────────────────────────────────────

    #[TestDox('Com período, as 7 células do Total empilham número + pílula; as 4 sem tendência levam o espaçador invisível')]
    public function testTotalEmpilhadoComPeriodo(): void
    {
        $client             = static::createClient();
        [, $tenant]         = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Lima');

        $crawler = $client->request('GET', '/dashboard?' . self::PERIODO);
        self::assertResponseIsSuccessful();

        // td do <tfoot>: [cargo, metas, ativas, vencidas, prazos, demandas, demandas ativas, criadas]
        $tds = $crawler->filter('.db-table-card table > tfoot > tr > td');
        self::assertCount(8, $tds);
        self::assertCount(7, $crawler->filter('.db-table-card table > tfoot > tr > td > .db-total-cel > .db-total-num'), 'número dentro do empilhado nas 7');

        foreach ([2, 3, 4, 6] as $i) {
            $esp = $tds->eq($i)->filter('td > .db-total-cel > .db-total-num + .db-total-espaco');
            self::assertCount(1, $esp, 'espaçador logo abaixo do número (coluna ' . $i . ')');
            self::assertSame('true', $esp->attr('aria-hidden'));
            self::assertCount(0, $tds->eq($i)->filter('.db-tend'), 'o espaçador não é pílula (coluna ' . $i . ')');
        }
        foreach ([1, 5, 7] as $i) {
            self::assertCount(1, $tds->eq($i)->filter('td > .db-total-cel > .db-total-num + .db-tend'), 'pílula de verdade (coluna ' . $i . ')');
            self::assertCount(0, $tds->eq($i)->filter('.db-total-espaco'));
        }
    }

    #[TestDox('Sem período não há pílula nem espaçador: nada para alinhar')]
    public function testTotalSemPeriodoSemEspacador(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Lima');

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        self::assertCount(7, $crawler->filter('.db-table-card table > tfoot > tr > td > .db-total-cel > .db-total-num'), 'a irmã prova que o Total montou');
        self::assertCount(0, $crawler->filter('.db-table-card table > tfoot .db-total-espaco'));
        self::assertCount(0, $crawler->filter('.db-table-card table > tfoot .db-tend'));
    }

    // ── Item 4: ordem padrão pintada ──────────────────────────────────────

    #[TestDox('Sem ordenar na URL, "Total metas" já vem pintado como decrescente (a ordem real do UseCase)')]
    public function testOrdemPadraoPintaTotalMetas(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('.db-table-card table > thead > tr > th.db-th-num.desc[data-ordenar="metas"]'));
        self::assertCount(1, $crawler->filter('.db-table-card table > thead > tr > th.asc, .db-table-card table > thead > tr > th.desc'), 'só uma coluna pintada');

        // coluna desconhecida cai no mesmo padrão; a direção pedida é ignorada
        $crawler = $client->request('GET', '/dashboard?ordenar=nao_existe&direcao=asc');
        self::assertCount(1, $crawler->filter('.db-table-card table > thead > tr > th.db-th-num.desc[data-ordenar="metas"]'));

        // ordem pedida válida continua mandando
        $crawler = $client->request('GET', '/dashboard?ordenar=cargo&direcao=asc');
        self::assertCount(1, $crawler->filter('.db-table-card table > thead > tr > th.db-th-cargo.asc[data-ordenar="cargo"]'));
        self::assertCount(0, $crawler->filter('.db-table-card table > thead > tr > th[data-ordenar="metas"].desc'));
    }

    // ── Item 7: vazio com cabeçalho ───────────────────────────────────────

    #[TestDox('Sem ninguém, a tabela mantém a linha de cabeçalhos e o aviso fica logo depois dela, fora do <tbody>')]
    public function testVazioMantemCabecalho(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Lima');

        $xhr = $client->xmlHttpRequest('GET', '/dashboard?busca=ninguem-com-esse-nome');
        self::assertResponseIsSuccessful();

        self::assertCount(9, $xhr->filter('.db-table-card .db-table-scroll > table > thead > tr > th'), 'os 9 cabeçalhos seguem');
        self::assertCount(0, $xhr->filter('.db-table-card table > tbody > tr'), 'ninguém contado como gente');
        self::assertCount(0, $xhr->filter('.db-table-card table > tfoot'), 'sem linhas, sem Total');
        self::assertCount(1, $xhr->filter('.db-table-card .db-table-scroll > table + .db-empty'), 'aviso logo depois da tabela');
        self::assertCount(1, $xhr->filter('.db-table-card .db-empty'), 'um só aviso');

        // irmã: com gente, nenhum aviso
        $xhr = $client->xmlHttpRequest('GET', '/dashboard?busca=ana');
        self::assertCount(1, $xhr->filter('.db-table-card table > tbody > tr'));
        self::assertCount(0, $xhr->filter('.db-table-card .db-empty'));
    }

    // ── Item 5: ícone de "Advogado sócio" ─────────────────────────────────

    #[TestDox('"Advogado sócio" ganha bi-award na tabela, no card do celular e no select; "Advogado" segue bi-briefcase')]
    public function testSocioGanhaAward(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $carla      = $this->criarColaborador($tenant, 'Carla Sócia');
        $davi       = $this->criarColaborador($tenant, 'Davi Advogado');
        $this->darCargo($carla, $tenant, 'Advogado sócio');
        $this->darCargo($davi, $tenant, 'Advogado');

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $carlaLinha = $this->linhaDe($crawler, 'Carla Sócia');
        self::assertCount(1, $carlaLinha->filter('td.db-td-cargo > .db-cargo > i.bi-award'));
        self::assertCount(0, $carlaLinha->filter('i.bi-briefcase'));
        self::assertCount(1, $this->linhaDe($crawler, 'Davi Advogado')->filter('td.db-td-cargo > .db-cargo > i.bi-briefcase'));

        self::assertCount(1, $this->cardDe($crawler, 'Carla Sócia')->filter('.db-cel-cargo > i.bi-award'));
        self::assertCount(1, $this->cardDe($crawler, 'Davi Advogado')->filter('.db-cel-cargo > i.bi-briefcase'));

        $op = $crawler->filter('.db-dd[data-db-dd="cargo"] [role="option"][data-valor="Advogado sócio"]');
        self::assertCount(1, $op);
        self::assertCount(1, $op->filter('.db-dd-av > .db-dd-av-ico > i.bi-award'), 'mesmo ícone do select');
    }

    // ── Item 2: boneco no tom pastel ──────────────────────────────────────

    #[TestDox('Pessoa sem foto no select: boneco num dos 6 tons pastel, (id - 1) % 6, e não o degradê da tabela')]
    public function testBonecoNoTomPastel(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $bruno      = $this->criarColaborador($tenant, 'Bruno Melo');
        $tom        = ((int) $bruno->getId() - 1) % 6;

        $crawler = $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        $op = $crawler->filter('.db-dd[data-db-dd="responsavel"] [role="option"][data-valor="' . $bruno->getId() . '"]');
        self::assertCount(1, $op);
        self::assertCount(1, $op->filter('.db-dd-av > .db-dd-av-ini.db-dd-tom--p' . $tom . ' > i.bi-person-fill'));
        self::assertCount(0, $crawler->filter('.db-dd .db-dd-av-ini[class*="db-avatar--"]'), 'a cor da tabela não vale no select');

        // selecionado, o botão mostra o mesmo tom
        $crawler = $client->request('GET', '/dashboard?responsavel=' . $bruno->getId());
        self::assertCount(1, $crawler->filter('.db-dd[data-db-dd="responsavel"] > .db-dd-btn > .db-dd-av > .db-dd-av-ini.db-dd-tom--p' . $tom . ' > i.bi-person-fill'));
    }

    // ── Item 6: busca no cabeçalho do PDF ─────────────────────────────────

    #[TestDox('Com busca, o cabeçalho do PDF acrescenta "· Busca: <texto>"; sem busca, não')]
    public function testBuscaNoCabecalhoDoPdf(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $this->criarColaborador($tenant, 'Ana Lima');

        $crawler = $client->request('GET', '/dashboard?busca=ana');
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Todos os responsáveis · Todos os cargos · Busca: ana',
            trim($crawler->filter('.db-print-cabecalho .db-print-filtros > .db-print-facetas')->text()),
        );

        $crawler = $client->request('GET', '/dashboard?busca=');
        self::assertSame(
            'Todos os responsáveis · Todos os cargos',
            trim($crawler->filter('.db-print-cabecalho .db-print-filtros > .db-print-facetas')->text()),
        );
    }
}
