<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Tarefa\Enum\PrioridadeDaMeta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(TarefaController::class)]
final class TarefaMinhasControllerTest extends JusPrimeWebTestCase
{
    private function autenticar(KernelBrowser $client): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Metas ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('metas_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Metas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel isSystem → bypassa o PermissionChecker (módulo 'tarefas').
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    /**
     * Meta que ALGUÉM atribuiu ao usuário — sem criador definido de propósito: uma meta que
     * ele mesmo tivesse criado apareceria (com razão) também na aba "Criei", e não serviria
     * para provar que os papéis não se misturam.
     */
    private function criarMeta(Tenant $tenant, User $responsavel, string $titulo, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('META-' . uniqid());
        $pasta->setNomeCliente('Cliente Meta');
        $pasta->setResponsavel($responsavel);
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('Descrição da meta');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        $tarefa->addResponsavel($responsavel);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    #[TestDox('GET /tarefas/minhas autenticado retorna 200 e renderiza a barra de filtro')]
    public function testExibeBarraDeFiltro(): void
    {
        $client = static::createClient();
        $this->autenticar($client);

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-filtro-root', $body);
        self::assertStringContainsString('js-filtro-busca', $body);
    }

    #[TestDox('GET /tarefas/minhas exibe a meta do usuário')]
    public function testExibeMinhaMeta(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar contestação');

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Protocolar contestação', (string) $client->getResponse()->getContent());
    }

    #[TestDox('XHR em /tarefas/minhas devolve só o fragmento, sem o layout nem a barra')]
    public function testXhrRetornaFragmentoSemLayout(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Elaborar parecer');

        $client->xmlHttpRequest('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Elaborar parecer', $body);
        self::assertStringNotContainsString('<!DOCTYPE', $body);
        self::assertStringNotContainsString('data-filtro-root', $body);
    }

    #[TestDox('XHR com busca filtra as metas por título')]
    public function testXhrFiltraPorBusca(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso');
        $this->criarMeta($tenant, $usuario, 'Agendar audiência');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'recurso']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Protocolar recurso', $body);
        self::assertStringNotContainsString('Agendar audiência', $body);
    }

    #[TestDox('XHR com busca sem correspondência mostra o vazio filtrado do desenho (dc L107): uma caixa só, sem ícone, e o trilho continua')]
    public function testXhrBuscaSemResultado(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'zzz-inexistente-zzz']);

