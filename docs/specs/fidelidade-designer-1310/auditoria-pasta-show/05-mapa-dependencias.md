# 05. Mapa de dependências

> Auditoria integral da `pasta_show` contra o protótipo ONLINE `02 - EXPEDIENTES 1.2.3.dc.html` (baixado em 16/10/2026), worktree `fidelidade-designer` @ `9c081725`. Somente leitura. Índice e resumo em `00-LEIAME.md`. "dc:N" = linha do protótipo online sem os blocos injetados pelo serviço de pré-visualização.

Este documento junta (1) a matriz de segurança dos comandos que alteram dados a partir da pasta_show, levantada no backend, e (2) o resumo das dependências de cada lacuna (backend, arquivo global, decisão do dono), na seção final escrita pelo orquestrador.

# Parte 1. Comandos que alteram dados (segurança)


### 2. Matriz de comandos que alteram dados (acionáveis da pasta_show)

Colunas: rota (nome, método, path) | controller::ação | UseCase/serviço | entidade e operação | autorização | isolamento | CSRF (id do token) | validação | auditoria | erro | testes | observação.
Todas as rotas abaixo estão sob o listener de lápide, salvo indicação.

#### 2.1 Cabeçalho e dados da pasta

| Rota | Controller | UseCase | Entidade/op | Autorização | Isolamento | CSRF | Validação | Audit | Erro | Testes | Obs. |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `pasta_edit` POST /pasta/{id}/editar | PastaController::editar :1400 | EditarPastaUseCase | Pasta update (nup, nomeCliente, nomeAcao, situacao) | R:edit (redirect pasta_show) :1407 | TF no resolver | `edit_pasta_{id}` :1411, inválido lança 403 | DTO `EditarPastaDTO` sem Assert; NUP obrigatório no UseCase; situação só `ativo`/`arquivado` (UseCase:30); campo ausente mantém valor (PastaController:1419-1430) | Pasta Auditavel | flash `error` + redirect | EditarPastaControllerTest, EditarPastaUseCaseTest, PastaEditarDadosTelaTest | "Alterar número" é este mesmo endpoint (NUP); NUP pode repetir (UseCase:25). Dispara sync Drive se nome mudou :1442 |
| `pasta_alternar_situacao` POST /alternar-situacao | ::alternarSituacao :1489 | nenhum (setSituacao + flush no controller) | Pasta update ativo↔arquivado | R:edit :1496 | TF | `pasta_alternar_situacao_{id}` :1500 (400) | toggle binário | Auditavel | JSON | 1 arquivo, sem teste de outro escritório | Fora do padrão UseCase |
| `pasta_alterar_prioridade` POST /prioridade | ::alterarPrioridade :1517 | AlterarPrioridadeUseCase | Pasta update | R:edit :1524 | TF | `pasta_prioridade_{id}` :1528 | enum `PrioridadePasta::from` (normal/prioridade/urgente), 422 | Auditavel | JSON | AlterarPrioridadeControllerTest (sem auth, CSRF, 422, 200), AlterarPrioridadeUseCaseTest; sem teste de isolamento | |
| `pasta_atualizar_responsavel` PATCH /responsavel | ::atualizarResponsavel :1450 | nenhum (controller) | Pasta.responsavel | R:edit :1457 | TF; usuário alvo validado com `existeVinculoAtivo(user, tenant)` :1470-1473 | `pasta_responsavel_{id}` :1461 | id vazio limpa | Auditavel | JSON 404 | PastaShowChipResponsavelTest, PastaRespMenuDesenhoTelaTest; sem isolamento explícito | `userRepository->find` sem tenant (User não é TenantAware), compensado pelo vínculo |
| `pasta_delete` POST /deletar | ::delete :1546 | ExcluirPastaUseCase | lápide (`marcarExcluida`, UseCase:78) quando há pastas posteriores; senão remoção real (remove docs + pasta, UseCase:84-101) | **R:delete** :1553 | TF | `delete_pasta_{id}` :1557 | número digitado `confirmar_nup` conferido no servidor :1565-1571 | Auditavel | flash + redirect | ExcluirPastaUseCaseTest, ExcluirPastaLapideTest, ExcluirPastaConfirmacaoNumeroTest, ExcluirPastaComLixeiraTest, ExclusaoLapideNaTelaTest | Remoção real apaga arquivos após commit |
| `pasta_restaurar` POST /restaurar | ::restaurar :1595 | RestaurarPastaUseCase | tira lápide | R:delete :1603 | TF | `restaurar_pasta_{id}` :1607 | UseCase lança InvalidArgument | Auditavel | flash | PastaLapideTest e afins | Liberada no listener (óbvio) |
| `pasta_duplicar` POST /duplicar | PastaDuplicarController::duplicar :48 | DuplicarPastaUseCase | cria Pasta | tenant explícito :55, R:view :59, **M:pastas** :63 | explícito + TF | `pasta_duplicar_{id}` :67 | | Auditavel (insert) | | PastaDuplicarControllerTest, DuplicarPastaUseCaseTest | Duplicar exige só VER a origem |
| `pasta_favorito_alternar` POST /favorito | PastaFavoritoController::alternar :42 | AlternarFavoritoDaPastaUseCase | PastaFavorita create/delete (por usuário) | tenant explícito :48 + UseCase (tenant :40, R:view :44) | explícito | `pasta_favorito_{id}` :52 | | não Auditavel | JSON | PastaFavoritoControllerTest, AlternarFavoritoDaPastaUseCaseTest | |
| `pasta_administrativa_definir` POST /administrativa | PastaAdministrativaController::definir :50 | DefinirPastaAdministrativaUseCase | Pasta.administrativa | tenant :57 + UseCase R:edit :52 | explícito | `pasta_administrativa_{id}` :61 | | Auditavel | | PastaAdministrativaControllerTest | |
| `expediente_pasta_marcadores` POST /expediente/pasta/{id}/marcadores | ExpedienteController::atualizarMarcadoresDaPasta :247 | SincronizarMarcadoresDaPastaUseCase | Pasta.marcadores (coleção) | **só M:expediente** (`assertAccess`) :253; **sem R:edit da pasta** | Pasta por `find($id)` só com TF (UseCase:29); marcadores por `findPorTenant` (UseCase:36); `#[PastaPorId]` para lápide | `pasta_marcadores_{id}` :255 | ids inteiros | coleção da Pasta (Auditavel) | JSON 403/404 | SincronizarMarcadoresDaPastaUseCaseTest, PastaExpedienteTenantFilterTest | Ver observação O4 |
| `pasta_atualizar_valor_causa` POST /valor-causa | PastaController::atualizarValorCausa :1790 | AtualizarValorCausaUseCase | Pasta.valorCausa | R:edit :1798 | TF | `pasta_valor_causa_{id}` :1802 | campo ausente 422 :1808; parse no UseCase | Auditavel | JSON | PastaValorCausaControllerTest | |
| `pasta_alternar_situacao_contrato` POST /situacao-contrato | ::alternarSituacaoContrato :1734 | AlterarSituacaoContratoUseCase | Pasta.situacaoContrato | R:edit :1741 | TF | `pasta_situacao_contrato_{id}` :1745 | só `PENDENTE`/`REGULAR` (UseCase:12) | Auditavel | JSON 422 | AlterarSituacaoContratoUseCaseTest; sem functional de isolamento | |
| `pasta_atualizar_pro_bono` POST /pro-bono | ::atualizarProBono :1762 | nenhum | Pasta.proBono | R:edit :1769 | TF | `pasta_pro_bono_{id}` :1773 | FILTER_VALIDATE_BOOLEAN | Auditavel | JSON | 1 arquivo, sem isolamento | |

