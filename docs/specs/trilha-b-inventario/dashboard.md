# Investigação — Dashboard atual × `01 - Dashboard 1.2.2.dc.html` (05/10/2026)

Investigação read-only. Nada no repositório foi alterado.

**Fontes lidas**
- Desenho: `docs/design/claude-design-2026-10-05 (1)/01 - Dashboard 1.2.2.dc.html` (abreviado **dc**; template l.102–1671, script l.1673–3431). É **byte a byte igual** a `design_handoff_dashboard/Dashboard 1A.dc.html` (`cmp`), e os 4 JS do handoff são iguais aos da raiz.
- `design_handoff_dashboard/README.md` (820 linhas, abreviado **RD**).
- `bluejus-intelligence.js`, `bluejus-avancado.js`, `bluejus-equipe.js` (cabeçalhos e contratos).
- Spec `docs/specs/trilha-a-visual-pje.md` §1, §2.2, §3 (A7–A10), §4, §5.
- Código: `app/src/Dashboard/Controller/DashboardController.php`, `UseCase/ObterDadosDashboardUseCase.php`, `DTO/*`, `app/templates/dashboard/index.html.twig` (82 l.), `_resultado.html.twig` (342 l.), `app/public/css/dashboard.css` (1014 l.), `app/public/js/dashboard.js` (201 l.), `_partials/_filtro_barra.html.twig`, `filtro-tabela.js/.css`, `PastaRepository`, `TarefaRepository`, `ExpedienteController`, `debug:router`.

**Ressalvas que valem para o relatório todo**
- O dc tem **dois valores** em vários pontos (o RD foi escrito em rodadas). Onde divergem, vale o que o **dc renderiza** (regra da spec §1.1 e §5 "vale o dc 1.2.2").
- O dc monta a tabela como **grade de `div`**; a spec §2.2 obriga a manter `<table>` (decisão já tomada, não é divergência a corrigir).
- O dc não tem tema escuro (omisso) → vale a convenção do sistema (tokens em `[data-bs-theme="dark"]`), que já existe.
- Raio: o dc usa **4px** em tudo (o RD fala 16/10/8, já resolvido no §5).

---

## 1. Auditoria visual remanescente (item a item)

Legenda: ✓ igual · ✗ diferente (com valor dc × valor atual) · ◐ parcialmente.
"Atual" cita `arquivo:linha` (caminhos relativos a `app/`).

### 1.1 Casca / página

| # | Item | dc 1.2.2 | Atual | |
|---|---|---|---|---|
| 1 | Largura e recuo da área útil | `max-width:1500px;margin:0 auto;padding:24px 28px 64px` (celular `<720`: `18px 14px 48px`) — script l.3163 | `.db-page` não tem `max-width` nem `padding` (`public/css/dashboard.css:167`); herda o `container-fluid` do `base.html.twig:439`. Em tela >1500px o painel estica até a borda. A Pasta já faz isso (`pasta-show.css:283-290`, inclusive zerando o padding do `.container-fluid`) | ✗ |
| 2 | Fundo da página | degradê `#eaf1f6 → #f3f6f9` em 360px (l.103) | `--db-page-bg` no `.app-main` (`dashboard.css:35,154`) | ✓ |
| 3 | Entrada do bloco cards+tabela | wrapper com `animation:dashIn .5s` (l.3186) | não existe | ✗ (menor) |
| 4 | Ordem título → filtros → cards → tabela | idem | `order` 0/1/2/3 (`dashboard.css:175-179`) | ✓ |

### 1.2 Título e "Atualizado às"

| # | Item | dc | Atual | |
|---|---|---|---|---|
| 5 | H1 | 30/700, `-0.02em`, `#0e2533`, `padding-bottom:6px` (l.185) | `dashboard.css:192-201` | ✓ |
| 6 | Traço sob o título | 28×3, petróleo→azul, `tituloTraco .6s` **com atraso 1.4s** (l.185) | atraso `.2s` (`dashboard.css:212`) — coerente só porque o resto da entrada não existe | ◐ |
| 7 | Revelação do título | `tituloRevela 1.5s` (clip-path), `tituloBrilho 1.3s @1.45s`, `tituloReflexo 7s @4s infinite` com `background-clip:text` (l.185, keyframes l.77-88) | não existe | ✗ |
| 8 | Símbolo que rola sobre o título | `<img bluejus-simbolo-petroleo.png>` 32px, `simboloPassa 1.5s` (l.186) | não existe. O PNG está só no pacote (`bluejus-favicons/`); no app há `public/images/logo/bluejus_simbolo.svg` | ✗ |
| 9 | "Atualizado às" | 12.5px `#6b8494`, ponto 8px com `pontoAnel`/`pontoBrilho` 2.4s, entra com `btnIn .5s` **@1.6s** (l.189-195) | `dashboard.css:222-244`; entra `@.4s` (`:231`); `_resultado.html.twig:9-12` | ◐ (atraso) |
| 10 | Botão "BLUEJUS Intelligence" à direita do título | l.197-207 | não existe (regra "nada fake") | ✗ (função nova, ver §2) |

### 1.3 Barra de filtros

