# Auditoria 2: fidelidade visual do Dashboard × dc 1.2.2 (05/10/2026)

Read-only, sem nada alterado no repositório. Base: `master` em `3a8769b0`.
Abreviações: **dc** = `docs/design/claude-design-2026-10-05 (1)/01 - Dashboard 1.2.2.dc.html`;
**RD** = `design_handoff_dashboard/README.md`. Caminhos do app são relativos a `app/`.
Severidade: **A** = aparece de relance ou em toda carga · **B** = detalhe, estado raro ou um tema só.

Fiquei só com o que ainda diverge depois de B4/B5/B12/B18, do painel Intelligence e dos selects/calendário
próprios. O que já bate e o que depende do dono estão no fim, para ninguém refazer.

---

## 1. Divergências concretas e corrigíveis

| # | Sev | Onde (atual) | Atual | Desenho | Correção sugerida |
|---|---|---|---|---|---|
| 1 | **A** | `templates/dashboard/_resultado.html.twig:398-404` + `public/css/dashboard.css:1749` | Na linha de Total, com período ativo, só 3 colunas (Total metas, Total demandas, Pastas criadas) têm `.db-total-cel` (número + pílula empilhados). As outras 4 têm só o número, centralizado na vertical pelo `vertical-align:middle`. Resultado: os números da linha de Total ficam **em duas alturas** (desnível de ≈10px) | dc l.815-822 + script l.3345: toda célula do Total é `flex-direction:column;align-items:center;gap:3px`, número no topo e pílula embaixo; todos os números alinhados | Envolver as 4 células sem tendência em `.db-total-cel` e, quando `tAnt` não for null, colocar um espaçador invisível de 18px no lugar da pílula (`visibility:hidden`, `aria-hidden`). Sem período (sem pílulas) não muda nada |
| 2 | **A** | `public/css/dashboard.css:750-753` (`.db-dd-av-ini`) + `templates/dashboard/index.html.twig:164-166, 196-198` (`db-avatar--{{ av }}`) | Pessoa sem foto nos selects: círculo com o **degradê saturado da tabela** (9 cores) e boneco branco a `--av × .38` (≈8px no botão, ≈11px na lista) | dc script l.2183 + `avatar()` l.2207-2214: `AV_TONS` (6 tons **pastel**, l.1692), fundo `linear-gradient(160deg, bg, mistura 82% bg/18% fg)`, boneco **na cor do tom** a **62%** do tamanho (14px no botão de 22, 19px na lista de 30), deslocado `translateY(10%)`, anel `inset 0 0 0 1px fg@18%` | Classe de tom própria (`.db-dd-tom--p0…p5`, índice `(id) % 6`) com os 6 pares do dc e tokens escuros; ícone a `calc(var(--av) * .62)` + `translateY(calc(var(--av) * .1))`. Não usar `.db-avatar--N` aqui (é da tabela) |
| 3 | B | `public/css/dashboard.css:740-744` (`.db-dd-av-ico`) | Quadrado de "Todos"/cargo sem anel | dc l.2213: `box-shadow:inset 0 0 0 1px <fg>2e` em todo avatar do select | Anel inset na cor do tom a 18% (tokens `--db-fp-tom-*-fg`) |
| 4 | B | `templates/dashboard/_resultado.html.twig:262` | Sem `ordenar` na URL, nenhum cabeçalho fica em acento, mas a tabela já vem ordenada por Total metas desc (regra do UseCase, repetida em `_desempenho_cards.html.twig:41-44`) | dc l.1790 `sortKey:'totalMetas', sortDir:'desc'` + `th()` l.3021: "TOTAL METAS" em `#0c7a9c` com chevron opaco desde a abertura | `ordCol` com o mesmo fallback do `_desempenho_cards` (`'metas'` quando vazio ou inválido). Só visual: o `filtro-tabela.js:177-184` decide a direção pelos hidden, não pela classe |
| 5 | B | `templates/dashboard/_resultado.html.twig:288-294` e `_desempenho_cards.html.twig:68-74` | Mapa do ícone testa `'advog'` **antes** de `'sóci'`: "Advogado sócio" ganha `bi-briefcase` na tabela e no celular, mas `bi-award` no select de Cargo (`index.html.twig:224-225`, que testa sócio primeiro) | dc l.1691 `CARGO_ICONE`: "Advogado sócio" → `bi-award`; RD "Filtros em cascata": sócio `bi-award` | Trocar a ordem nas duas cópias (sócio antes de advogado), igual ao `index.html.twig` |
| 6 | B | `templates/dashboard/index.html.twig:56` + `public/js/dashboard.js:362-365` | Cabeçalho do PDF: "Período … · Responsável · Cargo", sem a busca | dc script l.3162-3166 `printFiltros`: acrescenta `· Busca: <texto>` quando há busca | Acrescentar o trecho no Twig (`filtros.busca`) e no `atualizarCabecalhoImpressao` (lendo o `.js-filtro-busca`) |
| 7 | B | `templates/dashboard/_resultado.html.twig:251-256` | Sem linhas, a `<table>` inteira some: fica só o ícone e o texto, **sem a linha de cabeçalhos** | dc l.775-787 + l.826-831: a linha de cabeçalhos (`headStyle`) é sempre renderizada; o vazio fica embaixo dela | Renderizar a `<table>` com `<thead>` sempre e pôr o estado vazio numa linha `<tbody><tr><td colspan="9">`. **Cuidado:** os testes contam `table > tbody > tr`. Se o vazio virar `tr`, isso quebra a contagem. Melhor deixar o `<div class="db-empty">` logo depois de uma `<table>` só com `<thead>` |
| 8 | B | `public/css/dashboard.css:1572-1578` + regra `<768` em `:1867-1870` | No celular o vazio mostra o ícone `bi-people` de 30px com `48px 20px` | dc l.766-768 (`ehCel`): só o texto, `padding:40px 16px`, 14px `#5f7684`, sem ícone | Dentro de `@media screen and (max-width:767.98px)`: `.db-empty { padding:40px 16px } .db-empty i { display:none }` |
| 9 | B | `public/css/dashboard.css:1239` (`.db-barra-marca`) | Marcas de 25/50/75% `rgba(255,255,255,.7)` cravadas. No **tema escuro** o trilho vira `--bs-secondary-bg` e as marcas viram três riscos brancos fortes | dc l.604-606: marca branca sutil sobre trilho claro (o dc é omisso no escuro; vale a convenção, que é token) | Token `--db-barra-marca` (claro `rgba(255,255,255,.7)`, escuro `rgba(255,255,255,.12)`) |
| 10 | B | `public/css/dashboard.css:2141-2144` (`@media print`) | No papel, só `tbody td`/`tfoot` vão para 5px; o cabeçalho das colunas fica com 11px | dc l.69 `[data-print="tabela"] > div { padding-top:5px; padding-bottom:5px }`: vale também para a linha de cabeçalhos (`headStyle` é um desses `div`) | Incluir `.db-table thead th` na regra de 5px |
| 11 | B | `public/css/dashboard-inteligencia.css:173-183` + `templates/dashboard/_inteligencia.html.twig:39-42` | Ponto âmbar de 9px **no canto do botão** "BlueJus Intelligence" quando há alerta; a dica é o `title` nativo | dc l.197-206 + script l.2707-2709: o botão não tem ponto. A dica é um **balão próprio** no hover (`top:calc(100%+8px);right:0`, 12.5/700, `padding:6px 11px 6px 9px`, raio 4). Com alerta: fundo `#fdf3e1`, texto `#7a4c05`, anel `#f0dcb4` e ponto de 7px `#d6921e` **dentro do balão**. Sem alerta: `#e9f5f9`/`#0b6a88`/anel `#cfe7ef` | Tirar `.db-ia-abrir-ponto` do botão e pôr um `<span role="tooltip">` irmão, visível em `:hover`/`:focus-visible` (só CSS), com o ponto dentro. O texto continua o atual ("N pontos de atenção" / "Leitura do período"), porque a regra "nada fake" já foi decidida |
| 12 | B | `public/css/dashboard-inteligencia.css:485-486` (`<720px`) | No celular o botão Intelligence ocupa **100% da largura**, centralizado | dc l.183/197 + `iaBtnStyle` l.2652: sem regra de celular. O grupo da direita só quebra para a linha de baixo, com o botão na largura natural, alinhado à esquerda | Na `@media (max-width:719.98px)`: tirar `width:100%;justify-content:center` do `.db-ia-abrir` e manter só o `.db-ia` em `flex:0 0 100%` |
| 13 | B | `public/css/dashboard-inteligencia.css:139-145` (`.db-ia`) | `padding-bottom:6px` sobe o botão: a base dele fica 6px acima da base do título (onde está o traço) | dc l.183: a linha do título é `align-items:flex-end`. A base do grupo da direita coincide com a base do `<h1>`, que inclui o `padding-bottom:6px` do traço | Tirar o `padding-bottom:6px` do `.db-ia` (o `margin-bottom:18px` já iguala ao do título) |

