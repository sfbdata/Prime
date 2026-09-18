<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Tenant\DTO\PurgaEscritorioResultado;
use App\Tenant\Exception\PurgaComDestinoIncerto;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Purga definitiva (hard delete) de um escritório em quarentena — a etapa final do
 * ciclo soft-delete → quarentena → purga (RN09). Apaga o Tenant e TUDO que pertence a
 * ele, sem tocar em dado de outro escritório e sem apagar entidades compartilhadas.
 *
 * Disparado pelo job de manutenção (sem request/usuário no contexto). Pontos de risco
 * ALTO tratados aqui:
 *
 *  - O TenantFilter do Doctrine fica DESLIGADO no CLI (só liga por request), e não afeta
 *    DML direto de qualquer forma. Por isso o isolamento é garantido por `tenant_id`
 *    EXPLÍCITO em cada DELETE (nunca confiar no filtro — lição do achado B1/Datajud).
 *  - 32 das 34 FKs → tenant são NO ACTION: o banco NÃO cascateia a partir do tenant, então
 *    a deleção é manual, de baixo para cima (ver ORDEM_DELECAO). As cascatas internas de
 *    cada subsistema (pasta, processo, kanban, etc.) são aproveitadas.
 *  - Antes de apagar o tenant, uma verificação de integridade (garantirTenantVazio) confere,
 *    via information_schema, que nenhuma tabela tenant-scoped sobrou — falha segura e clara
 *    caso uma tabela nova não tenha sido adicionada à ordem (drift de schema).
 *  - PRESERVADOS: User (nunca apagado — decisão de produto), audit_log (retido; a própria
 *    purga é registrada como evento), permission (catálogo global), cadastro_pendente e
 *    jornada_colaborador (per-user, sem tenant_id).
 *
 * ## Arquivos (E2.5)
 *
 * O banco é autoritativo: a exclusão física acontece DEPOIS do COMMIT e nunca desfaz nada. O que
 * não puder ser removido vira registro no log e item em `arquivosNaoRemovidos` — o comando reporta
 * o escritório como purgado, com sobra no disco.
 *
 * **Se o próprio COMMIT falhar** (D18), o destino é perguntado ao banco: confirmado → o disco segue
 * normalmente; desfeito → a exceção original sobe (nada foi apagado); sem prova →
 * `PurgaComDestinoIncerto`, sem tocar em arquivo nenhum, com a lista do que seria removido no log.
 *
 * **Trava.** A linha do escritório (e os chamados dele) é travada antes da coleta: um INSERT
 * concorrente de linha do escritório — o cron do Drive continua sincronizando escritório
 * soft-deletado com conexão ativa — espera o COMMIT e então falha na FK. Sem isso, em READ
 * COMMITTED, uma linha confirmada entre a coleta e o DELETE seria apagada sem que o arquivo dela
 * tivesse sido coletado.
 *
 *  - **Categorias com isolamento físico** (imagens do editor e documentos de cobrança): o
 *    diretório inteiro do escritório sai por `ArmazenamentoComPrefixo::excluirPrefixo()`, que
 *    prova o pertencimento antes de apagar (D7);
 *  - **categorias planas** (diretório compartilhado por todos os escritórios): os arquivos saem
 *    **um a um**, pelos registros do escritório, e só os que nenhum registro de OUTRO escritório
 *    referencia ({@see ARQUIVOS_POR_REGISTRO}). A classificação roda dentro da transação, antes
 *    dos DELETEs. Isolamento lógico não se infere de diretório compartilhado;
 *  - **Kanban** entrou na E2.5: antes os anexos ficavam órfãos (o diretório nem era consultado);
 *  - **fora da purga, de propósito:** a foto de perfil (é do User, escopo global); os anexos de
 *    Tarefa, cuja coluna guarda caminho público e não é endereçável por chave até a E2.7 (D5) —
 *    eles são listados em `arquivosForaDoEscopo`, que é o único rastro depois do COMMIT; e
 *    `documento_processo`, que não tem escritor em `src/` nem diretório
 *    (`DocumentoProcessoSemEscritorTest` cobra a premissa).
 */
final class PurgarEscritorioUseCase
{
    /** Tabelas com coluna tenant_id que NÃO são apagadas na purga (retidas por decisão de produto). */
    private const TABELAS_RETIDAS = ['audit_log'];