| # | Item | dc | Atual | |
|---|---|---|---|---|
| 11 | Caixa | `rgba(255,255,255,.9)`, borda `#dde5eb`, raio 4, `padding:14px 16px`, `gap:12px` (l.211) | `dashboard.css:305-316` | ✓ |
| 12 | Segmentado | trilho `#edf2f6`/borda `#dfe7ed`, pad 3, botões 30px, `0 12px`, 13.5px, 600/700, ativo `#0b6a88`, indicador deslizante `.42s cubic-bezier(.34,1.3,.5,1)` (l.212-218, script l.3020, 3188) | `dashboard.css:251-299`, `dashboard.js:89-160` | ✓ |
| 13 | Datas do segmentado | "Este mês" = 01 → **último dia do mês** (`setMes` 2026-08-01→08-31, l.3193); 60/90 dias = hoje−60/−90 até hoje; "Este ano" = 01/01 → hoje | `data_ate` é sempre **hoje** em todos (`index.html.twig:42-48`) | ◐ (só muda a data exibida em "Até"; as contagens são por data de criação, então o número não muda) |
| 14 | Controle de data | botão próprio 36px, `bi-calendar3` 13px `#0c7a9c` à esquerda, texto Arial tabular `dd/mm/aaaa`, vazio em `#95a6b2`, painel 252px (l.221-256, script l.2161-2175) | `<input type="date">` nativo do parcial, com o ícone movido para a esquerda por `order:-1` (`dashboard.css:330-358`). Visual próximo; o painel do calendário é o nativo | ◐ (calendário próprio = função, ver §2) |
| 15 | "até" entre as datas | `<span>` 13px `#6b8494`, grupo com `gap:6px` (l.257) | pseudo-elemento `::before` + `margin-left:24px` (`dashboard.css:360-368`) | ✓ (equivalente) |
| 16 | Selects Responsável / Cargo | botão próprio 36px, `padding:0 11px 0 7px`, **avatar/ícone 22px à esquerda**, chevron `bi-chevron-down` 11px `#7b93a2` que gira 180° ao abrir; rótulos **"Todos os responsáveis" / "Todos os cargos"**; cargo tem opção **"Sem cargo"** (l.287-357, script l.3196-3224, 2212) | `<select>` nativo, sem ícone, rótulo vazio = **"Responsável" / "Cargo"** (vem de `f.rotulo` no parcial `_filtro_barra.html.twig:36`); sem opção "Sem cargo" (`DashboardController.php:64` filtra nulos) | ✗ |
| 17 | Largura dos selects | `flex:1.15 1 200px;min-width:190px` / `flex:1 1 170px;min-width:160px` (l.3170-3171) | `dashboard.css:383-384` (+ `min-width:160px` em `:372`) | ✓ |
| 18 | Filtros ativos | **sem chips**; um botão **"Limpar filtros"** 36px, `0 13px`, borda `#d3dde4`, 13.5/600 `#34505f`, `bi-x-lg` 12px; hover borda/texto `#0c7a9c`, fundo `#f4fafc` (l.359-361) | linha de **chips** + link "Limpar tudo" do `filtro-tabela.js:97-104`, só recoloridos (`dashboard.css:323-326, 394-399`) | ✗ (resolúvel só com CSS em `.db-page`: esconder `.filtro-chip` e vestir `.filtro-limpar` como o botão do dc; o JS/parcial compartilhados não mudam) |
| 19 | ⋮ "Acesso ao Dashboard" no canto da barra | só Master (l.362-410) | não existe | ✗ (função, §2) |

### 1.4 Cards

| # | Item | dc | Atual | |
|---|---|---|---|---|
| 20 | Caixa do card | `padding:20px 20px 19px`, raio 4, degradê `#fff→#fbfcfd`, sombra em 3 camadas, hover sobe 3px etc. (script l.2796-2800) | `dashboard.css:441-455, 663-714` | ✓ |
| 21 | **Número do card** | **48px**, 700, `letter-spacing:-0.03em`, Arial tabular, `margin:22px 0 10px` (script **l.2802**; o RD diz 50 numa seção e 48 noutra — o dc renderiza 48) | **50px / -0.035em** (`dashboard.css:614,617`) | ✗ |
| 22 | Rótulo + traço | 12/700/.075em `#34505f`, `rotuloIn` + traço 18×2 (cascata .55/.65/.75/.85s) | `dashboard.css:586-609` | ✓ |
| 23 | **Legenda do card "Pastas criadas"** | **não existe** no dc: número + link "ver pastas"; abaixo, ou o nivelador (com meta) ou o botão "Definir meta do mês" (l.452-480) | mostra **"abertas no período selecionado"** sempre (`templates/dashboard/_resultado.html.twig:66`) | ✗ (texto que o desenho não tem) |
| 24 | Legendas de Metas/Urgentes | ocultas, aparecem no hover (`opacity 0→1`, sobem 4px); **em toque aparecem ao tocar** (RD "Legendas dos cards") | hover ✓ (`dashboard.css:664-668, 682`); **em toque ficam sempre visíveis** (não há regra fora do `@media (hover:hover)`) | ◐ |
| 25 | Links "ver pastas"/"ver metas"/"ver pastas" ao lado do número | 13.5/600 `#0f6fc4`, seta `bi-arrow-right` 12px que anda 3px no hover (l.454, 518, 548; script l.2805) | omitidos (comentário em `_resultado.html.twig:22-23`) | ✗ (depende de destino real, §2) |
| 26 | Glifos SVG, halo, anel, órbitas, flutuação, hover por card | l.420-610 / script l.2809-2860 | `_resultado.html.twig:29-210`, `dashboard.css:504-582, 683-713` | ✓ |
| 27 | Barra da Meta global | 8px, trilho `#e9eff3`, marcas 25/50/75, verde, `barGrow .9s` **uma vez** (no dc o nó não remonta ao filtrar) | `dashboard.css:633-659`. **Defeito:** a regra que desliga a reanimação depois do 1º XHR tem seletor impossível — `.db-page.db-pronto .db-page .db-barra-fill` (`dashboard.css:430`, `.db-page` repetido). A barra volta a crescer do zero a cada ordenação/filtro | ✗ (bug) |
| 28 | Contagem dos números | 0→valor 1.1s @0.38s, easeOutQuart; troca 0.65s | `dashboard.js:30-74` | ✓ |
| 29 | Grade 4 / 2×2 / 1, `grid-auto-rows:1fr`, gap 16 | l.3229 | `dashboard.css:406-412, 716-721` | ✓ |
| 30 | Entrada dos wrappers (esq / sobe .22 / sobe .32 / dir) | l.419, 485, 515, 545 | `dashboard.css:413-433` | ✓ |
| 31 | Botão direito no card (menu meta / histórico) | l.420 `onContextMenu` | não existe | ✗ (função, §2) |

