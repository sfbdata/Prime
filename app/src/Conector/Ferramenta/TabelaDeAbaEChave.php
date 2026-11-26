<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Pasta\Entity\AbaDaPasta;

/**
 * A tabela "Aba e chave do Master por ferramenta e ação" (`docs/specs/mcp-remoto-oauth.md`, 6.6, e 6.2, regras 2 e 11),
 * num lugar só, para as 17 ferramentas do {@see CatalogoFechadoDoConector}: por ação, o módulo que a abre, as abas da
 * pasta de onde a informação vem ({@see AbaDaPasta}) e as chaves do Master
 * ({@see \App\Master\Service\CatalogoDeRecursos}). Quem confere é a {@see \App\Conector\Service\GuardaDaAcaoDoConector};
 * a `quem_sou_eu` lê daqui o módulo de cada ferramenta.
 *
 * A tela barra pela rota (o `BarreiraDeAbaOcultaListener` e o guarda da chave do Master); o conector não tem rota de
 * tela, então cada ação diz aqui a aba e a chave da tela de onde vem.
 *
 * A ação é o valor do campo de {@see campoDasAcoes()} no esquema da ferramenta (`acao` em geral; `partes` na
 * `ler_pasta`; `lugar` na `registrar_na_pasta`, onde a aba muda pelo lugar e não pela ação). Ferramenta sem esse campo
 * tem uma linha só, {@see UNICA}. O teste de arquitetura percorre a tabela: toda ação do esquema de cada ferramenta
 * registrada tem linha aqui, cada módulo existe no catálogo de permissões, cada aba é uma {@see AbaDaPasta} e cada chave
 * está no catálogo do Master.
 *
 * Onde a spec não diz módulo para a ação (o item pede só o recurso), fica o módulo que o mapa da `quem_sou_eu` do PR 5
 * já pedia para a ferramenta, o lado mais fechado. As linhas das ferramentas de gravação (PRs 10 a 13) usam os nomes
 * de ação da 6.6; o PR que registrar a ferramenta confirma os nomes contra o esquema dela (o teste derruba o que faltar).
 */
final class TabelaDeAbaEChave
{
    /** A linha da ferramenta sem campo de ação no esquema (a `quem_sou_eu` e a `confirmar_acao`). */
    public const UNICA = 'unica';

    /** O campo do esquema que traz a ação, quando não é `acao`. */
    public const CAMPO_DAS_ACOES = [
        'ler_pasta' => 'partes',
        'registrar_na_pasta' => 'lugar',
    ];

    private const PASTAS = ['pastas'];
    private const EXPEDIENTE = ['expediente'];
    private const PROCESSOS = ['processos'];
    private const CLIENTES = ['clientes'];
    private const AGENDA = ['agenda'];
    private const TAREFAS = ['tarefas'];
    private const NENHUM = [];

    private const EXPEDIENTES = ['expedientes'];
    private const NENHUMA = [];

    private const DADOS = [AbaDaPasta::Dados];
    private const METAS = [AbaDaPasta::Tarefas];
    private const PROCESSO = [AbaDaPasta::Processo];
    private const FINANCEIRO = [AbaDaPasta::Financeiro];
    private const DETALHES = [AbaDaPasta::Detalhes];
    private const DOCUMENTOS = [AbaDaPasta::Documentos];
    private const PUSH = [AbaDaPasta::Push];

