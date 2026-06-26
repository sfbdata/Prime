# Spec — C4: CSRF em endpoints AJAX/JSON

> Frente **C4** da segurança residual (`followups-seguranca-residual.md`). Endpoints mutantes que
> respondem JSON (AJAX) e **não** passam por form Symfony estavam sem verificação CSRF. Sem migration.
> Sequenciamento decidido pelo dono: **C4a (jornada, ALTO) primeiro**, depois C4b (Kanban) e C4c
> (Agenda/Notificação) reusando a mesma infraestrutura.

## Enumeração (workflow read-only, 19 controllers)

A maioria das rotas mutantes já é protegida (form CSRF embutido no `handleRequest`, ou
`isCsrfTokenValid` por intenção). As **lacunas** (mutam estado + respondem JSON + sem CSRF):

- **ALTO (ponto eletrônico) — C4a:** `app_jornada_tenant_save` (POST), `app_jornada_colaborador_save`
  (POST), `app_jornada_colaborador_delete` (DELETE) **e `ponto_batida` (POST)** — o registro da
  batida de ponto, o endpoint mais sensível do domínio.
  > ⚠️ `ponto_batida` foi **perdido pela enumeração automática** (o agente Haiku do workflow não o
  > listou) e capturado pela **revisão adversarial**. Lição reforçada: não confiar no resumo de um
  > subagente — a revisão adversarial é a rede que pega o que a varredura erra.
- **Kanban (BAIXO, isolado por board) — C4b:** card criar/atualizar/mover; comentário criar/editar;
  checklist criar / item criar / item toggle; marcador criar/editar/toggle; anexo upload; board
  criar/editar (verificar se o form CSRF já cobre).
- **Agenda/Notificação — C4c:** `agenda_criar_ajax`, `agenda_legendas_salvar`, `notificacao_marcar_lida`.

Achado lateral (fora do CSRF): `kanban_card_mover` não valida acesso ao board (só existência do
card) — IDOR potencial; registrar como follow-up à parte.

## Mecanismo (definitivo, reutilizável)

CSRF de AJAX por **token único `'ajax'` enviado no header `X-CSRF-Token`**, validado no backend.
Escolhido por ser o padrão profissional (Rails/Laravel/etc.), DRY (frontend num único ponto) e
uniforme — funciona para POST com body JSON **e** para DELETE sem body (onde um `_token` no corpo
não serviria).

1. **Layout base (`templates/base.html.twig`):** `<meta name="csrf-token" content="{{ csrf_token('ajax') }}">`
   no `<head>` + um **interceptador JS** (vanilla, sem dependência) que injeta `X-CSRF-Token` em toda
   requisição AJAX **same-origin** de método inseguro (POST/PUT/PATCH/DELETE), cobrindo `fetch` **e**
   `XMLHttpRequest` (jQuery). É inofensivo para endpoints que ainda não validam o token.
2. **Backend (`App\Shared\Trait\ValidaCsrfAjaxTrait`):** `validarCsrfAjax(Request $request)` →
   `isCsrfTokenValid('ajax', $request->headers->get('X-CSRF-Token'))`; lança 403 se inválido.
   Endpoints-alvo chamam o helper logo após a checagem de autenticação.

Rollout incremental: o interceptador (frontend) entra inteiro no C4a e passa a mandar o token em
tudo; a **validação** (backend) é ligada por endpoint, frente a frente. Quando todos estiverem
cobertos, avaliar trocar por um listener global (follow-up).

## C4a — escopo desta entrega

- Infra: meta + interceptador no `base.html.twig`; trait `App\Shared\Trait\ValidaCsrfAjaxTrait`.
- Validação em `JornadaTenantController::save`, `JornadaColaboradorController::save`/`::delete` e
  `PontoController::batida` — sempre **após** os guards de autz/tenant, **antes** de qualquer mutação.
- Frontend dos 4 endpoints (`templates/tenant/_jornada_colaborador_tab.html.twig`,
  `_modal_jornada_tenant.html.twig`, `templates/ponto/index.html.twig`) usa `fetch` nativo → coberto
  **automaticamente** pelo interceptador, sem edição de template.
- **Risco ALTO** (ponto eletrônico): registrar spec (este doc) e revisar contra ela.

### Testes (C4a)

`tests/Ponto/Functional/JornadaCsrfControllerTest.php` (jornada) e
`tests/Ponto/Functional/BatidaCsrfControllerTest.php` (batida): para cada endpoint, requisição
**sem** `X-CSRF-Token` → **403**; **com** token válido → sucesso (jornada) ou 422 da validação de
payload (batida, provando que o CSRF passou). **Mutação confirmada:** removendo as chamadas de
validação, as requisições sem token passam (200/422) — testes ficam vermelhos. (O interceptador JS é
frontend → validado no smoke manual / E2E, não no teste funcional.)

### Smoke manual (C4a)

