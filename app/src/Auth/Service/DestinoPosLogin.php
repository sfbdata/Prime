<?php

declare(strict_types=1);

namespace App\Auth\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as ExcecaoDeRota;
use Symfony\Component\Routing\RouterInterface;

/**
 * Valida para onde o usuário pode ir depois do login (spec login-desenho-10, comportamento 2).
 *
 * O destino nasce de fonte que o usuário controla (o `target_path` da sessão vem da URL que ele
 * abriu; o `?para=` do `/login/entrando` vem da barra de endereço), então tudo aqui é defesa
 * contra open redirect: só sai daqui um caminho relativo deste mesmo site, ou `null`.
 *
 * Aceita:
 * - caminho relativo que começa com `/`, sem `//` nem `/\` na frente, sem `\` em lugar nenhum,
 *   sem espaço nem caractere de controle (nem cru, nem codificado com `%`);
 * - URL absoluta `http(s)` do próprio host (e porta) da requisição, reduzida a caminho e query.
 *
 * E, além disso, só PÁGINA: o caminho tem de casar uma rota GET no router e a rota tem de estar em
 * `PAGINAS`. Antes a regra era recusar o que parecia dado (`.../count`); em produção o `fetch('/notificacoes/count')`
 * do sino com a sessão vencida virou destino e o dono entrou e caiu no JSON `{"count":390}`. Lista de recusa
 * sempre esquece um endpoint; lista de permitidas, no máximo, esquece uma página, e aí a pessoa vai à home.
 */
final class DestinoPosLogin
{
    private const TAMANHO_MAXIMO = 2048;

    /**
     * As rotas que podem ser destino do login: páginas inteiras que a pessoa abre pela barra de endereço.
     *
     * Na dúvida, a rota fica de fora e o login vai à home (o Expediente). Não entram: sondagem e endpoint de
     * dados (JSON), pedaço de tela carregado por `fetch` (os templates `_*.html.twig`), exportação e download (pdf,
     * xlsx, csv, zip, anexo, visualizar documento), iframe, telas de entrada (login, senha, cadastro, convite,
     * termos, seleção de escritório) e as rotas públicas. Página nova entra aqui com o nome da rota.
     */
    private const PAGINAS = [
        // Início
        'homepage',
        'dashboard_index',
        // Expediente e pastas
        'expediente_index',
        'expediente_relatorio_processos',
        'pasta_show',
        'pasta_peticionar',
        'processo_index',
        'processo_show',
        'processo_new',
        'processo_edit',
        'push_processual_index',
        'push_processual_show',
        'push_processual_oabs',
        // Agenda
        'agenda_index',
        'agenda_nova',
        'agenda_lista',
        'agenda_show',
        'agenda_novo',
        'agenda_editar',
        // Tarefas e Kanban
        'tarefa_minhas',
        'tarefa_equipe',
        'tarefa_show',
        'kanban_index',
        'kanban_board_view',
        'demandas_index',
        // Clientes
        'cliente_show',
        'cliente_edit',
        // Cobrança
        'cobranca_carteira_index',
        'cobranca_carteira_show',
        'cobranca_importacao_upload',
        'cobranca_caso_index',
        'cobranca_caso_show',
        'cobranca_objeto_show',
        'cobranca_acordo_show',
        'cobranca_pessoa_show',
        'cobranca_dashboard',
        'cobranca_alertas',
        'cobranca_central',
        // Central, Notificações, Novidades, Trabalho Externo, Service Desk
        'central_index',
        'notificacao_index',
        'novidades_index',
        'trabalho_externo_index',
        'servicedesk_index',
        'servicedesk_meus_chamados',
        'servicedesk_novo',
        'servicedesk_show',
        // Ponto
        'ponto_index',
        // Meu Perfil, convites, Contribua, Google Drive
        'app_profile',
        'auth_meus_convites',
        'contribua_index',
        'app_sync_drive_conexao',
        // Escritório e usuários
        'escritorio_criar',
        'auth_gerenciar_convites',
        'app_tenant_index',
        'app_tenant_new',
        'app_tenant_show',
        'app_tenant_edit',
        'app_tenant_users',
        'app_tenant_user_edit_role',
        'app_tenant_user_ponto_edit',
        'app_tenant_role_index',
        'app_tenant_role_new',
        'app_tenant_role_edit',
        'app_tenant_sedes',
        'app_feriado_index',
        'app_feriado_new',
        'app_feriado_edit',
        'audit_log_index',
        'app_access_request_index',
        // Master e administração da plataforma
        'master_painel',
        'admin_platform_dashboard',
        'admin_platform_convite_index',
        'admin_platform_oab_index',
        'inteligencia_admin_config',
        'auth_suporte_mensagens',
    ];

    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    public function validar(?string $destino, Request $request): ?string
    {
        if ($destino === null || $destino === '' || strlen($destino) > self::TAMANHO_MAXIMO) {
            return null;
        }

        $caminho = str_starts_with($destino, '/') && !str_starts_with($destino, '//')
            ? $destino
            : $this->reduzirUrlDoProprioHost($destino, $request);

        if ($caminho === null) {
            return null;
        }

        return $this->caminhoSeguro($caminho) ? $caminho : null;
    }