    /**
     * Ordem de deleção (do filho para o pai), verificada contra o grafo de FKs do banco.
     * Cada item é [tabela, condição WHERE com :tenant]. Tabelas com tenant_id usam o
     * escopo direto; filhas sem tenant_id (bloco_jornada, tenant_role_permission) usam
     * subquery escopada pelo pai. As tabelas ausentes daqui caem por CASCADE do banco ao
     * apagar a raiz do seu subsistema (ex.: pasta_secao, documento_processo, kanban_*).
     *
     * @var list<array{0:string,1:string}>
     */
    private const ORDEM_DELECAO = [
        // Fase 1 — bloqueadores intermediários (NO ACTION): apagar antes do pai.
        ['bloco_jornada', 'jornada_tenant_id IN (SELECT id FROM jornada_tenant WHERE tenant_id = :tenant)'],
        ['tenant_role_permission', 'tenant_role_id IN (SELECT id FROM tenant_role WHERE tenant_id = :tenant)'],
        ['cliente_documento', 'tenant_id = :tenant'],
        ['pasta_documento', 'tenant_id = :tenant'],
        ['movimentacao_processo', 'tenant_id = :tenant'],
        ['parte_processo', 'tenant_id = :tenant'],
        ['assunto_processo', 'tenant_id = :tenant'],
        ['user_tenant', 'tenant_id = :tenant'],
        // DJEN — publicações e OABs monitoradas (tenant_id direto; FKs a processo/oab_monitorada
        // são SET NULL, então não caem por cascata — precisam de deleção explícita).
        ['publicacao_djen', 'tenant_id = :tenant'],
        ['oab_monitorada', 'tenant_id = :tenant'],
        // Sync — conexão de Drive do escritório (guarda o refresh_token CIFRADO). FK tenant NO ACTION
        // (bloqueia apagar o tenant) + tenant_id direto → deleção explícita. Apaga o segredo junto.
        ['sync_drive_conexao', 'tenant_id = :tenant'],
        // Cobranças — movimentos financeiros (Etapa 3). Filhos antes do pai: a alocação referencia
        // pagamento E obrigação; pagamento/liquidação referenciam o caso (NO ACTION). Por isso vem
        // ANTES do bloco Etapa 2 (que apaga obrigação e caso).
        ['cobranca_alocacao_pagamento', 'tenant_id = :tenant'],
        ['cobranca_pagamento', 'tenant_id = :tenant'],
        ['cobranca_liquidacao', 'tenant_id = :tenant'],
        // Cobranças — casos e movimentos (Etapa 2). Filhos antes do pai: evento/obrigação
        // referenciam o caso; o caso referencia objeto/pessoa (NO ACTION). Por isso vem ANTES
        // do bloco de cadastro abaixo (que apaga objeto/pessoa).
        ['cobranca_evento_historico', 'tenant_id = :tenant'],
        ['cobranca_obrigacao', 'tenant_id = :tenant'],
        // Estados/ações (Etapa 5): próxima ação referencia o caso (NO ACTION). Apagada ANTES do caso.
        // (A FK cobranca_caso.pasta_judicial_id é SET NULL e o caso é apagado antes de `pasta` na
        // Fase 2 — não precisa de deleção extra aqui.)
        ['cobranca_proxima_acao', 'tenant_id = :tenant'],
        // Acordo (Etapa 4): a obrigação referencia o acordo (SET NULL) e o acordo referencia o caso
        // (NO ACTION). Apagado APÓS a obrigação e ANTES do caso.
        // Documentos do Acordo (Ajuste #4): FK onDelete CASCADE, mas deleção EXPLÍCITA (padrão do
        // módulo) ANTES do acordo. Arquivos físicos ficam no MESMO diretório flat de
        // cobrancas/<tenantId>/ (decisão deliberada — sem subdiretório novo) e já são cobertos pelo
        // prefixo físico do escritório (excluirPrefixo).
        ['cobranca_acordo_documento', 'tenant_id = :tenant'],
        ['cobranca_acordo', 'tenant_id = :tenant'],
        // Documentos/seções (Etapa 6): documento referencia seção e caso; seção referencia o caso
        // (ambos onDelete CASCADE). Apagados EXPLICITAMENTE (padrão do módulo) ANTES do caso —
        // documento antes de seção. Os arquivos físicos moram em cobrancas/<tenantId>/ e são
        // removidos pelo prefixo físico do escritório (não dependem de coletarArquivos).
        ['cobranca_documento', 'tenant_id = :tenant'],
        ['cobranca_secao', 'tenant_id = :tenant'],
        ['cobranca_caso', 'tenant_id = :tenant'],
        // Cobranças — cadastro (Etapa 1). As FKs entre si e para cliente/tenant são NO ACTION
        // (não cascateiam), então deleção explícita de baixo para cima: vínculo → objeto →
        // carteira → pessoa. A carteira referencia cliente (NO ACTION), por isso todo o bloco
        // vem ANTES de apagar cliente na Fase 2.
        ['cobranca_vinculo_pessoa_objeto', 'tenant_id = :tenant'],
        ['cobranca_objeto', 'tenant_id = :tenant'],
        // Espelho da contabilidade: linha e totalizador caem por CASCADE do lote, mas a deleção é
        // EXPLÍCITA e de baixo para cima (padrão do módulo). O lote referencia a carteira sem
        // cascata, por isso o bloco inteiro vem ANTES dela. Nada aqui é dado de dívida — é a cópia
        // do que a contabilidade informou, e vai embora junto com o escritório.
        ['cobranca_relatorio_linha', 'tenant_id = :tenant'],
        ['cobranca_relatorio_totalizador', 'tenant_id = :tenant'],
        ['cobranca_relatorio_importado', 'tenant_id = :tenant'],
        // Documentos da Carteira (Ajuste #5): FK onDelete CASCADE, mas deleção EXPLÍCITA (padrão do
        // módulo) ANTES da carteira. Mesmo diretório flat de cobrancas/<tenantId>/ (sem
        // subdiretório novo) — já coberto pelo prefixo físico do escritório.
        ['cobranca_carteira_documento', 'tenant_id = :tenant'],
        ['cobranca_carteira', 'tenant_id = :tenant'],
        ['cobranca_pessoa', 'tenant_id = :tenant'],
        // Modelos de checklist de documentos: pendem do TENANT, não da pasta — por isso NÃO caem
        // pela cascata de `pasta` logo abaixo e precisam de deleção própria. As linhas
        // (`pasta_checklist_modelo_item`) caem por CASCADE do modelo.
        ['pasta_checklist_modelo', 'tenant_id = :tenant'],

        // Fase 2 — raízes de subsistema (a CASCADE do banco derruba os filhos estruturais).
        ['tarefa', 'tenant_id = :tenant'],
        ['notificacao', 'tenant_id = :tenant'],
        ['pasta', 'tenant_id = :tenant'],
        ['processo', 'tenant_id = :tenant'],
        ['cliente', 'tenant_id = :tenant'],
        ['chamado', 'tenant_id = :tenant'],
        ['evento', 'tenant_id = :tenant'],
        ['kanban_board', 'tenant_id = :tenant'],
        ['marcador', 'tenant_id = :tenant'],
        ['legenda_cor', 'tenant_id = :tenant'],
        ['jornada_tenant', 'tenant_id = :tenant'],
        ['justificativa_ponto', 'tenant_id = :tenant'],
        ['registro_ponto', 'tenant_id = :tenant'],
        // Horas pagas (ajuste manual do banco de horas): FK tenant_id/user_id NOT DEFERRABLE, sem
        // ON DELETE CASCADE (NO ACTION) — deleção explícita, no mesmo bloco das outras tabelas de
        // ponto e ANTES da Fase 4 (que apaga o tenant). Nada referencia esta tabela, então a posição
        // relativa às demais entradas não importa, só precisa vir antes do DELETE FROM tenant.
        ['ponto_lancamento_horas_pagas', 'tenant_id = :tenant'],
        ['home_office_config', 'tenant_id = :tenant'],
        ['feriado', 'tenant_id = :tenant'],
        // Fase 3 — estruturais / permissões do tenant.
        ['aceite_termo', 'tenant_id = :tenant'],
        ['access_request', 'tenant_id = :tenant'],
        ['resource_access', 'tenant_id = :tenant'],
        ['invitation', 'tenant_id = :tenant'],
        ['tenant_role', 'tenant_id = :tenant'],
        ['cargo', 'tenant_id = :tenant'],
        ['lotacao', 'tenant_id = :tenant'],
        ['sede', 'tenant_id = :tenant'],
    ];