### Conferido e já igual (não repetir)

Casca 1500/24·28·64 e celular 18/14/48. Entrada do título (revelação, símbolo, brilho, reflexo, traço @1.4s).
"Atualizado às" @1.6s. Barra de filtros e segmentado com indicador. Calendário 252px com as cores de sel/ponta/entre/fim de semana/hoje.
Painel do select 300px/100%. Divisor some <1024. Selects 100% <720. Cards (caixa, glifos e hover por card,
número 48/-0.03em, 22/700 do "%", barra, legendas no hover/toque). Cabeçalho "Desempenho" (véu, sombra, halo). Contador.
Busca recolhida em pílula 36→260/100%. Cabeçalho das colunas (11/700/.09em, recuo de 16px, sem hover). Linhas 7px.
Hover da linha. Avatar 34px (degradê 78%, anel). Nome curto <1100. Cargo e "sem cargo". Números 14.5/600, zero `#aebdc7`.
Pílulas e pulsos. Setas e balão (cores dos 3 estados, primeira linha abre para baixo). Pílula de tendência 18px/10.5.
Total (7px, `#d3dde4`, ícone 34px). Nota. Exportar PDF (34px, sem borda até o hover, @.8s). `@media print` (A4, zoom .78,
cards 40px, cabeçalho e rodapé do papel). Lista do celular (barra Ordenar, blocos span 3/4, 2×2 <380, card de Total com blocos brancos).
Movimento reduzido. Tokens do tema escuro nas caixas, textos, pílulas, setas, balões, segmentado, calendário e selects.

