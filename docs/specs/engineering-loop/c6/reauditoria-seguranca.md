# Reauditoria C6 — Segurança, isolamento de tenant e autorização (prefixo SEC)

Auditoria SOMENTE LEITURA em `4a98cc6b` (master local), sobre `git log 607f2c29..HEAD` (199 commits). Nada
executado (nem suíte, nem container, nem produção). Toda conclusão é por leitura de código; o que é hipótese
está dito. Aqui o "desenho" é a regra da casa: `docs/AUTORIZACAO.md`, `app/src/CLAUDE.md`, `CLAUDE.md` raiz.
Worktrees `seguranca-s1-*` / `s1-base-origin-master` não foram abertas.

## Fontes lidas

- Inventário do ciclo 0: `docs/specs/engineering-loop/inventario-seguranca.md` (SEC-001..011, matriz de 57 rotas).
- Ledger `docs/specs/engineering-loop-fidelidade-designer.md` §3.1 (SEC-001..008, 004b, 007b, 012, 013, 013b,
  015, 016; SEC-014 → F) e fila `docs/specs/engineering-loop/fila-a-restante.md` (SEC-011 → C).
- `git diff 607f2c29..HEAD -- app/src app/config app/templates app/public/js app/migrations` (134 arquivos em
  `app/src`; 6 migrations).
- Controllers (diff + action inteira onde mudou): `Pasta/Controller/PastaSecaoController.php`,
  `PastaDocumentoController.php:376-511`, `PastaTimelineController.php:78-110`, `PastaResumoController.php`,
  `PastaPushProcessualController.php:57-120`, `PastaAdministrativaController.php`, `Controller/PastaController.php:1036-1180,
  1925-1950, 2047-2305`, `Controller/TarefaController.php:140-222, 425-600`, `Tarefa/Controller/MetaNaListaController.php`,
  `Controller/AgendaController.php:76-133`, `Processo/Controller/NotaTecnicaController.php:70-260`,
  `Processo/Controller/ProcessoController.php:104-190`, `Inteligencia/Controller/AnalisePushController.php` (rotas novas e
  `guardar()`), `AnaliseDaPastaController.php`, `Dashboard/Controller/HistoricoDoDashboardController.php`.
- Serviços/UseCases: `MencoesDoRegistro.php`, `ResolucaoDeMencoesDoRegistro.php`, `CotaDeNotificacoesDoRegistro.php`,
  `Enviar/Editar/ExcluirObservacao{Financeira,Detalhes}UseCase.php`, `EnviarMensagemPastaUseCase.php`,
  `VagasDeZipDoEscritorio.php`, `VagaDeZip.php`, `CorrigirValorDoPagamentoUseCase.php:80-133`,
  `PurgarLixeiraUseCase.php:41-115, 300-345`, `DesfazerAlteracaoAuditLogUseCase.php:405-425`,
  `ListaDeMetasDaPastaEmJson.php`, `ReaberturaDesfazivelDaMeta.php`, `RegistrarVisitaDaTimelineUseCase.php`,
  `ObterHistoricoDoCardUseCase.php`, `ExportarAnaliseEmTextoUseCase.php`, `RecolherAnaliseUseCase.php`,
  `MarcarAnaliseComoNaoLidaUseCase.php`, `ListarAuditoriaDaPastaUseCase.php`, `ContarOQueAIaSabeDaPastaUseCase.php`,
  `VisibilidadeDoFinanceiroDaPasta.php`, `PermissionChecker::canAccessResource`.
- Repositórios (diff): `NotificacaoRepository`, `EventoRepository`, `HistoricoDoDashboardRepository`, `NotaTecnicaRepository`,
  `NotaTecnicaVersaoRepository`, `PastaSecaoRepository`, `PastaMensagemRepository`, `PastaRepository`, `TarefaRepository`,
  `AnaliseDeInteligenciaRepository`, `PastaTimelineVisitaRepository`.
- Infra: `Shared/Http/EntregaDeArquivo.php`, `config/packages/framework.yaml:70-90`, `PastaSomenteLeituraListener.php`,
  `Twig/PermissionExtension.php`, `Pasta/Twig/ExclusaoDaMetaExtension.php`, `Processo/Twig/NotaTecnicaExtension.php`,
  `Inteligencia/Twig/InteligenciaExtension.php`, `Tenant/UseCase/PurgarEscritorioUseCase.php` (diff).