    /**
     * Categorias PLANAS: de onde saem os nomes, com a prova de pertencimento (D7, E2.5).
     *
     * Cada consulta devolve, por nome, se ele é referenciado por algum registro de OUTRO escritório
     * (`compartilhado`). Só os não compartilhados são removidos; os outros ficam e são reportados.
     * A junção é por hash (`LEFT JOIN … GROUP BY`): uma subconsulta correlacionada por linha
     * levou 32 s em 20.954 documentos no `saas_ux` — não há índice nas colunas de nome.
     *
     * A prova também exige que a CADEIA do registro seja toda do escritório — senão a linha pode
     * cair por CASCADE de um pai deste escritório sendo de outro, e o arquivo é tratado como sem
     * prova: fica e é reportado. No Kanban, anexo, card, mural do card e mural da coluna; na
     * pasta, o documento e a seção dele.
     *
     * @var array<string, array{0: CategoriaDeArquivo, 1: string}>
     */
    private const ARQUIVOS_POR_REGISTRO = [
        'cliente_documento' => [
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            'SELECT d.caminho_arquivo AS nome, bool_or(o.id IS NOT NULL) AS compartilhado
             FROM cliente_documento d
             LEFT JOIN cliente_documento o ON o.caminho_arquivo = d.caminho_arquivo AND o.tenant_id IS DISTINCT FROM d.tenant_id
             WHERE d.tenant_id = :tenant
             GROUP BY d.caminho_arquivo',
        ],
        'pasta_documento' => [
            CategoriaDeArquivo::PASTA_DOCUMENTO,
            'SELECT d.caminho_arquivo AS nome,
                    bool_or(d.tenant_id IS DISTINCT FROM :tenant
                            OR (s.id IS NOT NULL AND s.tenant_id IS DISTINCT FROM :tenant))
                        OR bool_or(o.id IS NOT NULL) AS compartilhado
             FROM pasta_documento d
             LEFT JOIN pasta_secao s ON s.id = d.secao_id
             LEFT JOIN pasta_documento o ON o.caminho_arquivo = d.caminho_arquivo AND o.tenant_id IS DISTINCT FROM :tenant
             WHERE d.tenant_id = :tenant OR s.tenant_id = :tenant
             GROUP BY d.caminho_arquivo',
        ],
        'justificativa_ponto' => [
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO,
            'SELECT d.anexo_path AS nome, bool_or(o.id IS NOT NULL) AS compartilhado
             FROM justificativa_ponto d
             LEFT JOIN justificativa_ponto o ON o.anexo_path = d.anexo_path AND o.tenant_id IS DISTINCT FROM d.tenant_id
             WHERE d.tenant_id = :tenant AND d.anexo_path IS NOT NULL
             GROUP BY d.anexo_path',
        ],
        'chamado_anexo' => [
            CategoriaDeArquivo::CHAMADO_ANEXO,
            'SELECT d.nome_arquivo AS nome, bool_or(oc.id IS NOT NULL) AS compartilhado
             FROM chamado_anexo d
             JOIN chamado c ON c.id = d.chamado_id
             LEFT JOIN chamado_anexo o ON o.nome_arquivo = d.nome_arquivo
             LEFT JOIN chamado oc ON oc.id = o.chamado_id AND oc.tenant_id IS DISTINCT FROM c.tenant_id
             WHERE c.tenant_id = :tenant
             GROUP BY d.nome_arquivo',
        ],
        'kanban_anexo' => [
            CategoriaDeArquivo::KANBAN_ANEXO,
            'SELECT a.caminho AS nome,
                    bool_or(a.tenant_id IS DISTINCT FROM :tenant
                            OR c.tenant_id IS DISTINCT FROM :tenant
                            OR b.tenant_id IS DISTINCT FROM :tenant
                            OR (k.id IS NOT NULL AND kb.tenant_id IS DISTINCT FROM :tenant))
                        OR bool_or(o.id IS NOT NULL) AS compartilhado
             FROM kanban_anexo a
             JOIN kanban_card c ON c.id = a.card_id
             JOIN kanban_board b ON b.id = c.board_id
             LEFT JOIN kanban_coluna k ON k.id = c.coluna_id
             LEFT JOIN kanban_board kb ON kb.id = k.board_id
             LEFT JOIN kanban_anexo o ON o.caminho = a.caminho AND o.tenant_id IS DISTINCT FROM :tenant
             WHERE a.tenant_id = :tenant OR c.tenant_id = :tenant OR b.tenant_id = :tenant OR kb.tenant_id = :tenant
             GROUP BY a.caminho',
        ],
    ];