### Fora desta auditoria (decisão do dono, §4 do ledger, ou função)

- Links "ver pastas" (Pastas criadas) e "ver metas" (Metas ativas) nos cards. O RD "Links ver… dos cards" pede os três, mas o B12
  registrou "cards somados não viram link, para bater com a lista". Para Pastas criadas sem `criado_por` o Acervo
  lista lápides (o card não conta), então o número não bate. Precisa de prova número = lista antes de ligar.
- Setas e pílulas nas outras 4 colunas (D-DASH), troféu, período padrão "Este mês" e o `temFiltro` do "Limpar"
  (D-DASH), ⋮ da tabela e preferências (F18-F23), "Análise inteligente"/"IA identificou" (texto honesto já decidido),
  estrela de IA por linha, toque longo, botão direito, abertura de 2,65s, menu ⋮ de acesso.
- Raio 7px do número-link e 10/12/16 do RD: vale o 4px aprovado.
- "Este mês" até o último dia do mês (item 13 da auditoria 1): o dc fixa "hoje" = 31/08, então não dá para
  separar "fim do mês" de "hoje". Ambíguo, não entra.

---

## 2. LOTES (sem sobreposição de arquivo)

**Lote V1: tabela, selects, tema escuro, impressão** (série, um commit)
Arquivos: `public/css/dashboard.css`, `templates/dashboard/_resultado.html.twig`,
`templates/dashboard/_desempenho_cards.html.twig`, `templates/dashboard/index.html.twig`, `public/js/dashboard.js`.
Itens 1, 2, 3, 4, 5, 6, 7, 8, 9, 10.
Testes: arranjo com filho direto para o espaçador do Total (`tfoot td > .db-total-cel` nas 7 colunas com período);
`th.db-th-num.desc[data-ordenar="metas"]` sem query; `<thead>` presente com 0 linhas e `tbody > tr` = 0;
"Advogado sócio" → `bi-award` na tabela e no card do celular; classe de tom pastel no `.db-dd-av-ini`;
"Busca:" no `.db-print-filtros` com `?busca=`. Conferir `DashboardFiltrosPropriosTelaTest:247-253` (procura
`.db-dd-av-ini > i.bi-person-fill`: continua valendo se só a classe de cor mudar).

**Lote V2: botão BlueJus Intelligence** (pode rodar em paralelo ao V1)
Arquivos: `templates/dashboard/_inteligencia.html.twig`, `public/css/dashboard-inteligencia.css`.
Itens 11, 12, 13. Sem JS (balão por `:hover`/`:focus-visible`); tokens claro e escuro para os dois tons do balão.

Na tela, o dono precisa conferir: o Total com "Este mês" ativo (números na mesma altura), o select de Responsável aberto
(bonecos pastel), o tema escuro na barra da Meta global, o botão Intelligence no celular e no hover, e o PDF com busca.
