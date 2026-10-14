# Frente 2 — Fidelidade visual ao Claude Designer (pacote de 13/10/2026)

> **Para quem:** o dono e qualquer sessão que continue esta frente sem ter visto a conversa.
> **Escopo:** só a camada visual (Twig/CSS/JS). Banco, migrations, autenticação, permissões, APIs,
> CI/CD e changelog estão fora — são da frente de engenharia, que corre em paralelo.
> **Branch / worktree:** `fidelidade-designer` em `.claude/worktrees/fidelidade-designer`, base
> `origin/master` @ `a9653e66` (= produção, Engineering Loop publicado). Banco de teste
> `saas_testfidelidade-designer`.

## 1. Fonte

`/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/` (README, `especificacao/`, `prototipo/`).
O Engineering Loop anterior convergiu contra o pacote de **05/10** (`docs/design/claude-design-2026-10-05 (1)/`,
não versionado). Por isso o inventário desta frente é o **delta 05/10 → 13/10** mais as telas novas.

| Arquivo do protótipo | 05/10 → 13/10 |
|---|---|
| `01 - Dashboard 1.2.2` | mudou: 17 pílulas (999px) → 4px; inclui `bj-dica.js` e `bj-contas.js` |
| `02 - EXPEDIENTES 1.2.3` | mudou: 48 trocas de canto + 7 (b) + 16 (c) + 7 (d) — ver §3.2 |
| `bluejus-central.js` | mudou: 7 cantos + `/paradas` em linguagem natural + `ehPrimario` inclui Master |
| `Agenda 1A` | mudou: tag de tipo 999px → 4px; inclui bj-dica/bj-contas |
| `Push Compartilhado` | só inclui `bj-dica.js` |
| `03 Lista 1.1`, `04 Minhas Metas`, `05 Perfis`, `06 Trabalho Externo`, `07 Peticionar`, `FinBlue` | **novos** |
| `bj-dica.js`, `bj-contas.js`, `bj-agente.js`, mapas | **novos** |
| demais `.js` (editor, processo, visualizar, docsug, link, intelligence, avançado, equipe, docs, support) | iguais |

## 2. Entregas

| # | Entrega | Tamanho | Estado |
|---|---|---|---|
| E1 | Dashboard / Intelligence: cantos de 4px nos 6 elementos que existem no BlueJus | P | ✅ esta branch |
| E2 | Pasta: cantos de 4px nos 26 seletores cujo par mudou no 02 (§5) | P | ✅ `83d6724d` |
| E3 | **próxima** — Pasta: nota técnica (fundo `#f6fafd`, borda `#b9d6e8`, raio 4, conector 1.5px `#c9dfec`) e marca IA (10.5px/700/.12em `#0a6fa0`, "Intelligence" 14.5px) | P | — |
| E4 | Pasta: observações do Financeiro sem rolagem interna; nome do responsável com reticências (170px). (Tirar "Como usar esta aba" **só com decisão** — conflito com Farlei R215, §6) | P | — |
| E5 | Minhas Metas: cantos 4px, hover azul da linha, numeração por grupo | P | — |
| E6 | Pasta: Responsável na 1ª linha do cabeçalho; "Ação" e "Processo vinculado" como cards no trilho da aba Dados | M | ⛔ decisão — conflito com Farlei R087 (§6) |
| E7 | Diálogo de confirmação próprio com variante azul + troca dos ~20 `confirm()` nativos da Pasta | M | — |
| E8 | Lista do Expediente: hover azul, chips de prioridade/situação, menu de botão direito só com ações que já têm rota | M | — |
| E9 | Peticionar: barra do documento selecionado, ⋮/botão direito, número de ordem — com as rotas existentes | M | — |
| E10 | Agenda: tag de tipo (nome da `LegendaCor` pela cor) na lista e no detalhe | P | — |
| E11 | Perfis do escritório: lista no padrão (hover, cantos, ícones) | P | — |

Ordem: primeiro o que é só CSS e não toca arquivo compartilhado (E2–E5), depois arranjo de tela
(E6–E9), por fim telas que nunca foram redesenhadas (E10–E11).

## 3. Diferenças restantes

### 3.1 Dependem de decisão do dono (não decidi sozinho)