    /**
     * Ferramenta → ação → `modulos` (basta um), `abas` (todas visíveis), `chaves` (todas ligadas) e, opcional,
     * `so_com_pasta` (as chaves só valem quando a ação age por uma pasta).
     *
     * @var array<string, array<string, array{modulos: list<string>, abas: list<AbaDaPasta>, chaves: list<string>, so_com_pasta?: bool}>>
     */
    public const LINHAS = [
        // Leitura (6.4).
        'quem_sou_eu' => [
            self::UNICA => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
        // A Lista, a busca do Agente, a contagem e os valores de filtro: módulo `expediente` (`AgenteDePastasController`
        // :99, `LixeiraDoEscritorioController` :50). A busca rápida por número ou NUP e Minhas demandas: módulo `pastas`
        // (`BuscaRapidaController` :38, `DemandasController` :240). Nenhuma chave: a Lista é essencial.
        'buscar_pastas' => [
            'buscar' => ['modulos' => self::EXPEDIENTE, 'abas' => [], 'chaves' => self::NENHUMA],
            'contar' => ['modulos' => self::EXPEDIENTE, 'abas' => [], 'chaves' => self::NENHUMA],
            'opcoes' => ['modulos' => self::EXPEDIENTE, 'abas' => [], 'chaves' => self::NENHUMA],
            'lixeira' => ['modulos' => self::EXPEDIENTE, 'abas' => [], 'chaves' => self::NENHUMA],
            'numero' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::NENHUMA],
            'minhas_demandas' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::NENHUMA],
            // Os relatórios do Expediente (PR 14): módulo `expediente`, como as rotas `expediente_pastas_paradas` e
            // `expediente_relatorio_processos`; nenhuma chave (nenhuma das duas casa com a chave `expedientes`, que é do `pasta_show`).
            'pastas_paradas' => ['modulos' => self::EXPEDIENTE, 'abas' => [], 'chaves' => self::NENHUMA],
            'pastas_sem_processo' => ['modulos' => self::EXPEDIENTE, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
        // Por parte (`partes`), sem módulo: como a tela da pasta (`pasta_show`), quem lê é quem pode ver a pasta. O Registro barra pela aba Dados (decisão do PR 6: a mensagem do Registro mora na aba
        // Dados, mesmo sem rota no `MAPA`).
        'ler_pasta' => [
            'resumo' => ['modulos' => [], 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'andamento' => ['modulos' => [], 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'registro' => ['modulos' => [], 'abas' => self::DADOS, 'chaves' => self::EXPEDIENTES],
            'equipe' => ['modulos' => [], 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'cadastro' => ['modulos' => [], 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'prazos' => ['modulos' => [], 'abas' => self::METAS, 'chaves' => self::EXPEDIENTES],
            'metas' => ['modulos' => [], 'abas' => self::METAS, 'chaves' => self::EXPEDIENTES],
            'detalhes' => ['modulos' => [], 'abas' => self::DETALHES, 'chaves' => self::EXPEDIENTES],
            'controladoria' => ['modulos' => [], 'abas' => self::DETALHES, 'chaves' => self::EXPEDIENTES],
            'checklist' => ['modulos' => [], 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'financeiro' => ['modulos' => [], 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
            'publicacoes' => ['modulos' => [], 'abas' => self::PUSH, 'chaves' => self::EXPEDIENTES],
            // A Timeline inteligente (`pasta_timeline`) não tem barreira de aba na rota: o montador tira de cada evento a aba
            // oculta e o financeiro de quem não o vê. Como as vizinhas, a chave do Master é a da tela da pasta.
            'timeline' => ['modulos' => [], 'abas' => [], 'chaves' => self::EXPEDIENTES],
        ],
        'ler_documentos_e_modelos' => [
            'listar' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'ler' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'lixeira' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'modelos' => ['modulos' => self::PASTAS, 'abas' => self::DETALHES, 'chaves' => self::EXPEDIENTES],
            'modelo' => ['modulos' => self::PASTAS, 'abas' => self::DETALHES, 'chaves' => self::EXPEDIENTES],
            'modelos_de_checklist' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
        ],
        'ler_processos' => [
            'buscar' => ['modulos' => self::PROCESSOS, 'abas' => [], 'chaves' => self::NENHUMA],
            'detalhar' => ['modulos' => self::PROCESSOS, 'abas' => [], 'chaves' => self::NENHUMA],
            'para_vincular' => ['modulos' => self::PROCESSOS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
            'cnj' => ['modulos' => self::PROCESSOS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
        ],
        // `documentos_de_identidade` e `documentos_do_cliente`: a aba Documentos de cada pasta da lista (a pasta com a aba oculta sai).
        'ler_clientes' => [
            'buscar' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::NENHUMA],
            'ler' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::NENHUMA],
            'conferir_documento' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::NENHUMA],
            'documentos_do_cliente' => ['modulos' => self::CLIENTES, 'abas' => self::DOCUMENTOS, 'chaves' => self::NENHUMA],
            'documentos_de_identidade' => ['modulos' => self::CLIENTES, 'abas' => self::DOCUMENTOS, 'chaves' => self::NENHUMA],
            // `pendencias` (M9, 26/11/2026): como o `ler`, módulo `clientes`; cada pasta da lista ainda passa pelo recurso de ver.
            'pendencias' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
        // `agenda`: módulo `agenda` (`AgendaNovaController` :58). `minhas_metas` (14.i): módulo `tarefas` e a chave `metas`
        // da tela `tarefa_minhas`.
        'ler_agenda' => [
            'agenda' => ['modulos' => self::AGENDA, 'abas' => [], 'chaves' => self::NENHUMA],
            'minhas_metas' => ['modulos' => self::TAREFAS, 'abas' => [], 'chaves' => ['metas']],
        ],

        // Painel do Master (6.9): módulo e chave vazios, como a `confirmar_acao`; quem passa é a guarda do Master, não a tabela.
        'ler_comercial' => [
            'resumo' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'modelos' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'automacoes' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'campanhas' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'campanha' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'contar_publico' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'previa' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
        // Gravação do Painel do Master (6.9): mesma regra, a guarda do Master decide; a ação fina vem no campo `acao`.
        'automacoes_de_email' => [
            'alterar_automacao' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'alterar_data' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
        'modelos_de_email' => [
            'criar' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'duplicar' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'salvar_como' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'editar' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'voltar_ao_padrao' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'excluir' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
        'campanhas_de_email' => [
            'criar' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'editar' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'cancelar' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
            'enviar_teste' => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
        ],

        // Gravação (6.6).
        // `criar`: módulo `pastas`, como a tela (`pasta_new` e o lote de importação), e nenhuma chave (`pasta_new` não é
        // `pasta_show`).
        'atualizar_pasta' => [
            'criar' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::NENHUMA],
            'editar_dados' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'situacao' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'responsavel' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'prioridade' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'carteira' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'duplicar' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'restaurar' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'favoritar' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'acompanhar' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'destacar' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'marcadores_da_pasta' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'marcador' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'nome_do_cliente' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'cadastro_da_pasta' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'reu' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'pedir_acesso' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'enviar_relatorio_sem_processo' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            // Sempre por proposta; o alvo (pasta, marcador, réu) vai nos dados e a ferramenta exige, por alvo, a mesma
            // permissão da tela (decisão 31): pasta `delete`, módulo `expediente`, pasta editar.
            'excluir' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
        ],
        'mudar_andamento' => [
            'mudar_fase' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'nomear_etapa' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
        ],
        // Por lugar (`lugar`): a aba muda pelo lugar, não pela ação (escrever, responder, editar, destacar).
        'registrar_na_pasta' => [
            'registro' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::EXPEDIENTES],
            'relatorio_inicial' => ['modulos' => self::PASTAS, 'abas' => self::DETALHES, 'chaves' => self::EXPEDIENTES],
            'relatorio_financeiro' => ['modulos' => self::PASTAS, 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
        ],
        // Meta e análise do prazo: módulo `tarefas` (`AcessoAoModuloTarefas`), sem aba nem chave (rotas `tarefa_*`).
        'prazos_e_metas_da_pasta' => [
            'salvar_prazo' => ['modulos' => self::PASTAS, 'abas' => self::METAS, 'chaves' => self::EXPEDIENTES],
            'cumprir_prazo' => ['modulos' => self::PASTAS, 'abas' => self::METAS, 'chaves' => self::EXPEDIENTES],
            'analisar_prazo_da_meta' => ['modulos' => self::TAREFAS, 'abas' => [], 'chaves' => self::NENHUMA],
            'criar_meta' => ['modulos' => self::TAREFAS, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
        // Documentos e checklist na aba Documentos; modelos na Detalhes; `usar_modelo` nas duas (a resposta devolve o
        // documento criado); a peça escrita também com a chave `peticionar` (`pasta_peticionar_texto`).
        'documentos_e_pecas' => [
            'editar_documento' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'mover' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'copiar' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'favoritar' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'subpasta' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'reordenar' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'restaurar' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'excluir_de_vez' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'escrever_peca' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => ['peticionar', 'expedientes']],
            'editar_peca' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => ['peticionar', 'expedientes']],
            'destino_da_peca' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'usar_modelo' => ['modulos' => self::PASTAS, 'abas' => [AbaDaPasta::Detalhes, AbaDaPasta::Documentos], 'chaves' => self::EXPEDIENTES],
            'salvar_modelo' => ['modulos' => self::PASTAS, 'abas' => self::DETALHES, 'chaves' => self::EXPEDIENTES],
            'favoritar_modelo' => ['modulos' => self::PASTAS, 'abas' => self::DETALHES, 'chaves' => self::EXPEDIENTES],
            'checklist_item' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'checklist_estado' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
            'checklist_modelo' => ['modulos' => self::PASTAS, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES],
        ],
        'financeiro_da_pasta' => [
            'contrato' => ['modulos' => self::PASTAS, 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
            'pro_bono' => ['modulos' => self::PASTAS, 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
            'valor_da_causa' => ['modulos' => self::PASTAS, 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
            'pagamento' => ['modulos' => self::PASTAS, 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
            'documento_assinado' => ['modulos' => self::PASTAS, 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
            'renomear_documento' => ['modulos' => self::PASTAS, 'abas' => self::FINANCEIRO, 'chaves' => self::EXPEDIENTES],
        ],
        // `expedientes` quando age pela pasta; nenhuma sem pasta.
        'atualizar_cliente' => [
            'cadastrar' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
            'completar' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
            'contato' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
            'vincular_a_pasta' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
            'principal' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
            'ligar_documento' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
            // Excluir (por proposta): o documento do cadastro (o vinculado de pasta passa pela aba Documentos da pasta) e o cliente.
            'excluir_documento' => ['modulos' => self::CLIENTES, 'abas' => self::DOCUMENTOS, 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
            'excluir_cliente' => ['modulos' => self::CLIENTES, 'abas' => [], 'chaves' => self::EXPEDIENTES, 'so_com_pasta' => true],
        ],
        // Nota técnica: sem aba nem chave (é do processo, não da tela da pasta). Excluir processo: aba Processo e `expedientes`.
        'processos_da_pasta' => [
            'vincular' => ['modulos' => self::PASTAS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
            'principal' => ['modulos' => self::PASTAS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
            'dados_do_cartao' => ['modulos' => self::PASTAS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
            'acao_da_pasta' => ['modulos' => self::PASTAS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
            'via_administrativa' => ['modulos' => self::PASTAS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
            'nota_tecnica' => ['modulos' => self::PASTAS, 'abas' => [], 'chaves' => self::NENHUMA],
            'excluir_processo' => ['modulos' => self::PASTAS, 'abas' => self::PROCESSO, 'chaves' => self::EXPEDIENTES],
        ],
        'gravar_na_agenda' => [
            'salvar' => ['modulos' => self::AGENDA, 'abas' => [], 'chaves' => self::NENHUMA],
            'mover' => ['modulos' => self::AGENDA, 'abas' => [], 'chaves' => self::NENHUMA],
            'status' => ['modulos' => self::AGENDA, 'abas' => [], 'chaves' => self::NENHUMA],
            'avisar' => ['modulos' => self::AGENDA, 'abas' => [], 'chaves' => self::NENHUMA],
        ],

        // Confirmação (6.7): vale a linha da ação confirmada, conferida de novo ao executar.
        'confirmar_acao' => [
            self::UNICA => ['modulos' => self::NENHUM, 'abas' => [], 'chaves' => self::NENHUMA],
        ],
    ];

    private function __construct()
    {
    }

    /** O campo do esquema da ferramenta que traz a ação: `acao`, salvo as de {@see CAMPO_DAS_ACOES}. */
    public static function campoDasAcoes(string $ferramenta): string
    {
        return self::CAMPO_DAS_ACOES[$ferramenta] ?? 'acao';
    }

    /**
     * As ações da ferramenta na tabela, na ordem da spec; vazio para ferramenta fora dela.
     *
     * @return list<string>
     */
    public static function acoes(string $ferramenta): array
    {
        return array_keys(self::LINHAS[$ferramenta] ?? []);
    }

    /** A linha da ação; null quando a ferramenta ou a ação não estão na tabela. */
    public static function linha(string $ferramenta, string $acao): ?LinhaDeAbaEChave
    {
        $linha = self::LINHAS[$ferramenta][$acao] ?? null;

        return $linha === null ? null : self::montar($linha);
    }

    /**
     * Todas as linhas da ferramenta, por ação; vazio para ferramenta fora da tabela.
     *
     * @return array<string, LinhaDeAbaEChave>
     */
    public static function linhasDa(string $ferramenta): array
    {
        return array_map(self::montar(...), self::LINHAS[$ferramenta] ?? []);
    }

    /** @param array{modulos: list<string>, abas: list<AbaDaPasta>, chaves: list<string>, so_com_pasta?: bool} $linha */
    private static function montar(array $linha): LinhaDeAbaEChave
    {
        return new LinhaDeAbaEChave($linha['modulos'], $linha['abas'], $linha['chaves'], $linha['so_com_pasta'] ?? false);
    }
}