    /**
     * URL absoluta só vale se for deste host e porta; aí sobra o caminho e a query (o fragmento cai).
     */
    private function reduzirUrlDoProprioHost(string $url, Request $request): ?string
    {
        if (preg_match('#^https?://#i', $url) !== 1 || $this->temCaractereProibido($url)) {
            return null;
        }

        $partes = parse_url($url);
        if ($partes === false || !isset($partes['host']) || isset($partes['user']) || isset($partes['pass'])) {
            return null;
        }

        if ($this->autoridade($partes) !== strtolower($request->getHttpHost())) {
            return null;
        }

        $caminho = $partes['path'] ?? '/';

        return isset($partes['query']) ? $caminho . '?' . $partes['query'] : $caminho;
    }

    /**
     * Host e porta no mesmo formato de `Request::getHttpHost()`: a porta só aparece quando
     * não é a padrão do esquema.
     *
     * @param array<string, int|string> $partes
     */
    private function autoridade(array $partes): string
    {
        $esquema = strtolower((string) ($partes['scheme'] ?? 'http'));
        $host = strtolower((string) ($partes['host'] ?? ''));
        $porta = isset($partes['port']) ? (int) $partes['port'] : null;
        $padrao = $esquema === 'https' ? 443 : 80;

        return $porta === null || $porta === $padrao ? $host : $host . ':' . $porta;
    }

    private function caminhoSeguro(string $caminho): bool
    {
        if ($this->temCaractereProibido($caminho)) {
            return false;
        }

        // Confere também o decodificado: `/%2F%2Fevil.com`, `/%5Cevil.com` ou `/%09/evil.com`
        // não podem virar `//evil.com` em quem decodificar no meio do caminho.
        $decodificado = rawurldecode($caminho);
        foreach ([$caminho, $decodificado] as $forma) {
            if (!str_starts_with($forma, '/') || str_starts_with($forma, '//') || str_starts_with($forma, '/\\')) {
                return false;
            }
        }

        return preg_match('/[\x00-\x1F\x7F]/', $decodificado) !== 1
            && $this->ePagina($caminho);
    }

    /** Barra invertida, espaço e caractere de controle crus nunca entram. */
    private function temCaractereProibido(string $texto): bool
    {
        return str_contains($texto, '\\') || preg_match('/[\x00-\x20\x7F]/', $texto) === 1;
    }

    /**
     * O caminho (sem a query) casa uma rota GET que está em `PAGINAS`. O contexto do router é trocado por um
     * de GET só durante o `match()`: o login chega aqui num POST, e a rota POST de mesmo caminho não vale.
     */
    private function ePagina(string $caminho): bool
    {
        $soCaminho = (string) strtok($caminho, '?#');
        $contexto = $this->router->getContext();
        $this->router->setContext((clone $contexto)->setMethod('GET'));

        try {
            $rota = $this->router->match($soCaminho)['_route'] ?? null;
        } catch (ExcecaoDeRota) {
            return false;
        } finally {
            $this->router->setContext($contexto);
        }

        return is_string($rota) && in_array($rota, self::PAGINAS, true);
    }
}