- Front: grep de `innerHTML|insertAdjacentHTML|srcdoc` no diff de `app/public/js` e nos scripts de
  `templates/pasta/_financeiro_obs_script.html.twig`, `_detalhes_obs.html.twig`; `pasta-explorador.js:2390-2425`
  (cookie do .zip); `pasta-impressao.js:240-295`; `templates/pasta/zip_recusado.html.twig`; grep de `|raw` no diff
  de templates (zero ocorrências novas).
- Testes (grep): `DesligaTenantFilterNaRequisicao` e seus 7 usuários, `PastaSecaoPosseDoEscritorioTest`,
  `PastaMensagemPosseDoEscritorioTest`, `PastaTimelineVisitaTest`, `HistoricoDoDashboardControllerTest`,
  `Inteligencia/Functional/{AnaliseEmTexto,RecolherAnalise,MarcarNaoLida,AuditoriaDaPasta}ControllerTest`,
  `PastaDocumentoZipControllerTest`, `PastaResumoPdfControllerTest`, `PastaObservacao{Financeira,Detalhes}ControllerTest`,
  `CriarTarefaControllerTest`, `PastaSomenteLeituraPorIdTest`.

## Conferência dos feitos

| ID | Veredito | Evidência |
|---|---|---|
| SEC-001 (=DOC-119) | ✓ | `PastaSecaoController.php` sem nenhum `em->find`; `findByIdAndTenant` / `findByIdAndPastaAndTenant` (repo `PastaSecaoRepository.php:137-159`); prólogo `pastaDoEscritorio()` em criar/reordenar (`:337-344`). Teste com filtro DESLIGADO: `PastaSecaoPosseDoEscritorioTest` (11 casos, `:41-247`). |
| SEC-002 | ✓ | Prólogo 404 em enviar/editar/excluir mensagem (`PastaController.php:1044-1047, 1106-1109, 1152-1155`); mensagem por `findByIdAndPastaAndTenant` (`PastaMensagemRepository.php`); UseCase repete (`EnviarMensagemPastaUseCase.php:86-91`). Teste com filtro desligado: `PastaMensagemPosseDoEscritorioTest.php:38-141`. |
| SEC-003 | ✓ | `EntregaDeArquivo.php:83-149`: `nosniff` sempre; `CSP: sandbox; default-src 'none'` inline fora de PDF/raster/áudio/vídeo; SVG fora da lista segura. Únicos `BinaryFileResponse` inline restantes: `EntregaDeArquivo` e `TarefaController.php:566` (SEC-012). `ArquivoStorageService::servir` ficou sem chamador. Os tipos novos do DOC-115 (`message/rfc822`, `text/csv`, RTF, HEIC/TIFF) caem na sandbox. |
| SEC-004 | ✓ | Limiter `pasta_documentos_zip` 3/min (`framework.yaml:73-80`) consumido depois dos guardas e antes do UseCase (`PastaDocumentoController.php:441-450`); 429 com `Retry-After`. Testes `PastaDocumentoZipControllerTest.php:357-434`. |
| SEC-004b | ✓ | 2 vagas por escritório por `flock` não bloqueante (`VagasDeZipDoEscritorio.php`), liberadas no `kernel.terminate`, no `reset()` e no `__destruct` da vaga; liberadas também nas recusas do UseCase (`PastaDocumentoController.php:461-469`). Vale por container (ledger). Testes `:434-625`. |
| SEC-005 | ✓ | Ramo explícito Cargo/Sede/Lotação com dono não nulo (`DesfazerAlteracaoAuditLogUseCase.php:411-418`), sem torná-las `TenantAware`. |
| SEC-006 | ✓ | `beginTransaction` + `refresh(…, PESSIMISTIC_WRITE)` antes de comparar, flush e commit sob a trava (`CorrigirValorDoPagamentoUseCase.php:91-131`). |
| SEC-007 | ✓ (variante) | Zip trata `ChaveDeArquivoInvalida` como não encontrado (`MontarZipDeDocumentosUseCase.php:166`); cópia converte (`CopiarDocumentosDaPastaUseCase.php:155`); purga **pula** o item e loga só ids (`PurgarLixeiraUseCase.php:320-337`) — o inventário propunha remover a linha; pular é mais conservador e está documentado. |
| SEC-007b | ✓ | `--limite` conta só os purgados; pulados entram no resultado (`PurgarLixeiraUseCase.php:41-54, 109-115`). |
| SEC-008 | ✓ | Cota única por autor (`CotaDeNotificacoesDoRegistro.php`, 30/10 min, `framework.yaml:81-88`) consumida depois dos filtros, em Registro, notas técnicas e observações (`ResolucaoDeMencoesDoRegistro.php` `avisar()`, `notificarRespostaNaObservacao()`; `EnviarMensagemPastaUseCase.php:184-187`). Teto segue a ratificar (SEC-008b, C). |
| SEC-012 | ✓ | `TarefaController.php:552-575`: `nosniff` + sandbox pela mesma regra `EntregaDeArquivo::exigeSandbox()`; download é attachment (`:594`). |
| SEC-013 | ✓ | Posse antes do vínculo, 404 (`TarefaController.php:612-618`). Teste com filtro desligado: `TarefaPosseDoEscritorioTest.php:127-169`. **Mas o mesmo padrão "vínculo, não posse" sobrou em `tarefa_criar_para_pasta`** — ver C6-SEC-03. |
| SEC-013b | ✓ | `MetaNaListaController.php:174-184`; `MetaNaListaPosseDoEscritorioTest.php:140`. |
| SEC-015 | ✓ | `ListaDeMetasDaPastaEmJson::responder()` só devolve HTML/contagens a quem VÊ a pasta (`podeVerAPasta`). |
| SEC-016 | ✓ | `findNaAgendaDaPessoa` com tenant explícito + `restringirAoVisivelPara($visualizador)` (`EventoRepository.php:229-288`); pessoa só do escritório (`AgendaController.php:113-120`). A lista sem `responsavel` usa `findForCalendar($status)` sem usuário, que restringe aos abertos — seguro. |
| SEC-014 → F | ✓ | Modelo de `AUTORIZACAO.md` §7 (listagem por módulo). |
| SEC-009, SEC-010, SEC-011, SEC-008b | C (sem mudança) | SEC-010 ficou mais largo com o DASH-006 (`TarefaRepository::findMetasAtivasDoCardPaginado` lista também metas sem responsável/de quem saiu), mas a decisão é a mesma. |