### 1.5 Tabela "Desempenho"

| # | Item | dc | Atual | |
|---|---|---|---|---|
| 32 | Caixa | `#fff`, borda `#dde5eb`, raio 4, sombra (l.569) | `dashboard.css:724-730` | ✓ |
| 33 | Cabeçalho da caixa | `padding:16px 20px`, véu azul + vertical `#f7fafc→#fff`, sombra `0 8px 14px -12px`, **borda inferior `#e6edf2`** (l.571) | `dashboard.css:734-745`; borda via `--db-div-head` = `#e3eaef` | ✓ (diferença de 1 tom, desprezível) |
| 34 | Título 18/700 + contador `bi-people-fill` | l.572-573 | `_resultado.html.twig:223-224`, `dashboard.css:746-763` | ✓ |
| 35 | Lupa recolhida (36px redonda → 260px) + ⋮ "Opções da tabela" (28×36) à direita do cabeçalho | l.575-720 | não existe | ✗ (função, §2) |
| 36 | Cabeçalho das colunas | `padding:11px 20px` (14px abaixo de 1100), fundo `#f6f9fb`, borda `#e3eaef`, 11/700/.09em, `#5f7684`; ordenada em acento; **sem cor de hover**; numéricas centralizadas **com `padding-right:16px`** para compensar o ícone (script l.3021, 3346) | `padding:11px 12px` (1ª coluna 20px) (`dashboard.css:777-809`); **hover pinta de acento** (`:794`); **sem o recuo de 16px** das numéricas | ◐ |
| 37 | Densidade das linhas | padrão **compacta**: `7px 20px` (≥1100) / `7px 14px` (<1100); "Normal" = 9px (script l.2947) | `td` `7px 12px`, 1ª coluna 20px (`dashboard.css:812-826`) | ✓ (compacta). Sem a opção Normal (§2) |
| 38 | **Linha de Total** | mesmo `rowPad` das linhas (**7px**), fundo `#f6f9fb→#f1f6f9`, borda `#d3dde4`, `inset 0 1px 0 #fff` (script l.3345) | **`padding:9px 12px`** (`dashboard.css:923`); sombra `rgba(255,255,255,.6)` (`:927`) | ✗ (padding) |
| 39 | Hover da linha | `#f4f9fc` + `inset 3px #0c7a9c` + `0 10px 20px -16px`, sem transform (l.790) | `dashboard.css:821-825` | ✓ |
| 40 | Colaborador | avatar 34px gradiente + anel branco; nome Arial 14/700 em uma linha com reticências + `title` (l.791-803) | `_resultado.html.twig:269-281`, `dashboard.css:838-873` | ✓ |
| 41 | Nome abaixo de 1100px | **"Samuel F."** (primeiro nome + inicial do último), completo no `title` (script l.2985) | sempre o nome completo cortado (`max-width:260px`, `dashboard.css:865`) | ✗ |
| 42 | Cargo | 13/500 `#4f6878`, ícone `#8aa0ad` 12.5, sem cargo itálico `#a3b3be` | `dashboard.css:876-888`; mapa de ícones `_resultado.html.twig:260-267` | ✓ |
| 43 | Números / zero / pílulas | 14.5/600 tabular; zero 14.5/500 `#aebdc7`; pílula 13/700, `3px 10px 3px 9px`, ponto 6px pulsando 2.6s (script l.2957-2973) | `dashboard.css:891-918` | ✓ |
| 44 | Grade fluida abaixo de 1100px | colunas `minmax(140px,1.5fr) minmax(92px,1fr) repeat(7,minmax(56px,.82fr))`, `gap 8px`, recuo lateral 14px (script l.2951-2956) | só `min-width` fixos 170/108/62 (`dashboard.css:807-809`), sem regra <1100 | ✗ |
| 45 | Celular (<768px) | **tabela vira lista de cards** + select "Ordenar" + botão inverter (l.722-772) | tabela com rolagem horizontal e 1ª coluna `sticky` (`dashboard.css:765, 829-834`) | ✗ (função, §2) |
| 46 | Troféu, estrela de IA por nome, setas de tendência, números como links, pílulas de tendência no Total | l.800-829 | não existem | ✗ (funções, §2) |
| 47 | Nota de rodapé | mesmo texto; borda `#e9eff3` (l.842-845) | `_resultado.html.twig:338-341`, `dashboard.css:952-964` | ✓ |
| 48 | Estado vazio | ícone `bi-people` 30px **`#c3d2dd`**, texto com `margin-top:10px` (l.833-838) | ícone em `--db-zero` = `#aebdc7` (`dashboard.css:972`) | ◐ (1 tom) |
| 49 | Botão "Exportar PDF" abaixo da tabela, à direita | 34px, sem borda até o hover, `btnIn .6s @.8s` (l.849-851) | não existe | ✗ (função, §2) |

