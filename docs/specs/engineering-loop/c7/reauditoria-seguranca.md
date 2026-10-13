# Reauditoria C7 — Segurança, isolamento de tenant e autorização (prefixo SEC)

Auditoria SOMENTE LEITURA em `d985225e` (master local), sobre `git log 3ff6d9b0..HEAD` (40 commits). Nada executado
(nem suíte, nem container). Conclusões por leitura de código e de testes. Régua: `docs/AUTORIZACAO.md`,
`app/src/CLAUDE.md`, base `c6/reauditoria-seguranca.md`. Worktrees `seguranca-s1-*` não abertas.

Lido: diff de `Controller/{Pasta,Tarefa}Controller.php`, `Pasta/Controller/{PastaPushProcessual,PastaResumo,PastaMencao}Controller.php`,
`Inteligencia/Controller/AnalisePushController.php` (+ `guardar()`), `PastaSomenteLeituraListener.php`,
`RegistrarExportacaoDaAnaliseUseCase.php`, `ListarAuditoriaDaPastaUseCase.php`, `AnaliseDeInteligenciaRepository.php` (diff),
`ListarMencionaveisDaPastaUseCase.php`, `ResolucaoDeMencoesDoRegistro.php`, `MontadorDaTimelineInteligente.php`,
`PastaObservacao{Detalhes,Financeira}.php`, `Editar*ObservacaoUseCase.php`, `NotaTecnicaRepository.php`; diff de todos os
templates e JS do ciclo (grep de `|raw`, `innerHTML/insertAdjacentHTML`, `${…}` sem `esc()`, `csrf_token`, `can_access_resource`);
`pasta-dialogos.js` (`confirmarPs`/`avisarPs`); testes `PastaPushTextoControllerTest`, `RegistroDeExportacaoControllerTest`,
`PastaObservacaoPosseDoEscritorioTest`, `CriarTarefaControllerTest`, `PastaResumoPdfControllerTest`, `PastaMencaoTest`.

## Conferência (itens A do C6 marcados feitos)

| ID | Veredito | Evidência |
|---|---|---|
| C6-SEC-03 (A, P0) | ✓ | `TarefaController.php:162-171`: posse por `getTenant()->getId()` → 404 (guarda de vínculo removido), depois `canAccessResource(…, VIEW)` → 403, depois CSRF (`:173`). Testes `CriarTarefaControllerTest.php:281` (outro escritório com criador nos dois + filtro desligado → 404), `:317`, `:340` (sem VER → 403), `:366`. Parte C (EDITAR) segue com o Samuel. |
| C6-SEC-01 (A, P4) | ✓ | `PastaController.php:2064-2067, 2127-2130, 2173-2176, 2211-2214, 2285-2288, 2331-2334`: prólogo de posse 404 antes do 403 nas 6 rotas; editar/excluir por `findOneBy(['id','pasta','tenant'])` (`:2138, 2184, 2296, 2342`). Teste com filtro desligado e provider financeiro+detalhes: `PastaObservacaoPosseDoEscritorioTest.php:52-53, 61-175`; 404-antes-de-403 com não-editor `:215`, 403 na própria `:235`. |
| C6-SEC-02 (A, P4) | ✓ | `ResolucaoDeMencoesDoRegistro.php:248-252` (resposta) e `:406-410` (menção): origem `PastaObservacaoFinanceira` exige `VisibilidadeDoFinanceiroDaPasta::podeVer`. |
| C6-SEC-02b (pendência do S6-SEC) | ✓ | `ListarMencionaveisDaPastaUseCase.php` (`ORIGEM_FINANCEIRO`, filtro `podeVer` por colega; origem desconhecida não alarga); `PastaMencaoController.php:53-61`; `_financeiro.html.twig:166` manda `origem=financeiro`. Teste `PastaMencaoTest.php:169`. |

## Regressões

Nenhuma. Nos 40 commits: zero `|raw` novo; único `insertAdjacentHTML` tocado é o da nota técnica (`nota-tecnica.js`,
só mudou `afterbegin`→`beforeend`, HTML renderizado pelo servidor); os `${…}` novos em `show.html.twig` /
`_detalhes_obs.html.twig` passam por `esc()` (inclui `autorFotoUrl`, gerada por `generateUrl`) ou são inteiros do
servidor (`data.id`, `criadaEmEpoch`); `confirmarPs`/`avisarPs` usam só `textContent` (`pasta-dialogos.js:371-373, 475`).
Nenhum guarda removido. A escrita escondida dos templates (Detalhes, Clientes, checklist, Sugerir documentos,
"Criar tarefa" da IA, Push pendurado) é só UI: os tokens de escrita passaram a sair só para quem escreve
(`_cliente_linha`/`_dados_trilho` `podeMexerNoCliente`, `_documentos_sugeridos` `sugPodeEscrever`) e as rotas
continuam com seus guardas. "Ver alteração" (PAS-045b) da observação financeira só chega a quem vê o financeiro
(`MontadorDaTimelineInteligente.php:145-160`).

### Rotas novas/alteradas do C6 — checagem

