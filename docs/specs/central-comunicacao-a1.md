# Central de Comunicação — A1: conversas 1:1 persistidas (spec)

Frente `designer-central-ia`. Decisões do dono de 14/10 que valem aqui: **D2** polling, sem Mercure/WebSocket;
**D3** modelo atual de permissões, sem papel novo; **D5** nada ambíguo nem inventado; **D6** componentes
isolados, **sem tocar `base.html.twig`**. Base de desenho: `bluejus-central.js` do Designer 13/10; proposta em
`central-comunicacao-proposta.md`. Risco **MÉDIO** (conteúdo entre pessoas, multi-tenant).

## 1. Escopo

**Entra:** conversa individual entre duas pessoas do mesmo escritório; enviar mensagem de texto; histórico
paginado; não lidas por conversa e total; marcar como lida; novidades por polling com cursor; uma página
própria, `/central`, com a lista de conversas, a conversa aberta e o compositor.

**Fica fora (próximas entregas):** grupos, anexos, editar/excluir mensagem, @menção e notificação, menus de
botão direito, fixar/silenciar/arquivar, Chat I.A (D1), presença/"digitando", botão flutuante global (D6).

## 2. Quem pode (D3: modelo atual, sem papel novo)

- **Usar a Central:** quem tem **vínculo ativo** (`user_tenant.is_active`) com o escritório da sessão — o mesmo
  critério que já define quem é colaborador (`findColaboradoresAtivosPorTenant`). Não há permissão de módulo nova:
  conversar com colega não é módulo de negócio, como as notificações não são.
- **Abrir conversa com alguém:** o destinatário também precisa de vínculo ativo no MESMO escritório; consigo
  mesmo, não.
- **Ler e escrever numa conversa:** só os **participantes**. Nem administrador do escritório, nem
  `ROLE_SUPER_ADMIN`, nem papel de sistema lê conversa alheia — a regra não passa pelo `PermissionChecker`, que
  tem esses bypasses; é posse direta.
- **Participante que saiu do escritório:** não entra mais (sem vínculo ativo). Quem ficou continua lendo o
  histórico e **não** consegue mandar mensagem nova para ele (422).

## 3. Isolamento e privacidade

- Toda entidade é `TenantAware` (o `TenantFilter` age) **e** toda consulta leva o escritório explícito.
- Conversa de outro escritório ou de que não participo → **404** (nunca 403: não confirma que existe).
- **Fora da auditoria (`Auditavel`)**: o `AuditLogSubscriber` grava o estado inteiro da entidade, e o
  `audit_log` é lido por quem administra o escritório — auditar a mensagem entregaria a conversa privada a
  terceiros. Registrado em `AuditavelCoberturaTest::NAO_AUDITAVEIS`.
- Texto puro: renderizado escapado (Twig) e por `textContent` (JS). Nunca `innerHTML` com conteúdo.

## 4. Modelo

| Tabela | Colunas | Restrições |
|---|---|---|
| `central_conversa` | `id`, `tenant_id`, `tipo` (`individual`), `usuario_menor_id`, `usuario_maior_id`, `criada_por_id`, `criada_em`, `ultima_mensagem_em` | único `(tenant_id, usuario_menor_id, usuario_maior_id)` — o par nunca duplica |
| `central_participante` | `id`, `tenant_id`, `conversa_id`, `usuario_id`, `entrou_em`, `ultima_lida_id` | único `(conversa_id, usuario_id)` |
| `central_mensagem` | `id`, `tenant_id`, `conversa_id`, `autor_id`, `conteudo` (texto, 1–4.000), `criada_em` | índice `(conversa_id, id)` |

"Não lida" = mensagem de **outra pessoa** com `id > ultima_lida_id` do participante. Ler é mover o cursor; o
cursor nunca anda para trás.

## 5. Rotas (JSON; escrita com CSRF `ajax` no cabeçalho `X-CSRF-Token`)

| Método e rota | Nome | Faz |
|---|---|---|
| `GET /central` | `central_index` | página |
| `GET /central/contatos` | `central_contatos` | colegas com vínculo ativo, menos eu |
| `GET /central/conversas` | `central_conversas` | minhas conversas: `{id, outro:{id,nome}, ultima: Mensagem\|null, naoLidas, outroAtivo}`, mais recentes primeiro (pela última mensagem, senão pela criação) |
| `POST /central/conversas` `{destinatarioId}` | `central_conversa_abrir` | abre ou devolve a conversa existente com a pessoa (idempotente): `{conversa:{id, outro, outroAtivo}}`; corrida dos dois lados → 409, a tela tenta de novo |
| `GET /central/conversas/{id}/mensagens?antesDe=` | `central_mensagens` | 50 por vez, da mais antiga para a mais nova |
| `POST /central/conversas/{id}/mensagens` `{conteudo}` | `central_mensagem_enviar` | grava (201). O cursor de quem enviou **não** anda: a própria mensagem nunca conta como não lida |
| `POST /central/conversas/{id}/lida` `{ateId}` | `central_conversa_lida` | avança o cursor até `ateId` (limitado à última mensagem da conversa), com `UPDATE` condicionado no banco: duas abas não o fazem recuar |
| `GET /central/novidades` | `central_novidades` | **sem `desde`** (ou com valor inválido): primeira chamada, só o cursor atual e `naoLidas`. **Com `desde`** (0 inclusive): mensagens com `id > desde` nas minhas conversas (até 200, `completo=false` quando bate o teto) + `naoLidas` |

`Mensagem` = `{id, conversaId, autorId, autorNome, conteudo, criadaEm, minha}`. Ids acima do `int` do PostgreSQL
são tratados como inválidos (nunca chegam ao banco).

Erros: sem vínculo ativo → 403 (na prática a plataforma redireciona antes para `/escritorio/selecionar` quem não
tem vínculo com o escritório da sessão; a tela trata **só** o redirecionamento — e o 403 da Central — como sessão
encerrada: para o polling e mantém o rascunho. Página de erro em HTML (500, 502/503 de deploy, 403 de CSRF) é falha
passageira: "Sem conexão" e nova tentativa); conversa alheia → 404; texto vazio ou acima de 4.000, destinatário inválido,
consigo mesmo, destinatário que saiu → 422 `{erro}`; CSRF inválido → 403.

## 6. Tela

`/central` estende o layout atual (`base.html.twig` **não** muda). CSS em `public/css/central.css` (classes
`.bj-central-*`, cores por variável), JS nativo em `public/js/central.js` (sem biblioteca nova). Polling: 5 s
com a aba visível, parado com a aba oculta (`visibilitychange`), retomado ao voltar. Valores visuais do protótipo
onde ele os define; a página não tem link no menu lateral até a D6 (o menu mora no `base.html.twig`).

**Limitação conhecida (para a A2):** o cursor do polling é o id. Ids de identidade são reservados no `INSERT` mas
podem ser confirmados fora de ordem; se a 101 confirma antes da 100, a 100 não chega pelo polling (aparece ao
reabrir a conversa). Na A1 só acontece com a mesma pessoa enviando de duas abas no mesmo instante; em grupo vira
perda real na tela — a A2 precisa de janela de sobreposição no cursor (pedir um pouco antes e descartar repetidas).

## 7. Testes

Unit dos UseCases (regras de §2 e §4) · Functional das rotas: isolamento entre escritórios, conversa de que não
participo, super admin não lê conversa alheia, vínculo inativo, idempotência do par, contagem de não lidas,
cursor que não volta, novidades só das minhas conversas, CSRF · prova por reintrodução das guardas.