    /**
     * Fora da abstração até a E2.7 (D5): a coluna guarda caminho público (`/uploads/tarefas/…`),
     * que `ChaveDeArquivo` recusa. A purga não monta chave nem toca o disco — lista os valores.
     */
    private const ANEXOS_DE_TAREFA_FORA_DA_PURGA =
        'SELECT DISTINCT arquivo_anexo FROM tarefa_mensagem WHERE tenant_id = :tenant AND arquivo_anexo IS NOT NULL';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ArmazenamentoComPrefixo $prefixo,
        private readonly ConsultaDeDestinoDaTransacao $consultaDeDestino,
        private readonly LoggerInterface $logger,
        private readonly int $carenciaPurgaDias,
    ) {
    }

    public function executar(Tenant $tenant, bool $dryRun): PurgaEscritorioResultado
    {
        $this->garantirElegivel($tenant);

        $tenantId = (int) $tenant->getId();
        $nome     = $tenant->getName() ?? '';
        $conn     = $this->em->getConnection();

        if ($dryRun === true) {
            return $this->simular($conn, $tenantId, $nome);
        }

        $aninhada   = $conn->getTransactionNestingLevel() > 0;
        $nivelAntes = $conn->getTransactionNestingLevel();
        $xid        = '';

        try {
            $conn->beginTransaction();

            // Nenhuma linha nova do escritório entra daqui até o COMMIT (ver o docblock da classe).
            $conn->executeQuery('SELECT id FROM tenant WHERE id = :tenant FOR UPDATE', ['tenant' => $tenantId]);
            $conn->executeQuery('SELECT id FROM chamado WHERE tenant_id = :tenant FOR UPDATE', ['tenant' => $tenantId]);

            // Fase 0 — os arquivos, ANTES de apagar as linhas e dentro da mesma transação: é aqui
            // que se prova, pelos registros, que cada arquivo plano é só deste escritório.
            ['chaves' => $chaves, 'semProva' => $semProva] = $this->coletarArquivos($conn, $tenantId);
            $foraDoEscopo = $this->anexosDeTarefa($conn, $tenantId);

            $linhas = [];

            foreach (self::ORDEM_DELECAO as [$tabela, $where]) {
                $n = (int) $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE %s', $tabela, $where),
                    ['tenant' => $tenantId],
                );

                if ($n > 0) {
                    $linhas[$tabela] = $n;
                }
            }

            // Fase 3.5 — verificação de integridade (guard anti-drift): nada tenant-scoped pode sobrar.
            $this->garantirTenantVazio($conn, $tenantId);

            // Fase 4 — o próprio tenant (agora sem filhas bloqueando).
            $conn->executeStatement('DELETE FROM tenant WHERE id = :tenant', ['tenant' => $tenantId]);
            $linhas['tenant'] = 1;

            // Fase 6 — auditoria: registra a purga (audit_log é retido; insert manual, pois o
            // hard delete é via DBAL e não passa pelo AuditLogSubscriber do ORM).
            $this->registrarAuditoria($conn, $tenantId, $nome);

            // A identidade da transação, lida ANTES do COMMIT: é por ela que se pergunta ao banco
            // o que aconteceu se o COMMIT falhar.
            $xid = (string) $conn->fetchOne('SELECT pg_current_xact_id()::text');
        } catch (\Throwable $e) {
            $this->desfazer($conn, $nivelAntes);

            throw $e;
        }

        try {
            $conn->commit();
        } catch (\Throwable $e) {
            $destino = $this->destinoDoCommit($aninhada, $xid);

            if ($destino !== DestinoDaTransacao::Confirmada) {
                $this->registrar('error', 'Purga: o COMMIT falhou e nenhum arquivo foi tocado.', [
                    'tenant'         => $tenantId,
                    'xid'            => $xid,
                    'destino'        => $destino->value,
                    'erro'           => $e->getMessage(),
                    'seria_removido' => array_map(static fn (ChaveDeArquivo $c): string => $c->comoTexto(), $chaves),
                    'prefixos'       => array_map(static fn (CategoriaComIsolamentoFisico $c): string => $c->value, CategoriaComIsolamentoFisico::cases()),
                    'sem_prova'      => $semProva,
                    'fora_da_purga'  => $foraDoEscopo,
                ]);

                if ($destino === DestinoDaTransacao::NaoConfirmada) {
                    throw $e; // provado: nada foi apagado
                }

                throw PurgaComDestinoIncerto::para($tenantId, $xid, $e);
            }

            $this->registrar('warning', 'Purga: o COMMIT lançou, mas o banco confirmou; o disco segue.', [
                'tenant' => $tenantId,
                'xid'    => $xid,
                'erro'   => $e->getMessage(),
            ]);
        }

        // Fase 5 — disco, só depois do COMMIT. Nada aqui lança: o que não sair vira registro.
        ['removidos' => $arquivosRemovidos, 'naoRemovidos' => $sobras] = $this->limparDisco($chaves, $tenantId);

        foreach ($semProva as $item) {
            $this->registrar('error', 'Purga: arquivo sem prova de pertencimento exclusivo ao escritório; ficou no disco.', [
                'tenant' => $tenantId,
                'item'   => $item,
            ]);
        }

        if ($foraDoEscopo !== []) {
            $this->registrar('warning', 'Purga: anexos de Tarefa ficaram no disco (fora da abstração até a E2.7).', [
                'tenant'   => $tenantId,
                'arquivos' => $foraDoEscopo,
            ]);
        }

        return new PurgaEscritorioResultado(
            $tenantId,
            $nome,
            false,
            $linhas,
            $arquivosRemovidos,
            array_merge($semProva, $sobras),
            $foraDoEscopo,
        );
    }

    /**
     * A simulação também olha os arquivos — só lendo: quantos a purga removeria, o que ficaria
     * sem prova de pertencimento e o que fica fora dela. O dry-run é o que o dono roda antes de
     * ligar o cron; um "0 arquivos" ali mentiria.
     */
    private function simular(Connection $conn, int $tenantId, string $nome): PurgaEscritorioResultado
    {
        ['chaves' => $chaves, 'semProva' => $semProva] = $this->coletarArquivos($conn, $tenantId);

        $previstos = 0;
        foreach ($chaves as $chave) {
            try {
                if ($this->armazenamento->existe($chave)) {
                    ++$previstos;
                }
            } catch (\Throwable $e) {
                $semProva[] = sprintf('%s: não foi possível verificar (%s)', $chave->comoTexto(), $e->getMessage());
            }
        }

        foreach (CategoriaComIsolamentoFisico::cases() as $categoria) {
            try {
                $previstos += \count(iterator_to_array(
                    $this->prefixo->listar(EscopoDeArquivo::deTenant($tenantId), $categoria),
                    false,
                ));
            } catch (\Throwable $e) {
                $semProva[] = sprintf('prefixo %s: %s', $categoria->value, $e->getMessage());
            }
        }

        return new PurgaEscritorioResultado(
            $tenantId,
            $nome,
            true,
            $this->contarPorTabela($conn, $tenantId),
            0,
            $semProva,
            $this->anexosDeTarefa($conn, $tenantId),
            $previstos,
        );
    }

    private function destinoDoCommit(bool $aninhada, string $xid): DestinoDaTransacao
    {
        if ($aninhada) {
            return DestinoDaTransacao::Incerta;
        }

        try {
            return $this->consultaDeDestino->destinoDe($xid);
        } catch (\Throwable) {
            return DestinoDaTransacao::Incerta;
        }
    }

    /**
     * Depois da decisão sobre o COMMIT, registrar não pode mudar o desfecho: um logger que lança
     * transformaria "banco purgado, arquivo ficou" (ou "resultado incerto") na mensagem "nada foi
     * apagado" do comando. O resultado devolvido continua sendo o rastro.
     *
     * @param array<string, mixed> $contexto
     */
    private function registrar(string $nivel, string $mensagem, array $contexto): void
    {
        try {
            $this->logger->log($nivel, $mensagem, $contexto);
        } catch (\Throwable) {
            // Sem onde registrar; o desfecho segue o que o banco decidiu.
        }
    }

    /** Desfaz só o nível que a purga abriu; uma falha aqui não troca a exceção original. */
    private function desfazer(Connection $conn, int $nivelAntes): void
    {
        try {
            if ($conn->getTransactionNestingLevel() > $nivelAntes) {
                $conn->rollBack();
            }
        } catch (\Throwable) {
            // A conexão caiu: o servidor aborta a transação sozinho.
        }
    }

    /**
     * Guarda de segurança: só purga escritório inativo (soft-deletado) cuja carência de
     * quarentena já venceu. Impede purgar um escritório ativo ou ainda recuperável, mesmo
     * se chamado por engano.
     */
    private function garantirElegivel(Tenant $tenant): void
    {
        if ($tenant->isActive() !== false) {
            throw new \LogicException('Só é possível purgar escritório inativo (soft-deletado).');
        }

        $excluidoEm = $tenant->getExcluidoEm();

        if ($excluidoEm === null) {
            throw new \LogicException('Escritório inativo sem data de exclusão — não elegível à purga.');
        }

        $limite = new \DateTimeImmutable(sprintf('-%d days', $this->carenciaPurgaDias));

        if ($excluidoEm > $limite) {
            throw new \LogicException('Escritório ainda dentro da carência de quarentena — não pode ser purgado.');
        }
    }

    /**
     * Tabelas com coluna tenant_id (descobertas do schema), exceto as retidas por decisão.
     * Serve tanto ao dry-run quanto à verificação de integridade e é imune a drift.
     *
     * @return list<string>
     */
    private function tabelasComTenantId(Connection $conn): array
    {
        $tabelas = $conn->fetchFirstColumn(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = 'public' AND column_name = 'tenant_id'
             ORDER BY table_name",
        );

        return array_values(array_diff($tabelas, self::TABELAS_RETIDAS));
    }

    /**
     * Contagem do dry-run: usa as MESMAS cláusulas WHERE da ORDEM_DELECAO, então o número
     * por tabela espelha exatamente o que a execução real apaga diretamente (inclui as
     * tabelas-ponte sem tenant_id, como bloco_jornada e tenant_role_permission). As
     * linhas-filhas removidas por CASCADE do banco não são itemizadas aqui (nem no real).
     *
     * @return array<string,int>
     */
    private function contarPorTabela(Connection $conn, int $tenantId): array
    {
        $contagens = [];

        foreach (self::ORDEM_DELECAO as [$tabela, $where]) {
            $n = (int) $conn->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE %s', $tabela, $where),
                ['tenant' => $tenantId],
            );

            if ($n > 0) {
                $contagens[$tabela] = $n;
            }
        }

        return $contagens;
    }

    /**
     * Confere que nenhuma tabela tenant-scoped ainda tem linha do tenant antes de apagá-lo.
     * Se sobrou algo, aborta com o nome da tabela (rollback) em vez de estourar um FK
     * críptico — protege contra tabela tenant_id nova não coberta pela ORDEM_DELECAO.
     */
    private function garantirTenantVazio(Connection $conn, int $tenantId): void
    {
        foreach ($this->tabelasComTenantId($conn) as $tabela) {
            $n = (int) $conn->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE tenant_id = :tenant', $tabela),
                ['tenant' => $tenantId],
            );

            if ($n > 0) {
                throw new \RuntimeException(sprintf(
                    'Purga abortada: a tabela "%s" ainda tem %d linha(s) do escritório %d — '
                    . 'provável tabela tenant-scoped nova fora da ordem de deleção, ou linha deste '
                    . 'escritório pendurada em registro de outro (ex.: card em mural alheio). Nada foi apagado.',
                    $tabela,
                    $n,
                    $tenantId,
                ));
            }
        }
    }

    /**
     * As chaves dos arquivos PLANOS provadamente deste escritório, e o que ficou de fora por não
     * ter prova (nome também referenciado por outro escritório, cadeia do registro que passa por
     * outro escritório, ou nome recusado pela chave).
     *
     * Só lê, e não registra nada: roda dentro da transação da purga (que pode ser desfeita) e na
     * simulação. Quem registra é quem sabe o desfecho.
     *
     * Nomes repetidos dentro do escritório (o anexo de um lote do Ponto é gravado em N
     * justificativas) viram UMA chave: o `GROUP BY` já deduplica.
     *
     * @return array{chaves: list<ChaveDeArquivo>, semProva: list<string>}
     */
    private function coletarArquivos(Connection $conn, int $tenantId): array
    {
        $escopo   = EscopoDeArquivo::deTenant($tenantId);
        $chaves   = [];
        $semProva = [];

        foreach (self::ARQUIVOS_POR_REGISTRO as $tabela => [$categoria, $sql]) {
            foreach ($conn->fetchAllAssociative($sql, ['tenant' => $tenantId]) as $linha) {
                $nomeArquivo = $linha['nome'];

                if (!\is_string($nomeArquivo) || $nomeArquivo === '') {
                    continue;
                }

                // Na dúvida sobre o valor devolvido, é compartilhado: falha fechada, o arquivo fica.
                if (!\in_array($linha['compartilhado'], [false, 'f', 0, '0'], true)) {
                    $semProva[] = sprintf(
                        '%s/%s (sem prova de que é só deste escritório: outro escritório referencia o nome, '
                        . 'ou o registro está numa cadeia de outro escritório)',
                        $tabela,
                        $nomeArquivo,
                    );
                    continue;
                }

                try {
                    $chaves[] = new ChaveDeArquivo($escopo, $categoria, $nomeArquivo);
                } catch (ChaveDeArquivoInvalida $e) {
                    $semProva[] = sprintf('%s: nome recusado pela chave (%s)', $tabela, $e->getMessage());
                }
            }
        }

        return ['chaves' => $chaves, 'semProva' => $semProva];
    }

    /**
     * Os anexos de Tarefa do escritório, como texto — sem chave e sem disco (D5, E2.7).
     *
     * @return list<string>
     */
    private function anexosDeTarefa(Connection $conn, int $tenantId): array
    {
        return array_values(array_filter(
            $conn->fetchFirstColumn(self::ANEXOS_DE_TAREFA_FORA_DA_PURGA, ['tenant' => $tenantId]),
            static fn (mixed $valor): bool => \is_string($valor) && $valor !== '',
        ));
    }

    /**
     * Remove os arquivos planos um a um e o prefixo físico do escritório nas duas categorias que
     * o têm. Nunca lança: o banco já foi confirmado.
     *
     * @param list<ChaveDeArquivo> $chaves
     *
     * @return array{removidos: int, naoRemovidos: list<string>}
     */
    private function limparDisco(array $chaves, int $tenantId): array
    {
        $resultado    = $this->remocao->remover($chaves, sprintf('PurgarEscritorioUseCase: escritório %d', $tenantId));
        $removidos    = $resultado->removidos;
        $naoRemovidos = $resultado->naoRemovidas;

        foreach (CategoriaComIsolamentoFisico::cases() as $categoria) {
            try {
                $prefixo      = $this->prefixo->excluirPrefixo(EscopoDeArquivo::deTenant($tenantId), $categoria);
                $removidos   += $prefixo->removidos;
                $naoRemovidos = array_merge($naoRemovidos, $prefixo->naoRemovidas);

                if (!$prefixo->completa()) {
                    $this->registrar('error', 'Purga: o diretório do escritório saiu só em parte; o resto ficou no disco.', [
                        'tenant'    => $tenantId,
                        'categoria' => $categoria->value,
                        'removidos' => $prefixo->removidos,
                        'ficaram'   => $prefixo->naoRemovidas,
                    ]);
                }
            } catch (\Throwable $e) {
                // Pertencimento não provado: o prefixo ficou INTEIRO (nada foi removido dele).
                $naoRemovidos[] = sprintf('prefixo %s inteiro: %s', $categoria->value, $e->getMessage());

                $this->registrar('error', 'Purga: o diretório do escritório não foi removido; ficou no disco.', [
                    'tenant'    => $tenantId,
                    'categoria' => $categoria->value,
                    'erro'      => $e->getMessage(),
                ]);
            }
        }

        return ['removidos' => $removidos, 'naoRemovidos' => $naoRemovidos];
    }

    private function registrarAuditoria(Connection $conn, int $tenantId, string $nome): void
    {
        $conn->insert('audit_log', [
            'action'       => 'purge',
            'entity_class' => Tenant::class,
            'entity_id'    => (string) $tenantId,
            'changes'      => json_encode(['nome' => $nome, 'motivo' => 'quarentena_expirada'], \JSON_THROW_ON_ERROR),
            'tenant_id'    => $tenantId,
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
