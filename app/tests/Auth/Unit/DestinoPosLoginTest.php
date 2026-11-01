<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Service\DestinoPosLogin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Com o router de verdade (sem mock): o destino só vale se casar uma rota GET da lista de páginas.
 */
#[CoversClass(DestinoPosLogin::class)]
final class DestinoPosLoginTest extends KernelTestCase
{
    private function sut(): DestinoPosLogin
    {
        self::bootKernel();

        return static::getContainer()->get(DestinoPosLogin::class);
    }

    private function validar(string $destino, string $origem = 'http://localhost/login'): ?string
    {
        return $this->sut()->validar($destino, Request::create($origem));
    }

    /**
     * Um exemplo de cada página da lista: [nome da rota, caminho].
     *
     * @return iterable<string, array{string, string}>
     */
    public static function paginasDaLista(): iterable
    {
        yield 'homepage' => ['homepage', '/inicio'];
        yield 'tenant_selecionar' => ['tenant_selecionar', '/escritorio/selecionar'];
        yield 'dashboard_index' => ['dashboard_index', '/dashboard'];
        yield 'expediente_index' => ['expediente_index', '/expediente'];
        yield 'expediente_relatorio_processos' => ['expediente_relatorio_processos', '/expediente/relatorio-processos'];
        yield 'pasta_show' => ['pasta_show', '/pasta/7'];
        yield 'pasta_peticionar' => ['pasta_peticionar', '/pasta/7/peticionar'];
        yield 'processo_index' => ['processo_index', '/processos/'];
        yield 'processo_show' => ['processo_show', '/processos/7'];
        yield 'processo_new' => ['processo_new', '/processos/novo'];
        yield 'processo_edit' => ['processo_edit', '/processos/7/editar'];
        yield 'push_processual_index' => ['push_processual_index', '/push-processual'];
        yield 'push_processual_show' => ['push_processual_show', '/push-processual/7'];
        yield 'push_processual_oabs' => ['push_processual_oabs', '/push-processual/oabs'];
        yield 'agenda_index' => ['agenda_index', '/agenda'];
        yield 'agenda_nova' => ['agenda_nova', '/agenda/nova'];
        yield 'agenda_lista' => ['agenda_lista', '/agenda/lista'];
        yield 'agenda_show' => ['agenda_show', '/agenda/7'];
        yield 'agenda_novo' => ['agenda_novo', '/agenda/novo'];
        yield 'agenda_editar' => ['agenda_editar', '/agenda/7/editar'];
        yield 'tarefa_minhas' => ['tarefa_minhas', '/tarefas/minhas'];
        yield 'tarefa_equipe' => ['tarefa_equipe', '/tarefas/equipe'];
        yield 'tarefa_show' => ['tarefa_show', '/tarefas/7'];
        yield 'kanban_index' => ['kanban_index', '/kanban'];
        yield 'kanban_board_view' => ['kanban_board_view', '/kanban/7'];
        yield 'demandas_index' => ['demandas_index', '/demandas'];
        yield 'cliente_show' => ['cliente_show', '/clientes/7'];
        yield 'cliente_edit' => ['cliente_edit', '/clientes/7/editar'];
        yield 'cobranca_carteira_index' => ['cobranca_carteira_index', '/cobrancas'];
        yield 'cobranca_carteira_show' => ['cobranca_carteira_show', '/cobrancas/carteiras/7'];
        yield 'cobranca_importacao_upload' => ['cobranca_importacao_upload', '/cobrancas/carteiras/7/importar'];
        yield 'cobranca_caso_index' => ['cobranca_caso_index', '/cobrancas/casos'];
        yield 'cobranca_caso_show' => ['cobranca_caso_show', '/cobrancas/casos/7'];
        yield 'cobranca_objeto_show' => ['cobranca_objeto_show', '/cobrancas/objetos/7'];
        yield 'cobranca_acordo_show' => ['cobranca_acordo_show', '/cobrancas/acordos/7'];
        yield 'cobranca_pessoa_show' => ['cobranca_pessoa_show', '/cobrancas/pessoas/7'];
        yield 'cobranca_dashboard' => ['cobranca_dashboard', '/cobrancas/painel'];
        yield 'cobranca_alertas' => ['cobranca_alertas', '/cobrancas/alertas'];
        yield 'cobranca_central' => ['cobranca_central', '/cobrancas/central'];
        yield 'central_index' => ['central_index', '/central'];
        yield 'notificacao_index' => ['notificacao_index', '/notificacoes'];
        yield 'novidades_index' => ['novidades_index', '/novidades'];
        yield 'trabalho_externo_index' => ['trabalho_externo_index', '/trabalho-externo'];
        yield 'servicedesk_index' => ['servicedesk_index', '/servicedesk'];
        yield 'servicedesk_meus_chamados' => ['servicedesk_meus_chamados', '/servicedesk/meus-chamados'];
        yield 'servicedesk_novo' => ['servicedesk_novo', '/servicedesk/novo'];
        yield 'servicedesk_show' => ['servicedesk_show', '/servicedesk/7'];
        yield 'ponto_index' => ['ponto_index', '/ponto/'];
        yield 'app_profile' => ['app_profile', '/perfil'];
        yield 'auth_meus_convites' => ['auth_meus_convites', '/convites'];
        yield 'contribua_index' => ['contribua_index', '/contribua'];
        yield 'app_sync_drive_conexao' => ['app_sync_drive_conexao', '/sync/drive/conexao'];
        yield 'escritorio_criar' => ['escritorio_criar', '/escritorio/criar'];
        yield 'auth_gerenciar_convites' => ['auth_gerenciar_convites', '/escritorio/convites'];
        yield 'app_tenant_index' => ['app_tenant_index', '/tenant'];
        yield 'app_tenant_new' => ['app_tenant_new', '/tenant/new'];
        yield 'app_tenant_show' => ['app_tenant_show', '/tenant/7'];
        yield 'app_tenant_edit' => ['app_tenant_edit', '/tenant/7/edit'];
        yield 'app_tenant_users' => ['app_tenant_users', '/tenant/7/users'];
        yield 'app_tenant_user_edit_role' => ['app_tenant_user_edit_role', '/tenant/7/user/7/edit-role'];
        yield 'app_tenant_user_ponto_edit' => ['app_tenant_user_ponto_edit', '/tenant/7/user/7/ponto/7/edit'];
        yield 'app_tenant_role_index' => ['app_tenant_role_index', '/tenant/7/roles'];
        yield 'app_tenant_role_new' => ['app_tenant_role_new', '/tenant/7/roles/new'];
        yield 'app_tenant_role_edit' => ['app_tenant_role_edit', '/tenant/7/roles/7/edit'];
        yield 'app_tenant_sedes' => ['app_tenant_sedes', '/tenant/7/sedes'];
        yield 'app_feriado_index' => ['app_feriado_index', '/tenant/7/feriados'];
        yield 'app_feriado_new' => ['app_feriado_new', '/tenant/7/feriados/novo'];
        yield 'app_feriado_edit' => ['app_feriado_edit', '/tenant/7/feriados/7/editar'];
        yield 'audit_log_index' => ['audit_log_index', '/auditoria'];
        yield 'app_access_request_index' => ['app_access_request_index', '/access-requests'];
        yield 'master_painel' => ['master_painel', '/master'];
        yield 'admin_platform_dashboard' => ['admin_platform_dashboard', '/admin/platform'];
        yield 'admin_platform_convite_index' => ['admin_platform_convite_index', '/admin/platform/convites'];
        yield 'admin_platform_oab_index' => ['admin_platform_oab_index', '/admin/platform/oab'];
        yield 'inteligencia_admin_config' => ['inteligencia_admin_config', '/admin/inteligencia'];
        yield 'auth_suporte_mensagens' => ['auth_suporte_mensagens', '/suporte/mensagens'];
    }