**Totais:** 15 confirmados (inclui SEC-014 F), 0 parciais, 0 regredidos.

## Regressões

Nenhuma regressão de segurança encontrada nos 199 commits: nenhum `|raw` novo, nenhum guarda removido, nenhum
`em->find` novo em rota de pasta. A remoção do upload legado `pasta_documento_upload` (`PastaController` diff) tirou
um caminho que fazia `em->find(PastaSecao)` sem tenant — melhora.

## Pendências A (do inventário)

Nenhuma. SEC-001..008 feitos; SEC-009/010 seguem C; SEC-011 foi reclassificado C (fila, l.222).

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Regra da casa | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-SEC-03 | **A** (VER mínimo) + **C** (VER × EDITAR) | **P0** | P | não | `tarefa_criar_para_pasta` — criar meta pela pasta | `AUTORIZACAO.md:358-366` (ação por item = `canAccessResource`); `app/src/CLAUDE.md` (posse do tenant explícita); precedente SEC-013 e `MetaNaListaController.php:162-169` (renomear/reabrir/alertar exigem EDITAR) | `TarefaController.php:143-170`: só `assertAccess` (módulo `tarefas`, `:597-607`), `#[PastaPorId]` (lápide), `$pastaRepository->find($pastaId)` e o guarda de **vínculo** do criador/responsável da pasta (`:159-169`); **nenhum** `canAccessResource` (o arquivo não tem nenhum). Botão "Nova meta" visível a todo leitor (`templates/pasta/_metas.html.twig:118`, só `not pasta.estaExcluida`) | Logo após o `find`: `if ($pasta === null \|\| $pasta->getTenant()?->getId() !== $tenant->getId()) → 404` (posse, como SEC-013), removendo o guarda de vínculo; depois `canAccessResource($usuario, $tenant, 'pasta', $pastaId, ACTION_EDIT)` → 403 JSON (VER é o mínimo indiscutível; EDITAR é a paridade com `MetaNaListaController` — ratificar com o Samuel, e então esconder o botão com `can_access_resource('pasta', pasta.id, 'edit')`) | Funcional em `CriarTarefaControllerTest`: (a) com `DesligaTenantFilterNaRequisicao`, pasta de outro escritório cujo criador tem vínculo ativo no meu → 404 e nenhuma `tarefa`; (b) usuário com módulo `tarefas` sem `resources.pasta.view` → 403, nenhuma meta, nenhuma notificação; (c) só VER → 403 (se ratificado EDITAR). Provar reintroduzindo o defeito |
| C6-SEC-01 | A | P4 | P | não | Observações de Detalhes e do Relatório financeiro: enviar/editar/excluir (6 rotas tocadas por FPN-005, PAS-039, EDT-010r) | Mesmo padrão corrigido no SEC-002: posse explícita da pasta, recurso de outro escritório → **404** | `PastaController.php:2047-2305`: sem prólogo de tenant; editar/excluir por `em->find` + id da pasta (`:2118, 2158, 2250, 2290`). A defesa existe nos UseCases (`EnviarObservacao*UseCase` confere a pasta; `Editar/Excluir*` conferem o tenant da observação), então **não há vazamento**: com o filtro falhando a resposta é 422/403 em vez de 404 | Prólogo idêntico ao do SEC-002 nas 6 actions; editar/excluir por `findOneBy(['id','pasta','tenant'])` (como já faz o `resposta_a`, `:2074, 2204`) | Funcional com `DesligaTenantFilterNaRequisicao`: POST em pasta/observação de outro escritório → 404, nada muda (modelo: `PastaMensagemPosseDoEscritorioTest`) |
| C6-SEC-02 | A | P4 (latente) | P | não | Aviso no sino de @menção/resposta no **Relatório financeiro** | `VisibilidadeDoFinanceiroDaPasta.php:13-21`: "quando ela ganhar tela, o aperto entra AQUI, num lugar só" | `ResolucaoDeMencoesDoRegistro.php` `avisar()` e `notificarRespostaNaObservacao()` filtram destinatário por `canAccessResource(…, VIEW)`, não por `VisibilidadeDoFinanceiroDaPasta::podeVer`; a prévia leva 180 caracteres do texto financeiro. Hoje VER = ver financeiro, então **nada vaza hoje**; no dia do aperto, o sino vazaria (a Timeline já filtra `#financeiro`, `NotificacaoRepository::findDoUsuarioLigadasAPasta`) | Quando a origem for `PastaObservacaoFinanceira`, exigir também `VisibilidadeDoFinanceiroDaPasta::podeVer($destinatario, …)`; `ListarMencionaveisDaPastaUseCase` idem para o compositor do Financeiro | Unit com dublê de `VisibilidadeDoFinanceiroDaPasta` dizendo "não": menção no financeiro não cria `notificacao`; no Registro, cria |