- **Selo "9+" das notificações com 4px.** Só o protótipo do Dashboard mudou, mas no BlueJus o selo é
  global (`.navtop-badge` em `app.css`). Mudar lá altera todas as telas, e a regra de 05/10 é adotar
  os 4px tela a tela.
- **`bj-dica.js` global** (todo `title` vira dica no padrão). Mexe em `base.html.twig`, que é
  compartilhado. E o pacote se contradiz: o README diz "fundo azul com letra branca"; o `bj-dica.js`
  usa fundo `#e5f1f7` e letra `#0b4f73`.
- **`bj-contas.js`** (modal multiconta): o BlueJus já tem a troca de escritório no dropdown do topo.
  Refazer como modal é visual; "primário/secundário" é permissão (fora desta frente).
- **FinBlue** não é o BlueJus: o código mora em `/home/prime/projetos/finblue`. Fora desta frente, salvo ordem contrária.

### 3.2 Visuais puras ainda não feitas

- (a) **Pasta — cantos**: feito na E2 (§5). Ficaram de fora, de propósito: `fm-*` em `pasta-arquivos.css`
  (gerenciador antigo, congelado para a Cobrança), `.pasta-contagem` (CSS sem uso), `.pasta-resp-chip`
  (`_resp_estilo.html.twig`, compartilhado com a lista do Expediente → E8), `.ps-chip-vinc` (faixa do
  cabeçalho que o desenho trocou pelo andamento em fases → depende de backend), o chip de cliente montado
  em JS no modal de vínculo (`show.html.twig:1244`, sem par identificado no protótipo).
- **Central (chat)**: 7 trocas de canto — só onde a janela existe no BlueJus.
- **Dashboard × protótipo, fora dos cantos** (vistos na comparação de E1, não fazem parte do delta de
  13/10): o painel do protótipo tem a alternância "Escritório | Inteligência de Desempenho" e o KPI
  "Fila (backlog)"; o BlueJus mostra "Vencidas". Já existiam em 05/10 — conferir no ledger do
  Engineering Loop se são itens C/D antes de mexer.
- Itens de E2–E11 acima.

### 3.3 Dependem de backend (fora desta frente — registrar para a engenharia)

Stepper "Andamento da pasta" (10 fases, datas, reativação, "Avançar para X?") e tudo que depende dele;
registros automáticos do sistema na linha do tempo; apensos com tipo e "Vincular apenso"; Push por
processo/apenso; exclusão de comentário em 60 min (hoje 15); `/paradas` do chat Central; cadeado/sigilo
e destaque de linha da Lista; situações Suspenso/Cancelado; descrição e duplicar marcador; prioridade
por meta; classificação Master/Primário/Secundário e todo o painel Master (mapa, idades, contas, planos,
cotas); módulo Trabalho Externo inteiro.

## 4. E1 — evidência

- **Desenho:** `01 - Dashboard 1.2.2.dc.html` de 13/10, linhas 874, 997, 1077, 2618, 2626, 2642 (999px → 4px).
  Mantidos em 999px no mesmo arquivo: `qualBarra` (L2617), `sw()` (L2577), barra do ritmo (L1323).
- **Código:** `app/public/css/dashboard-inteligencia.css` — `.db-ia-av-switch`, `.db-ia-qual-trilho`,
  `.db-ia-dist-trilho`, `.db-ia-chip`, `.db-ia-nivel-chip`, `.db-ia-pergunta > summary`.
- **Fora do Dashboard do BlueJus:** os outros 11 elementos alterados no protótipo são do painel
  "Equipe" (visão, perfis, responsáveis, plano por colaborador, eu × média, contagem de pastas), que o
  BlueJus não tem nessa tela, e o selo "9+" (§3.1).
- **Teste:** `tests/Dashboard/Unit/DashboardCantosPjeIntelligenceCssTest.php` — vermelho antes (6
  falhas), verde depois (9/9); trava também os 3 que o desenho manteve em pílula. `tests/Dashboard`
  478/478 no banco da frente.