Abrir a tela de jornada (modal do tenant + aba do colaborador), salvar e excluir → deve funcionar
(o interceptador injeta o header). Sem o interceptador, salvar daria 403.

## C4b — Kanban (entregue)

Re-enumeração **verificada** (leitura dos 6 controllers + `debug:router`, 26 rotas), não confiando só na
lista do workflow. Resultado: **12 endpoints** mutantes JSON sem CSRF (não 14 — ver abaixo). Em cada um,
`use App\Shared\Trait\ValidaCsrfAjaxTrait;` + `$this->validarCsrfAjax($request)` **após** o guard de
tenant/acesso (404/403) e **antes** da mutação. `toggleItem` ganhou `Request $request` na assinatura.

| Controller | Endpoints ligados |
|---|---|
| `KanbanCardController` | `kanban_card_criar`, `kanban_card_atualizar`, `kanban_card_mover` |
| `KanbanChecklistController` | `kanban_checklist_criar`, `kanban_checklist_item_criar`, `kanban_checklist_item_toggle` |
| `KanbanAnexoController` | `kanban_anexo_upload` |
| `KanbanMarcadorController` | `kanban_marcador_criar`, `kanban_marcador_editar`, `kanban_marcador_toggle` |
| `KanbanComentarioController` | `kanban_comentario_criar`, `kanban_comentario_editar` |

**Já protegidos — NÃO tocados (verificado):**
- Os 6 `*_excluir` (board/card/checklist/item/anexo/marcador/comentário) usam token por-intenção no
  corpo (`isCsrfTokenValid('kanban_..._excluir_'.$id, _token)`).
- `kanban_board_criar` / `kanban_board_editar` usam **Symfony Form** (`KanbanBoardType`/`KanbanBoardEditType`
  com `csrf_token_id` e `csrf_protection` ligado por padrão) → o `_token` do form é validado no `isValid()`.
  Por isso a spec dizia "14"; 14 − 2 (board, já cobertos) = **12 reais**. `KanbanBoardController` ficou intocado.

## C4c — Agenda/Notificação (entregue)

Legado (`src/Controller/`) — edição cirúrgica (Opção A), sem migrar o domínio. **3 endpoints**:

| Controller | Endpoint | Obs |
|---|---|---|
| `AgendaController` | `agenda_criar_ajax` | CSRF após o guard `canAccessModule('agenda')`, antes do `try` |
| `AgendaController` | `agenda_legendas_salvar` | CSRF após o guard, antes do `json_decode` |
| `NotificacaoController` | `notificacao_marcar_lida` | ganhou `Request $request`; CSRF após o guard de posse |

**Já protegidos — NÃO tocados (confirmado lendo, varredura do `debug:router`):** `agenda_excluir`
(`delete{id}`), `agenda_cancelar` (`cancelar{id}`), `agenda_atualizar_datas` (`agenda_atualizar_datas`,
feito no S4), `agenda_novo`/`agenda_editar` (form `EventoType`), `notificacao_marcar_todas_lidas`
(`marcar_todas_lidas`), `notificacao_excluir` (`excluir_notificacoes`). A varredura achou esses 2 últimos
POSTs de Notificação — mutam, mas já têm CSRF; adicioná-los seria falso-positivo.

### Testes (C4b/C4c)

- `tests/Kanban/Functional/KanbanCsrfControllerTest.php` (13 testes): par discriminante por endpoint
  (sem token → 403; com token `TOKEN_ajax` via storage fake → sucesso + `sucesso:true`). Cenário inline
  (gestor `isSystem` → acesso ao módulo; board do próprio user → `temAcesso`; coluna/card/checklist/item/
  marcador/comentário). `kanban_anexo_upload`: com token mas sem arquivo → 422 (CSRF passou; mesmo padrão da
  batida no C4a). +1 teste prova que `kanban_board_criar` sem `_token` do form → 422 (form CSRF ativo).
  Usa `disableReboot()` p/ o storage fake sobreviver às 2 requisições do par.
- `tests/Agenda/Functional/AgendaCsrfControllerTest.php` (2) + `tests/Notificacao/Functional/NotificacaoCsrfControllerTest.php` (1).
- 2 testes legados de isolamento (`AgendaIsolamentoControllerTest::testSalvarLegendas...`/`testCriarAjaxRejeita...`)
  passaram a instalar o storage CSRF + header (exercem os endpoints agora protegidos; continuam testando isolamento).
- **Mutação confirmada:** neutralizando o `throw` do trait, **15/16** testes CSRF ficam vermelhos (os 15 que usam o
  trait); o 16º (board form) continua verde — prova que aquele teste é independente do trait. Suíte **825/825**.

## Fora de escopo

- IDOR de `kanban_card_mover` (não valida acesso ao board, só existência do card) — follow-up à parte.
- Migração para listener global de CSRF (follow-up, após cobertura total).
- Smoke do interceptador no browser (pendência manual herdada do C4a, Chrome ausente no ambiente).
