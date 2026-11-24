<?php

declare(strict_types=1);

namespace App\Produto\Service;

use App\Produto\DTO\ClassificacaoDaRota;
use App\Produto\Enum\Produto;
use App\Voto\EventListener\CedulaSemTenantFilterListener;

/**
 * Catálogo fixo dos produtos e de que produto cada rota é (docs/specs/multiproduto-contratacao-e-voto.md, §3).
 *
 * Molde: Master/Service/CatalogoDeRecursos.php. Só dado e regra pura: quem decide se o escritório tem o produto
 * é o serviço de contratação (PR 4), e quem barra a rota é a guarda (PR 6).
 *
 * Regra da classificação, nesta ordem:
 * 1. nome exato da lista pública;
 * 2. nome exato de `ROTAS_EXATAS`;
 * 3. o prefixo mais longo entre núcleo, plataforma, públicas e produtos;
 * 4. nada casou: Expediente, sem ser explícita (a rota nasce fechada, e o teste de arquitetura reclama).
 */
final class CatalogoDeProdutos
{
    /**
     * Dados de tela de cada produto. `autoativavel`: o dono liga sozinho no cartão "Ferramentas do escritório" (D-ia).
     *
     * @var array<string, array{titulo: string, icone: string, rotaInicial: string, autoativavel: bool}>
     */
    public const PRODUTOS = [
        'completo'   => ['titulo' => 'Completo', 'icone' => 'bi-grid', 'rotaInicial' => 'expediente_index', 'autoativavel' => false],
        'voto'       => ['titulo' => 'Voto', 'icone' => 'bi-archive', 'rotaInicial' => 'voto_index', 'autoativavel' => true],
        'expediente' => ['titulo' => 'Expediente', 'icone' => 'bi-inbox-fill', 'rotaInicial' => 'expediente_index', 'autoativavel' => true],
        'ia'         => ['titulo' => 'I.A.', 'icone' => 'bi-stars', 'rotaInicial' => 'chat_ia_pagina', 'autoativavel' => true],
        'ponto'      => ['titulo' => 'Ponto', 'icone' => 'bi-fingerprint', 'rotaInicial' => 'ponto_index', 'autoativavel' => true],
        'calculo'    => ['titulo' => 'Cálculo', 'icone' => 'bi-calculator', 'rotaInicial' => 'calculo_index', 'autoativavel' => true],
        'kanban'     => ['titulo' => 'Kanban', 'icone' => 'bi-kanban', 'rotaInicial' => 'kanban_index', 'autoativavel' => true],
        'assinatura' => ['titulo' => 'Assinatura', 'icone' => 'bi-pen', 'rotaInicial' => 'assinatura_eletronica_index', 'autoativavel' => true],
        'cobrancas'  => ['titulo' => 'Cobranças', 'icone' => 'bi-cash-coin', 'rotaInicial' => 'cobranca_carteira_index', 'autoativavel' => true],
    ];

    /**
     * Prefixo do NOME da rota para os produtos que a abrem (basta um). Vence o prefixo mais longo.
     *
     * @var array<string, list<string>>
     */
    public const ROTAS = [
        'voto_' => ['voto'],

        // Expediente: pastas, demandas, metas, push, processos, agenda e o que mora nas pastas.
        'expediente_' => ['expediente'],
        'pasta_' => ['expediente'],
        'demandas_' => ['expediente'],
        'tarefa_' => ['expediente'],
        'push_processual_' => ['expediente'],
        'djen_' => ['expediente'],
        'processo_' => ['expediente'],
        'api_datajud' => ['expediente'],
        'agenda_' => ['expediente'],
        'app_agenda_' => ['expediente'],
        'app_sync_drive_' => ['expediente'],
        // `dashboard_inteligencia_*` é o perfil da pessoa no Dashboard, não a I.A.
        'dashboard_' => ['expediente'],
        // Convites e vagas logados; os públicos estão em PUBLICAS (nome exato).
        'trabalho_externo_' => ['expediente'],
        'servicedesk_' => ['expediente'],
        'service_desk_' => ['expediente'],
        // Telas do Conector: usam a I.A. da conta da pessoa e leem pastas e clientes (D11).
        'conector_admin_' => ['expediente'],
        'conector_perfil_' => ['expediente'],

        'inteligencia_' => ['ia'],
        'chat_ia_' => ['ia'],
        'central_ia_' => ['ia'],

        'cobranca_' => ['cobrancas'],
        // Clientes abre com o Expediente ou com as Cobranças (a carteira usa Cliente como credor).
        'cliente_' => ['expediente', 'cobrancas'],

        'calculo_' => ['calculo'],
        'kanban_' => ['kanban'],
        'assinatura_eletronica_' => ['assinatura'],

        'ponto_' => ['ponto'],
        'app_jornada_' => ['ponto'],
        'app_home_office_' => ['ponto'],
        'app_tenant_sede' => ['ponto'],
        'app_tenant_user_ponto_' => ['ponto'],
        'app_tenant_user_justificativa_' => ['ponto'],
    ];

    /**
     * Nomes exatos que fogem do prefixo: o Judicializar das Cobranças depende das pastas (§9, regra 9).
     * `cobranca_caso_cancelar_judicializacao` fica com as Cobranças: tirar o vínculo nunca trava.
     *
     * @var array<string, list<string>>
     */
    public const ROTAS_EXATAS = [
        'cobranca_caso_judicializar' => ['expediente'],
        'cobranca_pastas_buscar' => ['expediente'],
    ];

