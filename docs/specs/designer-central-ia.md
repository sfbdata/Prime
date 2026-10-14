# Designer → BlueJus: Central, Chat I.A, Agente de Pastas e comandos

Frente `designer-central-ia` (aberta em 14/10/2026, base `origin/master` @ `a3919b5c`). Converte para a
arquitetura real do BlueJus (Symfony 7.4 + Twig + JS nativo) o que o pacote do Claude Designer de 13/10
(`~/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/`) descreve em `bluejus-central.js`, `bj-agente.js`,
`COMANDOS_CHAT_IA.md` e `ESPEC_AGENTE_PASTAS.md`.

**Retomada:** `docs/specs/designer-central-ia/HANDOFF.md` (estado verificado, decisões, próxima tarefa).

**Não é esta frente:** fidelidade visual das telas existentes (frente `fidelidade-designer`, E1–E11).
Esta frente não toca Twig/CSS/JS das telas que aquela reivindica (Pasta, Expediente, Minhas Metas,
Dashboard), nem `base.html.twig`/`app.css` sem decisão do dono (D6), nem `PastaRepository` (a E8 mexe no
`aplicarFiltrosPasta`).

## 1. Stack medida (14/10)

PHP 8.2 (`Dockerfile`), symfony/framework-bundle 7.4.5, twig 3.23.0, doctrine/orm 3.6.2, messenger 7.4.14
(transporte Doctrine). Frontend sem bundler: Bootstrap 5.3.3, Bootstrap Icons 1.13.1, AdminLTE 4.0.0-rc3,
jQuery 3.6.0, Select2 4.1.0-rc.0, FontAwesome 6.5.2 — todos por CDN em `base.html.twig`; JS/CSS próprios em
`app/public/{js,css}/` (kebab-case por tela). Não há Mercure, WebSocket nem SSE: "tempo real" hoje é polling
(notificações 30 s; status da IA com backoff). Não há adaptador real de IA: `ProvedorNaoConfigurado` em
produção e `ProvedorFalso` só em `when@test`.

## 2. Matriz de rastreabilidade

Estado: ✅ existe · 🟡 parcial · ❌ não existe · ⛔ bloqueado por decisão.

| Funcionalidade | Origem no Designer | Equivalente no BlueJus | Estado | Depende de | Implementação | Testes |
|---|---|---|---|---|---|---|
| Agente · ferramentas de leitura (`buscar_pastas`, `contar_pastas`, `listar_documentos`) | `ESPEC_AGENTE_PASTAS` Fase 1; `bj-agente.js` `TOOLS` | `PastaRepository::findByFilters`/`findByFiltrosEMarcador`, `PastaDocumentoRepository::findByPastaComSecao` | ✅ **Fase 1 desta frente** (§3) | — | `AgenteDePastasController` + 3 UseCases | Unit + Functional |
| Agente · vocabulário (responsáveis, marcadores) | `BJPastasPonte.resps()/marcadores()` | `UserRepository::findColaboradoresAtivosPorTenant`, `MarcadorRepository::findTodosPorTenant` | ✅ Fase 1 | — | `GET /api/agente/opcoes` | Functional |
| Agente · `ler_documento` (texto PDF/DOCX/OCR) | Fase 1 | nenhum extrator de texto no projeto | ❌ | D8 (dependência nova) | — | — |
| Agente · plano assinado, lote, auditoria `agente_acao`, desfazer 10 min | Fases 2–3 | `PastaSomenteLeituraListener` (lápide), `canAccessResource` | ⛔ | D3, D4, migration | Fase 3 | — |
| Agente · planejador em linguagem natural | `planejar()` (`window.claude.complete`) | porta `ProvedorDeLinguagem` | ⛔ | D1 | Fluxo novo | ProvedorFalso |
| Agente · janela lateral | `bj-agente.js` (Lista 1.1) | `templates/expediente/*` (frente fidelidade) | ⛔ | Fase 3 + coordenação | — | — |
| Agente · conector MCP | Fase 4 | `app/src/Mcp/` (servidor MCP de leitura de PROD, outro propósito) | ❌ | D9 (token pessoal) | — | — |
| Central · conversas 1:1, histórico, não lidas, polling | `bluejus-central.js` l.295–376, 5991–6157 | `app/src/Central/` (A1) | ✅ **A1** (`central-comunicacao-a1.md`) | — | 3 entidades + migration + `/central` isolada | Unit + Functional |
| Central · grupos | l.342–376 | — | ❌ | A1 | `tipo = grupo` | — |
| Central · anexos | l.5895–5915 | storage E2 (`app/src/Shared/`) | ❌ | Central | — | — |
| Central · notificações (15 tipos, grupos, baixa, encaminhar) | l.61–145 | `App\Entity\Notificacao` + `/notificacoes/*` | 🟡 | Central | ampliar tipos | — |
| Central · @menção global | l.5916–5958 | só no Registro da pasta (`pasta-mencoes.js`) | 🟡 | Central | — | — |
| Central · presença, "digitando", tempo real | l.263–341 | polling | ❌ | D2 | — | — |
| Central · menus de botão direito (notificação, usuário, conversa, mensagem) | l.5591–5873 | — | ❌ | Central | — | — |
| Ligações (BlueJus Connect, WebRTC) | l.5161–5400 | — | ⛔ | D-CONNECT (servidor de sinalização/TURN) | frente própria | — |
| Chat I.A (`responderIA`) | l.4724–4900 | porta `ProvedorDeLinguagem`, cota, auditoria | ⛔ | D1 | — | — |
| Comandos `/` classe (a) — consulta determinística | `COMANDOS_CHAT_IA.md` (~30) | dados existentes (pastas, metas, push) | ❌ | Central (onde aparece) | lote por domínio | — |
| Comandos `/` classe (b) — LLM sobre contexto | idem (~250) | — | ⛔ | D1 | — | — |
| Comandos `/` classe (c) — domínio inexistente (negociação, terceiros, workflows, núcleo cognitivo, red team) | idem | — | ⛔ | D5 (spec do Farlei) | — | — |
| Pedido de acesso a pasta trancada | l.148–215 | `resource_access` (sem pedido) | ❌ | D3 | — | — |
| Zerar estatísticas do Dashboard | l.168–215 | — | ❌ | spec | — | — |
| Controle de "baixas aceleradas" | l.5427–5475 | — | ❌ | spec | — | — |
| Motores do Dashboard (`bluejus-intelligence/avancado/equipe.js`) | módulos ES puros | `app/src/Dashboard/` | 🟡 (frente fidelidade E1) | — | — | — |

