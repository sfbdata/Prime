<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Notificacao;
use App\Entity\Permission\ResourceAccess;
use App\Pasta\Controller\PastaCadeadoController;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\EstadoDoPedidoDeAcesso;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use App\Pasta\EventListener\BarreiraDoCadeadoListener;
use App\Service\PermissionChecker;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O cadeado pela tela e pelo servidor (spec pasta-cadeado §7): `pasta_show` em cada nível, escrita
 * recusada em Visualizar, 403 nas sub-rotas em Oculto, o AccessRequest aprovado que não fura o cadeado,
 * o pedido (duplicado e janela de 24 h) e as notificações, e o modal de quem configura.
 */
#[CoversClass(PastaCadeadoController::class)]
#[CoversClass(BarreiraDoCadeadoListener::class)]
final class PastaCadeadoControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    // ── pasta_show em cada nível ───────────────────────────────────────

    #[TestDox('Oculto: só o cabeçalho com o cliente e a tela "Pasta trancada", sem abas nem menu ⋮')]
    public function testShowOculto(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant, 'JOANA SIGILOSA');
        $pasta->setNomeAcao('AÇÃO SIGILOSA DA PASTA');
        $this->em()->persist(new \App\Pasta\Entity\PastaReu($tenant, $pasta, 'RÉU SIGILOSO LTDA', null, 0));
        $processo = new \App\Processo\Entity\Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso('0799999-88.2026.8.07.0001');
        $this->em()->persist($processo);
        $pasta->vincularProcesso($processo);
        $this->em()->flush();
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['AÇÃO SIGILOSA', 'RÉU SIGILOSO', '0799999-88', '07999998820268070001'] as $segredo) {
            self::assertStringNotContainsString($segredo, $html, 'a tela trancada não mostra ' . $segredo);
        }

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('JOANA SIGILOSA', $c->filter('h1.ps-cab-titulo')->text());
        self::assertStringContainsString('Pasta trancada', $c->text());
        self::assertStringContainsString('O usuário primário restringiu o acesso a esta pasta. Você vê apenas o nome do cliente e o CPF.', $c->text());
        self::assertCount(1, $c->filter('form[action$="/acesso/pedidos"] textarea[name="motivo"][placeholder="Motivo (opcional)"]'));
        self::assertSame('Pedir acesso ao primário', trim($c->filter('form[action$="/acesso/pedidos"] button[type="submit"]')->text()));
        self::assertCount(0, $c->filter('#psMenuAcoes'), 'sem menu ⋮');
        self::assertCount(0, $c->filter('#pastaTabs, .ps-abas'), 'sem abas');
    }

    #[TestDox('quem não veria a pasta nem sem o cadeado segue o caminho antigo (redirect do pedido genérico), sem a tela trancada')]
    public function testShowSemPermissaoDeVerNaoMostraATelaTrancada(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $semPerfil = $this->criarColaborador($tenant, 'Sem Perfil', ['modules.expediente.view']);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario);
        $this->entrar($semPerfil, $tenant);

        $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseRedirects();
        self::assertStringContainsString('requestAccess=1', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Oculto: as filhas por id também são recusadas (download de documento, a meta e o anexo da resposta da meta)')]
    public function testFilhasPorIdEmOculto(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Equipe', ['modules.expediente.view', 'resources.pasta.view', 'modules.tarefas.view']);
        $pasta = $this->criarPasta($tenant);
        $doc = new \App\Pasta\Entity\PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo('Contrato');
        $doc->setCategoria(\App\Pasta\Entity\PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('nao_existe_' . uniqid() . '.pdf');
        $doc->setNomeOriginal('Contrato.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $this->em()->persist($doc);
        $meta = new \App\Entity\Tarefa\Tarefa();
        $meta->setTitulo('Meta');
        $meta->setDescricao('Descrição');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($primario);
        $meta->addResponsavel($ana);
        $this->em()->persist($meta);
        $resposta = new \App\Entity\Tarefa\TarefaMensagem();
        $resposta->setTarefa($meta);
        $resposta->setTenant($tenant);
        $resposta->setUsuario($primario);
        $resposta->setMensagem('Anexo');
        $resposta->setArquivoAnexo('nao_existe.pdf');
        $this->em()->persist($resposta);
        $this->em()->flush();
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);

        foreach (['/pasta/documento/' . $doc->getId() . '/download', '/tarefas/' . $meta->getId(), '/tarefas/mensagem/' . $resposta->getId() . '/arquivo/download'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    #[TestDox('Visualizar: a pasta abre, com a faixa de somente visualização e sem as ações de escrita')]
    public function testShowVisualizar(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($ana, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pasta trancada pelo usuário primário: você pode visualizar, mas não alterar.', $c->text());
        self::assertCount(1, $c->filter('header.ps-cabecalho > div[role="status"] button[data-cad-pedir="1"]'), 'a faixa fica dentro do cabeçalho, logo abaixo do cliente');
        self::assertCount(1, $c->filter('button[data-cad-pedir="1"]'));
        self::assertCount(1, $c->filter('#psMenuAcoes'));
        self::assertCount(0, $c->filter('#psMenuAcoes [data-bs-target="#modalEditarPasta"]'), 'sem "Editar dados"');
        self::assertCount(0, $c->filter('.js-cadeado-abrir'), 'o cadeado é só de quem configura');
    }

    #[TestDox('Editar (na lista do cadeado): a pasta abre normalmente, sem faixa')]
    public function testShowEditar(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Editar]]);
        $this->entrar($ana, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('você pode visualizar, mas não alterar', $c->text());
        self::assertCount(1, $c->filter('#psMenuAcoes [data-bs-target="#modalEditarPasta"]'));
    }

    #[TestDox('quem configura vê o item "Cadeado" no ⋮ com o selo, o selo grande e o modal com a grade da equipe')]
    public function testShowPrimario(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($primario, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('Acesso · Primário', trim($c->filter('#psMenuAcoes .ps-pop-secao')->eq(1)->text()));
        self::assertSame('Restrito', trim($c->filter('#psMenuAcoes .js-cadeado-abrir .js-cadeado-selo')->text()));
        self::assertCount(1, $c->filter('.ps-cab-palco > div.js-cadeado-abrir'), 'o selo grande no andamento');
        $modal = $c->filter('#psCadeadoModal');
        self::assertStringContainsString('Cadeado da pasta ' . $pasta->getNup(), $modal->text());
        self::assertSame('visualizar', $modal->filter('.js-cadeado-pessoa[data-id="' . $ana->getId() . '"]')->attr('data-nivel'));
        self::assertCount(1, $modal->filter('form[action$="/cadeado/remover"]'), 'com cadeado, "Remover cadeado"');
    }

    #[TestDox('pasta livre: o modal começa com todos em Editar (desenho, cdAbrir) e sem "Remover cadeado"')]
    public function testModalDePastaLivre(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->entrar($primario, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertSame('Livre', trim($c->filter('#psMenuAcoes .js-cadeado-selo')->text()));
        self::assertSame('editar', $c->filter('#psCadeadoModal .js-cadeado-pessoa[data-id="' . $ana->getId() . '"]')->attr('data-nivel'));
        self::assertCount(0, $c->filter('#psCadeadoModal form[action$="/cadeado/remover"]'));
        self::assertCount(0, $c->filter('#psCadeadoModal .js-cadeado-pessoa[data-id="' . $primario->getId() . '"]'), 'o primário não está na grade');
    }

    // ── Barreira de rota ───────────────────────────────────────────────

    #[TestDox('Visualizar: escrita recusada no servidor (403 JSON), e a pasta não muda')]
    public function testEscritaRecusadaEmVisualizar(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($ana, $tenant);

        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/prioridade', ['prioridade' => 'urgente', '_token' => 'TOKEN_pasta_prioridade_' . $pasta->getId()]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Somente visualização: o usuário primário trancou esta pasta.', json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem']);
        $this->em()->clear();
        self::assertSame('normal', $this->em()->find(\App\Pasta\Entity\Pasta::class, $pasta->getId())->getPrioridade()->value);
    }

    #[TestDox('Visualizar: preferência pessoal (fixar nos favoritos) continua valendo')]
    public function testPreferenciaPessoalEmVisualizar(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($ana, $tenant);

        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/favorito', ['_token' => 'TOKEN_pasta_favorito_' . $pasta->getId()]);

        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function subRotas(): iterable
    {
        yield 'timeline (XHR)' => ['/timeline', true];
        yield 'lixeira de documentos (XHR)' => ['/documentos/lixeira', true];
        yield 'resumo para imprimir (navegação)' => ['/resumo/imprimir', false];
        yield 'painel da IA (XHR)' => ['/ia/agentes', true];
    }

    #[DataProvider('subRotas')]
    #[TestDox('Oculto: 403 nas sub-rotas da pasta')]
    public function testSubRotasEmOculto(string $subRota, bool $xhr): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);

        $xhr
            ? $this->client->xmlHttpRequest('GET', '/pasta/' . $pasta->getId() . $subRota)
            : $this->client->request('GET', '/pasta/' . $pasta->getId() . $subRota);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('o AccessRequest aprovado (ResourceAccess com ver e editar) não fura o cadeado')]
    public function testAccessRequestAprovadoNaoFuraOCadeado(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Sem Perfil', ['modules.expediente.view']);
        $pasta = $this->criarPasta($tenant);
        $concedido = new ResourceAccess();
        $concedido->setUser($ana);
        $concedido->setTenant($tenant);
        $concedido->setResourceType('pasta');
        $concedido->setResourceId((int) $pasta->getId());
        $concedido->setCanView(true);
        $concedido->setCanEdit(true);
        $concedido->setGrantedBy($primario);
        $this->em()->persist($concedido);
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);

        $checker = static::getContainer()->get(PermissionChecker::class);
        self::assertFalse($checker->canAccessResource($ana, $tenant, 'pasta', (int) $pasta->getId(), 'view'));
        self::assertTrue($checker->canAccessResourceIgnoringPastaLock($ana, $tenant, 'pasta', (int) $pasta->getId(), 'view'), 'sem o cadeado o grant valeria');

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pasta trancada', $c->text());
        self::assertCount(0, $c->filter('#psMenuAcoes'));
    }

    #[TestDox('pasta de outro escritório passa reto pela barreira: a action responde o 404 dela')]
    public function testOutroEscritorio(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        [$outroPrimario, $outro] = $this->criarEscritorio();
        $dele = $this->criarPasta($outro);
        $this->trancar($dele, $outroPrimario);
        $this->entrar($primario, $tenant);
        // Requisição nova de verdade: sem o mapa de identidade do teste, o TenantFilter vale para o find().
        $this->em()->clear();

        $this->client->request('GET', '/pasta/' . $dele->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->xmlHttpRequest('POST', '/pasta/' . $dele->getId() . '/cadeado/remover', ['_token' => 'TOKEN_pasta_cadeado_' . $dele->getId()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cadeado WHERE pasta_id = ?', [$dele->getId()]));
    }

    // ── Configurar ─────────────────────────────────────────────────────

    #[TestDox('o primário tranca por pessoa pelo formulário do modal; quem não configura recebe 403')]
    public function testConfigurar(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $bia   = $this->criarColaborador($tenant, 'Bia Equipe');
        $pasta = $this->criarPasta($tenant);

        $this->entrar($bia, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/cadeado', ['modo' => 'todos', '_token' => 'TOKEN_pasta_cadeado_' . $pasta->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->entrar($primario, $tenant);
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/cadeado', [
            'modo'   => 'por_pessoa',
            'niveis' => [(string) $ana->getId() => 'visualizar', (string) $bia->getId() => 'oculto'],
            '_token' => 'TOKEN_pasta_cadeado_' . $pasta->getId(),
        ]);
        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $this->em()->clear();
        $cadeado = $this->em()->getRepository(CadeadoDaPasta::class)->findOneBy(['pasta' => $pasta->getId()]);
        self::assertSame(ModoDoCadeado::PorPessoa, $cadeado->getModo());
        self::assertCount(1, $cadeado->getAcessos());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'cadeado' AND conteudo LIKE '%Cadeado ativado (0 editam, 1 visualizam, 1 ocultos)%'",
            [$pasta->getId()],
        ), 'histórico da pasta');
        self::assertGreaterThan(0, (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_class = ? AND tenant_id = ?",
            [CadeadoDaPasta::class, $tenant->getId()],
        ), 'auditoria da conta');
    }

    // ── Pedido e decisão ───────────────────────────────────────────────

    #[TestDox('pedir pela tela trancada: grava, avisa o primário e a tela passa a "Pedido enviado às HH:MM"; o segundo pedido no mesmo dia é recusado')]
    public function testPedirEJanela(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $bia   = $this->criarColaborador($tenant, 'Bia Equipe');
        $socio = $this->novoUsuario('Sócio Primário');
        $this->vincular($socio, $tenant, true, []);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos', ['nivel' => 'editar', 'motivo' => 'preciso do contrato', '_token' => 'TOKEN_pasta_acesso_' . $pasta->getId()]);
        $destinatarios = array_map('intval', $this->em()->getConnection()->fetchFirstColumn("SELECT usuario_id FROM notificacao WHERE tipo = 'pasta_pedido_acesso' AND tenant_id = ? ORDER BY usuario_id", [$tenant->getId()]));
        $esperados = [(int) $primario->getId(), (int) $socio->getId()];
        sort($esperados);
        self::assertSame($esperados, $destinatarios, 'os primários ativos, e não a colega nem quem pediu');
        self::assertNotContains((int) $bia->getId(), $destinatarios);
        self::assertResponseRedirects('/pasta/' . $pasta->getId());
        $c = $this->client->followRedirect();
        self::assertStringContainsString('Aguardando o usuário primário.', $c->text());
        self::assertCount(0, $c->filter('form[action$="/acesso/pedidos"]'), 'sem novo pedido enquanto aguarda');

        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $primario, 'tipo' => Notificacao::TIPO_PASTA_PEDIDO_ACESSO]);
        self::assertCount(1, $avisos);
        self::assertStringEndsWith('Motivo: "preciso do contrato" A pasta está trancada por você. Quer liberar?', (string) $avisos[0]->getMensagem());
        self::assertStringContainsString('para editar', $avisos[0]->getTitulo());
        self::assertStringContainsString('Motivo: "preciso do contrato"', (string) $avisos[0]->getMensagem());

        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos', ['nivel' => 'editar', '_token' => 'TOKEN_pasta_acesso_' . $pasta->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_pedido_acesso WHERE pasta_id = ?', [$pasta->getId()]));
    }

    #[TestDox('o primário libera para editar pela faixa da pasta: Todos vira PorPessoa e quem pediu é avisado e entra')]
    public function testDecidir(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos', ['nivel' => 'editar', '_token' => 'TOKEN_pasta_acesso_' . $pasta->getId()]);
        $pedidoId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->entrar($primario, $tenant);
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertStringContainsString('Ana Equipe pediu acesso à Pasta ' . $pasta->getNup() . ' (MARIA DA SILVA) às ', $c->filter('#cadeado-pedidos')->text());
        self::assertStringContainsString(', para editar. A pasta está trancada por você. Quer liberar?', $c->filter('#cadeado-pedidos')->text());
        self::assertCount(1, $c->filter('header.ps-cabecalho > #cadeado-pedidos'), 'a faixa fica dentro do cabeçalho');
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos/' . $pedidoId . '/decidir', ['decisao' => 'editar', '_token' => 'TOKEN_pasta_cadeado_' . $pasta->getId()]);
        self::assertResponseRedirects('/pasta/' . $pasta->getId());

        $this->em()->clear();
        self::assertSame(EstadoDoPedidoDeAcesso::LiberadoEditar, $this->em()->find(PedidoDeAcessoAPasta::class, $pedidoId)->getEstado());
        $avisos = $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $ana, 'tipo' => Notificacao::TIPO_PASTA_ACESSO_DECIDIDO]);
        self::assertCount(1, $avisos);
        self::assertSame('O usuário primário liberou a Pasta ' . $pasta->getNup() . ' para você editar.', $avisos[0]->getTitulo());

        $this->entrar($ana, $tenant);
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertStringNotContainsString('Pasta trancada', $c->filter('.ps-page')->text());
        self::assertCount(1, $c->filter('#psMenuAcoes'));
    }

    #[TestDox('a decisão é um endpoint JSON, para outro front (o chat) chamar o mesmo; a faixa dos pedidos é um parcial próprio')]
    public function testDecidirPorJson(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos', ['nivel' => 'visualizar', '_token' => 'TOKEN_pasta_acesso_' . $pasta->getId()]);
        $pedidoId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->entrar($primario, $tenant);
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(3, $c->filter('#cadeado-pedidos[data-cadeado-pedidos] .js-cadeado-pedido form.js-cadeado-decidir'));
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos/' . $pedidoId . '/decidir', ['decisao' => 'visualizar', '_token' => 'TOKEN_pasta_cadeado_' . $pasta->getId()]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('ok', $json['status']);
        self::assertSame('Restrito', $json['selo']);
        self::assertSame('Pasta ' . $pasta->getNup() . ' liberada para Ana', $json['mensagem']);

        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos/' . $pedidoId . '/decidir', ['decisao' => 'recusar', '_token' => 'TOKEN_pasta_cadeado_' . $pasta->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Este pedido já foi atendido.', json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem']);
    }

    #[TestDox('recusado: a tela trancada diz a partir de quando pode pedir de novo, e não oferece o pedido antes disso')]
    public function testRecusadoMostraAData(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos', ['nivel' => 'editar', '_token' => 'TOKEN_pasta_acesso_' . $pasta->getId()]);
        $pedidoId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        $this->entrar($primario, $tenant);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acesso/pedidos/' . $pedidoId . '/decidir', ['decisao' => 'recusar', '_token' => 'TOKEN_pasta_cadeado_' . $pasta->getId()]);

        $this->entrar($ana, $tenant);
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertMatchesRegularExpression('/O usuário primário não liberou o pedido anterior\. Você pode pedir de novo a partir de \d{2}\/\d{2} às \d{2}:\d{2}\./u', $c->text());
        self::assertCount(0, $c->filter('form[action$="/acesso/pedidos"]'));
    }
}