### 1.6 Responsividade

| # | Item | dc | Atual | |
|---|---|---|---|---|
| 50 | Segmentado ocupa a linha (<720) | `flex:1 1 100%`, botões `flex:1 1 0`, 12.5px (l.3020, 3265) | `dashboard.css:981-985` | ✓ |
| 51 | **Selects em largura total** | **<720px** (`cel = w < 720`, l.3170-3171) | só **<576px** (`dashboard.css:987-992`) — entre 576 e 719 ficam lado a lado | ✗ |
| 52 | Cards 4/2/1 | 1100/600 | idem | ✓ |
| 53 | Tabela <1100 e <768 | itens 41, 44, 45 | — | ✗ |
| 54 | Topo e menu global | some nome do escritório/usuário etc. | `base.html.twig` — fora (spec §5 "Barra global") | — |

### 1.7 Tema escuro

- Os tokens claros/escuros existem e cobrem caixas, textos, pílulas, segmentado, Total e foco (`dashboard.css:19-148, 974-978`). ✓
- Cores cravadas em regra (contra a spec §1.5, mas são cores do desenho que funcionam nos dois temas): glifos (`:529-582`), gradientes dos avatares (`:854-862`), preenchimento da barra (`:649`), núcleo do "Atualizado" (`:238`), ícone do Total no claro (`:935`, com override no escuro em `:978`). Não vi texto ilegível por isso; anoto como dívida, não como bug.
- `.db-page .db-seg-btn:hover` tem override no escuro (`:299`). ✓

### 1.8 Movimento reduzido / impressão

- `prefers-reduced-motion` desliga entrada, flutuação, pulsos e contagem (`dashboard.css:996-1014`, `dashboard.js:25,66`). ✓
- `@media print` (17 regras + `@page A4 landscape`, l.51-71): **não existe** no `dashboard.css`. ✗ (função "PDF", §2)

### 1.9 Resumo da auditoria visual

Divergências que **não dependem de função nova** (corrigíveis já): **1, 3, 6–9, 13, 18, 21, 23, 24, 27 (bug), 36, 38, 41, 44, 48, 51**. As demais ✗ são função nova e estão no §2.

---

## 2. Inventário funcional

Classificação: **A** já existe · **B** parcial · **C** implementável com a infra atual · **D** exige infra interna nova (tabela, job, endpoint, tela) sem decisão externa · **E** depende de IA, credencial ou decisão do dono. Esforço **P/M/G**.