#### 2.2 Clientes

| Rota | Controller | UseCase | Entidade/op | Autorização | Isolamento | CSRF | Validação | Audit | Testes | Obs. |
|---|---|---|---|---|---|---|---|---|---|---|
| `pasta_cliente_novo` POST /cliente/novo | PastaController::novoCliente :503 | nenhum (controller grande, até ~720) | cria ClientePF/PJ + vincula | R:edit :510 (não exige M:clientes) | TF; duplicidade CPF/CNPJ por `findOneBy([... 'tenant' => $tenant])` :671/:681 | `pasta_cliente_novo_{id}` :516 | manual no controller | Cliente NÃO Auditavel; vínculo entra como coleção da Pasta | PastaClientesCadastroTelaTest, ClienteUnicidadeViaPastaControllerTest | Cria cliente sem módulo Clientes (H: por design) |
| `pasta_cliente_vincular` POST /cliente/vincular | ::vincularCliente :971 | nenhum | Pasta.clientes add | R:edit :978 | Cliente por `find($clienteId)` só com TF (:987) | `pasta_cliente_vincular_{id}` :982 | duplicado 422 | coleção Pasta | PastaClienteVincularControllerTest (tem caso de outro escritório) | |
| `pasta_cliente_desvincular` POST /cliente/{cliente}/desvincular | ::desvincularCliente :725 | nenhum/serviço | Pasta.clientes remove | R:edit :732 | `Cliente $cliente` por resolver (TF) | `pasta_cliente_desvincular_{id}_{cli}` :736 | | coleção Pasta | PastaClienteDesvincularPrincipalControllerTest (outro escritório) | |
| `pasta_cliente_principal` POST /cliente/{cliente}/principal | ::definirClientePrincipal :787 | DefinirClientePrincipalUseCase | Pasta.clientePrincipal | R:edit :797 | TF | `pasta_cliente_principal_{id}_{cli}` :801 | | Auditavel | PastaClientePrincipalControllerTest | |
| `pasta_clientes_buscar` GET (leitura) | ::buscarClientes :876 | | | R:view :881 | filtra cliente de outro tenant :900 | | | | PastaClientesBuscaControllerTest | |

#### 2.3 Processos