| Rota | Posse 404 → 403 | CSRF | XSS | Efeito em GET | Teste cross-tenant |
|---|---|---|---|---|---|
| `pasta_push_texto` (GET) | ✓ `PastaPushProcessualController.php:153-159`, publicação casada com processo desta pasta e tenant (`:161-169`) | n/a | ✓ JSON → clipboard (`pasta-push.js:217-220`) | ✓ nenhum (teste confirma `lida` intacto) | ✓ `PastaPushTextoControllerTest.php:98, 123, 159, 184, 205` |
| `inteligencia_analise_registro` (POST) | ✓ `guardar()` `AnalisePushController.php:320-330`; análise por tenant+alvo+pasta, excluída/não concluída → 404 (`RegistrarExportacaoDaAnaliseUseCase`) | ✓ token por análise (`:211`) | ✓ trilha renderizada pelo Twig (`_auditoria.html.twig`) | n/a (GET do .txt segue puro, teste `:154`) | ✓ `RegistroDeExportacaoControllerTest.php:212, 241, 328` |
| `ROTAS_LIBERADAS` + `inteligencia_analise_registro` | ✓ mesmo motivo do zip (VER + CSRF, efeito só no `audit_log`) | — | — | — | ✓ `:140` (lápide registra) |
| `pasta_resumo_pdf` `secao=observacao/processo` | ✓ `guarda()` antes (`PastaResumoController.php:192`), obs por `findOneBy(id,pasta,tenant)`, processo só entre os vínculos e com tenant (`observacaoDaPasta`/`processoDaPasta`) | n/a | ✓ corpo do banco, `registro_texto` (sanitizador), nunca HTML da tela | ✓ nenhum | ✓ `PastaResumoPdfControllerTest.php:273, 306, 332, 352` |
| `pasta_mencionaveis?origem=financeiro` | ✓ inalterado (UseCase) | n/a | ✓ JSON | ✓ | ✓ `PastaMencaoTest` |
| `tarefa_criar_para_pasta` | ✓ (acima) | ✓ | — | — | ✓ |
| 6 rotas de observação | ✓ (acima) | ✓ inalterado | ✓ | — | ✓ |

## Pendências A

Nenhuma. Os três A do C6 e a pendência C6-SEC-02b fecharam. Seguem C (sem mudança): SEC-008b, SEC-009, SEC-010,
SEC-011 e C6-SEC-03 parte C (VER × EDITAR). C6-FPN-05 não é desta área.

## Novas divergências

Nenhuma A nova. 0 B/C/D/E novos com ID.

### Observações (não contadas)

- **`inteligencia_analise_registro` sem limitador:** quem vê a pasta pode repetir o POST (token fixo por sessão e
  análise) e inflar o "Registro de auditoria (N)" e o `audit_log` sem limite. Insert barato, sem vazamento; o ledger já
  diz que a trilha é informativa. O .zip, que também grava no `audit_log`, tem o limitador próprio. Se quiser fechar:
  reaproveitar uma cota por usuário (ex.: 30/10 min) e responder 201 sem gravar acima dela. Decisão, não A.
- **Mencionáveis do financeiro:** quem pergunta só precisa VER a pasta (não ver o financeiro). Devolve só nomes e,
  hoje, VER = ver financeiro. Latente, como o C6-SEC-02.
- **Responsáveis de `tarefa_criar_para_pasta`** continuam filtrados só por tenant (aviso a quem não vê a pasta). Vai
  junto com a parte C do C6-SEC-03.
- **`pasta_resumo_pdf`** (Dompdf por GET, sem limitador) ganhou duas folhas; custo por pedido igual. Mantém o registro do C6.
- **Comentário "o TenantFilter não se aplica a `find()`"** continua em `PastaMencaoController.php` e `AnalisePushController::guardar`
  (Obs. 1 do inventário).

## Matriz

Denominador: as 75 rotas do C6 + 2 novas (`pasta_push_texto`, `inteligencia_analise_registro`) = **77**. Correção: a soma
da matriz do C6 dava **481/498** (17 células fora), e não 537/551 como estava escrito. Os números abaixo usam a soma corrigida.

| Checagem | OK | Aplica-se | Fora do OK |
|---|---|---|---|
| (1) Tenant explícito | 76 | 77 | `processo_edit` (obs., legado) |
| (2) IDOR → 404 | 77 | 77 | — (C6-SEC-01 fechado) |
| (3) Permissão adequada | 74 | 77 | SEC-009 (2, C), `tarefa_concluir` (obs.) |
| (3b) Listagem sem vazar | 0 | 1 | SEC-010 (C) |
| (4) CSRF em POST | 56 | 56 | — |
| (5) Lápide | 52 | 52 | — |
| (6) Auditoria das escritas | 40 | 42 | `cliente_contatos`, `tarefa_alertar` (ciclo 0) |
| (7) XSS | 40 | 40 | — |
| (8) Upload/entrega de arquivo | 3 | 3 | — |
| (9) Rate limit (notificação/zip/IA) | 5 | 5 | — (registro da IA: obs.) |
| (10) Teste cross-tenant | 77 | 77 | — |
| Itens do §9 (#3, #5, #6) | 3 | 3 | — |
| Corrida no corrigir valor | 1 | 1 | — |
| C6-SEC-02 (+02b) | 1 | 1 | — |
| **Total** | **505** | **512** | 7 células: SEC-009/010 (C) + 3 obs. legadas |

## Resumo

- Conferência: **4 ✓** (C6-SEC-03, C6-SEC-01, C6-SEC-02, C6-SEC-02b), 0 ◐, 0 ✗.
- Regressões: **0**. Pendências A: **0**. Novas A: **0** (5 observações, nenhuma com ID).
- Nenhuma migration. Nada acionável como A nesta área.
