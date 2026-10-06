<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Lote 7 do Dashboard (F13 + F14), na TELA: selects próprios de Responsável (foto ou
 * iniciais + cargo) e Cargo ("N pessoas"), e o calendário próprio de De/Até.
 *
 * O contrato que este teste segura:
 *  - os controles NATIVOS do parcial compartilhado continuam no form, com os mesmos
 *    `name` (o filtro-tabela.js lê só eles; o dashboard-filtros.js escreve neles);
 *  - o componente próprio existe, no lugar certo (filho direto, `A > B`);
 *  - as opções do próprio são as MESMAS do nativo, com dado real (foto, cargo,
 *    contagem) e nada de outro escritório.
 *
 * A interação (abrir, teclado, escrever no nativo + `change`) é JS e não é coberta
 * pelo PHPUnit — fica para o smoke do dono.
 *
 * Nomes/cargos: compara sempre com o getter (o valor gravado pode ser normalizado
 * pela entidade), nunca com o literal passado ao setter.
 */
#[CoversClass(DashboardController::class)]
#[Group('dashboard')]
final class DashboardFiltrosPropriosTelaTest extends DashboardWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
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

    private static function iniciais(string $nome): string
    {
        $partes = array_values(array_filter(explode(' ', $nome), static fn (string $p): bool => $p !== ''));

        return mb_strtoupper(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice($partes, 0, 2))));
    }

    /**
     * Gestora (sem cargo), Ana e Bruno (mesmo cargo, Ana com foto), Carla (outro cargo).
     * Outro escritório: colaborador com cargo de mesmo nome — não pode entrar em nada.
     *
     * @return array{gestora: User, ana: User, bruno: User, carla: User, advogado: Cargo, estagio: Cargo, intruso: User}
     */
    private function montarCenario(KernelBrowser $client): array
    {
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $ana   = $this->criarColaborador($tenant, 'Ana Lima');
        $bruno = $this->criarColaborador($tenant, 'Bruno Melo');
        $carla = $this->criarColaborador($tenant, 'Carla Souza');

        $advogado = $this->criarCargo($tenant, 'Advogado');
        $estagio  = $this->criarCargo($tenant, 'Estagiário');
        $this->darCargo($ana, $tenant, $advogado);
        $this->darCargo($bruno, $tenant, $advogado);
        $this->darCargo($carla, $tenant, $estagio);
        $this->darFoto($ana, 'foto_ana_lote7.jpg');

        $outro   = $this->criarTenant();
        $intruso = $this->criarColaborador($outro, 'Intruso Externo');
        $this->darCargo($intruso, $outro, $this->criarCargo($outro, 'Advogado'));
        $this->darFoto($intruso, 'foto_intruso_lote7.jpg');

        return compact('gestora', 'ana', 'bruno', 'carla', 'advogado', 'estagio', 'intruso');
    }

    /** @return list<string> valores das <option> do select nativo, sem a vazia */
    private function valoresNativos(Crawler $crawler, string $name): array
    {
        return array_values(array_filter(
            $crawler->filter('form[data-filtro-form] select[name="' . $name . '"] > option')->each(
                static fn (Crawler $o): string => (string) $o->attr('value'),
            ),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /** @return list<string> valores das opções do select próprio, sem a "Todos" */
    private function valoresProprios(Crawler $crawler, string $name): array
    {
        return array_values(array_filter(
            $crawler->filter('.db-dd[data-db-dd="' . $name . '"] [role="listbox"] > [role="option"]')->each(
                static fn (Crawler $o): string => (string) $o->attr('data-valor'),
            ),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function opcaoPropria(Crawler $crawler, string $name, string $valor): Crawler
    {
        $op = $crawler->filter('.db-dd[data-db-dd="' . $name . '"] [role="listbox"] > [role="option"][data-valor="' . $valor . '"]');
        self::assertCount(1, $op, 'opção ' . $name . '=' . $valor);

        return $op;
    }

    // ── Nativos preservados ───────────────────────────────────────────────

    #[TestDox('Os controles nativos do parcial continuam no form, com os mesmos name e a classe do motor')]
    public function testNativosContinuamNoForm(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $form = '[data-filtro-root] > .db-filtro-wrap > form[data-filtro-form]';
        self::assertCount(1, $crawler->filter($form . ' select.js-filtro-campo[name="responsavel"]'));
        self::assertCount(1, $crawler->filter($form . ' select.js-filtro-campo[name="cargo"]'));
        self::assertCount(1, $crawler->filter($form . ' input.js-filtro-campo[type="date"][name="data_de"]'));
        self::assertCount(1, $crawler->filter($form . ' input.js-filtro-campo[type="date"][name="data_ate"]'));

        // O próprio NÃO é campo do form: nenhum name duplicado iria na query.
        self::assertCount(0, $crawler->filter('.db-fp [name]'), 'nada com name dentro do componente próprio');
        self::assertCount(1, $crawler->filter('[name="responsavel"]'));
        self::assertCount(1, $crawler->filter('[name="cargo"]'));
    }

    // ── Arranjo ───────────────────────────────────────────────────────────

    #[TestDox('O componente próprio vem logo depois do form, na caixa de filtros, sem JS escondido')]
    public function testComponenteProprioNoLugar(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $fp = $crawler->filter('[data-filtro-root] > .db-filtro-wrap > form[data-filtro-form] + .db-fp[data-db-filtros]');
        self::assertCount(1, $fp, 'irmão imediato do form do parcial');
        self::assertStringContainsString('db-fp--sem-js', (string) $fp->attr('class'), 'sem JS fica escondido e os nativos valem');

        // O segmentado continua imediatamente antes do form (contrato do Lote anterior).
        self::assertCount(1, $crawler->filter('[data-filtro-root] > .db-filtro-wrap > .db-seg + form[data-filtro-form]'));

        // Calendários: De e Até, cada um com botão e painel de diálogo.
        foreach (['data_de', 'data_ate'] as $name) {
            $cal = '.db-fp > .db-fp-datas > .db-cal[data-db-cal="' . $name . '"]';
            self::assertCount(1, $crawler->filter($cal . ' > button.db-cal-btn[type="button"][aria-haspopup="dialog"][aria-expanded="false"]'), $name);
            self::assertCount(1, $crawler->filter($cal . ' > .db-cal-painel[role="dialog"][hidden]'), $name);
        }

        // Selects: Responsável (com busca interna) e Cargo, listbox com options.
        foreach (['responsavel', 'cargo'] as $name) {
            $dd = '.db-fp > .db-dd[data-db-dd="' . $name . '"]';
            self::assertCount(1, $crawler->filter($dd . ' > button.db-dd-btn[type="button"][aria-haspopup="listbox"][aria-expanded="false"]'), $name);
            self::assertCount(1, $crawler->filter($dd . ' > .db-dd-painel[hidden] > [role="listbox"]'), $name);
            self::assertGreaterThan(0, $crawler->filter($dd . ' > .db-dd-painel > [role="listbox"] > [role="option"]')->count(), $name);
        }
        self::assertCount(1, $crawler->filter('.db-dd[data-db-dd="responsavel"] > .db-dd-painel > .db-dd-busca > input.db-dd-busca-input'));
        self::assertCount(0, $crawler->filter('.db-dd[data-db-dd="cargo"] .db-dd-busca'), 'cargo sem busca (desenho)');

        // aria-controls do botão aponta para a lista que existe.
        foreach (['responsavel', 'cargo'] as $name) {
            $id = $crawler->filter('.db-dd[data-db-dd="' . $name . '"] > .db-dd-btn')->attr('aria-controls');
            self::assertCount(1, $crawler->filter('[role="listbox"]#' . $id), $name);
        }

        // Script novo carregado depois do motor e do dashboard.js.
        $srcs = $crawler->filter('script[src]')->each(static fn (Crawler $s): string => (string) $s->attr('src'));
        $motor = $this->posicao($srcs, 'js/filtro-tabela.js');
        $proprio = $this->posicao($srcs, 'js/dashboard-filtros.js');
        self::assertGreaterThan($motor, $proprio, 'dashboard-filtros.js depois do filtro-tabela.js');
    }

    /** @param list<string> $srcs */
    private function posicao(array $srcs, string $trecho): int
    {
        foreach ($srcs as $i => $src) {
            if (str_contains($src, $trecho)) {
                return $i;
            }
        }
        self::fail('script não encontrado: ' . $trecho);
    }

    // ── Responsável ───────────────────────────────────────────────────────

    #[TestDox('Responsável: as opções próprias são as mesmas do nativo, com foto ou iniciais e o cargo; nada de outro escritório')]
    public function testOpcoesDeResponsavel(): void
    {
        $client = static::createClient();
        $c      = $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $nativos = $this->valoresNativos($crawler, 'responsavel');
        self::assertSame($nativos, $this->valoresProprios($crawler, 'responsavel'), 'mesmas opções, na mesma ordem');
        self::assertCount(4, $nativos, 'gestora + 3 colaboradores');
        self::assertNotContains((string) $c['intruso']->getId(), $nativos);

        // "Todos" primeiro, marcado, com a contagem de pessoas.
        $todos = $crawler->filter('.db-dd[data-db-dd="responsavel"] [role="listbox"] > [role="option"]')->first();
        self::assertSame('', $todos->attr('data-valor'));
        self::assertSame('true', $todos->attr('aria-selected'));
        self::assertSame('4 pessoas', trim($todos->filter('.db-dd-op-sub')->text()));

        // Ana: foto real (rota de foto) e cargo abaixo do nome.
        $ana = $this->opcaoPropria($crawler, 'responsavel', (string) $c['ana']->getId());
        self::assertSame($c['ana']->getFullName(), trim($ana->filter('.db-dd-op-nome')->text()));
        self::assertSame($c['advogado']->getNome(), trim($ana->filter('.db-dd-op-sub')->text()));
        $img = $ana->filter('.db-dd-av > img.db-dd-av-foto');
        self::assertCount(1, $img, 'com foto, a foto');
        self::assertStringContainsString('foto_ana_lote7.jpg', (string) $img->attr('src'));
        self::assertCount(1, $ana->filter('.db-dd-av > .db-dd-av-ini'), 'iniciais de reserva se a foto falhar');

        // Bruno: sem foto → boneco.
        $bruno = $this->opcaoPropria($crawler, 'responsavel', (string) $c['bruno']->getId());
        self::assertCount(0, $bruno->filter('img'));
        // Desenho (dc 1.2.2): sem foto, o boneco `bi-person-fill` na cor da tabela.
        self::assertCount(1, $bruno->filter('.db-dd-av > .db-dd-av-ini > i.bi-person-fill'));
        self::assertSame($c['advogado']->getNome(), trim($bruno->filter('.db-dd-op-sub')->text()));

        // Gestora: sem cargo → "Sem cargo".
        $gestora = $this->opcaoPropria($crawler, 'responsavel', (string) $c['gestora']->getId());
        self::assertSame('Sem cargo', trim($gestora->filter('.db-dd-op-sub')->text()));

        // A foto do outro escritório não aparece em lugar nenhum da tela.
        self::assertStringNotContainsString('foto_intruso_lote7.jpg', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($c['intruso']->getFullName(), $crawler->filter('.db-fp')->html());
    }

    #[TestDox('Com responsavel na URL, a opção fica marcada e o botão mostra o nome e o avatar dela')]
    public function testResponsavelSelecionado(): void
    {
        $client = static::createClient();
        $c      = $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?responsavel=' . $c['ana']->getId());

        self::assertResponseIsSuccessful();
        $marcadas = $crawler->filter('.db-dd[data-db-dd="responsavel"] [role="option"][aria-selected="true"]');
        self::assertCount(1, $marcadas);
        self::assertSame((string) $c['ana']->getId(), $marcadas->attr('data-valor'));

        $btn = $crawler->filter('.db-dd[data-db-dd="responsavel"] > .db-dd-btn');
        self::assertSame($c['ana']->getFullName(), trim($btn->filter('.db-dd-btn-nome')->text()));
        self::assertStringContainsString('foto_ana_lote7.jpg', (string) $btn->filter('.db-dd-av > img')->attr('src'));

        // o nativo segue com a mesma seleção
        self::assertSame((string) $c['ana']->getId(), $crawler->filter('select[name="responsavel"] > option[selected]')->attr('value'));
    }

    // ── Cargo ─────────────────────────────────────────────────────────────

    #[TestDox('Cargo: mesmas opções do nativo, com "N pessoas" contadas no escritório e "Sem cargo" por último')]
    public function testOpcoesDeCargo(): void
    {
        $client = static::createClient();
        $c      = $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $nativos = $this->valoresNativos($crawler, 'cargo');
        self::assertSame($nativos, $this->valoresProprios($crawler, 'cargo'), 'mesmas opções, na mesma ordem');
        self::assertSame('__sem__', end($nativos), '"Sem cargo" por último');

        // 2 no cargo da Ana e do Bruno — o homônimo do outro escritório não conta.
        $adv = $this->opcaoPropria($crawler, 'cargo', $c['advogado']->getNome());
        self::assertSame('2 pessoas', trim($adv->filter('.db-dd-op-sub')->text()));
        self::assertCount(1, $adv->filter('.db-dd-av > .db-dd-av-ico > i.bi-briefcase'), 'ícone do desenho para advogado');

        $est = $this->opcaoPropria($crawler, 'cargo', $c['estagio']->getNome());
        self::assertSame('1 pessoa', trim($est->filter('.db-dd-op-sub')->text()), 'singular');
        self::assertCount(1, $est->filter('i.bi-mortarboard'));

        $sem = $this->opcaoPropria($crawler, 'cargo', '__sem__');
        self::assertSame('Sem cargo', trim($sem->filter('.db-dd-op-nome')->text()));
        self::assertSame('1 pessoa', trim($sem->filter('.db-dd-op-sub')->text()), 'só a gestora');
        self::assertCount(1, $sem->filter('i.bi-dash-circle'));

        $todos = $crawler->filter('.db-dd[data-db-dd="cargo"] [role="listbox"] > [role="option"]')->first();
        self::assertSame('', $todos->attr('data-valor'));
        self::assertSame('Todos os cargos', trim($todos->filter('.db-dd-op-nome')->text()));
        self::assertSame('true', $todos->attr('aria-selected'));
    }

    #[TestDox('Sem colaborador sem cargo, o select próprio também não tem "Sem cargo"')]
    public function testSemOpcaoSemCargoQuandoTodosTemCargo(): void
    {
        $client             = static::createClient();
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        $cargo              = $this->criarCargo($tenant, 'Sócio');
        $this->darCargo($gestora, $tenant, $cargo);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame([$cargo->getNome()], $this->valoresProprios($crawler, 'cargo'));
        self::assertCount(0, $crawler->filter('.db-dd[data-db-dd="cargo"] [role="option"][data-valor="__sem__"]'));
        $op = $this->opcaoPropria($crawler, 'cargo', $cargo->getNome());
        self::assertCount(1, $op->filter('i.bi-award'), 'sócio → bi-award (desenho)');
    }

    #[TestDox('Com cargo=__sem__ na URL, "Sem cargo" fica marcado no próprio e no nativo')]
    public function testCargoSelecionado(): void
    {
        $client = static::createClient();
        $this->montarCenario($client);

        $crawler = $client->request('GET', '/dashboard?cargo=__sem__');

        self::assertResponseIsSuccessful();
        $marcadas = $crawler->filter('.db-dd[data-db-dd="cargo"] [role="option"][aria-selected="true"]');
        self::assertCount(1, $marcadas);
        self::assertSame('__sem__', $marcadas->attr('data-valor'));
        self::assertSame('Sem cargo', trim($crawler->filter('.db-dd[data-db-dd="cargo"] > .db-dd-btn .db-dd-btn-nome')->text()));
        self::assertNotNull($crawler->filter('select[name="cargo"] > option[value="__sem__"]')->attr('selected'));
    }

    // ── Calendário ────────────────────────────────────────────────────────

    #[TestDox('Calendário: o botão mostra a data dos filtros em dd/mm/aaaa, ou o marcador quando vazia')]
    public function testRotuloDasDatas(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard?data_de=2024-02-01');

        self::assertResponseIsSuccessful();
        $de = $crawler->filter('.db-cal[data-db-cal="data_de"] > .db-cal-btn');
        self::assertSame('01/02/2024', trim($de->filter('.db-cal-rotulo')->text()));
        self::assertStringNotContainsString('is-vazio', (string) $de->attr('class'));

        $ate = $crawler->filter('.db-cal[data-db-cal="data_ate"] > .db-cal-btn');
        self::assertSame('dd/mm/aaaa', trim($ate->filter('.db-cal-rotulo')->text()));
        self::assertStringContainsString('is-vazio', (string) $ate->attr('class'));

        // o nativo continua com o valor ISO que o motor manda na query
        self::assertSame('2024-02-01', $crawler->filter('input[name="data_de"]')->attr('value'));
        // "até" entre as duas datas, como no desenho
        self::assertCount(1, $crawler->filter('.db-fp-datas > .db-cal[data-db-cal="data_de"] + .db-fp-ate + .db-cal[data-db-cal="data_ate"]'));
    }

    // ── XHR ───────────────────────────────────────────────────────────────

    #[TestDox('O XHR do filtro não devolve os controles próprios: eles são da casca')]
    public function testXhrNaoTrazOsControles(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $client->xmlHttpRequest('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-db-filtros', $body);
        self::assertStringNotContainsString('db-dd-op', $body);
    }
}
