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
| E2 | Pasta: cantos de 4px nos ~31 seletores cujo par mudou no 02 (lista em §3.2-a) | P | próxima |
| E3 | Pasta: nota técnica (fundo `#f6fafd`, borda `#b9d6e8`, raio 4, conector 1.5px `#c9dfec`) e marca IA (10.5px/700/.12em `#0a6fa0`, "Intelligence" 14.5px) | P | — |
| E4 | Pasta: observações do Financeiro sem rolagem interna; nome do responsável com reticências (170px); remover card "Como usar esta aba" | P | — |
| E5 | Minhas Metas: cantos 4px, hover azul da linha, numeração por grupo | P | — |
| E6 | Pasta: Responsável na 1ª linha do cabeçalho; "Ação" e "Processo vinculado" como cards no trilho da aba Dados | M | — |
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

- (a) **Pasta, 49 pílulas restantes**: ~31 trocam para 4px (o par mudou no protótipo), 11 continuam
  pílula (interruptores, selos de status montados em JS), 7 incertas
  (`pasta-explorador.css:646,744`; `pasta-arquivos.css:335,539`; `pasta-ia-agentes.css:216,381,475`).
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