## 3. Fase 1 do Agente de Pastas — contrato (implementado)

Risco BAIXO (só leitura), com isolamento multi-tenant inegociável. Sem migration, sem tela.

**Quem pode:** quem tem o módulo `expediente` no escritório atual (o mesmo `assertAccess` da Lista).
**A busca enxerga exatamente o que a Lista do Expediente enxerga** — todas as pastas do escritório, com
lápides marcadas (`excluida: true`). Não há exposição nova. Os documentos exigem, além disso,
`canAccessResource(pasta, view)`, como a aba Documentos.

| Rota | Nome | Entrada | Saída |
|---|---|---|---|
| `GET /api/agente/pastas` | `inteligencia_agente_pastas_buscar` | `busca` (texto livre da Lista), `responsavel` (id), `marcador` (id), `situacao` (`ativo`\|`arquivado`), `prioridade` (`normal`\|`prioridade`\|`urgente`), `desde`/`ate` (`AAAA-MM-DD`, data de abertura), `nups` (separados por vírgula, até 500), `pagina` (1–10.000) | `{pastas:[{id, nup, identificador, clientes:[nome], acao, responsavel:{id,nome}\|null, marcadores:[{id,nome,cor}], situacao, prioridade, abertaEm, excluida}], total, pagina, porPagina:50}` |
| idem `?contar=1` | idem | mesmos filtros | `{total}` |
| `GET /api/agente/pastas/{id}/documentos` | `inteligencia_agente_pastas_documentos` | id da pasta | `{pasta:{id,nup}, documentos:[{id, titulo, nome, categoria, tipo, tamanho, data, sha256, secao}]}` |
| `GET /api/agente/opcoes` | `inteligencia_agente_opcoes` | — | `{responsaveis:[{id,nome}], marcadores:[{id,nome,cor,paiId}], situacoes, prioridades}` |