| # | Função | O que o desenho faz (onde) | O que existe hoje | Classe | Esf. | Arquivos |
|---|---|---|---|---|---|---|
| F1 | **Segmentado de período** | Este mês/60/90/Este ano, indicador deslizante (l.212-218, RD "Seletor de período") | Implementado (A10) | **A** | — | `index.html.twig:42-57`, `dashboard.js` |
| F2 | **Período padrão "Este mês"** | Sem filtro abre em Este mês; "Limpar" volta para Este mês (RD "Período padrão"; `temFiltro` l.3185) | Sem datas = período aberto; nenhum botão ativo (spec §3 A10, §5) | **E** (decisão do dono pendente) | P | `DashboardController.php`, `index.html.twig` |
| F3 | **Contagem animada, glifos, hover dos cards** | RD "Contagem…", "Hover dos cards" | Implementado (A8) | **A** | — | `_resultado.html.twig`, `dashboard.css`, `dashboard.js` |
| F4 | **"Atualizado às"** | l.189-195 | Implementado no fragmento (A10) | **A** | — | `_resultado.html.twig:9-12` |
| F5 | **Linha de Total + contador** | l.815-831 | Implementado em `<tfoot>` (A9) | **A** | — | `_resultado.html.twig:310-334` |
| F6 | **Ordenação por coluna** | clique no cabeçalho; texto começa asc, número desc (RD "Colunas e ordenação") | Existe no servidor (`ObterDadosDashboardUseCase::ordenar`, `filtro-tabela.js`). O primeiro clique é sempre na direção do motor compartilhado (spec §5: não muda hoje) | **B** | P (se o dono aceitar mexer no `filtro-tabela.js` ou fazer um ajuste local) | `filtro-tabela.js` (compartilhado) |
| F7 | **"Limpar filtros" no lugar dos chips** | l.359-361 | Chips + "Limpar tudo" do motor | **C** (só CSS em `.db-page`) | P | `dashboard.css` |
| F8 | **Opção "Sem cargo"** no filtro de cargo | `__sem` (l.3224, 2932) | Cargo nulo não aparece (`DashboardController.php:64`) e o UseCase só compara igualdade (`ObterDadosDashboardUseCase.php:77-83`) | **C** | P | Controller, UseCase, teste unit |
| F9 | **Busca de colaborador** (lupa recolhida, Esc limpa) | l.575-578, script l.3172-3182; filtra linhas e o Total soma "as linhas visíveis" | Não existe. O RD a chama de "proposta a confirmar", mas o dc final a mostra. Feita no servidor (`busca` → UseCase), o Total do `<tfoot>` continua certo sem JS novo | **C** | P–M | Controller, UseCase, `index.html.twig` (campo `js-filtro-campo` ou busca do motor), CSS |
| F10 | **Troféu do melhor resultado** + animação | Quem tem mais (total − ativas), empate menos vencidas, calculado **antes** dos filtros de responsável/cargo; ninguém se zero (script l.2928-2929, 2986; RD "Troféu") | Não existe. Todo o dado já está nas linhas (`totalMetas − metasAtivas`, `metasVencidas`); basta calcular antes do `array_filter` de responsável/cargo (`ObterDadosDashboardUseCase.php:71-83`) e expor `DashboardOutput.campeaoId` | **E** para o critério (o RD diz "Critério a confirmar com o dono"; e "concluídas" aqui = concluídas **entre as criadas no período**, não "concluídas no período") → depois **C** | P | UseCase, DTO, `_resultado.html.twig`, CSS |
| F11 | **Som do campeão** (fanfarra Web Audio, máx. 2 por página) | script l.2068-2100; RD "Som do campeão" | Não existe | **C** técnico, mas o RD exige opção do usuário para desligar → depende de F18 (**D**) | P | `dashboard.js` |
| F12 | **Som do calendário** | script l.2105+ | Não existe (e não tem como existir com `<input type="date">` nativo) | depende de F14 (**C**) + F18 (**D**) | P | `dashboard.js` |
| F13 | **Selects próprios com avatar** (Responsável com foto/bonequinho e cargo, busca interna, teclado; Cargo com ícone e "N pessoas") | l.287-357, script l.2185-2240; RD "Filtros em cascata" | `<select>` nativo do parcial. Dá para fazer **sem tocar o parcial**: esconder o `<select>` em `.db-page` e escrever nele + disparar `change` (o `filtro-tabela.js` só lê `.js-filtro-campo`). Fotos: `UserRepository::findFotoPorColaboradores` já existe | **C** | M–G | `index.html.twig`, `dashboard.js`, `dashboard.css`, Controller (passar foto/cargo/contagem às opções) |
| F14 | **Calendário próprio** (painel 252px, Hoje/Limpar, De/Até se acompanham) | l.221-286, script l.2140-2176; RD "Calendário das datas" | Nativo + `showPicker()` (`filtro-tabela.js:187-191`). Mesmo método do F13 | **C** | M | idem F13 |
| F15 | **Números clicáveis** (todo número >0 da tabela, do Total e "ver …" dos cards abre a lista filtrada) | `destino()` l.1835-1866, `destinoCard()` l.1812-1834; RD "Números clicáveis" | Nada. Situação de cada destino: **(a)** `/expediente` não abre o painel Acervo geral pela URL — o painel carrega por XHR (`expediente_acervo_geral`) e o estado vem do `sessionStorage` (`templates/expediente/index.html.twig:1052-1150`); **(b)** filtros `responsavel`, `status`, `prioridade`, `data_de`, `data_ate` **já existem** no acervo (`ExpedienteController.php:289-299`, `PastaRepository::aplicarFiltrosPasta` l.642-725) e usam a mesma régua `dataAbertura` do Dashboard; **(c)** falta `criado_por`; **(d)** o RD usa `status=ativa`, o valor real é `'ativo'` (`Pasta::SITUACAO_ATIVA`); **(e)** `/tarefas/equipe` **não existe** (`debug:router`: só `tarefa_minhas`) | **B** no total: Expediente = **C** (P–M); Metas = **D** (F16) | M | `ExpedienteController.php`, `PastaRepository.php`, `templates/expediente/index.html.twig`, `_resultado.html.twig`, `dashboard.css` |
| F15a | ↳ risco "número ≠ lista" | — | `countCriadasPorCriador` exclui lápide (`excluidaEm IS NULL`, `PastaRepository.php:555`), o acervo **não** (lista lápides riscadas) → o filtro `criado_por` precisa excluir lápide também, ou o número clicado não bate com a lista. `countUrgentes` aplica período (spec §5) mas o RD manda o link "ver pastas" de Urgentes **sem** período → o link precisa levar o período, senão lista mais do que o card conta | — | — | idem |
| F16 | **Listagem `/tarefas/equipe`** (destino de Total/Ativas/Vencidas/Prazos e "ver metas") | RD tabela de destinos; `status=todas|ativas|vencidas|prazo_proximo|concluidas|em_revisao` | Não existe. Os critérios já estão nos `count*PorResponsavel` do `TarefaRepository` (l.464-580); a tela pode reaproveitar `tarefa/_resultado.html.twig`. Permissão: o RD já decide "mesmo perfil que vê o Dashboard" (módulo `bi`) | **D** | M–G | `TarefaController.php` (ou controller novo no domínio), `TarefaRepository.php`, template novo, testes (inclusive cross-tenant) |
| F17 | **Tendências** (pílula no Total; só a seta em cada célula, balão com emoji, "antes → agora") | `tendencia()` l.1890-1915, `dica()` l.1917-1941; RD "Tendência na linha de Total/em cada célula" | Não existe. Exige período definido (sem datas não há "período anterior" → liga em F2). **Reconstruível exato** para o anterior: Total metas (`dataCriacao`), Total demandas e Pastas criadas (`dataAbertura`). **Não reconstruível** sem foto mensal: Metas ativas, Demandas ativas (usam status/situação **de hoje**), Vencidas e Prazos próximos (o RD sugere `referencia = data_de − 1`, mas com o status atual a meta concluída depois daquela data some da contagem antiga; daria para aproximar com `dataConclusao`, que é **nula nas metas concluídas antes da coluna existir** — `TarefaRepository.php:312`) | **C** para as 3 métricas por data de criação (M); **D** (foto mensal) ou **E** (aceitar aproximação) para as outras 4 | M | UseCase (rodar os `count*` no intervalo anterior), DTOs (`totaisAnteriores`, valores anteriores na linha), `_resultado.html.twig`, CSS, `dashboard.js` (balão) |
| F18 | **Preferências por usuário** (densidade, sons, animações, setas, colunas ocultas/extras; "Restaurar padrão"; faixa "Ajustes salvos só para {nome}") | l.639-720, script l.1767-1782; RD "Personalização por usuário" (servidor **obrigatório**: `user_preferencia (user_id, tenant_id, chave, valor JSON)`, `GET/PUT /preferencias/dashboard` com CSRF, injeção em `data-preferencias`, allowlist) | Nada (`grep preferenc` em `src/` = 0) | **D** | M | Entity + Repository + migration, Controller + UseCase, `index.html.twig`, `dashboard.js`, testes (usuário sempre o logado; cross-tenant) |
| F19 | **Menu ⋮ da tabela** | idem F18 + Exibição + Colunas + Adicionar coluna + Zerar + Restaurar | Não existe | **B/D**: casca **C** (P–M); conteúdo depende de F17/F18/F20/F23 | M | `_resultado.html.twig` ou casca, `dashboard.js`, CSS |
| F20 | **Ocultar colunas** | Colaborador fixa (cadeado); não oculta a última numérica; PDF respeita (RD "Opções da tabela") | Não existe | **C** (CSS por `data-` + JS) — persistir só com F18 (**D**) | M | idem |
| F21 | **Densidade Normal/Compacta** | 9px × 7px (l.2947) | só compacta | **C** (classe) + F18 para persistir | P | CSS, JS |
| F22 | **Animações liga/desliga** | `semAnim` (l.103) | só `prefers-reduced-motion` | **C** + F18 | P | CSS, JS |
| F23 | **Colunas extras** (catálogo) | `EXTRAS` l.1730-1739: Metas concluídas, Taxa de conclusão, Em revisão, Tempo médio, Pastas urgentes, Pastas concluídas, Eventos na agenda, **Relatório (comentários)** | Nada. Dado disponível: concluídas/taxa = das linhas (**C**, P); Em revisão = `Tarefa::STATUS_EM_REVISAO` (**C**, P); Pastas urgentes/arquivadas por responsável (**C**, P; "concluída" não existe — situações são `ativo`/`arquivado`, `Pasta.php:30-31`); Eventos = `evento_participante` (**C**, P–M); Tempo médio = `dataConclusao − dataCriacao` com `dataConclusao` nula no legado (**E**: aceitar a lacuna); Relatório = contagem por `autor`/`criadaEm` em `PastaObservacaoDetalhes`/`PastaObservacaoFinanceira` (+ "Dados da pasta" a confirmar) (**C**, M) | **B–C**, uma com **E** | M–G | UseCase, Repositories, DTO, template, CSS |
| F24 | **Zerar** ("Zerar relatório de todos", botão direito no cabeçalho da coluna e no nome: zerar de uma pessoa / da equipe / desfazer; não-Master "Pedir para zerar" → usuário primário e Controladoria via `BJCentral`) | l.705-711, 780-786, 1494, 1562-1578, script l.1876-1895, 3305-3316 | Nada. Muda o significado de um dado estatístico ("a contagem recomeça agora"), precisa de tabela de corte por coluna/pessoa, auditoria e o fluxo "Controladoria" que não existe no sistema | **E** (regra de negócio + quem pode) → depois **D** | G | — |
| F25 | **Acesso restrito ao Dashboard** (⋮ só para o Master; "Ocultar Dashboard" + lista de liberados; tela "Dashboard restrito") | l.106-118, 362-410; RD "Acesso ao Dashboard" | Já existe uma barreira real: módulo `bi` por perfil (`DashboardController::assertAccess`, `PermissionChecker::canAccessModule`). "Master" = `TenantRole::isSystem()` (`docs/AUTORIZACAO.md` §2b). A lista por pessoa duplicaria o modelo de perfis — risco **MÉDIO** (Permission/TenantRole) | **E** (o dono decide se é perfil `bi` ou lista própria) | M | Controller, entidade/config por tenant, `docs/AUTORIZACAO.md` |
| F26 | **Meta de pastas do mês (nivelador)** | botão direito no card → "Definir meta do mês"; barra com traço do previsto, azul/vermelho, "Meta batida"; só em Este mês sem filtros; vigência herdada mês a mês; **do escritório, com permissão de gestor** (l.452-480, 1626-1655, script l.2030-2060; RD "Nivelador", "Meta de pastas: vigência") | Nada | **D** (tabela por tenant/mês + endpoint + CSRF) com **E** em "qual permissão é gestor" e no F2 (só aparece em "Este mês") | M–G | Entity/migration, Controller/UseCase, `_resultado.html.twig`, `dashboard.js`, CSS |
| F27 | **Histórico dos cards** (botão direito: 12 meses, melhor mês dourado, média/melhor/vs ano anterior) | l.1431-1474, `HIST_CFG` l.1716-1722; RD "Botão direito nos cards" | Nada. Pastas criadas por mês é reconstruível (`dataAbertura`, **C**); Metas ativas, Urgentes e Meta global exigem **foto do último dia do mês** (job mensal + `dashboard_fechamento_mes`) | **D** | G | Command agendado, Entity/migration, endpoint, `dashboard.js` (janela flutuante) |
| F28 | **Cartão de desempenho do colaborador** (botão direito no nome: posição no ranking, "1º do mês" por ano, rendimento vs média, comentários) | l.1476-1558, script l.3040-3100; RD "Botão direito no colaborador" | Nada. "Posição" e "Rendimento" saem das linhas (**C**, M). "1º do mês" exige `dashboard_campeao_mes` gerada por comando no 1º dia do mês (**D**) e herda o critério do F10 (**E**) | **C + D + E** | G | idem F27 |
| F29 | **Janelas flutuantes arrastáveis** (várias abertas, Esc fecha a da frente) | RD "Janelas: arrastar…" | Nada | **C** (JS puro) — só tem conteúdo com F27/F28 | M | `dashboard.js`, CSS |
| F30 | **Cards no celular** (<768: tabela vira lista de cards, 4+3 blocos, select "Ordenar" + inverter, Total em card) | l.722-772, `mob()` l.1700-1703; RD "Responsividade (revisão final)" | Tabela com rolagem e 1ª coluna fixa | **C** (segunda renderização no fragmento; a ordenação do celular pode escrever nos hidden `ordenar`/`direcao` e disparar `change`). Testes contam `table > tbody > tr` — a lista não pode ser `<table>` | M | `_resultado.html.twig`, `dashboard.css`, `dashboard.js` |
| F31 | **Exportar PDF** (`window.print()`, A4 paisagem, cabeçalho "BlueJus · Dashboard / Relatório de desempenho", período e filtros, "Gerado em … por {usuário}", rodapé interno, `zoom:.78`, nome do arquivo) | l.51-71, 171-181, 849-851, 1666; script l.3198-3206 | Nada | **C** | P–M | `dashboard.css` (`@media print`), `index.html.twig` (cabeçalho só-impressão, botão), `dashboard.js` (título do documento) |
| F32 | **Abertura animada da página** (2.65s, "Inicializando painel", pausa as outras animações com `html.bj-pausa`, "Pular") | script l.1802, 2274-2320; RD "Abertura da página" | Nada | **C** técnico; recomendo **perguntar ao dono** antes (atrasa o conteúdo 2.65s a cada carga/atualização) | M | `index.html.twig`, `dashboard.js`, CSS |
| F33 | **Entrada do título** (símbolo que rola, revelação, brilho, reflexo a cada 7s) | l.185-187; RD "Entrada do título" | Só o traço | **C** | P | `index.html.twig`, `dashboard.css`, asset `bluejus-simbolo-petroleo.png` (ou o `bluejus_simbolo.svg` que já existe) |
| F34 | **BlueJus Intelligence** (botão, painel lateral 460px, ritmo, riscos, próximas ações, estrela por colaborador, "Aumentar o nível" com `POST /dashboard/meta`) | l.197-207, 853-1430; `bluejus-intelligence.js`; RD "BlueJus Intelligence" | Nada. **Nota:** o "motor" do pacote é **regra determinística em JS** (sem LLM, sem `fetch`), mas a tela o apresenta como "IA identificou N pontos". Precisa de: concluídas por dia (`dataConclusao` com lacuna no legado), período anterior "até o mesmo dia", média dos 3 últimos (`dashboard_fechamento_mes`), meta do período (`dashboard_meta_periodo`) | **E** (spec §4 e cabeçalho: IA fica para a próxima rodada; nome "IA" para regra é decisão do dono) | G | — |
| F35 | **Modo avançado** (cockpit, Central de Inteligência com alertas → "Criar tarefa", feedback, introdução de 5,4s, sons) | l.939-1100; `bluejus-avancado.js`; RD "Modo avançado" | Nada. Alertas/feedback precisam ser gravados por escritório; o próprio RD diz que faltam histórico diário, movimentações, documentos e etapas | **E** | G | — |
| F36 | **Inteligência de Desempenho** (condição por pessoa sem ranking, 7 perfis × 13 permissões, privacidade, sensibilidade, auditoria de quem viu) | l.877-935, 1099-1278; `bluejus-equipe.js`; RD "Inteligência de Desempenho" | Nada. Cria um modelo de permissão paralelo (risco MÉDIO) | **E** | G | — |
| F37 | **Simulações do protótipo** ("entrar como", "Simulação: entrar como" no bloqueio, "Teste: simular dia") | l.144-159, 112-117, 1421-1426 | — | **não vão para produção** (o RD diz) | — | — |

