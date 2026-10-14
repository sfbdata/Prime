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
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

    #[TestDox('XHR com busca sem correspondência mostra o estado vazio filtrado')]
    public function testXhrBuscaSemResultado(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'zzz-inexistente-zzz']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhuma meta encontrada', (string) $client->getResponse()->getContent());
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
        self::assertCount(1, $crawler->filter('.mm-arranjo > .mm-coluna-lista > .mm-cartao'), 'A lista tem de ser a outra coluna do mesmo arranjo.');
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
        $client->getCrawler()->filter('.mm-cartao article.mm-meta .mm-meta-topo > .mm-meta-titulo')->each(
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

        $cartoes = $pilulas('/tarefas/minhas', '.mm-cartao article.mm-meta', '.mm-meta-titulo');
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
        $client->getCrawler()->filter('.mm-cartao article.mm-meta')->each(static function ($l) use (&$achado): void {
            $t = mb_strtoupper(trim(preg_replace('/^\d+\.\s*/', '', $l->filter('.mm-meta-titulo')->text()) ?? ''));
            $p = $l->filter('.mm-meta-linha2 > .mm-prazo');
            $achado[$t] = [trim(preg_replace('/\s+/', ' ', $p->text()) ?? ''), str_contains((string) $p->attr('class'), 'is-atrasado')];
        });

        foreach ($esperado as $titulo => [, , $texto, $atrasado]) {
            self::assertSame([$texto, $atrasado], $achado[$titulo] ?? null, $titulo);
        }
    }

    #[TestDox('E5c (R118/R236): ⋮ "Opções" no lugar da seta, só com ações que já existem — relatório completo, aba Metas da pasta com a meta realçada e Editar nome; "Encaminhar via Chat I.A" não aparece (sem backend)')]
    public function testMenuDaMetaComAcoesQueJaExistem(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Meta com menu');
        $id                 = $meta->getId();
        $pastaId            = $meta->getPasta()->getId();

        $client->request('GET', '/tarefas/minhas');
        self::assertResponseIsSuccessful();
        $acoes = $client->getCrawler()->filter('.mm-cartao article.mm-meta > .mm-meta-acoes');
        self::assertCount(1, $acoes);

        self::assertCount(0, $acoes->filter('.bi-chevron-right'), 'a seta "Abrir a meta" saiu');
        $botao = $acoes->filter('.mm-menu > button.mm-acao-icone[data-bs-toggle="dropdown"]');
        self::assertCount(1, $botao);
        self::assertSame('Opções', $botao->attr('title'));
        self::assertCount(1, $botao->filter('i.bi-three-dots-vertical'));
        self::assertSame('false', $botao->attr('aria-expanded'));

        $itens = $acoes->filter('.mm-menu > .dropdown-menu .mm-menu-item');
        self::assertSame(
            ['Abrir relatório completo', 'Ir para a aba Metas da pasta', 'Editar nome'],
            $itens->each(static fn ($i): string => trim($i->text())),
        );
        self::assertSame(
            [
                '/tarefas/' . $id,
                '/pasta/' . $pastaId . '?meta=' . $id . '#tarefas',
                '/pasta/' . $pastaId . '?meta=' . $id . '&acao=renomear#tarefas',
            ],
            $itens->each(static fn ($i): string => (string) $i->attr('href')),
        );
        self::assertStringNotContainsString('Encaminhar', $acoes->text());
    }

    #[TestDox('E5c: meta de pasta excluída (somente leitura) não oferece "Editar nome" no ⋮ — mesma regra da aba Metas da pasta')]
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
        $menus = $client->getCrawler()->filter('.mm-cartao article.mm-meta .mm-menu');
        self::assertCount(1, $menus, 'a meta da pasta excluída aparece na aba Todas');
        self::assertSame(
            ['Abrir relatório completo', 'Ir para a aba Metas da pasta'],
            $menus->filter('.mm-menu-item')->each(static fn ($i): string => trim($i->text())),
        );
    }

    #[TestDox('E5c (dc L104-118): trilho com "X de Y", legenda de três itens com "(s)" e "Título · Pasta NNN" no "Precisa de atenção"')]
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
            'Atrasada · Pasta ' . $atrasada->getPasta()->getNup(),
            trim(preg_replace('/\s+/', ' ', $c->filter('.mm-trilho-atencao .mm-atencao-titulo')->text()) ?? ''),
        );
    }

    #[TestDox('E5c (revisão): na aba Criei — que agrupa por etapa e não tem grupo "Atrasadas" — a legenda conta as delegadas atrasadas, como o desenho')]
    public function testLegendaDaAbaCrieiContaAsAtrasadas(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Delegada atrasada')->setPrazo(new \DateTimeImmutable('-2 days'));
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Delegada folgada')->setPrazo(new \DateTimeImmutable('+20 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas?aba=criei');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['0 concluída(s)', '1 atrasada(s)', '0 em revisão'],
            $client->getCrawler()->filter('.mm-trilho-andamento .mm-fatias > .mm-fatia')->each(static fn ($f): string => trim(preg_replace('/\s+/', ' ', $f->text()) ?? '')),
        );
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