- **Comparação na tela, 1440×900:** servidor embutido apontando para a worktree, painel aberto, Modo
  avançado ligado **só no navegador** (classe no DOM; nenhuma preferência gravada). Raio calculado pelo
  navegador: 4px nos 6 seletores (23 nós visíveis), 999px no interruptor, na barra do ritmo e no
  preenchimento. Captura lado a lado com o protótipo no mesmo viewport: ✓ chips de contagem, ✓ chips
  de nível, ✓ botão "Modo avançado", ✓ interruptor continua arredondado. O modal de alerta do ponto foi
  removido do DOM para a captura, sem registrar nada.
- **Não visto:** celular (o raio não tem regra de mídia) e tema escuro (as variáveis de tema não tocam
  no raio).

## 5. E2 — evidência

- **Desenho:** `02 - EXPEDIENTES 1.2.3.dc.html` de 13/10. Pareamento linha a linha contra o de 05/10:
  48 trocas reais (44 de 999px→4px, 4 de 3px→4px — estas 4 são a nota técnica, que vai na E3). O
  protótipo manteve 24 pílulas (interruptores, preenchimentos de barra, selos de status em JS, "9+").
- **Mapa:** cada seletor do BlueJus pareado pela assinatura do estilo (padding, cor, altura) e pelo texto
  do elemento. Duas correções sobre o inventário automático, conferidas no protótipo: `.ps-mrel-situacao`
  **muda** (é o `pill` de `metaVals`, L3689), e os preenchimentos `.ps-pag-barra-cheia`,
  `.ps-metas-barra-cheia`, `.pex-ck-barra-fill` **continuam** pílula (L3617, L6890, L6823) — só os trilhos
  mudaram.
- **Código:** 26 regras em `pasta-show.css` (14), `pasta-ia.css` (2), `pasta-ia-agentes.css` (3),
  `pasta-documentos-sugeridos.css` (1), `pasta-explorador.css` (5), `pasta-checklist.css` (1). Só o valor do
  raio; nenhum HTML, JS, rota ou dado.
- **Teste:** `tests/Pasta/Unit/PastaCantosPjeCssTest.php` — 26 vermelhos antes, 38/38 depois (trava também
  os 12 que continuam pílula). `tests/Pasta` + `tests/Inteligencia`: 3458/3458 no banco da frente.
- **Tela, 1440×900, Pasta 297 (id 464) × protótipo (Pasta 1180), 7 abas:** raio calculado pelo navegador
  nos 16 elementos visíveis com esses dados — 4px em todos os que mudaram, 999px nos 4 que ficam. Os
  outros 22 dependem de estado que a pasta não tem (Push com itens, meta aberta, análise de IA, busca de
  cliente, pagamentos); para eles vale o teste da folha.
- **Estados:** filtro de metas em repouso/hover/foco = 4px; o protótipo também não define hover nem foco
  próprio nesse filtro (só `transition`), então o contorno padrão do navegador no foco é igual nos dois.
  Busca dos documentos: hover e foco = 4px; no foco, fundo branco e anel `0 0 0 2px rgba(15,111,196,.35)`,
  idêntico ao `style-focus-within` do protótipo (L2133). Contador do filtro de metas já era 4px.
- **Visto na comparação e fora da E2** (vai ao checklist §6): andamento em fases; Responsável na linha 1;
  botão "Cadastro" ao lado das abas; botão flutuante do Chat I.A.; botão de marcador a mais no checklist
  do BlueJus; caixas de seleção nas linhas de documentos; faixa "Ganhe espaço" (só com duplicados).
- **Não visto:** celular e tema escuro (o raio não tem regra de mídia nem de tema nesses seletores).

## 6. Checklist acumulado da Pasta

`fidelidade-designer-1310-checklist-pasta.md` — 377 elementos da Pasta (cabeçalho, 7 abas, trilho,
modais, menus ⋮ item a item, menus de botão direito, editor, visualizador, Chat I.A/Central, globais),
cada um com ✓/≈/∅, onde mora no BlueJus, se precisa de backend e o ID do ledger anterior.
Contagem inicial: **216 ✓ · 48 ≈ · 113 ∅**. A §19 dele separa os ausentes por natureza e diz o que dá para
criar já como casca visual; e registra os 3 conflitos entre o desenho de 13/10 e a especificação do Farlei
(que o README do pacote manda prevalecer): faixa do cabeçalho (R087), "Como usar esta aba" (R215) e
andamento em fases (sem pedido).