---

## 3. "IMPLEMENTAR PRIMEIRO" — lotes sem sobreposição de arquivos

Critério: primeiro o que corrige divergência visual sem função nova (o desenho manda, risco zero de dado), depois o que só usa dado que já existe, depois infra interna. Tudo que é **E** fica de fora até o dono decidir.

Os lotes com o **mesmo** conjunto de arquivos rodam **em série** (um commit por lote). Os conjuntos marcados "paralelo" são disjuntos e podem ir em worktrees separados.

### Lote 1 — Fidelidade da casca e dos cards (só front, P) · arquivos: `public/css/dashboard.css`, `templates/dashboard/index.html.twig`, `templates/dashboard/_resultado.html.twig`, `public/js/dashboard.js`
1. `max-width:1500px;margin:0 auto;padding:24px 28px 64px` (celular 18/14/48) em `.db-page` + zerar o padding do `.container-fluid` em `.db-body`, como a Pasta (itens 1, 3).
2. Número do card 48px / `-0.03em` (item 21) — conferir `DashboardArranjoTelaTest` (não testa tamanho).
3. Tirar "abertas no período selecionado" do card Pastas criadas (item 23).
4. Corrigir o seletor de `dashboard.css:430` (`.db-page.db-pronto .db-barra-fill`) (item 27).
5. Legendas de Metas/Urgentes ocultas também em toque, aparecendo ao tocar (item 24).
6. Selects em 100% a partir de **<720px** (item 51).
7. Total com `padding:7px` (item 38); cabeçalho numérico com `padding-right:16px` e sem cor de hover (item 36); estado vazio `#c3d2dd` (item 48).
8. Abaixo de 1100px: `gap`/recuo 14px, mínimos 140/92/56 e nome abreviado "Samuel F." (itens 41, 44) — o abreviado sai no Twig (`title` com o nome completo).
9. Chips escondidos e "Limpar tudo" vestido como o botão "Limpar filtros" do dc, **só com CSS em `.db-page`** (item 18 / F7). Atenção: o texto vem do `filtro-tabela.js` ("Limpar tudo"); trocar para "Limpar filtros" exige `content` por CSS ou mexer no motor compartilhado — **perguntar**.
10. Entrada do título (F33) e atrasos do traço (1.4s) e do "Atualizado às" (1.6s) (itens 6–9).
11. Exportar PDF (F31): `@media print` + cabeçalho só-impressão com período/filtros/"Gerado em … por {{ app.user }}" + botão + `document.title`.

