<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDestaque;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Arranjo da Lista do Expediente integral contra o desenho 1.1 (E12). Combinador de filho direto onde a
 * posição importa: "está no trilho, logo abaixo do cabeçalho" e não "existe em algum lugar da página".
 * Estilo (cor, fonte) segue invisível para o teste; isso fica para a conferência na tela.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteListaIntegralTelaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('trilho do 1.1: Todas as pastas com ↻, EXPEDIENTES com cadeado e 6 filtros, MARCADORES com ⋮ e recolher')]
    public function testTrilho(): void
    {
        [$user, $tenant] = $this->entrar();
        $pai = $this->marcador($tenant, $user, 'Distribuir', '#5b4fb5');
        $sub = $this->marcador($tenant, $user, 'Dra Mariana', '#0a6fa0', $pai);
        $p1 = $this->pasta($tenant, 'T1');
        $p1->addMarcador($pai);
        $p1->addMarcador($sub);   // a mesma pasta no pai e no sub: conta uma vez no pai
        $this->pasta($tenant, 'T2')->addMarcador($sub);
        $this->meta($this->pasta($tenant, 'T3'), $user);   // meta aberta sem resposta
        $this->em()->flush();
        // Mesmo EntityManager da requisição: sem limpar, a coleção `filhos` do pai em memória não traz o sub.
        $this->em()->clear();

        $c = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $c->filter('#expedienteSidebar > #expTrilho > button.exp-tri-todas[data-painel] > .exp-tri-atualizar.js-tri-padrao > i.bi-arrow-repeat'));
        self::assertStringContainsString('Todas as pastas · 3', (string) $c->filter('.exp-tri-todas')->attr('data-tip'));

        self::assertCount(1, $c->filter('#expTrilho > .exp-tri-cab--sistema > i.bi-lock.exp-tri-cadeado'));
        $filtros = $c->filter('#expTrilho > .exp-tri-cab--sistema + .exp-tri-lista > [role="listitem"] > .exp-tri-sistema');
        self::assertSame(['resp10', 'findou', 'aud', 'prazo', 'metas', 'protocolar'], $filtros->each(static fn ($f): string => (string) $f->attr('data-expediente')));
        self::assertCount(0, $c->filter('.exp-tri-sistema.is-em-breve'), 'E12b: Audiência (prazos da pasta) e Protocolar (andamento) funcionam');
        self::assertSame('1', trim($c->filter('.exp-tri-sistema[data-expediente="metas"] .exp-tri-n')->text()));
        self::assertNotEmpty($c->filter('.exp-tri-sistema[data-expediente="findou"]')->attr('data-nota'), 'o botão direito abre a nota');

        self::assertCount(1, $c->filter('#expTrilho > .exp-tri-cab--marcadores > button.js-tri-cab-mais + button.js-tri-recolher#expedienteSidebarToggleBtn'));
        $grupo = $c->filter('#lista-marcadores > .exp-tri-grupo#item-pasta-' . $pai->getId());
        self::assertCount(1, $grupo);
        $item = $grupo->filter('.exp-tri-grupo > .exp-tri-marcador[draggable="true"][data-pai-id=""]');
        self::assertSame('2', $item->attr('data-n'), 'pastas distintas do pai somando o sub (a T1 conta uma vez)');
        self::assertCount(1, $item->filter('.exp-tri-marcador > .js-tri-abrir:not(.is-vazio)'));
        self::assertCount(1, $item->filter('.exp-tri-marcador > i.bi-folder-fill.exp-tri-pasta + .exp-tri-nome + button.js-tri-opcoes + .exp-tri-n'));
        self::assertStringContainsString('--mk-cor: #5b4fb5', (string) $item->attr('style'));
        $subItem = $grupo->filter('.exp-tri-grupo > .exp-tri-subs[hidden] > [role="listitem"] > .exp-tri-sub[data-pai-id="' . $pai->getId() . '"]');
        self::assertSame('Dra Mariana', $subItem->attr('data-nome'));

        // Modais do desenho: marcador com as 10 cores e o sigilo.
        self::assertCount(10, $c->filter('#expMkModal .exp-mk-cores > button.exp-mk-cor'));
        self::assertCount(1, $c->filter('#expMkModal select#expMkPai'));
        self::assertCount(1, $c->filter('#expSigilo[data-url-pedir] .js-sigilo-pedir'));
    }

    #[TestDox('o trilho também sai sozinho (rota XHR), para a tela redesenhá-lo depois de mexer nos marcadores')]
    public function testRotaDoTrilho(): void
    {
        [$user, $tenant] = $this->entrar();
        $this->marcador($tenant, $user, 'Novo', '#c0392b');

        $c = $this->client->xmlHttpRequest('GET', '/expediente/trilho');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $c->filter('#expTrilho .exp-tri-marcador[data-nome="Novo"]'));
        self::assertCount(0, $c->filter('#expedienteSidebar'), 'só o trilho, sem a página');
    }

    #[TestDox('pasta pelo cadeado com quem trancou, destaque do usuário, réu com "e outros", selo Nx e situação do 1.1')]
    public function testLinha(): void
    {
        [$user, $tenant] = $this->entrar();
        $pasta = $this->pasta($tenant, 'L1');
        $this->em()->persist(new CadeadoDaPasta($tenant, $pasta, ModoDoCadeado::Todos, $user, new \DateTimeImmutable('2026-10-10')));
        $pasta->setSituacao(Pasta::SITUACAO_SUSPENSA);
        $proc = new Processo();
        $proc->setNumeroProcesso('0700000-00.2026.8.07.0001');
        $proc->setTenant($tenant);
        $this->em()->persist($proc);
        $pasta->vincularProcesso($proc, $user);
        foreach ([['BANCO DO BRASIL S.A.', 'POLO PASSIVO'], ['INSS', 'RÉU'], ['JOANA', 'POLO ATIVO']] as [$nome, $papel]) {
            $parte = new ParteProcesso();
            $parte->setTipo('PARTE');
            $parte->setNome($nome);
            $parte->setPapel($papel);
            $parte->setTenant($tenant);
            $proc->addParte($parte);
            $this->em()->persist($parte);
        }
        $this->em()->persist(new PastaDestaque($tenant, $user, $pasta, 'verde'));
        $this->em()->flush();

        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $linha = $c->filter('#tabelaPastas tbody > tr.pasta-row-link[data-pasta-id="' . $pasta->getId() . '"]');
        self::assertCount(1, $linha);
        self::assertSame('1', $linha->attr('data-trancada'));
        self::assertSame('0', $linha->attr('data-oculta'), 'quem configura nunca fica oculto');
        self::assertSame('verde', $linha->attr('data-dest'));
        self::assertStringContainsString('pasta trancada por Admin E12 em 10/10/2026', (string) $linha->filter('td[data-col="nup"] > i.js-cadeado')->attr('data-tip'));
        $reu = $linha->filter('td[data-col="reu"] > .exp-reu');
        self::assertSame('BANCO DO BRASIL S.A. e outros', trim($reu->text()), 'só o polo passivo; o ativo não entra');
        self::assertSame('Réus: BANCO DO BRASIL S.A. · INSS', $reu->attr('data-tip'));
        self::assertSame('Suspenso', trim($linha->filter('td[data-col="sit"] > .exp-sit-fixa[data-situacao="suspenso"]')->text()));
        self::assertCount(0, $linha->filter('.js-toggle-situacao'), 'o duplo clique só alterna ativo e arquivado');
        foreach (['nup', 'cliente', 'reu', 'acao', 'prio', 'resp', 'metas', 'marc', 'sit'] as $col) {
            self::assertCount(1, $linha->filter('tr > td[data-col="' . $col . '"]'), $col);
        }
        self::assertSame('1', $c->filter('#tabelaPastas')->attr('data-sou-admin'));
        self::assertNotEmpty($c->filter('#tabelaPastas')->attr('data-csrf-lista'));
    }

    #[TestDox('Metas conta só as abertas, como o desenho; com todas concluídas, a célula fica vazia, sem travessão')]
    public function testMetasAbertas(): void
    {
        [$user, $tenant] = $this->entrar();
        $mista = $this->pasta($tenant, 'MISTA');
        $this->meta($mista, $user);
        $this->meta($mista, $user, Tarefa::STATUS_CONCLUIDA);
        $concluida = $this->pasta($tenant, 'FEITA');
        $this->meta($concluida, $user, Tarefa::STATUS_CONCLUIDA);
        $this->em()->flush();
        $this->em()->clear();

        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        $celMista = $c->filter('tr[data-pasta-id="' . $mista->getId() . '"] > td[data-col="metas"]');
        self::assertSame('1', trim($celMista->filter('a.exp-metas')->text()));
        self::assertSame('1 meta(s) aberta(s) nesta pasta', $celMista->filter('a.exp-metas')->attr('data-tip'));
        $celFeita = $c->filter('tr[data-pasta-id="' . $concluida->getId() . '"] > td[data-col="metas"]');
        self::assertCount(0, $celFeita->filter('a.exp-metas'));
        self::assertDoesNotMatchRegularExpression('/[–—]/u', $celFeita->text());
    }

    #[TestDox('filtro do sistema: o título da barra é o do filtro e o formulário leva expediente e por_pagina escondidos')]
    public function testTituloEFormularioDoFiltroDoSistema(): void
    {
        [$user, $tenant] = $this->entrar();
        $this->meta($this->pasta($tenant, 'F1'), $user);
        $this->em()->flush();

        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?expediente=metas&por_pagina=25');

        self::assertSame('Metas não respondidas', trim($c->filter('#acervo-geral-wrapper > .exp-barra > h2.exp-barra-titulo[role="button"]')->text()));
        self::assertSame('metas', $c->filter('#formFiltrosPasta > input[type="hidden"][name="expediente"]')->attr('value'));
        self::assertSame('25', $c->filter('#formFiltrosPasta > input[type="hidden"][name="por_pagina"]')->attr('value'));
        self::assertCount(1, $c->filter('#formFiltrosPasta .pasta-filtros-linha > button.exp-limpar.js-pasta-limpar[hidden]'));
        self::assertNotEmpty($c->filter('.exp-resumo > button.js-colunas')->attr('data-url-csv'));
    }

    #[TestDox('quem não é administrador recebe a tabela marcada como não administrador (a janela de sigilo vale para ele)')]
    public function testNaoAdministrador(): void
    {
        [, $tenant] = $this->entrar();
        $colega = $this->novoUsuario('Colega');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Leitor ' . uniqid());
        $role->setIsSystem(false);
        $this->em()->persist($role);
        $perm = $this->em()->getRepository(\App\Entity\Permission\Permission::class)->findOneBy(['code' => 'modules.expediente.view']);
        if ($perm === null) {
            $perm = new \App\Entity\Permission\Permission();
            $perm->setCode('modules.expediente.view');
            $perm->setDescription('modules.expediente.view');
            $perm->setGroup('modules');
            $this->em()->persist($perm);
        }
        $trp = new \App\Entity\Tenant\TenantRolePermission();
        $trp->setTenantRole($role);
        $trp->setPermission($perm);
        $this->em()->persist($trp);
        $role->getTenantRolePermissions()->add($trp);
        $ut = new UserTenant($colega, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->pasta($tenant, 'N1');
        $this->em()->flush();
        $this->logarComTenant($this->client, $colega, $tenant);

        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        self::assertSame('0', $c->filter('#tabelaPastas')->attr('data-sou-admin'));
    }

    #[TestDox('Audiência e Protocolar: o título da barra é o do filtro aberto no trilho')]
    public function testTituloDeAudienciaEProtocolar(): void
    {
        $this->entrar();

        foreach (['aud' => 'Audiência', 'protocolar' => 'Protocolar'] as $filtro => $titulo) {
            $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?expediente=' . $filtro);
            self::assertResponseIsSuccessful();
            self::assertSame($titulo, trim($c->filter('#acervo-geral-wrapper > .exp-barra > h2.exp-barra-titulo')->text()));
        }
    }

    #[TestDox('menu de botão direito nos modos Lista e Cartões: cada item tem a linha da tabela da mesma pasta, que leva os dados e as URLs')]
    public function testItensDaListaECartoesTemALinhaDaTabela(): void
    {
        [, $tenant] = $this->entrar();
        $ids = [$this->pasta($tenant, 'M1')->getId(), $this->pasta($tenant, 'M2')->getId()];

        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        foreach ($ids as $id) {
            $linha = $c->filter('#tabelaPastas tbody tr.pasta-row-link[data-pasta-id="' . $id . '"]');
            self::assertCount(1, $linha);
            self::assertNotEmpty($linha->attr('data-url-nome'));
            self::assertNotEmpty($linha->attr('data-url-trancar'));
            self::assertCount(1, $c->filter('.exp-simples > .exp-simples-linha[data-pasta-id="' . $id . '"] > .exp-simples-cli'));
            self::assertCount(1, $c->filter('.exp-cartoes > .exp-cartao[data-pasta-id="' . $id . '"] > .exp-cartao-cli'));
        }
    }

    #[TestDox('busca pelo réu (desenho VS 18.10.26): o cadastrado na pasta e o polo passivo do Datajud, sem acento; o polo ativo não')]
    public function testBuscaPeloReu(): void
    {
        [$user, $tenant] = $this->entrar();
        $cadastrado = $this->pasta($tenant, 'R1');
        $this->em()->persist(new \App\Pasta\Entity\PastaReu($tenant, $cadastrado, 'Condomínio Residencial Park', null, 0));
        $datajud = $this->pasta($tenant, 'R2');
        $this->parteNoProcesso($datajud, $user, 'TELEFÔNICA BRASIL S.A.', 'POLO PASSIVO');
        $ativo = $this->pasta($tenant, 'R3');
        $this->parteNoProcesso($ativo, $user, 'TELEFÔNICA BRASIL S.A.', 'POLO ATIVO');
        // Como na coluna: com réu cadastrado, o polo passivo do Datajud não vale (nem para a busca).
        $this->parteNoProcesso($cadastrado, $user, 'TELEFÔNICA BRASIL S.A.', 'RÉU');
        $this->em()->flush();

        self::assertSame([$cadastrado->getId()], $this->idsDaBusca('condominio residencial'));
        self::assertSame([$datajud->getId()], $this->idsDaBusca('telefonica'));
    }

    #[TestDox('ordenar pela coluna Réu: pelo nome que a coluna mostra (o primeiro), o cadastrado manda e o Datajud vale para a pasta sem cadastrado')]
    public function testOrdenarPeloReu(): void
    {
        [$user, $tenant] = $this->entrar();
        $b = $this->pasta($tenant, 'O1');
        $this->em()->persist(new \App\Pasta\Entity\PastaReu($tenant, $b, 'BETA LTDA', null, 0));
        $this->parteNoProcesso($b, $user, 'AAA DO DATAJUD', 'RÉU');   // ignorado: a pasta tem réu cadastrado
        $a = $this->pasta($tenant, 'O2');
        $this->parteNoProcesso($a, $user, 'ALFA S.A.', 'POLO PASSIVO');
        $this->parteNoProcesso($a, $user, '  ', 'POLO PASSIVO');   // nome vazio: a coluna descarta, a ordem também
        // "ZETA e outros" na coluna: ordena como zeta, não como o menor (ACME).
        $z = $this->pasta($tenant, 'O3');
        $this->em()->persist(new \App\Pasta\Entity\PastaReu($tenant, $z, 'ZETA', null, 0));
        $this->em()->persist(new \App\Pasta\Entity\PastaReu($tenant, $z, 'ACME', null, 1));
        $this->em()->flush();

        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?ordenar=reu&direcao=asc');
        self::assertResponseIsSuccessful();
        $nossas = [$a->getId(), $b->getId(), $z->getId()];
        $ids = $c->filter('#tabelaPastas tbody > tr.pasta-row-link')->each(static fn ($tr): int => (int) $tr->attr('data-pasta-id'));
        self::assertSame($nossas, array_values(array_intersect($ids, $nossas)));
        self::assertSame('ZETA e outros', trim($c->filter('tr[data-pasta-id="' . $z->getId() . '"] td[data-col="reu"] > .exp-reu')->text()));
        self::assertStringContainsString('asc', (string) $c->filter('#tabelaPastas thead th[data-col="reu"].sortable[data-ordenar="reu"]')->attr('class'));
    }

    #[TestDox('navegação: Expediente leva a Demandas e volta pelas abas, e a linha abre a pasta')]
    public function testNavegacaoEntreAbasEPasta(): void
    {
        [, $tenant] = $this->entrar();
        $pasta = $this->pasta($tenant, 'NV');

        $c = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        self::assertSame('Expediente', trim($c->filter('.navtop-subnav > a.navtop-subnav-link.active')->text()));

        $c = $this->client->click($c->filter('.navtop-subnav > a.navtop-subnav-link')->reduce(static fn ($a): bool => trim($a->text()) === 'Demandas')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('Demandas', trim($c->filter('.navtop-subnav > a.navtop-subnav-link.active')->text()));

        $c = $this->client->click($c->filter('.navtop-subnav > a.navtop-subnav-link')->reduce(static fn ($a): bool => trim($a->text()) === 'Expediente')->link());
        self::assertResponseIsSuccessful();
        self::assertRouteSame('expediente_index');

        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        $href = (string) $c->filter('#tabelaPastas tbody tr.pasta-row-link[data-pasta-id="' . $pasta->getId() . '"]')->attr('data-href');
        $this->client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertRouteSame('pasta_show');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: User, 1: Tenant} */
    private function entrar(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Tela E12 ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin E12');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        return [$user, $tenant];
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('tela_e12_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function pasta(Tenant $tenant, string $rotulo): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('E12T' . $rotulo . strtoupper(substr(uniqid(), -5)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** @return int[] ids das pastas que a busca livre do acervo devolve */
    private function idsDaBusca(string $termo): array
    {
        $c = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode($termo));
        self::assertResponseIsSuccessful();

        return $c->filter('#tabelaPastas tbody > tr.pasta-row-link')->each(static fn ($tr): int => (int) $tr->attr('data-pasta-id'));
    }

    private function parteNoProcesso(Pasta $pasta, User $user, string $nome, string $papel): void
    {
        $proc = new Processo();
        $proc->setNumeroProcesso('07' . substr(uniqid(), -5) . '-00.2026.8.07.0001');
        $proc->setTenant($pasta->getTenant());
        $this->em()->persist($proc);
        $pasta->vincularProcesso($proc, $user);
        $parte = new ParteProcesso();
        $parte->setTipo('PARTE');
        $parte->setNome($nome);
        $parte->setPapel($papel);
        $parte->setTenant($pasta->getTenant());
        $proc->addParte($parte);
        $this->em()->persist($parte);
    }

    private function marcador(Tenant $tenant, User $user, string $nome, string $cor, ?Marcador $pai = null): Marcador
    {
        $m = new Marcador($nome, $tenant, $user, $pai);
        $m->setCor($cor);
        $this->em()->persist($m);
        $this->em()->flush();

        return $m;
    }

    private function meta(Pasta $pasta, User $user, string $status = Tarefa::STATUS_PENDENTE): void
    {
        $meta = new Tarefa();
        $meta->setStatus($status);
        $meta->setTitulo('Meta');
        $meta->setDescricao('');
        $meta->setPasta($pasta);
        $meta->setTenant($pasta->getTenant());
        $meta->setCriadoPor($user);
        $this->em()->persist($meta);
    }
}