        self::assertResponseIsSuccessful();
        $c     = $client->getCrawler();
        $caixa = $c->filter('.mm-arranjo > .mm-coluna-lista > .mm-vazio-caixa');
        self::assertCount(1, $caixa);
        self::assertSame('Nenhuma meta com esses filtros.', trim($caixa->text()));
        self::assertCount(0, $caixa->filter('i'), 'o vazio do desenho não tem ícone');
        self::assertCount(0, $c->filter('.mm-coluna-lista section.mm-grupo'));
        self::assertCount(1, $c->filter('.mm-arranjo > .mm-trilho'), 'o trilho aparece mesmo com a lista vazia (dc L111)');
    }

    #[TestDox('A aba padrão é "Sou responsável" e não traz o que o usuário delegou')]
    public function testAbaPadraoNaoMisturaOsPapeis(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Está comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Deleguei ao colega');

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Está comigo', $body);
        self::assertStringNotContainsString('Deleguei ao colega', $body, 'A aba padrão não pode trazer o que foi delegado.');
    }

    #[TestDox('A aba "Criei" mostra o que o usuário delegou')]
    public function testAbaCriei(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Está comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Deleguei ao colega');

        $client->request('GET', '/tarefas/minhas?aba=criei');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Deleguei ao colega', $body);
        self::assertStringNotContainsString('Está comigo', $body);
    }

    #[TestDox('Aba desconhecida cai no padrão, sem erro e sem virar "Todas"')]
    public function testAbaInvalidaCaiNoPadrao(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Está comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Deleguei ao colega');

        $client->request('GET', '/tarefas/minhas?aba=inventada');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Está comigo', $body);
        self::assertStringNotContainsString(
            'Deleguei ao colega',
            $body,
            'Aba inválida não pode degradar para a lista mais ampla — seria voltar ao defeito.',
        );
    }

    #[TestDox('As quatro abas respondem 200 e marcam a aba pedida como ativa')]
    public function testTodasAsAbasRespondem(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Uma meta qualquer');

        foreach (['responsavel', 'criei', 'acompanhando', 'todas'] as $aba) {
            $client->request('GET', '/tarefas/minhas?aba=' . $aba);

            self::assertResponseIsSuccessful("A aba '{$aba}' não respondeu 200.");
            $ativa = $client->getCrawler()->filter('.mm-aba.is-ativa');
            self::assertCount(1, $ativa, "A aba '{$aba}' deveria marcar exatamente uma aba ativa.");
            self::assertSame($aba, $ativa->attr('data-aba'));
        }
    }

    #[TestDox('A lista fica ao LADO do trilho, não abaixo dele (combinador de filho direto)')]
    public function testArranjoDaListaComTrilho(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta com trilho');

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertCount(1, $crawler->filter('.mm-arranjo > .mm-trilho'), 'O trilho tem de ser filho direto do arranjo de duas colunas.');
        self::assertCount(1, $crawler->filter('.mm-arranjo > .mm-coluna-lista > section.mm-grupo'), 'A lista tem de ser a outra coluna do mesmo arranjo, um cartão por grupo.');
        self::assertCount(0, $crawler->filter('.mm-arranjo > .mm-coluna-lista > .mm-cartao'), 'os grupos não moram mais num cartão só (LIS-02)');
    }

    #[TestDox('R239 (P5): antes do título vai o número da meta NA PASTA — o mesmo da aba Metas da pasta —, não a posição no grupo; número global não aparece (não existe por escritório)')]
    public function testNumeroDaMetaNaPastaComoNaAbaMetas(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);

        // Pasta A: a 1ª meta é de outra pessoa; o usuário responde pela 2ª e pela 3ª.
        $outro = new User();
        $outro->setEmail('outro_' . uniqid() . '@test.com');
        $outro->setFullName('Outra Pessoa');
        $outro->setRoles(['ROLE_USER']);
        $outro->setIsActive(true);
        $outro->setPassword('x');
        $em->persist($outro);
        $primeira = $this->criarMeta($tenant, $outro, 'Primeira da pasta');
        $pastaA   = $primeira->getPasta();
        $titulosA = ['Segunda da pasta' => '2.', 'Terceira da pasta' => '3.'];
        foreach (array_keys($titulosA) as $titulo) {
            $meta = new Tarefa();
            $meta->setTitulo($titulo);
            $meta->setDescricao('');
            $meta->setPasta($pastaA);
            $meta->setTenant($tenant);
            $meta->addResponsavel($usuario);
            $em->persist($meta);
            $em->flush();
        }
        // Pasta B: uma meta só.
        $this->criarMeta($tenant, $usuario, 'Sozinha na pasta');

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $naLista = [];
        $client->getCrawler()->filter('.mm-grupo article.mm-meta .mm-meta-topo > .mm-meta-titulo')->each(
            static function ($a) use (&$naLista): void {
                $num = $a->filter('.mm-meta-num');
                $naLista[mb_strtoupper(trim(str_replace($num->count() ? $num->text() : '', '', $a->text())))] = $num->count() ? trim($num->text()) : null;
            },
        );

        self::assertSame('2.', $naLista['SEGUNDA DA PASTA'] ?? null);
        self::assertSame('3.', $naLista['TERCEIRA DA PASTA'] ?? null);
        self::assertSame('1.', $naLista['SOZINHA NA PASTA'] ?? null);
        self::assertArrayNotHasKey('PRIMEIRA DA PASTA', $naLista, 'a meta da outra pessoa não é do usuário');
        self::assertStringNotContainsString('Nº ', (string) $client->getResponse()->getContent(), 'sem número global: não existe sequência por escritório');

        // O mesmo número que a aba Metas da pasta mostra (regra única: ordem de criação na pasta).
        $client->request('GET', '/pasta/' . $pastaA->getId());
        self::assertResponseIsSuccessful();
        $naPasta = [];
        $client->getCrawler()->filter('.ps-meta-titulo')->each(static function ($t) use (&$naPasta): void {
            $naPasta[mb_strtoupper(trim($t->filter('.ps-meta-titulo-tx')->text()))] = trim($t->filter('.ps-meta-num')->text());
        });
        self::assertSame($naPasta['SEGUNDA DA PASTA'] ?? null, $naLista['SEGUNDA DA PASTA']);
        self::assertSame($naPasta['TERCEIRA DA PASTA'] ?? null, $naLista['TERCEIRA DA PASTA']);
    }

    #[TestDox('Status de prazo derivados do dado real e iguais nos dois modos; a meta que vence hoje é "Vence em breve" (tom warn) em cartões e em lista')]
    public function testStatusDePrazoIguaisNosDoisModos(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);

        $esperado = [
            'ATRASADA ONTEM' => ['-1 day', 'Atrasada', 'tom-danger'],
            'VENCE HOJE'     => ['today', 'Vence em breve', 'tom-warn'],
            'VENCE EM TRES'  => ['+3 days', 'Vence em breve', 'tom-warn'],
            'VENCE EM VINTE' => ['+20 days', 'No prazo', 'tom-ok'],
            'SEM DATA'       => [null, 'Sem prazo', 'tom-cinza'],
        ];
        foreach ($esperado as $titulo => [$prazo]) {
            $meta = $this->criarMeta($tenant, $usuario, $titulo);
            $meta->setPrazo($prazo === null ? null : new \DateTimeImmutable($prazo));
        }
        $em->flush();

        $pilulas = function (string $url, string $linha, string $titulo) use ($client): array {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $mapa = [];
            $client->getCrawler()->filter($linha)->each(static function ($l) use (&$mapa, $titulo): void {
                $t = trim(preg_replace('/^\d+\.\s*/', '', $l->filter($titulo)->text()) ?? '');
                $p = $l->filter('.mm-pilula');
                $mapa[mb_strtoupper($t)] = [trim($p->text()), preg_replace('/^.*(tom-[a-z]+).*$/', '$1', (string) $p->attr('class'))];
            });

            return $mapa;
        };

        $cartoes = $pilulas('/tarefas/minhas', '.mm-grupo article.mm-meta', '.mm-meta-titulo');
        $lista   = $pilulas('/tarefas/minhas?modo=lista', '.mm-tabela .mm-linha:not(.mm-linha-cabeca)', '.mm-linha-titulo');

        foreach ($esperado as $titulo => [, $rotulo, $tom]) {
            self::assertSame([$rotulo, $tom], $cartoes[$titulo] ?? null, 'cartões: ' . $titulo);
            self::assertSame([$rotulo, $tom], $lista[$titulo] ?? null, 'lista: ' . $titulo);
        }
    }

    #[TestDox('E5b (dc de 13/10): abas no MESMO cartão da barra de filtros, com separador e a alternância de modo; a barra de filtros continua dentro da raiz do filtro')]
    public function testAbasDentroDoCartaoDosFiltros(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta qualquer');

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();

        self::assertCount(1, $c->filter('[data-filtro-root] > .mm-barra-cartao > .mm-abas > a.mm-aba[data-aba="responsavel"]'));
        self::assertCount(1, $c->filter('[data-filtro-root] > .mm-barra-cartao > .mm-abas + .mm-barra-sep + form[data-filtro-form]'));
        self::assertCount(1, $c->filter('[data-filtro-root] > .mm-barra-cartao > .mm-modos'));
        self::assertCount(1, $c->filter('[data-filtro-root] > [data-filtro-resultado]'), 'o resultado continua irmão da barra');
        self::assertCount(0, $c->filter('.mm-barra-abas'), 'a faixa solta de abas saiu');
    }

    #[TestDox('E5b: indicadores com ícone, halo e faixa do desenho; o número das atrasadas fica em alerta só quando há atrasadas')]
    public function testIndicadoresComIconeEHalo(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);

        $client->request('GET', '/tarefas/minhas');
        $kpis = $client->getCrawler()->filter('.mm-kpis > a.mm-kpi');
        self::assertSame(
            ['bi-exclamation-octagon', 'bi-hourglass-split', 'bi-clipboard2-check', 'bi-calendar-x'],
            $kpis->each(static fn ($k): string => trim(str_replace('bi ', '', (string) $k->filter('.mm-kpi > .mm-kpi-icone > i.bi')->attr('class')))),
        );
        self::assertSame(4, $kpis->filter('.mm-kpi > .mm-kpi-halo')->count());
        self::assertSame(4, $kpis->filter('.mm-kpi > .mm-kpi-faixa')->count());
        self::assertStringNotContainsString('is-alerta', (string) $kpis->eq(0)->filter('.mm-kpi-valor')->attr('class'), 'zero atrasadas: número normal');

        $this->criarMeta($tenant, $usuario, 'Atrasada')->setPrazo(new \DateTimeImmutable('-3 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request('GET', '/tarefas/minhas');
        self::assertStringContainsString('is-alerta', (string) $client->getCrawler()->filter('.mm-kpis > a.mm-kpi')->eq(0)->filter('.mm-kpi-valor')->attr('class'));
    }

    #[TestDox('E5b: "Atualizado às HH:MM" é a hora da leitura no servidor; o fragmento da recarga parcial traz a hora da nova leitura')]
    public function testAtualizadoAsComAHoraDaLeitura(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta qualquer');

        $client->request('GET', '/tarefas/minhas');
        $c = $client->getCrawler();
        self::assertMatchesRegularExpression('/^Atualizado às \d{2}:\d{2}$/', trim(preg_replace('/\s+/', ' ', $c->filter('.mm-atualizado')->text()) ?? ''));
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', (string) $c->filter('[data-filtro-resultado] [data-mm-lido-as]')->attr('data-mm-lido-as'));

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'Meta']);
        self::assertMatchesRegularExpression('/data-mm-lido-as="\d{2}:\d{2}"/', (string) $client->getResponse()->getContent());
    }

    #[TestDox('E5b: trilho com "Seu andamento" e "Precisa de atenção" marcados para as faixas coloridas do desenho')]
    public function testTrilhoComFaixas(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Atrasada')->setPrazo(new \DateTimeImmutable('-3 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas');
        $c = $client->getCrawler();
        self::assertCount(1, $c->filter('.mm-arranjo > .mm-trilho > .mm-cartao.mm-trilho-andamento'));
        self::assertCount(1, $c->filter('.mm-arranjo > .mm-trilho > .mm-cartao.mm-trilho-atencao'));
    }

    #[TestDox('E5c: texto do prazo na linha como o desenho ("vence em N dia(s) · data", "vence hoje", "sem prazo definido", "concluída", "N dia(s) atrasado"); só a atrasada ganha destaque')]
    public function testTextoDoPrazoComoODesenho(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);

        $daqui3 = new \DateTimeImmutable('+3 days');
        $esperado = [
            'ATRASADA DOIS' => ['-2 days', Tarefa::STATUS_PENDENTE, '2 dia(s) atrasado', true],
            'VENCE HOJE'    => ['today', Tarefa::STATUS_PENDENTE, 'vence hoje', false],
            'VENCE EM TRES' => ['+3 days', Tarefa::STATUS_PENDENTE, 'vence em 3 dia(s) · ' . $daqui3->format('d/m/Y'), false],
            'SEM DATA'      => [null, Tarefa::STATUS_PENDENTE, 'sem prazo definido', false],
            'JA FEITA'      => ['-5 days', Tarefa::STATUS_CONCLUIDA, 'concluída', false],
        ];
        foreach ($esperado as $titulo => [$prazo, $status]) {
            $meta = $this->criarMeta($tenant, $usuario, $titulo, $status);
            $meta->setPrazo($prazo === null ? null : new \DateTimeImmutable($prazo));
            if ($status === Tarefa::STATUS_CONCLUIDA) {
                $meta->setDataConclusao(new \DateTimeImmutable());
            }
        }
        $em->flush();

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $achado = [];
        $client->getCrawler()->filter('.mm-grupo article.mm-meta')->each(static function ($l) use (&$achado): void {
            $t = mb_strtoupper(trim(preg_replace('/^\d+\.\s*/', '', $l->filter('.mm-meta-titulo')->text()) ?? ''));
            $p = $l->filter('.mm-meta-linha2 > .mm-prazo');
            $achado[$t] = [trim(preg_replace('/\s+/', ' ', $p->text()) ?? ''), str_contains((string) $p->attr('class'), 'is-atrasado')];
        });

        foreach ($esperado as $titulo => [, , $texto, $atrasado]) {
            self::assertSame([$texto, $atrasado], $achado[$titulo] ?? null, $titulo);
        }
    }

    #[TestDox('E5c (R118/R236/R223): ⋮ "Opções" no lugar da seta, só com ações que já existem: relatório completo, aba Metas da pasta com a meta realçada, Editar nome e "Encaminhar via Chat I.A" por último')]
    public function testMenuDaMetaComAcoesQueJaExistem(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Meta com menu');
        $id                 = $meta->getId();
        $pastaId            = $meta->getPasta()->getId();

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $acoes = $client->getCrawler()->filter('.mm-grupo article.mm-meta > .mm-meta-acoes');
        self::assertCount(1, $acoes);

        self::assertCount(0, $acoes->filter('.bi-chevron-right'), 'a seta "Abrir a meta" saiu');
        $botao = $acoes->filter('.mm-menu > button.mm-acao-icone[data-bs-toggle="dropdown"]');
        self::assertCount(1, $botao);
        self::assertSame('Opções', $botao->attr('title'));
        self::assertCount(1, $botao->filter('i.bi-three-dots-vertical'));
        self::assertSame('false', $botao->attr('aria-expanded'));

        $itens = $acoes->filter('.mm-menu > .dropdown-menu .mm-menu-item');
        self::assertSame(
            ['Abrir relatório completo', 'Ir para a aba Metas da pasta', 'Editar nome', 'Encaminhar via Chat I.A'],
            $itens->each(static fn ($i): string => trim($i->text())),
        );
        self::assertSame(
            [
                '/tarefas/' . $id,
                '/pasta/' . $pastaId . '?meta=' . $id . '#tarefas',
                '/pasta/' . $pastaId . '?meta=' . $id . '&acao=renomear#tarefas',
            ],
            $acoes->filter('.mm-menu > .dropdown-menu a.mm-menu-item')->each(static fn ($i): string => (string) $i->attr('href')),
        );
    }

    #[TestDox('R223: "Encaminhar via Chat I.A" é o último item do ⋮, com bi-send e só ids em data-* (a pasta da meta e a meta, tipo meta)')]
    public function testEncaminharNoMenuDaMeta(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Meta para encaminhar');

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $item = $client->getCrawler()->filter('.mm-grupo article.mm-meta > .mm-meta-acoes > .mm-menu > .dropdown-menu > li:last-child > button.js-mm-encaminhar');
        self::assertCount(1, $item);
        self::assertSame('button', $item->attr('type'));
        self::assertSame('Encaminhar via Chat I.A', trim($item->text()));
        self::assertCount(1, $item->filter('button > i.bi.bi-send'));
        self::assertSame('meta', $item->attr('data-encaminhar-tipo'));
        self::assertSame((string) $meta->getId(), $item->attr('data-encaminhar-id'));
        self::assertSame((string) $meta->getPasta()->getId(), $item->attr('data-encaminhar-pasta'));

        // O clique dispara o evento da janela da Central só com os ids (script da página).
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("closest('.js-mm-encaminhar')", $html);
        self::assertStringContainsString("detail: { pastaId: pastaId, tipo: 'meta', itemId: itemId }", $html);
    }

    #[TestDox('R223: sem a Central (vínculo inativo) o ⋮ não tem "Encaminhar via Chat I.A"')]
    public function testEncaminharAusenteSemCentral(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta sem Central');
        // O portão da Central é o vínculo ATIVO (`central_disponivel()`); o papel de sistema segue vendo as metas.
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?',
            [$usuario->getId(), $tenant->getId()],
        );

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $menus = $client->getCrawler()->filter('.mm-grupo article.mm-meta .mm-menu');
        self::assertCount(1, $menus);
        self::assertCount(0, $menus->filter('.js-mm-encaminhar'));
        self::assertStringNotContainsString('Encaminhar', $menus->text());
    }

    #[TestDox('E5c: meta de pasta excluída (somente leitura) não oferece "Editar nome" nem "Encaminhar via Chat I.A" no ⋮, mesma regra da aba Metas da pasta')]
    public function testMenuSemEditarNomeNaPastaExcluida(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Meta da pasta excluida');
        $meta->getPasta()->marcarExcluida($usuario, new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas?aba=todas');
        self::assertResponseIsSuccessful();
        // Hoje Minhas Metas lista a meta de pasta excluída. Se um dia deixar de listar, este teste
        // falha de propósito — para alguém decidir, e não para virar skip silencioso.
        $menus = $client->getCrawler()->filter('.mm-grupo article.mm-meta .mm-menu');
        self::assertCount(1, $menus, 'a meta da pasta excluída aparece na aba Todas');
        self::assertSame(
            ['Abrir relatório completo', 'Ir para a aba Metas da pasta'],
            $menus->filter('.mm-menu-item')->each(static fn ($i): string => trim($i->text())),
        );
    }

    #[TestDox('E5c (dc L104-118): trilho com "X de Y", legenda fixa de três itens com "(s)", sem "em aberto" (desenho 04, P:177; dono 21/10) e "Título · Pasta NNN" no "Precisa de atenção"')]
    public function testTrilhoComTextosDoDesenho(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $atrasada           = $this->criarMeta($tenant, $usuario, 'Atrasada');
        $atrasada->setPrazo(new \DateTimeImmutable('-3 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas');
        $c = $client->getCrawler();

        self::assertSame('0 de 1', trim($c->filter('.mm-trilho-andamento .mm-progresso-numero')->text()));
        self::assertSame(
            ['0 concluída(s)', '1 atrasada(s)', '0 em revisão'],
            $c->filter('.mm-trilho-andamento .mm-fatias > .mm-fatia')->each(static fn ($f): string => trim(preg_replace('/\s+/', ' ', $f->text()) ?? '')),
        );
        self::assertSame(
            ['mm-fatia tom-ok', 'mm-fatia tom-danger', 'mm-fatia tom-accent'],
            $c->filter('.mm-trilho-andamento .mm-fatias > .mm-fatia')->each(static fn ($f): string => (string) $f->attr('class')),
            'verde, vermelho e azul do desenho, nesta ordem',
        );
        self::assertSame(
            'Atrasada · Pasta ' . $atrasada->getPasta()->getNup(),
            trim(preg_replace('/\s+/', ' ', $c->filter('.mm-trilho-atencao .mm-atencao-titulo')->text()) ?? ''),
        );
    }

    #[TestDox('Desenho 04 (P:112-118, P:176-177): com filtro ativo e em outra aba, "Seu andamento" mantém o título e conta TODAS as metas da pessoa, também na recarga parcial')]
    public function testAndamentoContaTodasAsMetasComFiltroEmOutraAba(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Delegada atrasada')->setPrazo(new \DateTimeImmutable('-2 days'));
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Delegada folgada')->setPrazo(new \DateTimeImmutable('+20 days'));
        $this->criarMeta($tenant, $usuario, 'Comigo, entregue', Tarefa::STATUS_EM_REVISAO);
        $this->criarMeta($tenant, $usuario, 'Comigo, feita', Tarefa::STATUS_CONCLUIDA)->setDataConclusao(new \DateTimeImmutable('-1 day'));
        $this->criarMeta($tenant, $colega, 'Só do colega')->setPrazo(new \DateTimeImmutable('-4 days'));
        $em->flush();

        $esperado = ['1 concluída(s)', '1 atrasada(s)', '1 em revisão'];
        foreach (['criei', 'responsavel', 'acompanhando'] as $aba) {
            $client->request('GET', '/tarefas/minhas', ['aba' => $aba, 'busca' => 'nada-casa-com-isto', 'prazo' => 'sem']);
            self::assertResponseIsSuccessful();
            $this->assertAndamento($client->getCrawler(), '1 de 4', $esperado, $aba);
        }

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['aba' => 'criei', 'busca' => 'folgada', 'status' => 'pendente']);
        self::assertResponseIsSuccessful();
        $this->assertAndamento($client->getCrawler(), '1 de 4', $esperado, 'XHR');
    }

    /** @param list<string> $fatias */
    private function assertAndamento(Crawler $c, string $xDeY, array $fatias, string $onde): void
    {
        $cartao = $c->filter('.mm-arranjo > .mm-trilho > .mm-cartao.mm-trilho-andamento');
        self::assertSame('Seu andamento', trim($cartao->filter('.mm-trilho-andamento > div > h2.mm-cartao-titulo')->text()), $onde);
        self::assertSame($xDeY, trim($cartao->filter('.mm-progresso-numero')->text()), $onde);
        self::assertSame(
            $fatias,
            $cartao->filter('.mm-trilho-andamento > .mm-fatias > .mm-fatia')->each(static fn ($f): string => trim(preg_replace('/\s+/', ' ', $f->text()) ?? '')),
            $onde,
        );
    }

    #[TestDox('Desenho 04 (P:151-154): a aba Criei mostra os grupos por prazo, na ordem e com os nomes do desenho (LIS-07/08/10), sem nota e todos abertos')]
    public function testAbaCrieiAgrupaPorPrazo(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Atrasada')->setPrazo(new \DateTimeImmutable('-2 days'));
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Vence logo')->setPrazo(new \DateTimeImmutable('+2 days'));
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Folgada')->setPrazo(new \DateTimeImmutable('+30 days'));
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Sem data');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Voltou para mim')->setStatus(Tarefa::STATUS_EM_REVISAO);
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Aprovei')->setStatus(Tarefa::STATUS_CONCLUIDA)->setDataConclusao(new \DateTimeImmutable());
        $em->flush();

        $client->request('GET', '/tarefas/minhas?aba=criei');
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();

        self::assertSame(
            ['Atrasadas', 'Vencem em 7 dias', 'Aguardando revisão', 'No prazo', 'Sem prazo', 'Concluídas'],
            $c->filter('.mm-arranjo > .mm-coluna-lista > section.mm-grupo > .mm-grupo-cabeca > .mm-grupo-nome')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertCount(1, $c->filter('.mm-coluna-lista > section.mm-grupo.mm-grupo-atrasadas > article.mm-meta.tom-danger'));
        self::assertCount(1, $c->filter('.mm-coluna-lista > section.mm-grupo.mm-grupo-concluidas > div.mm-grupo-cabeca + article.mm-meta'), 'Concluídas aberta, como os outros grupos (desenho 04, P:163)');
        self::assertCount(0, $c->filter('.mm-coluna-lista .collapse, .mm-coluna-lista button.mm-grupo-cabeca, .mm-grupo-seta, .mm-grupo-cabeca > .mm-grupo-nota'), 'sem recolhimento, seta nem nota em nenhum grupo');
        self::assertCount(0, $c->filter('.mm-grupo-aguardando, .mm-grupo-andamento'), 'os grupos por etapa saíram');
    }

    #[TestDox('O modo lista troca os cartões pela tabela e dispensa o trilho')]
    public function testModoLista(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta em lista');

        $client->request('GET', '/tarefas/minhas?modo=lista');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertGreaterThan(0, $crawler->filter('.mm-tabela')->count());
        self::assertCount(0, $crawler->filter('.mm-trilho'), 'O modo lista não desenha o trilho.');
        self::assertStringContainsString('Meta em lista', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Os quatro KPIs aparecem no topo com as contagens do usuário')]
    public function testKpisNoTopo(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);

        $atrasada = $this->criarMeta($tenant, $usuario, 'Atrasada');
        $atrasada->setPrazo(new \DateTimeImmutable('-2 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $kpis = $client->getCrawler()->filter('.mm-kpi');
        self::assertCount(4, $kpis, 'A faixa tem exatamente quatro atalhos.');
        self::assertSame('1', trim($kpis->eq(0)->filter('.mm-kpi-valor')->text()), 'O primeiro KPI conta as atrasadas.');
    }

    #[TestDox('Filtrar dentro de uma aba preserva a aba na recarga por XHR')]
    public function testFiltroPreservaAAba(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Protocolar comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Protocolar delegada');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['aba' => 'criei', 'busca' => 'protocolar']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Protocolar delegada', $body);
        self::assertStringNotContainsString('Protocolar comigo', $body, 'O filtro não pode furar o escopo da aba.');
    }

    #[TestDox('Marcar acompanhamento coloca a meta na aba "Em acompanhamento"')]
    public function testMarcarAcompanhamento(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $meta = $this->criarMetaDelegada($tenant, $usuario, $colega, 'Vou acompanhar esta');
        $id   = (int) $meta->getId();

        $client->request('GET', '/tarefas/minhas?aba=acompanhando');
        self::assertStringNotContainsString('Vou acompanhar esta', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tarefas/minhas?aba=criei');
        $botao = $client->getCrawler()->filter('[data-acompanhar]')->first();
        self::assertGreaterThan(0, $botao->count(), 'O cartão precisa oferecer o marcador.');

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $botao->attr('data-token')]);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['acompanhando']);

        $client->request('GET', '/tarefas/minhas?aba=acompanhando');
        self::assertStringContainsString('Vou acompanhar esta', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Clicar duas vezes no marcador desmarca — é um alternador')]
    public function testMarcadorAlterna(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Liga e desliga');
        $id                 = (int) $meta->getId();

        $client->request('GET', '/tarefas/minhas');
        $token = $client->getCrawler()->filter('[data-acompanhar]')->first()->attr('data-token');

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $token]);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['acompanhando']);

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $token]);
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['acompanhando']);
    }

    #[TestDox('Desenho de 19/10 (LIS-02/05/06): cada grupo é uma <section> própria, filha direta da coluna da lista, com a classe da chave; o grupo de 7 dias se chama "Vencem em 7 dias"; Concluídas aberta')]
    public function testCadaGrupoEmCartaoProprio(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);
        $this->criarMeta($tenant, $usuario, 'Atrasada')->setPrazo(new \DateTimeImmutable('-2 days'));
        $this->criarMeta($tenant, $usuario, 'Vence logo')->setPrazo(new \DateTimeImmutable('+2 days'));
        $this->criarMeta($tenant, $usuario, 'Sem data');
        $feita = $this->criarMeta($tenant, $usuario, 'Feita', Tarefa::STATUS_CONCLUIDA);
        $feita->setDataConclusao(new \DateTimeImmutable());
        $em->flush();

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();

        $grupos = $c->filter('.mm-arranjo > .mm-coluna-lista > section.mm-grupo');
        self::assertSame(
            ['Atrasadas', 'Vencem em 7 dias', 'Sem prazo', 'Concluídas'],
            $grupos->each(static fn ($g): string => trim($g->filter('section.mm-grupo > .mm-grupo-cabeca > .mm-grupo-nome')->text())),
        );
        self::assertStringNotContainsString('Próximos 7 dias', (string) $client->getResponse()->getContent());
        self::assertCount(1, $c->filter('.mm-coluna-lista > section.mm-grupo.mm-grupo-atrasadas > article.mm-meta.tom-danger'));
        self::assertCount(1, $c->filter('.mm-coluna-lista > section.mm-grupo.mm-grupo-proximas > article.mm-meta.tom-warn'));
        self::assertCount(1, $c->filter('.mm-coluna-lista > section.mm-grupo.mm-grupo-proximas > .mm-grupo-cabeca > .mm-dot + .mm-grupo-nome + .mm-grupo-contagem'));
        self::assertCount(1, $c->filter('.mm-coluna-lista > section.mm-grupo.mm-grupo-concluidas > div.mm-grupo-cabeca + article.mm-meta'), 'Concluídas aberta, dentro do próprio cartão');
    }

    #[TestDox('Desenho de 19/10 (LIS-15/18/33, MENU-02): linha inteira clicável que delega para o título; pasta com bi-folder2, nº em negrito e cliente inteiro; "criada" sem "mod."; dica do marcador do desenho')]
    public function testLinhaDaMetaComoODesenho(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);
        $meta               = $this->criarMeta($tenant, $usuario, 'Meta da linha');
        $cliente            = 'CONDOMÍNIO RESIDENCIAL JARDIM DAS ACÁCIAS BLOCO B ADMINISTRAÇÃO';
        $meta->getPasta()->setNomeCliente($cliente);
        $meta->setDescricao('Descrição alterada');   // o PreUpdate grava dataAlteracao: antes saía "· mod."
        $em->flush();
        self::assertNotNull($meta->getDataAlteracao());

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();

        $linha = $c->filter('section.mm-grupo > article.mm-meta[data-mm-linha-clicavel]');
        self::assertCount(1, $linha);
        self::assertSame('/tarefas/' . $meta->getId(), $linha->filter('article.mm-meta > .mm-meta-corpo > .mm-meta-topo > a.mm-meta-titulo')->attr('href'), 'o título é o link real da linha');

        $pasta = $linha->filter('.mm-meta-corpo > .mm-meta-linha2 > a.mm-meta-pasta');
        self::assertSame('/pasta/' . $meta->getPasta()->getId(), $pasta->attr('href'), 'o link da pasta continua');
        self::assertCount(1, $pasta->filter('a.mm-meta-pasta > i.bi-folder2 + b.mm-meta-pasta-num'));
        self::assertSame($meta->getPasta()->getNup(), trim($pasta->filter('b.mm-meta-pasta-num')->text()));
        self::assertSame($meta->getPasta()->getNup() . ' · ' . $cliente, trim(preg_replace('/\s+/', ' ', $pasta->text()) ?? ''), 'nome do cliente inteiro, sem corte');

        $datas = trim($linha->filter('article.mm-meta > .mm-meta-lado > .mm-meta-datas')->text());
        self::assertMatchesRegularExpression('#^criada \d{2}/\d{2}/\d{4}$#', $datas);

        self::assertSame('Acompanhar esta meta', $linha->filter('.mm-meta-acoes > button.mm-marcador')->attr('title'));

        // A delegação do clique: ignora os controles internos e repassa para o título.
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("closest('[data-mm-linha-clicavel]')", $corpo);
        self::assertStringContainsString("closest('a, button, input, select, textarea, label, .dropdown-menu')", $corpo);
        self::assertStringContainsString("querySelector('a.mm-meta-titulo')", $corpo);
        self::assertStringContainsString("document.addEventListener('auxclick', abrirMetaPelaLinha)", $corpo, 'botão do meio abre em outra aba');
        self::assertStringContainsString("meio || e.ctrlKey || e.metaKey || e.shiftKey", $corpo);
        self::assertStringContainsString("window.open(titulo.href, '_blank', 'noopener')", $corpo);
        self::assertStringContainsString("BJ_T.t('metas.js.parar_de_acompanhar') : BJ_T.t('metas.js.acompanhar_esta_meta')", $corpo, 'o JS do marcador troca para as dicas do desenho');
        // Desde a extração do idioma o texto vem no catálogo do JS que a própria página entrega.
        self::assertStringContainsString('"metas.js.acompanhar_esta_meta":"Acompanhar esta meta","metas.js.parar_de_acompanhar":"Parar de acompanhar"', $corpo);
    }

    #[TestDox('MENU-02: meta acompanhada mostra "Parar de acompanhar" no marcador, nos cartões e na lista')]
    public function testDicaDoMarcadorLigado(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Meta acompanhada');

        $client->request('GET', '/tarefas/minhas');
        $token = $client->getCrawler()->filter('[data-acompanhar]')->first()->attr('data-token');
        $client->request('POST', '/tarefas/' . $meta->getId() . '/acompanhar', ['_token' => $token]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/tarefas/minhas');
        self::assertSame('Parar de acompanhar', $client->getCrawler()->filter('section.mm-grupo > article.mm-meta button.mm-marcador.is-marcada')->attr('title'));
        $client->request('GET', '/tarefas/minhas?modo=lista');
        self::assertSame('Parar de acompanhar', $client->getCrawler()->filter('.mm-tabela > .mm-linha > button.mm-marcador.is-marcada')->attr('title'));
    }

    #[TestDox('LIS-34: aba vazia sem filtro mantém o texto que orienta, na mesma caixa do desenho, sem ícone, com o trilho ao lado')]
    public function testVazioDaAbaSemFiltro(): void
    {
        $client = static::createClient();
        $this->autenticar($client);

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $c     = $client->getCrawler();
        $caixa = $c->filter('.mm-arranjo > .mm-coluna-lista > .mm-vazio-caixa');
        self::assertCount(1, $caixa);
        self::assertStringStartsWith('Nenhuma meta atribuída a você.', trim($caixa->text()));
        self::assertCount(0, $caixa->filter('i.bi-check2-all'));
        self::assertCount(0, $c->filter('.mm-coluna-lista .mm-vazio'), 'o vazio antigo (com ícone grande) saiu');
        self::assertCount(1, $c->filter('.mm-arranjo > .mm-trilho > .mm-cartao.mm-trilho-andamento'));
    }

    #[TestDox('TRI-14: sem atrasadas o cartão "Precisa de atenção" continua no trilho, com um texto curto')]
    public function testPrecisaDeAtencaoSempreVisivel(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Sem pressa');

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();
        self::assertCount(1, $c->filter('.mm-arranjo > .mm-trilho > .mm-cartao.mm-trilho-atencao > .mm-cartao-cabeca > h2.mm-cartao-titulo'));
        self::assertSame('Nada pedindo atenção.', trim($c->filter('.mm-trilho > .mm-trilho-atencao > .mm-atencao-vazio')->text()));
        self::assertCount(0, $c->filter('.mm-trilho-atencao > .mm-trilho-rodape'));
    }

    #[TestDox('TRI-09: cada item de "Precisa de atenção" é um link de largura inteira para a página da META (decisão do dono de 14/10)')]
    public function testItemDeAtencaoAbreAMeta(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $atrasada           = $this->criarMeta($tenant, $usuario, 'Atrasada');
        $atrasada->setPrazo(new \DateTimeImmutable('-3 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas');
        $c    = $client->getCrawler();
        $item = $c->filter('.mm-trilho > .mm-cartao.mm-trilho-atencao > a.mm-atencao-item');
        self::assertCount(1, $item);
        self::assertSame('/tarefas/' . $atrasada->getId(), $item->attr('href'));
        self::assertCount(1, $item->filter('a.mm-atencao-item > i.mm-atencao-icone + .mm-atencao-texto > .mm-atencao-titulo + .mm-atencao-meta'));
        self::assertCount(0, $item->filter('a.mm-atencao-item a'), 'sem link dentro de link');
        self::assertCount(0, $c->filter('.mm-trilho-atencao > .mm-atencao-vazio'));
    }

    #[TestDox('CAB-01/04 e FIL-04: H1 sem ícone ao lado de "Atualizado às", subtítulo e placeholder da busca do desenho')]
    public function testCabecalhoEBuscaComoODesenho(): void
    {
        $client = static::createClient();
        $this->autenticar($client);

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();

        $h1 = $c->filter('.mm-cabecalho > .mm-cabecalho-linha > h1.mm-titulo');
        self::assertCount(1, $h1);
        self::assertSame('Minhas Metas', trim($h1->text()));
        self::assertCount(0, $h1->filter('i'), 'o H1 do desenho não tem ícone');
        self::assertCount(1, $c->filter('.mm-cabecalho > .mm-cabecalho-linha > h1.mm-titulo + .mm-atualizado'));
        self::assertSame(
            'O que está com você, o que você delegou e o que você acompanha.',
            trim($c->filter('.mm-cabecalho > p.mm-subtitulo')->text()),
        );
        self::assertSame('Buscar por título, pasta ou cliente', $c->filter('.mm-barra-cartao > form[data-filtro-form] .filtro-busca > input.js-filtro-busca')->attr('placeholder'));
    }

    #[TestDox('FIL-04: a busca da tela acha a meta pelo nº da pasta e pelo cliente sem acento')]
    public function testBuscaPorPastaECliente(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $alvo               = $this->criarMeta($tenant, $usuario, 'Juntar procuração');
        $alvo->getPasta()->setNomeCliente('José do Egito');
        $this->criarMeta($tenant, $usuario, 'Outra meta');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'jose do egito']);
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Juntar procuração', $corpo);
        self::assertStringNotContainsString('Outra meta', $corpo);

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => $alvo->getPasta()->getNup()]);
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Juntar procuração', $corpo);
        self::assertStringNotContainsString('Outra meta', $corpo);
    }

    #[TestDox('"Precisa de atenção" (dc `atencao`) traz as atrasadas de todas as metas: na aba Criei a delegada atrasada aparece, "de <criador>" como em qualquer aba, e a legenda conta 1')]
    public function testAtencaoNaAbaCrieiPelaRegra(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);
        $atrasada           = $this->criarMetaDelegada($tenant, $usuario, $colega, 'Delegada atrasada');
        $atrasada->setPrazo(new \DateTimeImmutable('-2 days'));
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Delegada folgada')->setPrazo(new \DateTimeImmutable('+20 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas?aba=criei');
        self::assertResponseIsSuccessful();
        $c     = $client->getCrawler();
        $itens = $c->filter('.mm-trilho > .mm-cartao.mm-trilho-atencao > a.mm-atencao-item');
        self::assertCount(1, $itens);
        self::assertSame('/tarefas/' . $atrasada->getId(), $itens->attr('href'));
        self::assertSame('Delegada atrasada · Pasta ' . $atrasada->getPasta()->getNup(), trim(preg_replace('/\s+/', ' ', $itens->filter('.mm-atencao-titulo')->text()) ?? ''));
        self::assertStringEndsWith('· de Usuário Metas', trim(preg_replace('/\s+/', ' ', $itens->filter('.mm-atencao-meta')->text()) ?? ''), 'dc P:178: sempre "de <criador>", também na aba Criei');
        $client->request('GET', '/tarefas/minhas?aba=responsavel');
        self::assertStringEndsWith('· de Usuário Metas', trim(preg_replace('/\s+/', ' ', $client->getCrawler()->filter('.mm-trilho > .mm-cartao.mm-trilho-atencao > a.mm-atencao-item .mm-atencao-meta')->text()) ?? ''), 'o mesmo item em outra aba');
        self::assertCount(0, $c->filter('.mm-trilho-atencao > .mm-atencao-vazio'));
        self::assertContains('1 atrasada(s)', $c->filter('.mm-trilho-andamento .mm-fatias > .mm-fatia')->each(static fn ($f): string => trim(preg_replace('/\s+/', ' ', $f->text()) ?? '')));
    }

    #[TestDox('"Precisa de atenção" é o mesmo em qualquer aba: atrasadas de todas as metas, da que venceu primeiro, e a em revisão vencida fica de fora')]
    public function testAtencaoNasOutrasAbasNaoMuda(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Venceu ontem')->setPrazo(new \DateTimeImmutable('-1 day'));
        $this->criarMeta($tenant, $usuario, 'Venceu faz tempo')->setPrazo(new \DateTimeImmutable('-9 days'));
        $this->criarMeta($tenant, $usuario, 'Em revisão vencida', Tarefa::STATUS_EM_REVISAO)->setPrazo(new \DateTimeImmutable('-3 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach (['responsavel', 'todas', 'criei', 'acompanhando'] as $aba) {
            $client->request('GET', '/tarefas/minhas?aba=' . $aba);
            self::assertResponseIsSuccessful();
            self::assertSame(
                ['Venceu faz tempo', 'Venceu ontem'],
                $client->getCrawler()->filter('.mm-trilho-atencao > a.mm-atencao-item .mm-atencao-titulo')->each(static fn ($t): string => trim((string) preg_replace('/ · Pasta .*$/', '', trim($t->text())))),
                $aba,
            );
        }
    }

    #[TestDox('Desenho 04 (P:119-127, P:178): "Precisa de atenção" mostra no máximo 4, sem "Ver todas" e sem a frase de rodapé')]
    public function testAtencaoNoMaximoQuatroSemLink(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        foreach ([1, 2, 3, 4, 5, 6] as $dias) {
            $this->criarMeta($tenant, $usuario, 'Venceu há ' . $dias)->setPrazo(new \DateTimeImmutable('-' . $dias . ' days'));
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $c      = $client->getCrawler();
        $cartao = $c->filter('.mm-arranjo > .mm-trilho > .mm-cartao.mm-trilho-atencao');

        self::assertSame(
            ['Venceu há 6', 'Venceu há 5', 'Venceu há 4', 'Venceu há 3'],
            $cartao->filter('.mm-trilho-atencao > a.mm-atencao-item > .mm-atencao-texto > .mm-atencao-titulo')->each(static fn ($t): string => trim((string) preg_replace('/ · Pasta .*$/', '', trim($t->text())))),
        );
        self::assertCount(0, $cartao->filter('.mm-trilho-rodape'));
        // O cabeçalho do layout tem "Ver todas as notificações": a conferência é só no trilho.
        $trilho = $c->filter('.mm-arranjo > .mm-trilho')->text();
        self::assertStringNotContainsString('Ver todas', $trilho);
        self::assertStringNotContainsString('continuam contando dias', $trilho);
        self::assertCount(0, $cartao->filter('a[href*="prazo=vencidas"]'), 'sem link para a lista das atrasadas');
    }

    #[TestDox('Desenho 04 (P:78-79): o select de prazo diz "Atrasadas", "Vencem em 7 dias" e "Sem prazo" (valores de sempre) e o botão de limpar diz "Limpar filtros"')]
    public function testRotulosDoFiltroDePrazoELimpar(): void
    {
        $client = static::createClient();
        $this->autenticar($client);

        $client->request('GET', '/tarefas/minhas?prazo=vencidas');
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();

        $opcoes = $c->filter('[data-filtro-root] > .mm-barra-cartao > form[data-filtro-form] > .filtro-linha > select[name="prazo"] > option');
        self::assertSame(
            [['', 'Prazo'], ['vencidas', 'Atrasadas'], ['proximas', 'Vencem em 7 dias'], ['sem', 'Sem prazo']],
            $opcoes->each(static fn ($o): array => [(string) $o->attr('value'), trim($o->text())]),
        );
        self::assertSame('vencidas', $c->filter('select[name="prazo"] > option[selected]')->attr('value'), 'o KPI continua abrindo a faceta pelo valor de sempre');
        self::assertSame('Limpar filtros', $c->filter('[data-filtro-root]')->attr('data-ft-rotulo-limpar'));
        self::assertStringNotContainsString('Próximas (7 dias)', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Desenho 04 (P:77): a faceta Prioridade é da META (Normal, Alta, Urgente); o valor antigo da pasta é ignorado; o cartão não mostra a prioridade da pasta')]
    public function testFacetaPrioridadeDaMeta(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $urgente            = $this->criarMeta($tenant, $usuario, 'Urgente da meta');
        $urgente->setPrioridade(PrioridadeDaMeta::Urgente);
        $normalNaPastaUrgente = $this->criarMeta($tenant, $usuario, 'Normal em pasta urgente');
        $normalNaPastaUrgente->getPasta()->setPrioridade(PrioridadePasta::Urgente);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas', ['prioridade' => 'urgente']);
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();
        self::assertSame(
            [['', 'Prioridade'], ['normal', 'Normal'], ['alta', 'Alta'], ['urgente', 'Urgente']],
            $c->filter('select[name="prioridade"] > option')->each(static fn ($o): array => [(string) $o->attr('value'), trim($o->text())]),
        );
        self::assertSame(['Urgente da meta'], $c->filter('.mm-coluna-lista a.mm-meta-titulo')->each(static fn ($a): string => trim((string) $a->attr('title'))));

        $client->request('GET', '/tarefas/minhas', ['prioridade' => 'prioridade']);
        $c = $client->getCrawler();
        self::assertCount(2, $c->filter('.mm-coluna-lista article.mm-meta'), 'o valor antigo não filtra');
        self::assertCount(0, $c->filter('select[name="prioridade"] > option[selected][value="prioridade"]'));
        self::assertCount(0, $c->filter('.mm-prioridade'), 'a pílula da prioridade da pasta saiu do cartão');
    }

    #[TestDox('Desenho 04 (P:69-80, P:174): a barra tem só Prioridade e Prazo, sem o filtro Status; `status` na URL é ignorado')]
    public function testBarraSemFiltroStatus(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Aberta comigo');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas', ['status' => 'concluida']);
        self::assertResponseIsSuccessful();
        $c = $client->getCrawler();

        self::assertSame(
            ['prioridade', 'prazo'],
            $c->filter('[data-filtro-root] > .mm-barra-cartao > form[data-filtro-form] > .filtro-linha > select')->each(static fn ($s): string => (string) $s->attr('name')),
        );
        self::assertCount(1, $c->filter('.mm-coluna-lista article.mm-meta'), 'a meta aberta continua na lista: o status da URL não filtra');
        self::assertCount(0, $c->filter('.mm-vazio-caixa'), 'nem conta como filtro ativo');
    }

    #[TestDox('Isolamento: meta ATRASADA do escritório B com o usuário de A como responsável não entra no "X de Y", na legenda nem no "Precisa de atenção" de A')]
    public function testTrilhoNaoContaMetaDeOutroEscritorio(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $em                 = static::getContainer()->get(EntityManagerInterface::class);
        $this->criarMeta($tenant, $usuario, 'Minha atrasada')->setPrazo(new \DateTimeImmutable('-2 days'));

        $outro = new Tenant();
        $outro->setName('Escritório B ' . uniqid());
        $em->persist($outro);
        $em->flush();
        // O vínculo que atravessa escritórios: a meta é de B, mas o responsável é o usuário de A.
        $deB = $this->criarMeta($outro, $usuario, 'Atrasada do escritório B');
        $deB->setPrazo(new \DateTimeImmutable('-9 days'));
        $em->flush();
        $em->clear();
        $gravada = $em->find(Tarefa::class, $deB->getId());
        self::assertSame($outro->getId(), $gravada?->getTenant()?->getId(), 'premissa: a meta é mesmo do escritório B');
        self::assertTrue($gravada->getResponsaveis()->exists(static fn ($k, User $u): bool => $u->getId() === $usuario->getId()), 'premissa: o usuário de A é responsável');

        foreach (['todas', 'responsavel'] as $aba) {
            $client->request('GET', '/tarefas/minhas?aba=' . $aba);
            self::assertResponseIsSuccessful();
            $c = $client->getCrawler();
            $this->assertAndamento($c, '0 de 1', ['0 concluída(s)', '1 atrasada(s)', '0 em revisão'], $aba);
            self::assertSame(
                ['Minha atrasada'],
                $c->filter('.mm-trilho > .mm-cartao.mm-trilho-atencao > a.mm-atencao-item .mm-atencao-titulo')->each(static fn ($t): string => trim((string) preg_replace('/ · Pasta .*$/', '', trim($t->text())))),
                $aba,
            );
            self::assertStringNotContainsString('Atrasada do escritório B', (string) $client->getResponse()->getContent(), $aba);
        }
    }

    private function criarColega(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $colega = new User();
        $colega->setEmail('colega_' . uniqid() . '@test.com');
        $colega->setFullName('Colega de Escritório');
        $colega->setRoles(['ROLE_USER']);
        $colega->setIsActive(true);
        $colega->setPassword($hasher->hashPassword($colega, 'senha123'));
        $em->persist($colega);

        $vinculo = new UserTenant($colega, $tenant);
        $em->persist($vinculo);
        $em->flush();

        return $colega;
    }

    /** Meta que o usuário logado CRIOU para outra pessoa — o caso que poluía a lista dele. */
    private function criarMetaDelegada(Tenant $tenant, User $criador, User $responsavel, string $titulo): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('DEL-' . uniqid());
        $pasta->setNomeCliente('Cliente Delegada');
        $pasta->setResponsavel($criador);
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('Descrição da meta delegada');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($criador);
        $tarefa->addResponsavel($responsavel);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    /**
     * O marcador é um alternador que muda o conteúdo de OUTRA aba. Sem a contagem nova na
     * resposta, o badge de "Em acompanhamento" só mudava com F5 — o usuário marcava, via o
     * ícone pintar e a aba continuar em zero. Relatado pelo dono no smoke de 14/09.
     */
    #[TestDox('Acompanhar devolve as contagens novas das abas, para a tela não precisar de F5')]
    public function testAcompanharDevolveContagemAtualizada(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $meta = $this->criarMetaDelegada($tenant, $usuario, $colega, 'Vou acompanhar');
        $id   = (int) $meta->getId();

        $client->request('GET', '/tarefas/minhas?aba=criei');
        $token = $client->getCrawler()->filter('[data-acompanhar]')->first()->attr('data-token');

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $token]);
        $marcou = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($marcou['acompanhando']);
        self::assertSame(1, $marcou['contagens']['acompanhando'], 'A aba "Em acompanhamento" tem de vir com 1 na mesma resposta.');

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $token]);
        $desmarcou = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertFalse($desmarcou['acompanhando']);
        self::assertSame(0, $desmarcou['contagens']['acompanhando'], 'Desmarcar devolve a aba a zero, sem recarregar.');
    }

    #[TestDox('A resposta traz as quatro abas, para o badge de cada uma poder ser atualizado')]
    public function testAcompanharDevolveAsQuatroAbas(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Uma meta');
        $id                 = (int) $meta->getId();

        $client->request('GET', '/tarefas/minhas');
        $token = $client->getCrawler()->filter('[data-acompanhar]')->first()->attr('data-token');

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $token]);
        $contagens = json_decode((string) $client->getResponse()->getContent(), true)['contagens'];

        self::assertSame(
            ['responsavel', 'criei', 'acompanhando', 'todas'],
            array_keys($contagens),
            'O JS atualiza os badges pela chave da aba; faltar uma deixaria o número velho na tela.',
        );
    }
}
