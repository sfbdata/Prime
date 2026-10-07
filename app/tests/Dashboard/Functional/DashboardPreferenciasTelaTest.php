<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Tests\Factory\Pasta\PastaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Zenstruck\Foundry\Test\Factories;

/**
 * O estilo pessoal na TELA do Dashboard: as classes saem do servidor no `.db-page` (sem esperar
 * JS), o menu ⋮ nasce no estado gravado, e a coluna oculta continua no HTML — some por classe,
 * nunca por omissão, para o fragmento do XHR e a ordenação seguirem os mesmos.
 *
 * Teste de PHPUnit lê HTML, não posição nem estilo: o que dá para travar é a classe certa no nó
 * certo (com filho direto), a marca `data-coluna` nas células e a regra de CSS que esconde cada
 * coluna do catálogo. Que a coluna de fato desaparece na tela é smoke do dono.
 */
#[CoversClass(DashboardController::class)]
final class DashboardPreferenciasTelaTest extends DashboardWebTestCase
{
    use Factories;

    private function repo(): PreferenciaDoUsuarioRepository
    {
        return static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    #[TestDox('Sem preferência gravada o .db-page não leva classe de estilo (a tela de antes do menu)')]
    public function testPadraoSemClasse(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
    }

    #[TestDox('O estilo gravado vira classe no .db-page já no HTML do servidor')]
    public function testClassesAplicadasNoServidor(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.densidade', 'confortavel');
        $this->repo()->gravar($tenant, $user, 'dashboard.animacoes', 'reduzidas');
        $this->repo()->gravar($tenant, $user, 'dashboard.setas', 'desligadas');
        $this->repo()->gravar($tenant, $user, 'dashboard.colunas_ocultas', ['cargo']);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(
            1,
            $crawler->filter('section.db-page.db-page--confortavel.db-page--sem-anim.db-page--sem-setas.db-oculta--cargo')->count(),
        );
        $estado = json_decode((string) $crawler->filter('section.db-page')->attr('data-preferencias'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['cargo'], $estado['dashboard.colunas_ocultas']);
        self::assertSame('/dashboard/preferencias', $crawler->filter('section.db-page')->attr('data-preferencias-endpoint'));
    }

    #[TestDox('A coluna oculta CONTINUA no HTML (cabeçalho, linha, Total e card do celular), marcada para o CSS esconder')]
    public function testColunaOcultaFicaNoHtmlMarcada(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.colunas_ocultas', ['cargo', 'pastas_criadas']);
        // uma pasta aberta pelo gestor garante a linha dele e a de Total
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $user]);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $raiz = 'section.db-page.db-oculta--cargo.db-oculta--pastas_criadas [data-filtro-resultado] > .db-table-card';
        self::assertSame(1, $crawler->filter($raiz . ' table.db-table > thead > tr > th[data-coluna="cargo"][data-ordenar="cargo"]')->count());
        self::assertSame(1, $crawler->filter($raiz . ' table.db-table > thead > tr > th[data-coluna="pastas_criadas"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter($raiz . ' table.db-table > tbody > tr > td[data-coluna="cargo"]')->count());
        self::assertSame(1, $crawler->filter($raiz . ' table.db-table > tfoot > tr > td[data-coluna="pastas_criadas"]')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter($raiz . ' .db-cel-grade > .db-cel-bloco[data-coluna="pastas_criadas"]')->count());
    }

    #[TestDox('Cada uma das 9 colunas mantém a mesma posição; só Colaborador fica sem data-coluna (é fixa)')]
    public function testOrdemDasColunasMarcadas(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        PastaFactory::createOne(['tenant' => $tenant, 'criadoPor' => $user]);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $cab = $crawler->filter('.db-table > thead > tr > th')->each(static fn ($th): ?string => $th->attr('data-coluna'));
        self::assertSame(array_merge([null], CatalogoDePreferenciasDoDashboard::COLUNAS_OCULTAVEIS), $cab);
        $linha = $crawler->filter('.db-table > tbody > tr')->first()->filter('td')->each(static fn ($c): ?string => $c->attr('data-coluna'));
        self::assertSame(array_merge([null], CatalogoDePreferenciasDoDashboard::COLUNAS_OCULTAVEIS), $linha);
        $total = $crawler->filter('.db-table > tfoot > tr > *')->each(static fn ($c): ?string => $c->attr('data-coluna'));
        self::assertSame(array_merge([null], CatalogoDePreferenciasDoDashboard::COLUNAS_OCULTAVEIS), $total);
    }

    #[TestDox('O XHR do filtro devolve as mesmas colunas com a preferência gravada (oculta-se por classe, não por omissão)')]
    public function testXhrNaoOmiteColuna(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.colunas_ocultas', ['cargo']);

        $crawler = $client->xmlHttpRequest('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(9, $crawler->filter('.db-table > thead > tr > th')->count());
        self::assertSame(1, $crawler->filter('.db-table > thead > tr > th[data-coluna="cargo"]')->count());
    }

    #[TestDox('O CSS tem a regra que esconde cada coluna do catálogo pela classe do .db-page')]
    public function testCssEscondeCadaColuna(): void
    {
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/dashboard.css');

        foreach (CatalogoDePreferenciasDoDashboard::COLUNAS_OCULTAVEIS as $coluna) {
            self::assertStringContainsString(
                sprintf('.db-page.db-oculta--%1$s [data-coluna="%1$s"]', $coluna),
                $css,
                sprintf('Falta a regra que esconde a coluna "%s"', $coluna),
            );
        }
        self::assertStringContainsString('.db-page.db-page--confortavel .db-table tbody td', $css);
        self::assertStringContainsString('.db-page.db-page--sem-anim *', $css);
        self::assertStringContainsString('.db-page.db-page--sem-setas .db-seta', $css);
    }

    #[TestDox('O menu ⋮ nasce no .db-page, FORA do fragmento que o XHR troca, e no estado gravado')]
    public function testMenuNoEstadoGravado(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.densidade', 'confortavel');
        $this->repo()->gravar($tenant, $user, 'dashboard.setas', 'desligadas');
        $this->repo()->gravar($tenant, $user, 'dashboard.colunas_ocultas', ['cargo']);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('section.db-page > .db-pref[data-db-pref] > button.db-pref-btn[aria-haspopup="menu"]')->count());
        self::assertSame(0, $crawler->filter('[data-filtro-resultado] [data-db-pref]')->count(), 'o menu não pode morar no fragmento trocado pelo XHR');

        $menu = 'section.db-page > .db-pref > .db-pref-menu[role="menu"]';
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-valor="confortavel"][aria-checked="true"]')->count());
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-valor="compacta"][aria-checked="false"]')->count());
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-chave="dashboard.setas"][aria-checked="false"]')->count());
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-chave="dashboard.animacoes"][aria-checked="true"]')->count());
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-coluna="cargo"][aria-checked="false"]')->count());
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-coluna="metas"][aria-checked="true"]')->count());
        self::assertSame(8, $crawler->filter($menu . ' [data-pref-coluna]')->count(), 'Cargo + 7 numéricas; Colaborador é fixa');
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-mostrar-todas]:not([hidden])')->count(), '"Mostrar todas" aparece quando há coluna oculta');
        self::assertSame(1, $crawler->filter($menu . ' [data-pref-restaurar]')->count());
        self::assertStringContainsString((string) $user->getFullName(), $crawler->filter($menu . ' .db-pref-faixa')->text());
    }

    #[TestDox('O menu não oferece o que o sistema não tem: zerar e "Pastas concluídas" (sem lastro)')]
    public function testMenuSemOQueNaoExiste(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');
        $texto   = $crawler->filter('section.db-page > .db-pref')->text();

        self::assertStringNotContainsString('Zerar', $texto);
        self::assertStringNotContainsString('Pastas concluídas', $texto);
        self::assertStringNotContainsString('Relatório', $texto);
        self::assertSame(1, $crawler->filter('section.db-page > .db-pref [data-pref-mostrar-todas][hidden]')->count(), 'sem coluna oculta, "Mostrar todas" fica escondido');
    }

    #[TestDox('Sons: no "Meu estilo", entre Densidade e Animações, ligado por padrão e sem classe no .db-page')]
    public function testSonsNoMeuEstiloLigadoPorPadrao(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $menu = 'section.db-page > .db-pref > .db-pref-menu[role="menu"]';
        // filho direto do menu, logo depois da linha de Densidade e logo antes de Animações (dc L646-664)
        self::assertSame(1, $crawler->filter($menu . ' > .db-pref-linha + button.db-pref-item[data-pref-chave="dashboard.sons"] + button.db-pref-item[data-pref-chave="dashboard.animacoes"]')->count());
        $sons = $crawler->filter($menu . ' > button[data-pref-chave="dashboard.sons"]');
        self::assertSame('menuitemcheckbox', $sons->attr('role'));
        self::assertSame('true', $sons->attr('aria-checked'));
        self::assertSame('ligados', $sons->attr('data-pref-liga'));
        self::assertSame('desligados', $sons->attr('data-pref-desliga'));
        self::assertSame(1, $sons->filter('button > i.bi-volume-up')->count());
        self::assertSame('Sons', trim($sons->filter('button > .db-pref-rotulo')->text()));
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
    }

    #[TestDox('Sons desligados gravados: o .db-page nasce com db-page--sem-som e o interruptor desligado')]
    public function testSonsDesligadosGravados(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.sons', 'desligados');

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('db-page db-page--sem-som', $crawler->filter('section.db-page')->attr('class'));
        self::assertSame(1, $crawler->filter('section.db-page > .db-pref > .db-pref-menu > button[data-pref-chave="dashboard.sons"][aria-checked="false"]')->count());
        $estado = json_decode((string) $crawler->filter('section.db-page')->attr('data-preferencias'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('desligados', $estado['dashboard.sons']);
    }

    #[TestDox('O som do calendário obedece à classe db-page--sem-som, a mesma que o menu liga (contrato entre os dois JS)')]
    public function testCalendarioLeAClasseDoSom(): void
    {
        $dir     = static::getContainer()->getParameter('kernel.project_dir') . '/public/js/';
        $filtros = (string) file_get_contents($dir . 'dashboard-filtros.js');
        $prefs   = (string) file_get_contents($dir . 'dashboard-preferencias.js');

        self::assertStringContainsString("'.db-page.db-page--sem-som'", $filtros);
        self::assertStringContainsString("lista.push('db-page--sem-som')", $prefs);
        self::assertStringNotContainsString('localStorage.setItem', $filtros, 'a preferência mora no servidor');
        self::assertStringNotContainsString('localStorage.setItem', $prefs, 'a preferência mora no servidor');
    }

    #[TestDox('O estilo de um colega (inclusive sons desligados) não aparece na minha tela')]
    public function testSonsDoColegaNaoVazam(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $colega = $this->criarColaborador($tenant, 'Colega Mudo');
        $this->repo()->gravar($tenant, $colega, 'dashboard.sons', 'desligados');

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
    }

    #[TestDox('A última numérica visível vem travada no menu (aria-disabled)')]
    public function testUltimaNumericaTravada(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.colunas_ocultas', array_slice(CatalogoDePreferenciasDoDashboard::COLUNAS_NUMERICAS, 0, 6));

        $crawler = $client->request('GET', '/dashboard');

        self::assertSame(1, $crawler->filter('.db-pref-menu [aria-disabled="true"]')->count());
        self::assertSame(1, $crawler->filter('.db-pref-menu [data-pref-coluna="pastas_criadas"][aria-disabled="true"]')->count());
    }

    #[TestDox('O estilo de um colega não aparece na minha tela')]
    public function testEstiloDoColegaNaoVaza(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarGestorLogado($client);
        $colega = $this->criarColaborador($tenant, 'Colega Compacto');
        $this->repo()->gravar($tenant, $colega, 'dashboard.densidade', 'confortavel');
        $this->repo()->gravar($tenant, $colega, 'dashboard.colunas_ocultas', ['cargo']);

        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('db-page', $crawler->filter('section.db-page')->attr('class'));
    }
}
