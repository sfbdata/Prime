<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
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
use App\Tenant\Exception\PurgaRecusada;
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
 *    purga é registrada como evento), permission (catálogo global) e cadastro_pendente.
 *    A jornada pessoal é por escritório desde a spec seguranca-b-jornada e sai na purga.
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
    /**
     * Tabelas com coluna tenant_id que NÃO são apagadas na purga (retidas por decisão de produto).
     * comercial_consentimento: prova de consentimento, 5 anos depois do término (Política L379); tenant_id sem FK.
     */
    private const TABELAS_RETIDAS = ['audit_log', 'comercial_consentimento'];

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
        ['bloco_jornada_colaborador', 'jornada_colaborador_id IN (SELECT id FROM jornada_colaborador WHERE tenant_id = :tenant)'],
        ['tenant_role_permission', 'tenant_role_id IN (SELECT id FROM tenant_role WHERE tenant_id = :tenant)'],
        ['cliente_documento', 'tenant_id = :tenant'],
        // Histórico do cadastro (E12 3d): cairia pela CASCADE de `cliente`, mas a FK de tenant é NO ACTION.
        ['cliente_historico', 'tenant_id = :tenant'],
        // Favoritos de arquivo/subpasta da aba Documentos (por usuário): cairiam pela CASCADE de
        // `pasta_documento`/`pasta_secao`, mas a FK de tenant é NO ACTION — deleção explícita
        // ANTES do documento, para a cobertura ficar visível aqui.
        ['pasta_documento_favorito', 'tenant_id = :tenant'],
        // Banco de modelos de petições (aba Modelos): estrelas antes do modelo, que as levaria por CASCADE
        // (a FK de tenant é NO ACTION).
        ['modelo_de_peca_uso', 'tenant_id = :tenant'],
        ['modelo_de_peca_favorito', 'tenant_id = :tenant'],
        ['materia_favorita_de_modelo', 'tenant_id = :tenant'],
        ['modelo_de_peca', 'tenant_id = :tenant'],
        ['pasta_documento', 'tenant_id = :tenant'],
        ['movimentacao_processo', 'tenant_id = :tenant'],
        ['parte_processo', 'tenant_id = :tenant'],
        ['assunto_processo', 'tenant_id = :tenant'],
        ['saida_da_equipe', 'tenant_id = :tenant'],
        ['resumo_diario_envio', 'tenant_id = :tenant'],
        ['user_tenant', 'tenant_id = :tenant'],
        // Notas técnicas do processo (tenant_id direto). Cairiam por cascata do processo, mas a FK à
        // publicação é SET NULL: apagar antes evita o UPDATE inútil e deixa a deleção explícita.
        // Versões anteriores da nota (PMC-020): caem pela CASCADE da nota, mas a FK de tenant é
        // NO ACTION — deleção explícita, ANTES da nota.
        ['nota_tecnica_versao', 'tenant_id = :tenant'],
        ['nota_tecnica', 'tenant_id = :tenant'],
        // Previsões da Situação atual do Push (taxa de acertos): caem pela CASCADE do processo, mas
        // a FK de tenant é NO ACTION; deleção explícita antes de apagar o processo e o tenant.
        ['previsao_processual', 'tenant_id = :tenant'],
        // DJEN — publicações e OABs monitoradas (tenant_id direto; FKs a processo/oab_monitorada
        // são SET NULL, então não caem por cascata — precisam de deleção explícita).
        ['publicacao_djen', 'tenant_id = :tenant'],
        ['oab_monitorada', 'tenant_id = :tenant'],
        ['djen_processo_acompanhado', 'tenant_id = :tenant'],
        // Diário da OAB registrado à mão (tenant_id direto; FK tenant e usuário NO ACTION): deleção explícita.
        ['djen_diario_oab', 'tenant_id = :tenant'],
        // Sync — conexão de Drive do escritório (guarda o refresh_token CIFRADO). FK tenant NO ACTION
        // (bloqueia apagar o tenant) + tenant_id direto → deleção explícita. Apaga o segredo junto.
        ['sync_drive_conexao', 'tenant_id = :tenant'],
        // BlueJus IA — análises e configuração do escritório (tenant_id direto; FK tenant NO ACTION,
        // FKs a "user" SET NULL). Deleção explícita antes de apagar o tenant. As perguntas da aba
        // Perguntar (F3) referenciam a análise (CASCADE) e o tenant (NO ACTION): saem ANTES dela.
        ['inteligencia_pergunta', 'tenant_id = :tenant'],
        ['inteligencia_analise', 'tenant_id = :tenant'],
        ['inteligencia_configuracao', 'tenant_id = :tenant'],
        // Contribua — plano e limites de espaço do escritório (tenant_id direto; FK tenant NO ACTION, nada a
        // referencia). O catálogo de planos e a configuração são globais e ficam.
        ['contribua_espaco_do_escritorio', 'tenant_id = :tenant'],
        // Bônus de espaço por indicação (tenant_id direto; FK tenant NO ACTION, nada o referencia).
        ['contribua_bonus_de_espaco', 'tenant_id = :tenant'],
        // Contribua C2: sugestões, apoios informados e motivos anônimos (tenant_id direto; FKs a "user"
        // SET NULL; nada as referencia).
        ['contribua_sugestao', 'tenant_id = :tenant'],
        ['contribua_apoio', 'tenant_id = :tenant'],
        // Contribua C4: cobranças PIX abertas da conta. FK de tenant CASCADE; explícito por convenção.
        ['contribua_cobranca_pix', 'tenant_id = :tenant'],
        // Service Desk (desenho 12): razão do saldo. Apagado como o apoio do Contribua (decisão do dono: exclusão apaga
        // na hora). Sem FK entre as duas; a FK de tenant é CASCADE, mas a deleção explícita deixa a cobertura visível.
        ['service_desk_chamado_historico', 'tenant_id = :tenant'],
        ['service_desk_chamado', 'tenant_id = :tenant'],
        ['service_desk_movimento', 'tenant_id = :tenant'],
        ['service_desk_saldo', 'tenant_id = :tenant'],
        // Gateway de pagamentos (M0): trilha antes do pagamento (a FK da trilha é CASCADE, mas a deleção explícita
        // deixa a cobertura visível). Apagado na hora, como o apoio e o razão (decisão do dono: exclusão apaga na hora).
        ['pagamento_evento', 'tenant_id = :tenant'],
        ['pagamento', 'tenant_id = :tenant'],
        ['contribua_motivo_de_nao_apoiar', 'tenant_id = :tenant'],
        // Contribua C3: pedidos e assinaturas de espaço (tenant_id direto; FKs a "user" SET NULL; nada as
        // referencia). A quarentena de 365 dias passa muito do fim de qualquer mês pago.
        ['contribua_assinatura', 'tenant_id = :tenant'],
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
        // Favoritos de pasta (por usuário): cairiam pela CASCADE de `pasta`, mas a FK de tenant é
        // NO ACTION e a deleção explícita deixa a cobertura visível aqui.
        ['pasta_favorita', 'tenant_id = :tenant'],
        // "Acompanhar alterações" desligado (por usuário): mesmo caso do favorito.
        ['pasta_acompanhamento_desligado', 'tenant_id = :tenant'],
        // Destaque de linha da Lista do Expediente (por usuário, E12): mesmo caso do favorito.
        ['pasta_destaque', 'tenant_id = :tenant'],
        // Destaque de comentário da pasta (spec pasta-destacar-comentario.md): FK de tenant NO ACTION; nada o referencia.
        ['pasta_comentario_destaque', 'tenant_id = :tenant'],
        // Chaves do "Avisar quando chegar" do Push (por pasta): cairiam pela CASCADE de `pasta`, mas a FK
        // de tenant é NO ACTION; deleção explícita aqui.
        ['pasta_aviso_push', 'tenant_id = :tenant'],
        // "Avisar cliente" do Push (P4c): o histórico de envios cai pela CASCADE de `pasta` e os e-mails do
        // cliente pela de `cliente`, mas a FK de tenant das duas é NO ACTION; deleção explícita aqui.
        ['envio_ao_cliente', 'tenant_id = :tenant'],
        ['cliente_email', 'tenant_id = :tenant'],
        // Chaves do envio automático ao cliente (P4d): cairiam pela CASCADE de `cliente`, mas a FK de
        // tenant é NO ACTION; deleção explícita aqui.
        ['cliente_envio_automatico', 'tenant_id = :tenant'],
        // Prazos e audiências da pasta (E12b): cairiam pela CASCADE de `pasta`; FK de tenant NO ACTION.
        ['pasta_prazo', 'tenant_id = :tenant'],
        // Andamento da pasta em fases (E12b): mesmo caso.
        ['pasta_andamento', 'tenant_id = :tenant'],
        // Réus cadastrados na pasta (E12h): mesmo caso.
        ['pasta_reu', 'tenant_id = :tenant'],
        // Cobrança automática do checklist (por item): cairia pela CASCADE do item; FK de tenant NO ACTION.
        ['pasta_checklist_cobranca', 'tenant_id = :tenant'],
        // Visitas à Timeline inteligente (por usuário, PAS-043): mesmo caso do favorito — cairiam
        // pela CASCADE de `pasta`, mas a FK de tenant é NO ACTION; deleção explícita aqui.
        ['pasta_timeline_visita', 'tenant_id = :tenant'],
        // Relatório de pastas sem processo (spec relatorio-processos-controladoria): a decisão cairia pela
        // CASCADE de `pasta`, mas a FK de tenant é NO ACTION; o histórico de envios só tem a FK de tenant.
        ['pasta_decisao_sem_processo', 'tenant_id = :tenant'],
        ['relatorio_sem_processo_envio', 'tenant_id = :tenant'],
        // Link externo do Push e seus acessos: cairiam pela CASCADE de `pasta`, mas as FKs de tenant
        // são NO ACTION. Acesso antes do link (filho antes do pai).
        ['push_link_externo_acesso', 'tenant_id = :tenant'],
        ['push_link_externo', 'tenant_id = :tenant'],
        // Cadeado da pasta (spec pasta-cadeado): cairiam pela CASCADE de `pasta`, mas as FKs de tenant são NO
        // ACTION. A lista de pessoas antes do cadeado (filho antes do pai); os pedidos de acesso à parte.
        ['pasta_cadeado_acesso', 'tenant_id = :tenant'],
        ['pasta_cadeado', 'tenant_id = :tenant'],
        ['pasta_pedido_acesso', 'tenant_id = :tenant'],
        // Abas ocultas da pasta (spec pasta-ocultar-aba §1): mesmo caso, CASCADE de `pasta` e FK de tenant NO ACTION.
        ['pasta_aba_oculta', 'tenant_id = :tenant'],
        // Relatório de pastas paradas (spec pastas-paradas): o envio do mês cairia pela CASCADE de
        // tenant, mas a purga apaga explicitamente, como o histórico do relatório irmão.
        ['relatorio_pastas_paradas_envio', 'tenant_id = :tenant'],
        // Pedido de exclusão da conta e os códigos da etapa 2 (PR 5): FKs de tenant NO ACTION. O histórico
        // do pedido fica no audit_log (retido); o motivo é texto do cliente e sai com o escritório.
        ['codigo_exclusao_conta', 'tenant_id = :tenant'],
        ['pedido_exclusao_conta', 'tenant_id = :tenant'],
        // Preferências pessoais de tela (menu ⋮ do Dashboard): a FK de usuário é CASCADE, mas o
        // usuário não é apagado na purga e a de tenant é NO ACTION — sai aqui, explicitamente.
        ['preferencia_usuario', 'tenant_id = :tenant'],
        // Meu Perfil: tipo de vínculo e datas do contrato da pessoa com este escritório.
        ['vinculo_no_escritorio', 'tenant_id = :tenant'],
        // Foto diária do estoque do Dashboard (dado derivado, por usuário): FK de tenant NO ACTION,
        // nada a referencia — deleção explícita antes de apagar o tenant.
        ['dashboard_foto', 'tenant_id = :tenant'],
        // Zerar de coluna e meta de pastas do Dashboard (dashboard-parte-1, PR3): FKs de tenant NO
        // ACTION, nada as referencia.
        ['dashboard_zeramento', 'tenant_id = :tenant'],
        ['dashboard_meta_pastas', 'tenant_id = :tenant'],
        // Acompanhamento de pessoa do painel (spec painel-acompanhamento-redistribuir, fatia A): FK de tenant NO ACTION.
        ['dashboard_acompanhamento_pessoa', 'tenant_id = :tenant'],
        // Central de Comunicação (A1): mensagem e participante caem pela CASCADE da conversa, mas as FKs de
        // tenant são NO ACTION — deleção explícita de baixo para cima, antes de apagar o tenant.
        ['central_mensagem', 'tenant_id = :tenant'],
        ['central_participante', 'tenant_id = :tenant'],
        ['central_conversa', 'tenant_id = :tenant'],
        // Chat I.A em página inteira (spec chat-pagina-inteira.md): anexo e mensagem caem pela CASCADE da conversa, mas as
        // FKs de tenant são NO ACTION. Deleção explícita de baixo para cima; os arquivos saem pela lista de registros.
        ['chat_ia_proposta', 'tenant_id = :tenant'],
        ['chat_ia_anexo', 'tenant_id = :tenant'],
        ['chat_ia_mensagem', 'tenant_id = :tenant'],
        ['chat_ia_conversa', 'tenant_id = :tenant'],
        // Presença na Central (F2): uma linha por vínculo, FK de tenant NO ACTION.
        ['central_presenca', 'tenant_id = :tenant'],
        // Regra de ausência do escritório (presença, fase 3): uma linha por escritório, FK de tenant NO ACTION.
        ['central_configuracao_presenca', 'tenant_id = :tenant'],
        // Opção de edição sem prazo do primário (edicao-sem-prazo §3): uma linha por escritório, FK de tenant NO ACTION.
        ['escritorio_configuracao_edicao', 'tenant_id = :tenant'],
        // Baixa de aviso na Central (F3): cai com a notificação (CASCADE), mas a FK de tenant é NO ACTION.
        ['central_aviso_resolvido', 'tenant_id = :tenant'],
        // Descadastro de e-mail do escritório (Shared, "Não quero mais receber estes avisos"): FK de tenant
        // NO ACTION e nada a referencia. Sai com o escritório: sem ele não há quem envie.
        ['email_descadastro', 'tenant_id = :tenant'],
        // Comercial (docs/specs/comercial-e-emails.md §3.2): o que um consumidor enviou com escopo deste escritório. FK
        // de tenant NO ACTION; o conteúdo do envio cai junto (CASCADE). A prova de consentimento é retida (acima).
        ['comercial_envio', 'tenant_id = :tenant'],
        ['comercial_confirmacao_endereco', 'tenant_id = :tenant'],
        ['comercial_ciclo_conta', 'tenant_id = :tenant'],
        // A vez dos avisos de espaço do escritório (comercial-e-emails.md §4): FK NO ACTION, nada a referencia.
        ['comercial_aviso_espaco', 'tenant_id = :tenant'],
        // Central de Notificações do Master (docs/specs/master-central-de-notificacoes.md, D3): estado por Master e
        // respostas de mensagens deste escritório. `tenant_id` sem FK (o Master lê todos os escritórios, as tabelas
        // não são TenantAware); sai explicitamente para não ficar órfão.
        ['central_master_estado', 'tenant_id = :tenant'],
        ['central_master_resposta', 'tenant_id = :tenant'],
        // Contador da sequência de metas do escritório ("Nº 0001"): FK de tenant NO ACTION, nada o referencia.
        ['tarefa_numeracao', 'tenant_id = :tenant'],
        // Caixas da esteira de Minhas Metas (nome e avisados): os avisados caem por CASCADE da caixa.
        ['tarefa_caixa_esteira', 'tenant_id = :tenant'],
        // Aprendizado de Minhas Metas (Fase 4): por usuário e escritório, FK de tenant NO ACTION, nada o referencia.
        ['tarefa_aprendizado_despacho', 'tenant_id = :tenant'],
        ['tarefa_aprendizado_pendencia', 'tenant_id = :tenant'],

        // Fase 2 — raízes de subsistema (a CASCADE do banco derruba os filhos estruturais).
        ['tarefa', 'tenant_id = :tenant'],
        ['notificacao', 'tenant_id = :tenant'],
        ['pasta', 'tenant_id = :tenant'],
        ['processo', 'tenant_id = :tenant'],
        ['cliente', 'tenant_id = :tenant'],
        ['chamado', 'tenant_id = :tenant'],
        ['evento', 'tenant_id = :tenant'],
        ['kanban_board', 'tenant_id = :tenant'],
        // Termo de Voluntariado (CPF e RG no snapshot): o escritório emissor é a sede da Central. A FK de tenant é NO ACTION; a do
        // currículo é CASCADE, então o termo também sai com o currículo.
        ['trabalho_externo_termo_voluntariado', 'tenant_id = :tenant'],
        // Trabalho Externo: as demandas que o escritório SOLICITOU. Histórico, mensagens, leituras,
        // propostas e documentos caem pela CASCADE da demanda. Se o escritório for a sede da
        // Central, a configuração perde a sede por SET NULL (a Central não é apagada).
        ['trabalho_externo_demanda', 'tenant_id = :tenant'],
        ['marcador', 'tenant_id = :tenant'],
        ['legenda_cor', 'tenant_id = :tenant'],
        // Agenda: ajustes do escritório nas datas do sistema (botão direito). FK de tenant CASCADE; explícito por convenção.
        ['agenda_data_ajuste', 'tenant_id = :tenant'],
        // Agenda (5b): inscrições de push e registro de envios. FKs de tenant CASCADE; explícito por convenção.
        ['agenda_push_enviado', 'tenant_id = :tenant'],
        ['agenda_push_inscricao', 'tenant_id = :tenant'],
        // Agenda (6): conexões com o Google Agenda (refresh token cifrado), ligações e exclusões pendentes. FKs CASCADE; explícito por convenção.
        ['agenda_google_ligacao', 'tenant_id = :tenant'],
        ['agenda_google_prazo', 'tenant_id = :tenant'],
        ['agenda_google_data', 'tenant_id = :tenant'],
        ['agenda_google_exclusao', 'tenant_id = :tenant'],
        ['agenda_google_conexao', 'tenant_id = :tenant'],
        ['jornada_tenant', 'tenant_id = :tenant'],
        ['jornada_colaborador', 'tenant_id = :tenant'],
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
        // O documento VINCULADO do cadastro (pasta_documento_id) não tem arquivo próprio: o arquivo é o da pasta.
        'cliente_documento' => [
            CategoriaDeArquivo::CLIENTE_DOCUMENTO,
            'SELECT d.caminho_arquivo AS nome, bool_or(o.id IS NOT NULL) AS compartilhado
             FROM cliente_documento d
             LEFT JOIN cliente_documento o ON o.caminho_arquivo = d.caminho_arquivo AND o.tenant_id IS DISTINCT FROM d.tenant_id
                                           AND o.pasta_documento_id IS NULL
             WHERE d.tenant_id = :tenant AND d.pasta_documento_id IS NULL
             GROUP BY d.caminho_arquivo',
        ],
        // A foto do cliente: a linha é o próprio cliente (coluna `foto`), sem cadeia acima dele.
        'cliente' => [
            CategoriaDeArquivo::CLIENTE_FOTO,
            'SELECT d.foto AS nome, bool_or(o.id IS NOT NULL) AS compartilhado
             FROM cliente d
             LEFT JOIN cliente o ON o.foto = d.foto AND o.tenant_id IS DISTINCT FROM d.tenant_id
             WHERE d.tenant_id = :tenant AND d.foto IS NOT NULL
             GROUP BY d.foto',
        ],
        // A logo do escritório (Meu Escritório): a linha é o próprio escritório (coluna `logo_chave`).
        'tenant' => [
            CategoriaDeArquivo::TENANT_LOGO,
            'SELECT d.logo_chave AS nome, bool_or(o.id IS NOT NULL) AS compartilhado
             FROM tenant d
             LEFT JOIN tenant o ON o.logo_chave = d.logo_chave AND o.id <> d.id
             WHERE d.id = :tenant AND d.logo_chave IS NOT NULL
             GROUP BY d.logo_chave',
        ],
        // Os anexos do Chat I.A: a linha é o próprio anexo (coluna `chave`), com escritório próprio.
        'chat_ia_anexo' => [
            CategoriaDeArquivo::CHAT_IA_ANEXO,
            'SELECT d.chave AS nome, bool_or(o.id IS NOT NULL) AS compartilhado
             FROM chat_ia_anexo d
             LEFT JOIN chat_ia_anexo o ON o.chave = d.chave AND o.tenant_id IS DISTINCT FROM d.tenant_id
             WHERE d.tenant_id = :tenant
             GROUP BY d.chave',
        ],
        // A foto do cabeçalho da pasta sem cliente: a linha é a própria pasta (coluna `foto`), sem cadeia acima dela.
        'pasta' => [
            CategoriaDeArquivo::PASTA_FOTO,
            'SELECT d.foto AS nome, bool_or(o.id IS NOT NULL) AS compartilhado
             FROM pasta d
             LEFT JOIN pasta o ON o.foto = d.foto AND o.tenant_id IS DISTINCT FROM d.tenant_id
             WHERE d.tenant_id = :tenant AND d.foto IS NOT NULL
             GROUP BY d.foto',
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
        'trabalho_externo_documento' => [
            CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO,
            'SELECT d.nome_armazenado AS nome, bool_or(od.id IS NOT NULL) AS compartilhado
             FROM trabalho_externo_documento d
             JOIN trabalho_externo_demanda m ON m.id = d.demanda_id
             LEFT JOIN trabalho_externo_documento o ON o.nome_armazenado = d.nome_armazenado AND o.id <> d.id
             LEFT JOIN trabalho_externo_demanda od ON od.id = o.demanda_id AND od.tenant_id IS DISTINCT FROM m.tenant_id
             WHERE m.tenant_id = :tenant
             GROUP BY d.nome_armazenado',
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
        private readonly RegraDosMasters $regraDosMasters,
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
            // Com a linha travada, as guardas de novo sobre o BANCO: a conta pode ter sido recuperada depois de entrar na lista.
            $this->garantirElegivelNoBanco($conn, $tenant);

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
     * quarentena já venceu, e nunca a conta do Master (spec perfis-1.2-contas C5). Impede purgar um
     * escritório ativo ou ainda recuperável, mesmo se chamado por engano.
     */
    private function garantirElegivel(Tenant $tenant): void
    {
        $this->exigirQuarentenaVencida($tenant->isActive(), $tenant->getExcluidoEm());
        $this->exigirQueNaoEhContaDoMaster($tenant);
    }

    /**
     * As mesmas guardas, RELIDAS do banco depois do `FOR UPDATE` (corrida com o Master): entre a lista
     * do job e este ponto a conta pode ter sido recuperada da quarentena (`ReativarContaUseCase`) ou
     * ter mudado de criador, ou o criador pode ter virado Master. Qualquer diferença aborta só esta conta,
     * dentro da transação, sem apagar nada.
     *
     * A recusa ({@see PurgaRecusada}) sai como FAILURE no `app:purgar-dados-expirados` DE PROPÓSITO: conta
     * em quarentena vencida que não pode ser purgada (recuperada no meio, ou do Master) precisa chegar ao
     * dono como alerta, não sumir num log.
     */
    private function garantirElegivelNoBanco(Connection $conn, Tenant $tenant): void
    {
        // O "criador é Master?" também vem do banco (roles do criador agora), não do User em memória.
        $linha = $conn->fetchAssociative(
            'SELECT t.is_active, t.excluido_em, t.criado_por_id, COALESCE(u.roles::text LIKE :papel, FALSE) AS criador_master
               FROM tenant t LEFT JOIN "user" u ON u.id = t.criado_por_id WHERE t.id = :tenant',
            ['tenant' => $tenant->getId(), 'papel' => '%"' . RegraDosMasters::PAPEL_MASTER . '"%'],
        );
        if ($linha === false) {
            throw new PurgaRecusada('Escritório não existe mais; nada a purgar.');
        }

        $this->exigirQuarentenaVencida((bool) $linha['is_active'], $linha['excluido_em'] !== null ? new \DateTimeImmutable((string) $linha['excluido_em']) : null);

        $criadorNoBanco = $linha['criado_por_id'] !== null ? (int) $linha['criado_por_id'] : null;
        if ($criadorNoBanco !== $tenant->getCriadoPor()?->getId()) {
            throw new PurgaRecusada('O criador do escritório mudou depois da seleção para a purga; purga adiada.');
        }

        if ((bool) $linha['criador_master']) {
            throw new PurgaRecusada('A conta do Master nunca é purgada.');
        }

        // O fallback (sem criador: Master com vínculo ativo) consulta o banco na mesma transação.
        $this->exigirQueNaoEhContaDoMaster($tenant);
    }

    private function exigirQuarentenaVencida(?bool $ativo, ?\DateTimeImmutable $excluidoEm): void
    {
        if ($ativo !== false) {
            throw new PurgaRecusada('Só é possível purgar escritório inativo (soft-deletado).');
        }

        if ($excluidoEm === null) {
            throw new PurgaRecusada('Escritório inativo sem data de exclusão: não elegível à purga.');
        }

        $limite = new \DateTimeImmutable(sprintf('-%d days', $this->carenciaPurgaDias));

        if ($excluidoEm > $limite) {
            throw new PurgaRecusada('Escritório ainda dentro da carência de quarentena: não pode ser purgado.');
        }
    }

    /** A purga nunca apaga a conta do Master: a mesma regra da C5, com o fallback do vínculo. */
    private function exigirQueNaoEhContaDoMaster(Tenant $tenant): void
    {
        if ($this->regraDosMasters->ehContaDoMaster($tenant)) {
            throw new PurgaRecusada('A conta do Master nunca é purgada.');
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