Erros: filtro inválido, chave desconhecida ou valor em lista (`?nups[]=`) → 422 `{erro, campo}`; marcador
ou responsável sem vínculo com o escritório → 422 (nunca lista vazia, que faria o agente afirmar "nenhuma
pasta"); pasta de outro escritório → 404 (antes de checar permissão); sem acesso à pasta → 403; sem módulo → 403.

**Semântica = a da Lista** (`PastaRepository`, sem alteração): as chaves são exatamente as que
`ExpedienteController::filtrosDaRequest` manda. `busca` ignora acento e caixa e casa NUP, identificador,
cliente vinculado PF/PJ, credor/devedor da cobrança judicializada, ação, classe, número e assunto do
processo principal; `desde`/`ate` são sobre `data_abertura`. A saída tem o mesmo texto da tela:
`identificador` = `identificador_pasta()` (derivado da cobrança, senão o gravado), `clientes` quando não há
identificador, `acao` com o mesmo recuo da coluna (ação → classe → assunto → número do processo principal).
Revisão `feature-review-agent` (14/10) achou a primeira versão divergente — usava os ramos `cliente`/`acao` do
repositório, que a Lista não usa e que não ignoram acento/caixa (`acao=cobrança` dava zero) — e foi corrigida.

**Divergências da especificação do Designer (registradas, não inventadas):**
- **NUP não é chave:** `pasta.nup` não é único no escritório (só índice). A rota de documentos usa o **id**
  da pasta; o `nup` vem na saída. `nups` aceita texto (o desenho declara inteiro), em ordem natural.
- **`cliente`/`acao` → `busca`:** o desenho separa os dois; a Lista não. Recusados com 422 indicando `busca`.
  Filtro preciso por cliente ou ação exige corrigir os ramos do repositório (acento/caixa) — junto com a
  E8 da `fidelidade-designer`, que mexe no mesmo método.
- **Nome → id:** o desenho manda nome em `responsavel`/`marcador` ("Mariana", "Despachar"); aqui é id, e
  `/api/agente/opcoes` é a ponte (quem traduz é o planejador, D1).
- **Enumerações em minúsculas** (`ativo`, `urgente`), como no banco; o desenho usa maiúsculas
  (`ATIVO`, `URGENTE`) — recebe 422 explicando os valores, nunca filtro ignorado.
- **Nomes da saída:** `identificador`/`clientes`/`abertaEm` em vez de `cliente`/`data`, porque
  `nome_cliente` é o identificador da pasta e não o cliente (decisão do dono, 01/09).
- **Situação:** o BlueJus tem 2 (`ativo`, `arquivado`); o desenho lista 4 → D4.
- **`responsavel=sem`:** chega com a integração da E8 da frente fidelidade (que ensina o repositório). Até
  lá, só id numérico; `sem` é recusado com 422 em vez de devolver lista vazia enganosa.
- **`semMetas`:** ambíguo (nenhuma meta? nenhuma em aberto?) → D7, fora da Fase 1.
- **`nups` com outros filtros:** recusado (422) na Fase 1 — o repositório não combina as duas coisas e
  alterá-lo colide com a E8.
- **`ler_documento`:** fora (D8).

## 4. Decisões do dono (abertas)

- **D1 — Provedor de IA:** ✅ decidido em 14/10: **Anthropic** (Claude, API oficial). Adaptador pronto e dormente;
  restam D1-a…D1-f em `inteligencia-provedor-anthropic.md` §6 (SDK, modelo/esforço, ZDR, base legal LGPD,
  `inference_geo`, piloto). Ativação em produção só com aprovação.
- **D2 — Tempo real da Central:** recomendação: polling com cursor (padrão da casa). SSE pelo próprio PHP-FPM
  prende um worker por aba aberta, então o passo seguinte real é um hub dedicado (Mercure), que é infra nova —
  só com medida que justifique. Ligações à parte (D-CONNECT). Proposta: `docs/specs/central-comunicacao-proposta.md`.
- (D5 detalhada em `docs/specs/designer-central-ia-comandos.md`.)
- **D3 — Papéis "Master", "primário da conta", "Controladoria":** não existem. Proposta: permissões novas no
  catálogo (`modules.agente_pastas.alterar_lote`, …) atribuídas pelo papel do escritório. Risco MÉDIO.
- **D4 — Situações da pasta:** manter 2 ou criar `suspenso`/`cancelado`.
- **D5 — Comandos:** aprovar a classificação (a)/(b)/(c) e a lista da classe (a).
- **D6 — Botão global do Chat:** exige `base.html.twig`; definir se esta frente ou a de fidelidade faz a casca.
- **D7 — `semMetas`:** "sem nenhuma meta" ou "sem meta em aberto".
- **D8 — Extrator de texto de documentos** (biblioteca nova) para `ler_documento`.
- **D9 — MCP do agente:** token pessoal por usuário (identidade = risco ALTO).

## 5. Ordem

1. ✅ Agente Fase 1 (leitura).
1b. ✅ Central A1 — conversas 1:1 persistidas, polling, página isolada (decisões D2/D3/D6 de 14/10).
2. Agente Fase 3 núcleo determinístico (plano assinado, `agente_acao`, lote, desfazer) — D3/D4.
3. Central A (conversas, mensagens, anexos, notificações, polling) — D2/D6 + spec própria.
4. Comandos classe (a) em lotes.
5. Adaptador de LLM + planejador + Chat I.A — D1.
6. Connect/ligações — frente própria.