| Rota | Controller | UseCase | Op | Autorização | Isolamento | CSRF | Audit | Testes |
|---|---|---|---|---|---|---|---|---|
| `pasta_vincular_processo` POST | PastaController::vincularProcesso :1190 | VincularProcessoUseCase | cria PastaProcesso (e pode criar Processo/partes/movimentações via CNJ, :2562-2576) | R:edit :1197 | `processoRepository->findOneByIdDoTenant` :1218 | `vincular_processo_{id}` :1203 | PastaProcesso/Processo NÃO Auditavel | VincularProcessoControllerTest, VincularProcessoUseCaseTest |
| `pasta_desvincular_processo` POST | ::desvincularProcesso :1246 | DesvincularProcessoUseCase | remove PastaProcesso | R:edit :1253 | findOneByIdDoTenant :1265 | `pasta_desvincular_processo_{id}` :1260 | idem | DesvincularProcessoUseCaseTest |
| `pasta_definir_processo_principal` POST | ::definirProcessoPrincipal :1280 | DefinirProcessoPrincipalUseCase | PastaProcesso.principal | R:edit :1284 | findOneByIdDoTenant :1296 | `pasta_definir_principal_processo_{id}` :1291 | idem | DefinirProcessoPrincipalUseCaseTest |
| `processo_nota_tecnica_criar/editar/excluir` POST /processos/{id}/nota-tecnica[/{notaId}/...] | Processo/Controller/NotaTecnicaController.php :73/:137/:179 | Criar/Editar/ExcluirNotaTecnica | NotaTecnica | pasta: tenant explícito + `temProcesso` + R:edit (:223-228); sem pasta: R:edit do processo (:242) | `findOneByIdDoTenant` em processo, publicação, nota (:80, :101, :264) | `processo_nota_tecnica_{id}` / `_editar_{notaId}` / `_excluir_{notaId}` | NotaTecnica Auditavel | NotaTecnicaControllerTest, NotaTecnicaRepositoryIsolamentoTest |

"Apenso": não encontrei rota nem campo de apenso; o vínculo só tem `principal`. (C: nenhuma rota com "apens" em rotas-pasta.txt; H para entidade, não grepei `apens` em src.)

#### 2.4 Metas (Tarefa)

| Rota | Controller | UseCase | Op | Autorização | Isolamento | CSRF | Validação | Audit | Testes | Obs. |
|---|---|---|---|---|---|---|---|---|---|---|
| `tarefa_criar_para_pasta` POST /tarefas/pasta/{pastaId}/criar | TarefaController::criarParaPasta :144 | (controller) | cria Tarefa | M:tarefas + tenant explícito :163 + **R:view** :170 | `#[PastaPorId]` | `tarefa_criar_{pastaId}` :174 | | Tarefa Auditavel | CriarTarefaControllerTest | Criar meta exige só VER a pasta |
| `tarefa_concluir` POST /tarefas/{id}/concluir | ::concluir :433 | (controller) + DesfazerReaberturaDaMetaUseCase | status | M:tarefas + `verificarAcessoTarefa` (tenant da meta :618 + criador OU responsável da pasta com vínculo ativo :622-631). **Sem R:edit/R:view da pasta** | tenant explícito da meta | `concluir_tarefa_{id}` :447 | | Auditavel | AlternarMetaPorXhrTest, TarefaPosseDoEscritorioTest | Ver risco R1 |
| `tarefa_excluir` POST /tarefas/{id}/excluir | ::excluir :513 | ExcluirTarefaUseCase | **delete real** (`em->remove`) | idem concluir + UseCase: só o criador e em até 24h (UseCase:19, :37-46) | idem | `excluir_tarefa_{id}` :521 | | Auditavel (vai ao Histórico) | ExcluirTarefaControllerTest, ExcluirTarefaUseCaseTest | |
| `tarefa_atualizar_prazo` POST /tarefas/{id}/prazo | ::atualizarPrazo :295 | AtualizarPrazoTarefaUseCase | prazo | idem + só criador (PrazoNaoEditavelException) | idem | `atualizar_prazo_tarefa_{id}` :303 | `new DateTimeImmutable` livre | Auditavel | AtualizarPrazoTarefaControllerTest | |
| `tarefa_update_responsaveis` POST /tarefas/{id}/responsaveis | ::updateResponsaveis :252 | (controller) | coleção responsáveis | M:tarefas + verificarAcessoTarefa; **sem R da pasta** | usuários por `findPorIdsETenant` :265 | `update_responsaveis_{id}` :260 | | coleção Tarefa | TarefaIsolamentoControllerTest | Ver R1 |
| `tarefa_mensagem`, `tarefa_mensagem_editar` | ::mensagem :336, ::editarMensagem :400 | EditarTarefaMensagemUseCase | TarefaMensagem | idem; **sem R da pasta** | tenant da tarefa | `mensagem_tarefa_{id}` / `editar_mensagem_tarefa_{id}` | sanitizador de texto rico | | EditarTarefaMensagemTest | |
| `tarefa_renomear` / `tarefa_reabrir` / `tarefa_alertar` POST | Tarefa/Controller/MetaNaListaController.php :49/:73/:118 | RenomearMeta / ReabrirMeta / AlertarResponsavelDaMeta | Tarefa | M:tarefas + verificarAcessoTarefa + **R:edit da pasta** (:166-171) | tenant explícito | `renomear_tarefa_{id}` / `reabrir_tarefa_{id}` / `alertar_tarefa_{id}` | | Auditavel | MetaNaListaControllerTest, MetaNaListaPosseDoEscritorioTest | Contraste com concluir (R1) |
| `tarefa_acompanhar` POST | TarefaController::acompanhar :110 | | acompanhamento | M:tarefas | | `acompanhar_tarefa_{id}` :122 | | | TarefaIsolamentoControllerTest | |

#### 2.5 Financeiro