### Detalhe pedido pelo orquestrador — `tarefa_criar_para_pasta` (C6-SEC-03)

1. **O TenantFilter já barra?** Sim, no caminho normal. `Pasta` é `TenantAware` (`Pasta.php:28`), o filtro SQL vale para
   o `find()` quando a entidade não está no identity map, e o `#[PastaPorId]` do listener busca por `id`+`tenant`
   (`PastaSomenteLeituraListener::pastaPorIdDeclarado`). Há teste com o filtro LIGADO: `PastaSomenteLeituraPorIdTest.php:144`
   (pasta riscada de outro escritório → 404, nenhuma meta).
2. **Pasta alheia cujo criador tem vínculo ativo no meu escritório passaria?** Sim, se o filtro falhar: o guarda
   (`:159-169`) só pergunta se o criador OU o responsável da pasta tem vínculo ativo no escritório da sessão — é
   exatamente o defeito do SEC-013. A meta nasceria com o MEU tenant (`:186`) pendurada na pasta ALHEIA (mistura dos
   dois escritórios) e `destino` devolveria o id dela. Nenhum teste cobre (nenhum usa `DesligaTenantFilterNaRequisicao`).
3. **Quem não vê / só vê a pasta cria meta?** Sim, ambos. Não há `canAccessResource`. Quem tem o módulo `tarefas` e
   não tem `resources.pasta.view` (nem acesso específico, `PermissionChecker::canAccessResource`) cria meta em qualquer
   pasta do escritório pelo id, escolhe responsáveis e dispara `notificarTarefaCriada`. Isso já existia antes do loop
   (blame: `0344eefb`, `be7601d3`), mas contradiz a regra de recurso-item e a guarda irmã do `MetaNaListaController`.
4. **Teste:** só o cross-tenant com filtro ligado e lápide (`PastaSomenteLeituraPorIdTest`); `CriarTarefaControllerTest`
   cobre responsáveis de outro tenant, 422 e CSRF, nada de permissão por pasta.

Classificação: posse (404) e "não vê não escreve" (403 para sem VER) = **A, P0**; exigir EDITAR em vez de VER = **C**
(o Registro aceita escrita de quem só VÊ, `PastaController.php:1048`; as ações irmãs da meta exigem EDITAR).

### Observações (não contadas como divergência)

- **Mesmo padrão no resto do `TarefaController`** (anterior ao loop, frente própria): nenhuma action confere
  `canAccessResource` da pasta — `tarefa_show`, `_concluir` (tocada pelo loop: XHR + "Desfazer"), `_mensagem`,
  `_prazo`, `_responsaveis`, `_acompanhar`, anexos. Quem tem o módulo `tarefas` conclui meta de pasta que não vê
  (o SEC-015 já impede a resposta de vazar a aba). Sugiro fatia própria, depois da decisão do C6-SEC-03.