    #[DataProvider('paginasDaLista')]
    #[TestDox('Aceita a página $_dataName')]
    public function testAceitaCadaPaginaDaLista(string $rota, string $caminho): void
    {
        self::assertSame($caminho, $this->validar($caminho));
        self::assertSame($rota, static::getContainer()->get(RouterInterface::class)->match($caminho)['_route'], 'O exemplo casa a rota que diz casar.');
    }

    #[TestDox('Toda rota da lista tem exemplo no teste, existe no router e aceita GET')]
    public function testListaCobertaEValida(): void
    {
        self::bootKernel();
        /** @var list<string> $paginas */
        $paginas = (new \ReflectionClassConstant(DestinoPosLogin::class, 'PAGINAS'))->getValue();
        $exemplos = array_keys(iterator_to_array(self::paginasDaLista()));
        sort($paginas);
        sort($exemplos);
        self::assertSame($paginas, $exemplos);

        $rotas = static::getContainer()->get(RouterInterface::class)->getRouteCollection();
        foreach ($paginas as $nome) {
            $rota = $rotas->get($nome);
            self::assertNotNull($rota, $nome);
            self::assertTrue($rota->getMethods() === [] || in_array('GET', $rota->getMethods(), true), $nome);
        }
    }

    #[TestDox('O match em GET não muda o contexto do router (o login chega num POST)')]
    public function testContextoDoRouterVoltaComoEstava(): void
    {
        $sut = $this->sut();
        $router = static::getContainer()->get(RouterInterface::class);
        $router->setContext((new RequestContext())->setMethod('POST'));

        self::assertSame('/agenda', $sut->validar('/agenda', Request::create('http://localhost/login', 'POST')));
        self::assertSame('POST', $router->getContext()->getMethod());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function destinosAceitos(): iterable
    {
        yield 'caminho simples' => ['/agenda', '/agenda'];
        yield 'início do sistema' => ['/inicio', '/inicio']; // a raiz (/) é o site institucional, fora do sistema
        yield 'caminho com query' => ['/agenda?dia=2026-10-19', '/agenda?dia=2026-10-19'];
        yield 'query com espaço codificado' => ['/expediente?q=a%20b', '/expediente?q=a%20b'];
        yield 'barra no fim de página sem barra' => ['/ponto', '/ponto'];
        yield 'URL absoluta do próprio host' => ['http://localhost/agenda?dia=1', '/agenda?dia=1'];
        yield 'URL absoluta https do próprio host' => ['https://localhost/agenda', '/agenda'];
        yield 'host em maiúsculas' => ['http://LOCALHOST/agenda', '/agenda'];
        yield 'URL absoluta do início' => ['http://localhost/inicio', '/inicio'];
        yield 'fragmento cai na redução' => ['http://localhost/agenda#topo', '/agenda'];
    }

    #[DataProvider('destinosAceitos')]
    #[TestDox('Aceita $_dataName')]
    public function testAceitaDestinoDoProprioSite(string $destino, string $esperado): void
    {
        self::assertSame($esperado, $this->validar($destino));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function destinosRecusados(): iterable
    {
        yield 'vazio' => [''];
        yield 'relativo ao protocolo' => ['//evil.com'];
        yield 'barra e barra invertida' => ['/\\evil.com'];
        yield 'barra invertida dupla' => ['\\\\evil.com'];
        yield 'barra invertida no meio' => ['/agenda\\x'];
        yield 'outro host' => ['https://evil.com'];
        yield 'host que começa igual' => ['http://localhost.evil.com/agenda'];
        yield 'host certo no caminho de outro' => ['http://evil.com/localhost'];
        yield 'host certo como usuário' => ['http://localhost@evil.com/'];
        yield 'host certo depois da interrogação' => ['http://evil.com?@localhost'];
        yield 'barra invertida antes da arroba' => ['http://localhost\\@evil.com'];
        yield 'mesmo host, outra porta' => ['http://localhost:8080/agenda'];
        yield 'mesmo host, caminho relativo ao protocolo' => ['http://localhost//evil.com'];
        yield 'esquema com uma barra' => ['http:/evil.com'];
        yield 'esquema com três barras' => ['https:///evil.com'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'javascript em maiúsculas' => ['JAVASCRIPT:alert(1)'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>'];
        yield 'ftp do próprio host' => ['ftp://localhost/agenda'];
        yield 'sem barra na frente' => ['agenda'];
        yield 'barras codificadas sem barra na frente' => ['%2F%2Fevil.com'];
        yield 'barras codificadas depois da barra' => ['/%2F%2Fevil.com'];
        yield 'barra invertida codificada' => ['/%5Cevil.com'];
        yield 'tab codificado' => ['/%09/evil.com'];
        yield 'espaço à frente' => [' /agenda'];
        yield 'tab à frente' => ["\t/agenda"];
        yield 'quebra de linha à frente' => ["\n/agenda"];
        yield 'quebra de linha no fim' => ["/agenda\n"];
        yield 'tab no meio' => ["/\t/evil.com"];
        yield 'byte nulo' => ["/agenda\0"];
        yield 'login' => ['/login'];
        yield 'login com query' => ['/login?x=1'];
        yield 'login em maiúsculas' => ['/LOGIN'];
        yield 'o próprio véu' => ['/login/entrando?para=/agenda'];
        yield 'login absoluto do próprio host' => ['http://localhost/login'];
        yield 'logout' => ['/logout'];
        yield 'senha' => ['/senha/esqueci'];
        yield 'cadastro' => ['/cadastro'];
        yield 'login codificado' => ['/%6Cogin'];
        yield 'longo demais' => ['/' . str_repeat('a', 2100)];
        // Não é página (incidente da v2.5.0: o polling do sino virou destino e o login caiu no JSON).
        yield 'contador do sino' => ['/notificacoes/count'];
        yield 'contador do sino absoluto' => ['http://localhost/notificacoes/count'];
        yield 'contador com query' => ['/notificacoes/count?x=1'];
        yield 'contador com barra no fim' => ['/notificacoes/count/'];
        yield 'lista do sino' => ['/notificacoes/lista-dropdown?categoria=pessoal'];
        yield 'estado do ponto' => ['/ponto/estado-do-dia'];
        yield 'alerta de horário do ponto' => ['/ponto/alerta-horario'];
        yield 'novidades da Central' => ['/central/novidades'];
        yield 'conversas da Central' => ['/central/conversas'];
        yield 'equipe da Central' => ['/central/equipe'];
        yield 'avisos da Central' => ['/central/avisos'];
        yield 'contatos da Central' => ['/central/contatos'];
        yield 'mensagens da Central' => ['/central/conversas/7/mensagens'];
        yield 'busca rápida' => ['/expediente/busca-rapida?q=silva'];
        yield 'trilho do Expediente (pedaço de tela)' => ['/expediente/trilho'];
        yield 'lembretes da agenda' => ['/agenda/lembretes'];
        yield 'compromissos da agenda' => ['/agenda/compromissos'];
        yield 'linha do tempo da pasta' => ['/pasta/7/timeline'];
        yield 'painel do Trabalho Externo (JSON)' => ['/trabalho-externo/api/painel'];
        yield 'API' => ['/api/agente/opcoes'];
        yield 'webhook' => ['/api/webhooks/cora/pix'];
        // Exportação, download e documento.
        yield 'exportar CSV do Expediente' => ['/expediente/exportar.csv'];
        yield 'relatório em PDF' => ['/expediente/relatorio-processos/pdf'];
        yield 'folha de ponto em PDF' => ['/ponto/exportar-folha-pdf'];
        yield 'folha de ponto em XLSX' => ['/ponto/exportar-folha-xlsx'];
        yield 'exportar carteira' => ['/cobrancas/carteiras/7/exportar'];
        yield 'download de documento da pasta' => ['/pasta/documento/7/download'];
        yield 'visualizar documento da pasta' => ['/pasta/documento/7/visualizar'];
        yield 'resumo da pasta em PDF' => ['/pasta/7/resumo/pdf'];
        yield 'arquivo de mensagem de tarefa' => ['/tarefas/mensagem/7/arquivo/download'];
        yield 'política em PDF' => ['/politica-de-privacidade.pdf'];
        // Rota que só aceita POST, rota inexistente, rota interna e arquivo estático.
        yield 'rota só de POST' => ['/agenda/compromissos/novo'];
        yield 'rota inexistente' => ['/nao-existe'];
        yield 'parecido com login, mas sem rota' => ['/loginx'];
        // Fragmento e iframe: carregados por fetch ou dentro de outra página, nunca abertos sozinhos.
        yield 'fragmento das contas do menu' => ['/escritorio/contas'];
        yield 'mapa do Master (iframe)' => ['/master/mapa/brasil'];
        yield 'mapa do mundo do Master (iframe)' => ['/master/mapa/mundo'];
        yield 'rota interna do Symfony' => ['/_fragment'];
        yield 'arquivo JSON' => ['/manifest.json'];
        yield 'arquivo JS' => ['/js/central.js'];
        yield 'imagem' => ['/images/logo/bluejus.png'];
        // Telas de entrada e públicas não são destino.
        yield 'termos' => ['/termos/aceitar'];
        yield 'convite' => ['/convite/abc'];
    }

    #[DataProvider('destinosRecusados')]
    #[TestDox('Recusa $_dataName')]
    public function testRecusaDestinoPerigosoOuEmLaco(string $destino): void
    {
        self::assertNull($this->validar($destino));
    }

    #[TestDox('Destino nulo vira nulo')]
    public function testNuloViraNulo(): void
    {
        self::assertNull($this->sut()->validar(null, Request::create('http://localhost/login')));
    }

    #[TestDox('Com porta fora do padrão, só a mesma porta vale')]
    public function testPortaFazParteDoHost(): void
    {
        $sut = $this->sut();
        $request = Request::create('http://bluejus.test:8080/login');

        self::assertSame('/agenda', $sut->validar('http://bluejus.test:8080/agenda', $request));
        self::assertNull($sut->validar('http://bluejus.test/agenda', $request));
        self::assertNull($sut->validar('http://bluejus.test:8081/agenda', $request));
    }
}