Testes: arranjo existente segue verde; novos com filho direto para o botão PDF (`.db-page > … > button`) e para a ausência da legenda em `.db-stat-card--pastas`.

### Lote 2 — Dados que já existem, no servidor (C, P–M) · arquivos: `src/Dashboard/Controller/DashboardController.php`, `src/Dashboard/UseCase/ObterDadosDashboardUseCase.php`, `src/Dashboard/DTO/*`, `tests/Dashboard/Unit/*`, `tests/Dashboard/Functional/DashboardCountFiltrosTest.php` · **paralelo ao Lote 3**
1. Opção "Sem cargo" (F8).
2. Busca por nome no servidor (`busca`), sem afetar cards (F9) — o RD diz que a busca **não** altera o card Pastas criadas: calcular `totalPastasCriadas` antes da busca.
3. `campeaoId` calculado antes dos filtros de responsável/cargo (F10) — **só depois do "sim" do dono ao critério**; até lá, não renderiza.
4. `totaisAnteriores` e valores anteriores por linha **só para Total metas, Total demandas e Pastas criadas** (F17, parte C), com período obrigatório.

### Lote 3 — Deep-link do Expediente (C, P–M) · arquivos: `src/Expediente/Controller/ExpedienteController.php`, `src/Pasta/Repository/PastaRepository.php` (só `aplicarFiltrosPasta`), `templates/expediente/index.html.twig`, `tests/Expediente/*` · **paralelo ao Lote 2**
1. Filtro `criado_por` (mesmo critério do `countCriadasPorCriador`, **excluindo lápide** quando `criado_por` vier — F15a).
2. `expediente_index` abrir o Acervo geral quando vier `?painel=acervo-geral` e repassar a query ao XHR, com precedência sobre o `sessionStorage`.
3. Teste cross-tenant: `criado_por`/`responsavel` de outro escritório não lista nada.
Obs.: `templates/expediente/index.html.twig` também é território da Trilha B (Expediente 1.2.3) — combinar a ordem com o orquestrador.