- **`processo_edit`** (tocado pelo PMC-056 só para CSRF) não compara `processo->getTenant()` com o da sessão — só o
  TenantFilter + `canAccessResource` (que não olha o tenant do recurso). Anterior ao loop; fatia de higiene da Obs. 6 do inventário.
- **`pasta_resumo_pdf`** (novo) roda Dompdf por GET sem limitador. Custo bem menor que o .zip; `isRemoteEnabled=false`
  e conteúdo escapado pelo Twig. Só registrar.
- **Comentário impreciso** "o TenantFilter não se aplica a `find()`" continua em código novo
  (`AnalisePushController::guardar`, `NotaTecnicaExtension::somenteLeitura`, `NotaTecnicaController:222`) — Obs. 1 do inventário.
- **Notificação ao responsável que não vê a pasta**: `tarefa_criar_para_pasta` filtra responsáveis só por tenant
  (`:200-203`). Entra junto com a decisão do C6-SEC-03.
- **Colisão com a frente S1:** nada desta auditoria toca segredos ou publicação; `MAILER_REPLY_TO` (`services.yaml`) foi só lido.

## Matriz

Denominador atualizado: 57 rotas do ciclo 0 + 7 rotas novas (`dashboard_historico`, `inteligencia_auditoria_da_pasta`,
`inteligencia_analise_txt`, `_nao_lida`, `_recolher`, `pasta_resumo_pdf`, `pasta_timeline_visita`) + 11 rotas alteradas
fora da lista original (6 observações, `tarefa_concluir`, `tarefa_mensagem_arquivo_view`, `agenda_lista`,
`processo_new`, `processo_edit`) = **75 rotas**.

| Dimensão | OK | Total |
|---|---|---|
| Visual / Interações / Responsivo / Dark | n/a | n/a |
| Funções (submatriz abaixo) | **537** | **551** |

| Checagem | OK | Aplica-se | Fora do OK |
|---|---|---|---|
| (1) Tenant explícito | 73 | 75 | C6-SEC-03, `processo_edit` (obs., legado) |
| (2) IDOR → 404 | 69 | 75 | C6-SEC-01 (6) |
| (3) Permissão adequada | 71 | 75 | SEC-009 (2, C), C6-SEC-03, `tarefa_concluir` (obs.) |
| (3b) Listagem sem vazar | 0 | 1 | SEC-010 (C) |
| (4) CSRF em POST | 55 | 55 | — (PMC-056 fechou `processo_new/edit`) |
| (5) Lápide | 50 | 50 | — |
| (6) Auditoria das escritas | 39 | 41 | `cliente_contatos`, `tarefa_alertar` (Observação, ciclo 0) |
| (7) XSS | 38 | 38 | — |
| (8) Upload/entrega de arquivo | 3 | 3 | — (peça DOC-115, anexo de tarefa, entrega inline) |
| (9) Rate limit (notificação/zip/IA) | 5 | 5 | — |
| (10) Teste cross-tenant | 74 | 75 | C6-SEC-03 sem caso de filtro desligado/permissão |
| Itens do §9 (#3, #5, #6) | 3 | 3 | — |
| Corrida no corrigir valor | 1 | 1 | — |
| C6-SEC-02 (latente) | 0 | 1 | C6-SEC-02 |
| **Total** | **537** | **551** | 14 células, em 6 divergências (3 novas A + SEC-009/010 C + 1 obs.) |

(As linhas "obs." contam como fora do OK mas não viraram ID: são anteriores ao loop e vão para a fatia de higiene.
Auditoria (6) não foi reconferida linha a linha nas 6 observações; `PastaObservacao*` já era `Auditavel` no ciclo 0.)

## Resumo

- Feitos: **15 confirmados**, 0 parciais, 0 regredidos (SEC-001..008, 004b, 007b, 012, 013, 013b, 015, 016; SEC-014 F).
- Pendências A do inventário: **0**. C inalterados: SEC-008b, 009, 010, 011.
- Novas: **3 A** — C6-SEC-03 (P0, com parte C: VER × EDITAR), C6-SEC-01 (P4), C6-SEC-02 (P4, latente). 0 B/C/D/E novas.
- Nenhuma exige migration. Nenhuma toca `base.html.twig`, `pasta-show.css/js` nem CSS global; C6-SEC-01 toca
  `Controller/PastaController.php` (legado compartilhado); C6-SEC-03 toca `Controller/TarefaController.php` e, se
  ratificado EDITAR, `templates/pasta/_metas.html.twig` (parcial da pasta).
