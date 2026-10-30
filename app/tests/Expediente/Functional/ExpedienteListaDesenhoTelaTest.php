<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Profile\Entity\UserProfile;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * E8 da frente de fidelidade (`docs/specs/fidelidade-designer-1310/E8-PLANO.md`): a Lista do Expediente no
 * desenho de 13/10 (`03 - EXPEDIENTE Lista 1.1.dc.html`), SÓ visual. Estes testes travam a marcação de que o
 * CSS e o JS dependem (`.exp-body`, barra, chips) e, principalmente, que as regras de sempre continuam: rótulo
 * da prioridade do sistema, duplo clique da situação, total de metas, vários marcadores, a janela da E7 na página.
 * Aparência (cor, hover, colunas) é da comparação na tela.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteListaDesenhoTelaTest extends JusPrimeWebTestCase
{
    #[TestDox('casca: <body> com exp-body, o CSS da Lista por último, a janela de confirmação da E7 (uma só) e os scripts dela e dos menus')]
    public function testCasca(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);

        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('exp-body', (string) $crawler->filter('body')->attr('class'));
        self::assertCount(1, $crawler->filter('link[href$="/css/expediente-lista.css"]'));
        self::assertCount(1, $crawler->filter('#psConfirmar[hidden] .ps-cf[role="alertdialog"][aria-modal="true"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/pasta-dialogos.js"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/expediente-lista.js"]'));
        self::assertCount(1, $crawler->filter('h1.exp-titulo > .exp-titulo-icone[aria-hidden="true"]'));
        self::assertSame('Expediente', trim($crawler->filter('h1.exp-titulo')->text()));
    }

    #[TestDox('acervo: barra do desenho (título + Nova Pasta que abre o modal de sempre) e resumo "1 de 2 · Total: 2 pastas" (intervalo de pastas da página, AUD-14) contando a consulta inteira')]
    public function testBarraEResumo(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $this->criarPasta($tenant, 'BARRA-' . uniqid());
        $this->criarPasta($tenant, 'BARRA-' . uniqid());

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#acervo-geral-wrapper.exp-painel > .exp-barra > h2.exp-barra-titulo'));
        self::assertSame('Acervo geral', trim($crawler->filter('.exp-barra-titulo')->text()));
        $nova = $crawler->filter('.exp-barra > button.exp-nova-pasta');
        self::assertSame('#modalNovaPasta', $nova->attr('data-bs-target'), 'o mesmo modal de sempre — cliente opcional, número no servidor');
        self::assertSame('modal', $nova->attr('data-bs-toggle'));
        // "1 de 2" é o INTERVALO de pastas desta página (dc L.455), não "página 1 de 1"; espaços largos em volta do ponto.
        self::assertSame("1 de 2\u{2003}·\u{2003}Total: 2 pastas", trim((string) preg_replace('/[ \t\n\r]+/', ' ', $crawler->filter('.exp-resumo')->text())));
        // Com 1 página a paginação do topo (a do desenho) continua visível; a do rodapé (só do BlueJus)
        // só aparece com mais de uma página — uma só na tela.
        self::assertCount(1, $crawler->filter('nav[aria-label="Paginação"] .page-item.active'));
    }

    #[TestDox('linha: prioridade com o rótulo do desenho 1.1 ("Alta", E12) e bandeira; situação mantém o duplo clique; metas mostram as ABERTAS (desenho, regra do dono 16/10)')]
    public function testChipsPreservamAsRegras(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $pasta = $this->criarPasta($tenant, 'CHIP-' . uniqid(), PrioridadePasta::Prioridade);

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $linha = $crawler->filter('#tabelaPastas tbody tr.pasta-row-link')->reduce(static fn ($tr): bool => str_contains($tr->text(), $pasta->getNup()));
        self::assertCount(1, $linha);

        $prio = $linha->filter('span.exp-prio');
        self::assertSame('prioridade', $prio->attr('data-prio'));
        self::assertSame('Alta', trim($prio->text()));
        self::assertCount(1, $prio->filter('i.bi-flag-fill'));

        $sit = $linha->filter('.js-toggle-situacao');
        self::assertSame('ativo', $sit->attr('data-situacao'));
        self::assertNotEmpty($sit->attr('data-url'), 'duplo clique preservado');
        self::assertNotEmpty($sit->attr('data-csrf'));

        // Decisão do dono (15/10): sem o "–" do desenho (dc L.168), pela regra sem travessão. A célula fica
        // vazia na tela, mas o leitor de tela ainda ouve que não há metas.
        $semMetas = $linha->filter('td.col-tarefas-tabela .exp-sem-metas');
        self::assertCount(1, $semMetas, 'sem metas: a célula continua marcada');
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $linha->filter('td.col-tarefas-tabela')->text(), 'sem travessão na célula de metas');
        self::assertSame('Sem metas abertas', trim($semMetas->filter('.visually-hidden')->text()));
        self::assertSame('Sem metas abertas', $linha->filter('td.col-tarefas-tabela')->attr('title'), 'a dica fica na célula (o span não tem largura)');
    }

    #[TestDox('marcadores: vários por pasta continuam (chip + "+N"); o chip abre o menu MARCADOR do 1.1 e traz o nome de todos na dica')]
    public function testVariosMarcadores(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = new Marcador('Alfa ' . uniqid(), $tenant, $admin);
        $b = new Marcador('Beta ' . uniqid(), $tenant, $admin);
        $em->persist($a);
        $em->persist($b);
        $pasta = $this->criarPasta($tenant, 'MARC-' . uniqid());
        $pasta->addMarcador($a);
        $pasta->addMarcador($b);
        $em->flush();

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $col = $crawler->filter('td.pasta-marcadores-col[data-pasta-id="' . $pasta->getId() . '"]');
        self::assertCount(1, $col->filter('.marcador-principal > i.bi-folder-fill'));
        self::assertSame('+1', trim($col->filter('.marcadores-extra')->text()));
        self::assertCount(1, $col->filter('button.marcador-principal.js-marc-pop'));
        self::assertCount(1, $col->filter('button.exp-tag-vazio.js-marc-pop.is-escondido'), 'com marcador, o botão de etiqueta fica escondido como no dc');
        $title = (string) $col->filter('.marcador-principal')->attr('data-tip');
        self::assertStringContainsString($a->getNome(), $title);
        self::assertStringContainsString($b->getNome(), $title);
    }

    #[TestDox('painel do marcador: o título da barra é o nome do marcador (dc `titulo`, E12); vazio no texto do desenho')]
    public function testPainelDoMarcadorEVazio(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $m = new Marcador('Gama ' . uniqid(), $tenant, $admin);
        $em->persist($m);
        $em->flush();

        $crawler = $client->xmlHttpRequest('GET', '/expediente/marcador/' . $m->getId() . '/pastas');
        self::assertResponseIsSuccessful();
        self::assertSame($m->getNome(), trim($crawler->filter('#painel-marcador-wrapper.exp-painel .exp-barra-titulo')->text()));
        self::assertStringContainsString('Nenhuma pasta', trim($crawler->filter('.exp-vazio')->text()));
        self::assertCount(1, $crawler->filter('.exp-vazio > i.bi-folder-x'));
    }

    #[TestDox('E8d, AUD-17/18/19: cabeçalho com os ícones do dc; sem ordenação escolhida, NUP aparece ordenando (decrescente, aria-sort) e as outras com bi-chevron-expand')]
    public function testCabecalhoDoDesenho11(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $this->criarPasta($tenant, 'CAB-' . uniqid());

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $ths = $crawler->filter('#tabelaPastas thead th');
        self::assertCount(9, $ths, 'E12: com a coluna Réu do 1.1');
        $icones = $ths->each(static fn ($th): string => (string) $th->filter('i')->first()->attr('class'));
        self::assertSame(['bi bi-upc me-1', 'bi bi-person me-1', 'bi bi-person-x me-1', 'bi bi-bank me-1', 'bi bi-flag me-1', 'bi bi-person-badge me-1', 'bi bi-check2-square me-1', 'bi bi-tags me-1', 'bi bi-circle-half me-1'], $icones);

        $nup = $crawler->filter('#tabelaPastas thead th[data-ordenar="nup"]');
        self::assertSame('descending', $nup->attr('aria-sort'), 'a ordem padrão do repositório é NUP decrescente');
        self::assertStringContainsString('desc', (string) $nup->attr('class'));
        self::assertCount(1, $nup->filter('i.sort-icon.bi-caret-down-fill'));
        self::assertCount(1, $crawler->filter('#tabelaPastas thead [aria-sort]'));
        self::assertCount(7, $crawler->filter('#tabelaPastas thead th[data-ordenar] > .exp-th > i.sort-icon.bi-chevron-expand'), 'VS 18.10.26: Réu também ordena; rótulo e seta no .exp-th (dc L.200)');
        self::assertCount(0, $crawler->filter('#tabelaPastas .bi-arrow-down-up, #tabelaPastas .ps-3'));

        // Ordenação escolhida: a seta vai para a coluna, crescente.
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?ordenar=cliente&direcao=asc');
        self::assertSame('ascending', $crawler->filter('#tabelaPastas thead th[data-ordenar="cliente"]')->attr('aria-sort'));
        self::assertCount(1, $crawler->filter('#tabelaPastas thead th[data-ordenar="cliente"] > .exp-th > i.bi-caret-up-fill'));
        self::assertCount(1, $crawler->filter('#tabelaPastas thead th[data-ordenar="nup"] > .exp-th > i.bi-chevron-expand'));
        self::assertCount(1, $crawler->filter('#tabelaPastas tbody td.col-marc-tabela .exp-tag-vazio > i.bi-tag'), 'AUD-24: ícone do dc');
    }

    #[TestDox('E8d, AUD-08/11/12/13: situação na barra (associada ao form), Visualizar e paginação nos controles, resumo embaixo, filtros com cara de botão, setas em ícone')]
    public function testFaixaDoDesenho11(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $this->criarPasta($tenant, 'FAIXA-' . uniqid(), PrioridadePasta::Urgente);   // casa com o filtro abaixo

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?status=ativo&prioridade=urgente');
        self::assertResponseIsSuccessful();

        // Situação: na barra, à direita de "Nova Pasta", fora do <form> mas associada a ele.
        $sit = $crawler->filter('#acervo-geral-wrapper > .exp-barra > .exp-situacao > select.exp-situacao-select[name="status"][form="formFiltrosPasta"]');
        self::assertCount(1, $sit);
        self::assertSame(['Todas', 'Ativo', 'Suspenso', 'Cancelado', 'Arquivado'], $sit->filter('option')->each(static fn ($o): string => trim($o->text())));
        self::assertSame('ativo', $sit->filter('option[selected]')->attr('value'));
        self::assertSame('ativo', $crawler->filter('.exp-situacao')->attr('data-valor'));
        self::assertSame('Situação', $sit->attr('data-rotulo'));
        self::assertCount(0, $crawler->filter('#formFiltrosPasta select[name="status"]'), 'a situação saiu da linha dos filtros');
        self::assertCount(1, $crawler->filter('.exp-barra > .exp-nova-pasta + .exp-situacao'));

        // Responsável e prioridade: <select> nativos com a seta e a bandeira do dc.
        self::assertCount(1, $crawler->filter('#formFiltrosPasta .pasta-filtros-linha > .exp-filtro--resp > select[name="responsavel"]'));
        self::assertCount(1, $crawler->filter('.exp-filtro--resp > i.exp-filtro-seta.bi-chevron-down'));
        $prio = $crawler->filter('#formFiltrosPasta .pasta-filtros-linha > .exp-filtro--prio');
        self::assertSame('urgente', $prio->attr('data-valor'));
        self::assertCount(1, $prio->filter('i.exp-filtro-bandeira.bi-flag-fill'));

        // Controles na mesma faixa: Visualizar (5 modos, pastas por página e painel, E12) e a paginação; resumo depois.
        $controles = $crawler->filter('#pastasView > .exp-topo > .exp-controles');
        self::assertCount(1, $controles);
        $botao = $controles->filter('.exp-visualizar.pasta-view-toggle > button.js-visualizar');
        self::assertSame('menu', $botao->attr('aria-haspopup'));
        self::assertSame('false', $botao->attr('aria-expanded'));
        self::assertSame('Visualizar', trim($botao->text()));
        $menu = $controles->filter('#expVisualizarMenu[role="menu"]');
        self::assertNotNull($menu->attr('hidden'));
        self::assertSame(['tabela', 'compacto', 'simples', 'lista', 'grandes'], $menu->filter('.js-view-toggle[role="menuitemradio"]')->each(static fn ($b): string => (string) $b->attr('data-view')));
        self::assertSame(['Detalhes', 'Detalhes compactos', 'Lista', 'Cartões', 'Cartões grandes'], $menu->filter('.js-view-toggle')->each(static fn ($b): string => trim($b->text())));
        self::assertCount(1, $menu->filter('.js-vis-painel[role="menuitemcheckbox"]'));
        self::assertSame(['25', '50', '100'], $menu->filter('.js-por-pagina')->each(static fn ($b): string => trim($b->text())));
        self::assertSame('50', trim($menu->filter('.js-por-pagina.is-atual')->text()), 'o padrão continua 50');
        self::assertCount(1, $controles->filter('nav[aria-label="Paginação"]'));
        self::assertCount(1, $crawler->filter('#pastasView > .exp-topo > .exp-controles + .exp-resumo'));

        // Setas em ícone.
        $pag = $controles->filter('nav[aria-label="Paginação"]');
        foreach (['bi-chevron-double-left', 'bi-chevron-left', 'bi-chevron-right', 'bi-chevron-double-right'] as $seta) {
            self::assertCount(1, $pag->filter('i.' . $seta), $seta);
        }
        self::assertDoesNotMatchRegularExpression('/[«‹›»]/u', $pag->text());
    }

    #[TestDox('E8d: o painel do marcador também traz a situação na barra e o Visualizar; "Todas as pastas" com bi-folder2 (AUD-07)')]
    public function testMarcadorETrilhoDoDesenho11(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $m = new Marcador('Delta ' . uniqid(), $tenant, $admin);
        $em->persist($m);
        $pasta = $this->criarPasta($tenant, 'MDELTA-' . uniqid());
        $pasta->addMarcador($m);
        $em->flush();

        $crawler = $client->xmlHttpRequest('GET', '/expediente/marcador/' . $m->getId() . '/pastas');
        self::assertCount(1, $crawler->filter('#painel-marcador-wrapper > .exp-barra > .exp-situacao > select[name="status"][form="formFiltrosPasta"]'));
        self::assertCount(1, $crawler->filter('#pastasView > .exp-topo > .exp-controles > .exp-visualizar'));

        $crawler = $client->request('GET', '/expediente');
        // E12: "Todas as pastas" é o primeiro item do trilho do 1.1, com bi-folder2 e o ↻.
        self::assertCount(1, $crawler->filter('#expTrilho > .exp-tri-todas[data-painel] > i.bi-folder2'));
    }

    /** @return array{0: User, 1: Tenant} */
    #[TestDox('dc L.64: no Expediente o nome do escritório tem a dica do desenho (data-tip) e não a do navegador')]
    public function testDicaDoEscritorioNoExpediente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->entrar($client);

        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        $botao = $crawler->filter('.navtop-action > button.mu-escritorio');
        self::assertCount(1, $botao);
        self::assertSame($tenant->getName(), $botao->attr('data-tip'));
        self::assertNull($botao->attr('title'));
    }

    #[TestDox('dc L.144 e L.146-148/652: a busca tem a dica do desenho; no filtro Responsável, quem tem foto leva o data-foto (avatar com a foto no menu e no botão)')]
    public function testDicaDaBuscaEFotoNoFiltroResponsavel(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new UserProfile($admin))->setFotoUrl('foto_filtro.jpg'));
        $em->flush();
        $this->criarPasta($tenant, 'FOTO-' . uniqid());

        // Os filtros vêm no painel do acervo (fragmento trocável), não na casca da página.
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();

        self::assertSame(
            'Buscar por número da pasta, nome do cliente, ação ou número do processo',
            $crawler->filter('.pasta-filtros-linha > .pasta-filtros-busca')->attr('data-tip'),
        );
        $opcao = $crawler->filter('.exp-filtro--resp > select[name="responsavel"] > option[value="' . $admin->getId() . '"]');
        self::assertCount(1, $opcao);
        self::assertSame('/perfil/foto/foto_filtro.jpg', $opcao->attr('data-foto'));

        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/expediente-lista.js');
        self::assertStringContainsString("foto: op.getAttribute('data-foto') || ''", $js);
        self::assertStringContainsString("if (it.foto) { fotoNoAvatar(av, it.foto); }", $js);
        self::assertStringContainsString("av.style.background = '#0a6fa0 url(\"' + url + '\") center/cover no-repeat';", $js);
    }

    #[TestDox('desenho 03 VS 30.10.26: "Importar em lote" à esquerda do rodapé da Nova Pasta; o clique fecha a modal e só então dispara bj:importar-lote')]
    public function testImportarEmLoteNoRodapeDaNovaPasta(): void
    {
        $client = static::createClient();
        $this->entrar($client);

        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        $botoes = $crawler->filter('#modalNovaPasta .exp-nova-rodape > button');
        self::assertSame('importar-lote', $botoes->first()->attr('data-acao'), 'o botão do lote é o primeiro do rodapé (à esquerda)');
        self::assertSame('Importar em lote', trim($botoes->first()->text()));
        self::assertCount(1, $crawler->filter('#modalNovaPasta [data-acao="importar-lote"]'));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("novaEl.addEventListener('hidden.bs.modal', avisar, { once: true });", $html);
        self::assertStringContainsString("document.dispatchEvent(new CustomEvent('bj:importar-lote'))", $html);
        self::assertCount(1, $crawler->filter('script[src$="/js/expediente-importar-lote.js"]'), 'a linha do e5 continua');
    }

    #[TestDox('desenho 03 VS 30.10.26: pasta arquivada sai esmaecida com a dica "Pasta arquivada"; a ativa não')]
    public function testPastaArquivadaEsmaecida(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->entrar($client);
        $ativa     = $this->criarPasta($tenant, 'ATIVA-' . uniqid());
        $arquivada = $this->criarPasta($tenant, 'ARQ-' . uniqid());
        $arquivada->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral', ['status' => '']);
        self::assertResponseIsSuccessful();

        $linha = $crawler->filter('#tabelaPastas tbody tr[data-pasta-id="' . $arquivada->getId() . '"]');
        self::assertSame('1', $linha->attr('data-arquivada'));
        self::assertSame('Pasta arquivada', $linha->attr('title'));
        self::assertNull($crawler->filter('#tabelaPastas tbody tr[data-pasta-id="' . $ativa->getId() . '"]')->attr('data-arquivada'));
    }

    #[TestDox('desenho 03 VS 30.10.26: o CSS zebra as linhas (#ffffff e #eef3f7) e esmaece a arquivada (.62, grayscale .35)')]
    public function testZebraEArquivadaNoCss(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/expediente-lista-12.css');

        self::assertStringContainsString('.exp-body #tabelaPastas tbody tr > td { background: #ffffff; border-bottom: 1px solid #eef2f5; }', $css);
        self::assertStringContainsString('.exp-body #tabelaPastas tbody tr:nth-child(even) > td { background: #eef3f7; }', $css);
        self::assertStringContainsString('.exp-body #tabelaPastas tbody tr[data-arquivada] > td { opacity: .62; filter: grayscale(.35); }', $css);
    }

    private function entrar($client): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lista ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('lista_' . uniqid() . '@test.com');
        $user->setFullName('Admin Lista');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nup, ?PrioridadePasta $prioridade = null): Pasta
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        if ($prioridade !== null) {
            $pasta->setPrioridade($prioridade);
        }
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }
}