| Rota | Controller | UseCase | Op | Autorização | Isolamento de id filho | CSRF | Validação | Audit | Testes |
|---|---|---|---|---|---|---|---|---|---|
| `pasta_pagamento_registrar` POST /pagamento | Pasta/Controller/PastaPagamentoController.php::registrar :49 | RegistrarPagamentoDaPastaUseCase | cria PastaPagamento | R:edit :61 | n/a | `pasta_pagamento_{id}` :65 | UseCase | Auditavel | PastaPagamentoControllerTest |
| `pasta_pagamento_parcelar` POST | ::parcelar :99 | RegistrarParcelamentoDaPastaUseCase | cria N pagamentos | R:edit :111 | n/a | `pasta_pagamento_{id}` :115 | CalculadoraDeParcelamento | Auditavel | PastaPagamentoParcelamentoControllerTest |
| `pasta_pagamento_alternar_quitacao` POST | ::alternarQuitacao :160 | AlternarQuitacaoDoPagamentoUseCase | update | R:edit :172 | `findByIdAndPastaAndTenant` :182 (404) | `pasta_pagamento_quitacao_{pagId}` :176 | | Auditavel | idem |
| `pasta_pagamento_excluir` POST | ::excluir :195 | ExcluirPagamentoDaPastaUseCase | **delete real** (UseCase:26) | R:edit :207 | findByIdAndPastaAndTenant :215 | `pasta_pagamento_excluir_{pagId}` :211 | | Auditavel | idem, PastaPagamentoExcluirConfirmScriptTest |
| `pasta_pagamento_corrigir_valor` POST | ::corrigirValor :236 | CorrigirValorDoPagamentoUseCase | update com trava otimista (`valorAnterior`, 409) | R:edit :248 | idem | `pasta_pagamento_corrigir_{pagId}` :252 | | audit_log é o histórico da correção | PastaPagamentoCorrigirValorControllerTest, CorrigirValorDoPagamentoTravaTest |
| `pasta_financeiro_upload` POST | PastaController::financeiroUpload :1834 | (controller) armazenamento por chave | cria PastaDocumento categoria contrato | R:edit :1842 | n/a | `pasta_financeiro_upload_{id}` :1846 | allowlist MIME + teto 10 MB (`MIME_LIMITS_CONTRATO` :1640) | Auditavel | PastaFinanceiroUploadArmazenamentoTest |
| `pasta_financeiro_documento_renomear` POST | ::financeiroRenomearDocumento :1987 | (controller) | update | R:edit :1994 | `find($docId)` + `doc.pasta == id` + categoria contrato :1998-2000 | `pasta_financeiro_renomear_{docId}` :2003 | | Auditavel | PastaFinanceiroControllerTest |
| `pasta_financeiro_documento_excluir` POST | ::financeiroExcluirDocumento :2023 | ExcluirItensDaPastaUseCase (lixeira) | soft delete (lixeira, purga depois) | R:edit :2037 | idem :2041-2043 | `pasta_financeiro_excluir_{docId}` :2046 | | Auditavel | idem |
| `pasta_financeiro_observacao_enviar/editar/excluir` POST | ::financeiroEnviarObservacao :2057, ::Editar :2123, ::Excluir :2169 | Enviar/Editar/ExcluirObservacaoFinanceiraUseCase | create/update/**delete real** | tenant explícito + R:edit | `findOneBy(['id','pasta','tenant'])` :2089/:2141/:2187 | `pasta_financeiro_obs_{id}` / `_obs_editar_{obsId}` / `_obs_excluir_{obsId}` | UseCase: só autor, janela 15 min (`JanelaDeEdicaoDeComentario::DURACAO = 'PT15M'`) | Auditavel | PastaObservacaoFinanceiraControllerTest, PastaObservacaoPosseDoEscritorioTest |
| leitura: `pasta_financeiro_documento_view/download` GET | :1933/:1960 | | | R:view | find + pasta + categoria | | | | |

#### 2.6 Anotações (mensagens), detalhes e checklist

| Rota | Controller | UseCase | Op | Autorização | Isolamento | CSRF | Regras | Audit | Testes |
|---|---|---|---|---|---|---|---|---|---|
| `pasta_enviar_mensagem` POST /mensagem | PastaController::enviarMensagem :1036 | EnviarMensagemPastaUseCase | cria PastaMensagem (anotação), menções, notificação | tenant explícito :1045 + **R:view** :1048 | `respostaA` por `em->find` :1067, posse (tenant e pasta, raiz incluída) no UseCase :103-110 | `pasta_mensagem_{id}` :1052 | | Auditavel | PastaMensagemControllerTest, PastaMensagemPosseDoEscritorioTest, EnviarMensagemPastaUseCaseTest |
| `pasta_editar_mensagem` POST | ::editarMensagem :1108 | EditarMensagemPastaUseCase | update | tenant + R:view :1120 | `findByIdAndPastaAndTenant` :1126 | `pasta_mensagem_editar_{msgId}` :1131 | só autor, janela (exceção NaoEditavel → 403) | Auditavel | idem |
| `pasta_excluir_mensagem` POST | ::excluirMensagem :1154 | ExcluirMensagemPastaUseCase | **delete real** (UseCase:67) | tenant + R:view :1166 | idem :1172 | `pasta_mensagem_excluir_{msgId}` :1177 | só autor, janela | Auditavel | ExclusaoApagaAvisosDaMensagemTest |
| `pasta_detalhes_observacao_enviar/editar/excluir` POST | :2207 / :2281 / :2327 | Enviar/Editar/ExcluirObservacaoDetalhesUseCase | create/update/**delete real** (UseCase:66) | tenant + **R:edit** | `findOneBy(['id','pasta','tenant'])` | `pasta_detalhes_obs_{id}` / `_editar_{obsId}` / `_excluir_{obsId}` | só autor, 15 min | Auditavel | PastaObservacaoDetalhesControllerTest |
| `pasta_checklist_adicionar` POST | :2365 | AdicionarChecklistItemUseCase | create | R:edit :2373 | TF | `checklist_pasta_{id}` | | Auditavel | AdicionarChecklistItemUseCaseTest |
| `pasta_checklist_toggle/editar/excluir` POST | :2398/:2423/:2454 | Toggle/Editar/ExcluirChecklistItemUseCase | update / **delete real** | R:edit | `em->find(item)` + `item.pasta == id` :2409-2410 | `checklist_item_{itemId}` | | Auditavel | ToggleChecklistItemUseCaseTest etc., PastaChecklistTelaTest |
| `pasta_checklist_reordenar` POST | :2479 | ReordenarChecklistItensUseCase | update ordem | R:edit | (UseCase) | `checklist_pasta_{id}` | | Auditavel | ReordenarChecklistItensUseCaseTest |
| `pasta_checklist_estado` POST | PastaChecklistEstadoController::alterar :49 | AlterarEstadoDoChecklistUseCase | estado/motivo | tenant :55 + UseCase R:edit :61 | explícito | `checklist_pasta_{id}` :59 | motivo obrigatório ao desativar | | PastaChecklistEstadoControllerTest |
| `pasta_checklist_modelo_salvar/aplicar/renomear/excluir` POST | PastaChecklistModeloController :72/:106/:140/:165 | Salvar/Aplicar/Renomear/ExcluirChecklistModeloUseCase | PastaChecklistModelo (do ESCRITÓRIO, não da pasta) | R:edit **da pasta atual** (`negar` :198) | `buscarDoTenant(modeloId, tenant)` | `checklist_modelo_{modeloId}` | nome único (409) | Auditavel | PastaChecklistModeloControllerTest | 

#### 2.7 Documentos (explorador)

| Rota | Controller | UseCase | Op | Autorização | Isolamento | CSRF | Audit | Testes |
|---|---|---|---|---|---|---|---|---|
| `pasta_peticionar_upload` POST | Pasta/Controller/PeticionarController.php::upload :158 | UploadPecaUseCase | cria PastaDocumento | R:edit :165 | TF | `peticionar_upload_{id}` | Auditavel | PeticionarControllerTest, PeticionarIsolamentoControllerTest, PeticionarUploadDuplicadoControllerTest |
| `pasta_peticionar_texto` POST / `pasta_documento_editar_texto` PUT / `pasta_peticionar_upload_imagem` POST | :262 / :370 / :335 | SalvarPecaTexto / EditarPecaTexto / UploadImagemEditor | create/update | R:edit | TF; doc por resolver | `peticionar_texto_{id}` / `peticionar_editar_{docId}` / `upload_imagem_editor_{id}` | Auditavel | idem |
| `pasta_documento_edit` POST /pasta/documento/{id}/editar | PastaDocumentoController::editar :112 | EditarDocumentoDaPastaUseCase | renomear/metadados | R:edit :127 | `#[PastaPelaFilha]` | `edit_documento_{id}` :131 | Auditavel | PastaDocumentoControllerTest |
| `pasta_documento_delete` POST | PastaController::deleteDocumento :1704 | ExcluirItensDaPastaUseCase | soft delete (lixeira) | R:edit :1711 | doc por resolver (TF) | `delete_documento_{id}` :1717 | Auditavel | ExcluirDocumentoDaPastaTest |
| `pasta_documentos_mover_lote` / `excluir_lote` / `restaurar` POST | PastaDocumentoController :170/:209/:247 | MoverItens / ExcluirItens / RestaurarItens | update / soft delete / restore | prólogo `loteAutorizado` :580-600: tenant explícito, R:edit, CSRF, teto de itens, posse de TODOS os ids numa consulta | explícito | `pex_lote_{id}` (`idDoTokenDeLote`) | Auditavel | MoverItensDaPastaUseCaseTest, ExcluirItensDaPastaUseCaseTest, PastaDocumentoLixeiraControllerTest |
| `pasta_documentos_copiar` POST | :520 | CopiarDocumentosDaPastaUseCase | cria cópias | loteAutorizado (R:edit) + destino só da mesma pasta (`destinoDoLote`) | explícito | `pex_lote_{id}` | Auditavel | PastaDocumentoCopiarControllerTest |
| `pasta_documentos_favorito` POST | :319 | AlternarFavoritoDeDocumentoUseCase | PastaDocumentoFavorito por usuário | tenant + R:view :327-331 | explícito | `pex_favorito_{id}` (400) | não | PastaDocumentoFavoritoControllerTest |
| `pasta_documentos_zip` POST (leitura) | :398 | MontarZipDeDocumentosUseCase | gera .zip (cookie de confirmação) | loteAutorizado com R:view | explícito | `pex_lote_{id}` | registro na timeline | PastaDocumentoZipControllerTest, VagasDeZipDoEscritorioTest |
| `pasta_secao_criar` / `renomear` / `excluir` / `mover` POST | PastaSecaoController :51/:102/:139/:182 | Criar/Renomear/Excluir/MoverPastaSecaoUseCase | PastaSecao create/update/soft delete | R:edit | `#[PastaPelaFilha]`; destino do mover por pasta+tenant :208 | `pasta_secao_criar_{id}` / `_renomear_{secaoId}` / `_excluir_{secaoId}` / `_mover_{secaoId}` | Auditavel | PastaSecaoControllerTest, PastaSecaoPosseDoEscritorioTest |
| `pasta_documento_mover_secao` / `pasta_documentos_reordenar` / `pasta_secoes_reordenar` POST | :229/:275/:304 | MoverDocumentoParaSecao / ReordenarDocumentos / ReordenarSecoes | update | R:edit | PastaPelaFilha / por pasta | `pasta_doc_mover_{docId}` / `reordenar_docs_pasta_{id}` / `reordenar_secoes_pasta_{id}` | Auditavel | MoverDocumentoParaSecaoUseCaseTest, ReordenarSecoesUseCaseTest |
| leitura: `pasta_documento_view/download`, `pasta_documentos_lixeira` GET (R:edit), `pasta_documento_exportar_texto` GET | | | | R:view (lixeira R:edit) | TF / explícito | | | |

"Sugeridos" e "Exigido pelo juízo": só leitura (SugestorDeDocumentos, DeterminacoesDoJuizo); a ação que eles disparam é o upload acima (C templates/pasta/_documentos_sugeridos.html.twig:28-36).

#### 2.8 Push, IA, timeline, impressão

| Rota | Controller | Op | Autorização | Isolamento | CSRF | Testes |
|---|---|---|---|---|---|---|
| `pasta_push_lida` POST /pasta/{id}/push/{publicacaoId}/lida | Pasta/Controller/PastaPushProcessualController.php::marcarLida :193 | marca publicação lida | tenant :199 + **R:view** :203 | `findOneByIdENumerosDoTenant` (publicação do tenant e de número desta pasta) :215 | `pasta_push_lida_{id}` :207 | PastaPushLidaControllerTest |
| `pasta_push_teor`, `pasta_push_texto` GET | :61 / :146 | leitura | tenant + R:view | idem | | PastaPushTeorControllerTest, PastaPushTextoControllerTest |
| `inteligencia_push_solicitar`, `inteligencia_agente_solicitar` POST | src/Inteligencia/Controller/AnalisePushController.php :85; AnaliseDaPastaController.php :71 | cria AnaliseDeInteligencia (assíncrona) | `guardar`: tenant explícito, R:view, M:inteligencia (:320-330 / :129-139) | explícito | `inteligencia_push_{id}` / `inteligencia_agentes_{id}` | AnalisePushControllerTest, AnaliseDaPastaControllerTest, InteligenciaTenantFilterTest |
| `inteligencia_analise_lida/nao_lida/excluir/interna/recolher/registro` POST | AnalisePushController :206-310 | update/exclusão da análise | guardar (R:view + M:inteligencia) | análise por pasta+tenant (H: não li o repositório) | `inteligencia_analise_{analiseId}` (:347), registro `inteligencia_analise_registro_{analiseId}` | MarcarNaoLidaControllerTest, RecolherAnaliseControllerTest, RegistroDeExportacaoControllerTest |
| `pasta_timeline_visita` POST | PastaTimelineController::registrarVisita :84 | PastaTimelineVisita (por usuário) | tenant :90 | explícito | `pasta_timeline_visita_{id}` :94 | PastaTimelineVisitaTest |
| `pasta_timeline` GET, `pasta_resumo_imprimir`, `pasta_resumo_pdf` GET, `pasta_mencionaveis` GET | PastaTimelineController :56; PastaResumoController :97/:123; PastaMencaoController :43 | leitura | tenant + R:view | explícito; observações e processos do resumo filtrados por pasta+tenant (PastaResumoController:264, :280) | | PastaTimelineControllerTest, PastaResumoImpressaoControllerTest, PastaResumoPdfControllerTest, PastaMencaoTest |

Contagem: **89 comandos de escrita mapeados** (2.1: 13, 2.2: 4, 2.3: 6, 2.4: 11, 2.5: 11, 2.6: 16, 2.7: 18, 2.8: 10) (os de leitura listados à parte não entram na conta).

"Histórico" da pasta: não tem escrita própria. É o audit_log de entidades Auditavel montado pelo `PastaTimelineAssembler` (C PastaController.php:324-338; tests/Pasta/Functional/AuditLogRepositoryMetasTimelineTest.php). Consequência: vincular/desvincular **processo** e o **cadastro de cliente** não deixam linha própria (PastaProcesso e Cliente não são Auditavel; ver memória "audit_log não cobre Processo/Cliente/Kanban").

---


### 5. Falhas conhecidas do docs/AUTORIZACAO.md que tocam a pasta_show

| Falha | Efeito na pasta_show | Estado |
|---|---|---|
| F1: show/edit/delete não checam módulo | `/pasta/{id}` abre com R:view sem `modules.pastas.view` | Aceito por design (§7, modelo paralelo) |
| F2: `canActOnResource` morto | `resources.pasta.*` no perfil vale como fallback genérico para TODAS as pastas do escritório | Vigente: quem tem `resources.pasta.edit` no TenantRole edita qualquer pasta |
| Bypass 2a/2b | ROLE_SUPER_ADMIN passa em tudo (inclusive sem tenant: `canAccessResource` devolve true antes de olhar tenant, PermissionChecker.php:66); TenantRole isSystem passa em tudo no escritório | Vigente. H: super admin sem tenant ativo tem TF desligado (TenantFilterListener: sem tenant, não liga), então rotas que confiam só no TF ficam sem filtro para ele; as que têm tenant explícito respondem 404/403 por `tenant === null` |
| Pasta sem guarda de módulo na sidebar (`pastas` sem item) | Expediente e Demandas são as portas; Demandas sem guarda | Vigente, fora da pasta_show |
| Doc §7 desatualizado | `ROTAS_LIBERADAS` tem 3 rotas no código (inclui `inteligencia_analise_registro`), o doc lista 2 | Drift de documentação (PastaSomenteLeituraListener.php:72) |
| "TenantFilter não cobre find()" | Afirmado em comentários de vários controllers (PastaDuplicarController:25, PastaPushProcessualController:67, NotaTecnicaController:39/:222 etc.) mas contradito pelo código do Doctrine e pelo teste PastaExpedienteTenantFilterTest:37 | Drift de comentário; inofensivo (gera checagem redundante) |

---

### Riscos P0 candidatos

**Nenhum P0 entre escritórios encontrado.** Toda rota mapeada tem pelo menos o TenantFilter na resolução da pasta (provado por teste) e, nas filhas por id, busca escopada por pasta+tenant ou comparação `filha.pasta == id`.

Candidato de autorização **dentro do mesmo escritório** (classifico como P1, não P0, porque não cruza escritório):

- **R1. Metas: concluir, trocar responsáveis, comentar e abrir a meta sem acesso à pasta.** `TarefaController::show` (:225), `::concluir` (:433), `::updateResponsaveis` (:252), `::mensagem` (:336) e `::editarMensagem` (:400) exigem só `modules.tarefas.view` + `verificarAcessoTarefa`, que confere o tenant da meta e se o criador OU o responsável da pasta tem vínculo ativo no escritório (:612-632). Isso é quase sempre verdade, logo não restringe por pessoa. Não há `canAccessResource('pasta', ...)`. O próprio código diz que isso é necessário: MetaNaListaController.php:160-163 ("Sem isto, quem tem só o módulo Tarefas agiria sobre metas de pastas a que não tem acesso") e aplica R:edit em renomear/reabrir/alertar (:166-171). Caminho concreto: usuário com Tarefas e sem ResourceAccess/fallback da pasta X abre `/tarefas/{id}` (recebe o token `concluir_tarefa_{id}` renderizado) e conclui, troca responsáveis ou comenta. Prazo e exclusão ficam protegidos pela regra "só o criador". Impacto real depende de quantos perfis NÃO têm `resources.pasta.*` (H: não consultei banco).

### Observações (não são P0)

- O1. `pasta_enviar_mensagem`/`editar`/`excluir` (anotações) exigem só **R:view** (PastaController.php:1048, :1120, :1166), enquanto observações de Detalhes e Financeiro exigem **R:edit** (:2219, :2069). Quem só vê pode anotar. H: pode ser intencional (comentar é "conversa"); vale decisão do dono antes do Designer novo.
- O2. `tarefa_criar_para_pasta` exige só R:view (TarefaController.php:170); duplicar pasta exige R:view + M:pastas (PastaDuplicarController.php:59-63).
- O3. Modelos de checklist são do escritório, mas renomear/excluir exige só R:edit **da pasta corrente** (PastaChecklistModeloController.php:198): quem edita uma pasta altera um modelo usado por todas.
- O4. `expediente_pasta_marcadores` exige só M:expediente, sem R:edit da pasta (ExpedienteController.php:253; UseCase:29 usa `find` puro). Isolamento entre escritórios depende só do TF (que funciona).
- O5. Ações no controller sem UseCase: alternar situação (:1504-1509), pró-bono (:1782), responsável (:1466-1477), vincular cliente (:996-997), cadastro de cliente (~:520-720). Fora do padrão da casa, sem teste de isolamento nas três primeiras.
- O6. Exclusões reais (sem lixeira) com trilha só no audit_log: pagamento (ExcluirPagamentoDaPastaUseCase:26), mensagem, observações, item de checklist, meta. Documentos e seções vão para lixeira (soft delete + purga).
- O7. Situação aceita só `ativo`/`arquivado`; não há Suspenso/Cancelado. Prazo manual e audiência da pasta não existem; Agenda não se liga a pasta; réu só vem das partes do processo.
- O8. `pasta_edit` usa flash `error` (:1435) e o resto usa `danger`/`warning`; e a validação do editar é manual (DTO sem `#[Assert]`), com NUP repetível (EditarPastaUseCase.php:25) apesar de existir `testNupUnicoPorTenant` (PastaExpedienteTenantFilterTest:85). H: o teste prova outra coisa (unicidade na criação ou índice); não conferi.

# Parte 2. Dependências das lacunas (síntese do orquestrador)

Agrupamento das colunas "Dependências" da matriz (`03`). Um item pode aparecer em mais de um grupo.

## 2.1 Por tipo de dependência

| Dependência | O que destrava | Itens da matriz | Entrega do plano |
|---|---|---|---|
| **Nenhuma (só tela)** | Janelas no lugar de `prompt()`/`alert()`, travessão, textos e contagem de prazos, moldura de Documentos, foco do Editar dados, movimento, Metas sem recarga, camada do drawer da IA, marcadores sem recarga | PZ-CAB-03, 07, 15, 24, 25, 35, 45, 48, PZ-MOD-11, 15, 26, PZ-TRI-03, 04, 07, N03, PZ-MET-06, 08, 25, N02, PZ-FIN-01, 02, 29, 36, PZ-DOC-00, 13, PZ-VIS-06, PZ-IA-01V, PZ-GLB-12, N04, N05, VT, MB-CF-06 | P1-1, P1-2, P2-1 a P2-8 |
| **Correção de autorização (sem migration)** | Metas, marcadores, stack trace, modelos de checklist, análise de IA | PZ-MET-11, PZ-CAB-05, A-03, P0-5, PZ-PUSH-09 | P0-1 a P0-5 |
| **Auditoria (audit_log)** | Histórico completo | PZ-MOD-20, PZ-MOD-N30, PZ-MOD-N22 | P1-3 |
| **Migration + entidade nova** | Prazos e audiências, Réu, Suspenso/Cancelado, apensos, lançamento previsto, versões de comentário, destaque, etiqueta do registro, nº global da meta | PZ-TRI-N01 a N14, PZ-MOD-N20 a N25, PZ-CAB-36, PZ-PRO-08, 11, PZ-MOD-24, PZ-DET-N07, 14, PZ-DAD-06, 13, PZ-MET-05 | P1-4, P1-7, P1-9, P1-10, P1-16 |
| **Motor no servidor (dias úteis, feriados, leitura de prazos)** | Prazos agregados do Push e dos registros, análise de prazo da Nova meta | PZ-TRI-N04, N05, PZ-DET-N03, N04, PZ-PRO-N01 a N04, PZ-MOD-03, PZ-PUSH-N04 | P1-5 (reaproveitar `Feriado`, hoje do Ponto) |
| **Autorização nova (ACL por pasta, papel primário, Master)** | Cadeado, sobreposição "trancada", exceções de edição em Detalhes | PZ-CAB-21, 32, 33, 37, PZ-MOD-12, N08 a N10, PZ-GLB-N03, PZ-DET-06, 13, PZ-PRO-38, PZ-DAD-08 | P1-11, P1-12 (risco ALTO) |
| **Serviço público com token** | Link externo do Push | PZ-CAB-19, PZ-MOD-13, 14, N11, N12, PZ-PUSH-N05, MB-CF-07 | P1-13 |
| **E-mail transacional** | Enviar e avisar cliente | PZ-PUSH-10, 32, 33, MB-ATL-08, MB-CF-04 | P1-14 |
| **Integração PJe/MNI e captura** | Monitoramento, Buscar agora, dados do PJe, Abrir no PJe | PZ-PUSH-27, 28, PZ-PRO-15, 19, 22 | P1-15 |
| **IA estruturada / OCR** | Abas do drawer, providências, relatórios, leitura de PDF, OCR da identidade | PZ-IA-04 a 14, N01, PZ-DOC-09, PZ-PUSH-29, 30, N01, N02, PZ-MOD-N27, N28 | P1-16 e fora do plano |
| **Chat I.A./Central** | Todo "Encaminhar via Chat I.A", presença, comandos "/", destino das menções | PZ-IA-16, 25, 29, N06, N07, PZ-DAD-05, 10, PZ-DET-04, 05, 08, PZ-DOC-17, PZ-PUSH-14, 20, 23, PZ-FIN-07, 08, 21, PZ-MET-14, 24, PZ-PRO-27, 34, PZ-TRI-06 | fora do plano |
| **Arquivo global (decisão do dono)** | Ctrl+K, dicas `bj-dica`, item ativo da navegação | PZ-MOD-10, MB-ATL-01, PZ-GLB-10, PZ-GLB-01a09 | fora do plano até decisão |
| **Decisão do dono** | Políticas D-1 a D-9 do plano; P1, P2, P3; rótulos | `04` §3; PZ-MOD-N02, N03, N05, 16, PZ-MOD-04, PZ-DOC-16, EXC, PZ-MOD-09, PZ-PRO-13, 39, PZ-EDT-02 a 09, 14, 15 | §0 do plano |

## 2.2 Cadeia de dependências entre entregas

```
P0-3 ──► P0-2 ──► P1-2                     (mesmo arquivo / mesma área)
P0-1                                       (independente)
P0-4                                       (independente)
D-1..5 ──► P0-5, P1-12
D-8 ──► P1-1 (medição), P2-4, P2-5, P2-9
D-9 ──► P1-4 ──► P1-5 ──► P1-6 ◄── D-7 (Lista 03)
              └──► P2-3 (vazio do desenho)
D-9 ──► P1-8 ──► P1-7 ◄── D-7
D-9 ──► P1-9 · P1-10 · P1-13 · P1-14 · P1-15 · P1-16   (uma migration por vez)
D-MASTER ──► P1-11 ──► P1-12
```

## 2.3 Arquivos tocados por mais de uma entrega (ordem importa)

| Arquivo | Entregas | Cuidado |
|---|---|---|
| `app/src/Expediente/Controller/ExpedienteController.php` | P0-3, P0-2 | Em sequência; a Lista do Expediente usa o mesmo endpoint |
| `app/templates/pasta/_cabecalho.html.twig` | P0-2, P1-2, P2-1, P2-2 | Uma de cada vez; conferir P1 (faixa preservada) |
| `app/templates/pasta/_metas.html.twig` | P0-1, P2-7 | P0-1 primeiro |
| `app/src/Controller/TarefaController.php` | P0-1 | Legado: testes do comportamento atual antes |
| `app/templates/pasta/show.html.twig` | P0-4, P1-2, P2-5 | Arquivo grande (≈4.300 linhas), muito tocado pela frente |
| `app/src/Pasta/DTO/PastaPrazoOutput.php` | P2-3, P1-4 | P2-3 só ajusta limite e total; a P1-4 reescreve a fonte |
| `app/public/css/pasta-show.css` | P2-4, P2-5, P2-6, P2-9 | Medir na tela depois de cada uma |