    /**
     * Prefixos do núcleo: abertos para todo escritório, com ou sem produto.
     *
     * @var list<string>
     */
    public const NUCLEO = [
        'app_login', 'app_logout', 'auth_', 'conta_', 'tenant_selecionar', 'escritorio_', 'meu_escritorio_',
        'app_tenant_', 'app_access_', 'invite_user', 'app_profile', 'backup_', 'notificacao_', 'novidades_',
        'ajuda_', 'legal_', 'termo_', 'contribua_', 'indicacao_', 'central_', 'audit_log_', 'homepage',
        'monitor_evento', 'app_feriado_', 'comercial_', 'email_descadastro', 'register_confirm',
        // OAuth e o servidor MCP do Conector: sem sessão; a regra fica em LiberacaoDoConector (§6.1).
        'conector_oauth_', 'conector_mcp', 'conector_metadado_',
        // Ferramentas de desenvolvimento do Symfony (só em dev).
        '_profiler', '_wdt', '_preview_error',
    ];

    /**
     * Prefixos da plataforma (painel do Master).
     *
     * @var list<string>
     */
    public const PLATAFORMA = ['master_', 'admin_platform_', 'agendador_falhas_dispensar'];

    /**
     * Rotas públicas por NOME EXATO (link aberto sem conta). Exato, e não prefixo: `trabalho_externo_convite_reenviar`
     * e `_revogar` são API logada do escritório e ficam no Expediente. As da cédula e do portal do Voto vêm de
     * CedulaSemTenantFilterListener::ROTAS (mais `voto_assembleia_portal_escolher`, também sob `^/voto/v/`).
     *
     * @var list<string>
     */
    public const PUBLICAS = [
        'voto_assembleia_portal_escolher',
        'push_compartilhado', 'push_compartilhado_codigo', 'push_compartilhado_renovacao',
        'trabalho_externo_convite', 'trabalho_externo_convite_criar_conta', 'trabalho_externo_convite_aceitar',
        'trabalho_externo_vagas_publicas', 'trabalho_externo_vagas_link', 'trabalho_externo_vagas_alertas',
        'vagas_publicas_cta',
        'calculo_verificar',
        'validacao_comprovante_ponto', 'validacao_comprovante_ponto_codigo',
        'contribua_webhook_cora', 'contribua_webhook_gateway',
        'indicacao_link_curto',
    ];

    /**
     * Prefixos públicos: o link do signatário (`/assinar/`), a saúde do container do Voto e o auto-cadastro.
     *
     * @var list<string>
     */
    public const PUBLICAS_PREFIXOS = ['assinatura_eletronica_publica', 'voto_publico_', 'auth_cadastro'];

    /** Produto padrão de rota sem mapa: nasce fechada. */
    public const PADRAO = 'expediente';

    public static function titulo(Produto $produto): string
    {
        return self::PRODUTOS[$produto->value]['titulo'];
    }

    /** Classe do ícone Bootstrap Icons do produto (o cartão do Meu Perfil). */
    public static function icone(Produto $produto): string
    {
        return self::PRODUTOS[$produto->value]['icone'];
    }

    public static function rotaInicial(Produto $produto): string
    {
        return self::PRODUTOS[$produto->value]['rotaInicial'];
    }

    public static function autoativavel(Produto $produto): bool
    {
        return self::PRODUTOS[$produto->value]['autoativavel'];
    }

    /** @return list<string> todas as rotas públicas por nome exato, as do Voto inclusive. */
    public static function publicasExatas(): array
    {
        return array_values(array_unique([...CedulaSemTenantFilterListener::ROTAS, ...self::PUBLICAS]));
    }

    public static function classificar(string $rota): ClassificacaoDaRota
    {
        if (in_array($rota, self::publicasExatas(), true)) {
            return ClassificacaoDaRota::publica();
        }
        if (isset(self::ROTAS_EXATAS[$rota])) {
            return ClassificacaoDaRota::produtos(self::paraEnum(self::ROTAS_EXATAS[$rota]));
        }

        $achada = null;
        $tamanho = 0;
        foreach (self::candidatos() as [$prefixo, $classificacao]) {
            if (strlen($prefixo) > $tamanho && str_starts_with($rota, $prefixo)) {
                $achada = $classificacao;
                $tamanho = strlen($prefixo);
            }
        }

        return $achada ?? ClassificacaoDaRota::produtos([Produto::from(self::PADRAO)], false);
    }

    /** @return list<array{0: string, 1: ClassificacaoDaRota}> */
    private static function candidatos(): array
    {
        $lista = [];
        foreach (self::NUCLEO as $prefixo) {
            $lista[] = [$prefixo, ClassificacaoDaRota::nucleo()];
        }
        foreach (self::PLATAFORMA as $prefixo) {
            $lista[] = [$prefixo, ClassificacaoDaRota::plataforma()];
        }
        foreach (self::PUBLICAS_PREFIXOS as $prefixo) {
            $lista[] = [$prefixo, ClassificacaoDaRota::publica()];
        }
        foreach (self::ROTAS as $prefixo => $produtos) {
            $lista[] = [$prefixo, ClassificacaoDaRota::produtos(self::paraEnum($produtos))];
        }

        return $lista;
    }

    /**
     * @param list<string> $valores
     *
     * @return list<Produto>
     */
    private static function paraEnum(array $valores): array
    {
        return array_map(static fn (string $v): Produto => Produto::from($v), $valores);
    }
}