### Lote 4 — UI dos dados do Lote 2 e dos links do Lote 3 (C, M) · arquivos: os do Lote 1 · **depois de 1, 2 e 3**
1. Campo de busca recolhido no cabeçalho da tabela (F9).
2. Números > 0 de **Pastas criadas, Total demandas, Demandas ativas** e "ver pastas" dos cards Pastas criadas/Urgentes como links reais (F15, parte Expediente), com `status=ativo` (não `ativa`) e o período no link de Urgentes.
3. Setas/pílulas de tendência **só** nas três colunas reconstruíveis (F17) — ou esperar o dono decidir F2 (sem período não há tendência).
4. Troféu (se o critério foi aprovado no Lote 2).

### Lote 5 — `/tarefas/equipe` (D, M–G) · arquivos: `src/Controller/TarefaController.php` (ou controller novo em `src/Tarefa/Controller/`), `src/Tarefa/Repository/TarefaRepository.php`, `templates/tarefa/equipe*.html.twig`, `tests/Tarefa/*` · **paralelo a 2 e 3**
Listagem para quem tem o módulo `bi`, `status=todas|ativas|vencidas|prazo_proximo`, `responsavel`, `cargo`, `data_de/data_ate`, reaproveitando `tarefa/_resultado.html.twig` e os mesmos critérios dos `count*PorResponsavel`. Depois dele, um lote pequeno (arquivos do Lote 1) liga os links de metas e o "ver metas".

### Lote 6 — Celular (C, M) · arquivos: os do Lote 1 · depois do 4
Lista de cards <768px (F30) com ordenação por select escrevendo nos hidden `ordenar`/`direcao`.

### Lote 7 — Selects e calendário próprios (C, M–G) · arquivos: `templates/dashboard/index.html.twig`, `public/js/dashboard.js` (ou `public/js/dashboard-filtros.js` novo, para não disputar com os lotes de tabela), `public/css/dashboard.css`, `DashboardController.php` (opções com foto/cargo/contagem) · depois do 6
F13 + F14, sempre escrevendo nos controles do parcial e disparando `change`. Sem som até existir F18.

### Depois (infra interna, D): preferências por usuário (F18) → menu ⋮ completo, densidade, animações, colunas ocultas/extras, sons (F19–F23, F11, F12) · fechamento mensal + campeão do mês (F27, F28) · meta de pastas (F26).

### Fora até decisão do dono (E): período padrão (F2) · critério do troféu (F10) · tendência das métricas de estoque e vencidas (F17 parte D/E) · tempo médio com `dataConclusao` nula (F23) · zerar (F24) · acesso restrito × módulo `bi` (F25) · permissão de gestor para a meta (F26) · Intelligence / Modo avançado / Inteligência de Desempenho (F34–F36) · abertura de 2,65s (F32, recomendo perguntar) · texto "Limpar filtros" se exigir mexer no `filtro-tabela.js` (Lote 1.9).
