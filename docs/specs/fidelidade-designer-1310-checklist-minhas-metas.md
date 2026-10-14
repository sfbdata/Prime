# Checklist acumulado de Minhas Metas — Claude Designer (13/10) × BlueJus

> **Para quem:** o dono e quem continuar a frente `fidelidade-designer`. Plano e evidências em
> `fidelidade-designer-1310.md` (§9 para a E5). Este arquivo é **acumulado**: cada entrega atualiza a marca
> do elemento em vez de abrir lista nova. Levantado em 14/10/2026 (inventário read-only) e já atualizado com
> a E5 (`e227183b`), a E5b (`3b5e1bcd`) e a E5c. Marcas: ✓ conforme · ≈ diverge · ∅ ausente · **+** só o BlueJus tem (fora das contagens).
>
> **∅ não quer dizer "fora".** Elemento sem backend continua na lista; a coluna *Backend?* diz o que falta.
>
> Fontes: protótipo `04 - Minhas Metas 1.0.dc.html` (P:linha). BlueJus (`app/`): `M` = `templates/tarefa/minhas.html.twig`,
> `R` = `_resultado.html.twig`, `C` = `_meta_cartao.html.twig`, `L` = `_metas_lista.html.twig`,
> `FB` = `templates/_partials/_filtro_barra.html.twig`, `CSS` = `public/css/minhas-metas.css`,
> `FT` = `public/css/filtro-tabela.css` + `public/js/filtro-tabela.js`, `B` = `templates/base.html.twig`,
> `AC` = `public/css/app.css`, `AG` = `src/Tarefa/Service/AgrupadorDeMetas.php`,
> `OUT` = `src/Tarefa/DTO/MinhasMetasOutput.php`, `REPO` = `src/Tarefa/Repository/TarefaRepository.php`,
> `ENT` = `src/Entity/Tarefa/Tarefa.php`. Linhas do BlueJus são de antes da E5 (podem ter deslocado poucas linhas).

## Achados que mudam leitura do desenho

- **O ⋮ do protótipo não abre menu**: `onClick="{{ m.marcar }}"` (P:98) — o mesmo do marcador. Não reproduzir.
  Os itens que a especificação pede para o ⋮ (R118, R236, R223) não estão no desenho.
- O protótipo não tem menu de botão direito, modal, toast nem atalho na própria tela (só dentro do Chat I.A).
- O protótipo não mostra prioridade na linha da meta (só no filtro), e a prioridade dele é **da meta**
  (Normal/Alta/Urgente); no BlueJus é **da pasta** (Normal/Prioridade/Urgente) — Tarefa não tem o campo.
- O protótipo não define `style-focus` em nada; a busca tem `outline:none` (sem indicador de foco). O BlueJus
  mantém os anéis de foco — melhor; não regredir.
- O KPI "Aguardando sua revisão" do protótipo leva à aba errada (resp sem filtro); o BlueJus leva a Criei.

## 1. Cabeçalho da página

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-CAB-01 | H1 "Minhas Metas" 36px negrito, -.5px, #12242f, text-shadow 0 2px 6px rgba(10,111,160,.12) (P:44) | texto | ≈ 1.5rem/600, sem sombra, com ícone bi-check2-square que o desenho não tem | M:21; CSS:107 | não | — |
| MM-CAB-02 | Traço sob o título 34×3px, raio 2, **azul #0a6fa0** | decoração | **✓ E5b** — traço 34×3px, raio 2, **vermelho** `--mm-danger-dot` (#c0392f, o da pendência das abas da pasta), por decisão do dono (P6: R129 prevalece) | — | não | **P6: R129 pede traço VERMELHO** |
| MM-CAB-03 | "Atualizado às HH:MM", ponto 8px #22a05a com halo, 14px #5b7383 (P:45) | indicador | **✓ E5b** — hora em que o servidor leu as metas (fuso America/Sao_Paulo); a recarga parcial do filtro traz a hora da nova leitura (`data-mm-lido-as`) | — | não (hora em que a página foi gerada) | — |
| MM-CAB-04 | Subtítulo "O que está com você, o que você delegou e o que você acompanha." 13.5px #5b7383 | texto | ≈ texto termina em "…marcou para acompanhar — ou tudo junto."; #6b8494 | M:22 | não | — |
| MM-CAB-05 | Botão "BLUEJUS Intelligence" (bi-stars), 42px, borda #d6e0e7, raio 4; hover sobe 2px + sombra; **sem ação no protótipo** | botão | ∅ | — | sim: não há painel de inteligência de metas | — |
| MM-CAB-06 | Página fundo #f3f6f8, padding 24/22/32, max-width 1600px, gap 16 | layout | ≈ fundo do Bootstrap; sem max-width | B:101 | não | — |
| + | Breadcrumb Home › Minhas Metas | navegação | só BlueJus | M:25 | — | — |

## 2. KPIs

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-KPI-01 | Grade auto-fit minmax(220px,1fr), gap 18 | layout | **✓ E5b** — auto-fit minmax(220px,1fr), gap 18 | CSS:122 | não | — |
| MM-KPI-02 | Cartão: #fff, borda #dfe7ed, **raio 4**, sombra 0 2px 10px, padding 22/22/24, min-height 160 | cartão | **✓ E5b** — raio 4, padding 22/22/24, min-height 160, sombra 0 2px 10px | CSS:127 | não | — |
| MM-KPI-03 | Hover: sobe 3px, sombra 0 12px 26px rgba(10,111,160,.14) | hover | **✓ E5b** — sobe 3px + sombra 0 12px 26px rgba(10,111,160,.14) | CSS:143 | não | — |
| MM-KPI-04 | Halo 130px no canto, radial + borda tracejada na cor do KPI | decoração | **✓ E5b** — halo 130px, radial + borda tracejada na cor | — | não | — |
| MM-KPI-05 | Ícone em quadrado 46px raio 12 (bi-exclamation-octagon, bi-hourglass-split, bi-clipboard2-check, bi-calendar-x) | ícone | **✓ E5b** — ícone 46px raio 12 (os 4 ícones do desenho); o ponto saiu | M:51 | não | — |
| MM-KPI-06 | Rótulo 12px negrito, 1.6px, #34505f, sublinhado 20×2px na cor | texto | **✓ E5b** — 12px/700/1.6px com sublinhado 20×2px na cor | CSS:152 | não | — |
| MM-KPI-07 | Número 46px negrito; **vermelho #a3232b quando Atrasadas > 0** | número | **✓ E5b** — 46px/700, -1px; `.is-alerta` vermelho só com atrasadas > 0 | CSS:163 | não | — |
| MM-KPI-08 | Descrição 13px #5b7383 | texto | **✓ E5b** — 13px | M:39 | não | — |
| MM-KPI-09 | Faixa inferior 3px em gradiente | decoração | **✓ E5b** — faixa de 3px em gradiente | — | não | — |
| MM-KPI-10 | ATRASADAS → resp + atrasada | atalho | ✓ | M:39 | já existe | — |
| MM-KPI-11 | VENCEM EM 7 DIAS → resp + 7d | atalho | ✓ | M:40 | já existe | — |
| MM-KPI-12 | AGUARDANDO SUA REVISÃO (o desenho leva à aba errada) | atalho | ≈ BlueJus leva a Criei (corrige o desenho) | M:41 | já existe | — |
| MM-KPI-13 | SEM PRAZO → resp + sem | atalho | ✓ | M:42 | já existe | — |
| MM-KPI-14 | Foco: anel nativo | foco | ✓ | — | não | — |
| + | Estado ativo `.is-ativo` | estado | só BlueJus | CSS:148 | — | — |

## 3. Abas

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-ABA-01 | Trilho segmentado #eef2f5, **raio 4**, padding 4, gap 2, **dentro do mesmo cartão da barra de filtros** | contêiner | **✓ E5b** — raio 4, sem borda, gap 2, **no mesmo cartão da barra de filtros** (dentro da raiz do filtro) | M:72; CSS:180 | não | — |
| MM-ABA-02 | Aba inativa 34px, **raio 3**, #34505f, 13px normal | aba | **✓ E5c** — 34px, padding 0 12px, gap 7, 13px/400, #34505f (`--mm-aba-texto`), sem borda; raio 3 (E5) | CSS:189 | não | — |
| MM-ABA-03 | Aba ativa #fff, **texto #0a6fa0**, negrito, sombra 0 1px 3px | estado | **✓ E5c** — fundo branco, texto #0a6fa0 700, sombra 0 1px 3px rgba(18,36,47,.12), sem borda | CSS:205 | não | — |
| MM-ABA-04 | Ícone na cor do texto | ícone | **✓ E5c** — ícone herda a cor do texto (#34505f inativa, #0a6fa0 ativa) | CSS:212 | não | — |
| MM-ABA-05..08 | Sou responsável · Criei · Em acompanhamento · Todas | aba | ✓ | M:63-66 | já existe | — |
| MM-ABA-09 | Contador 11px negrito, **raio 3**; ativo #e5f1f7/#0a6fa0, inativo #dfe6eb/#4a6373 | badge | **✓ E5c** — 11px/700, padding 1/6, raio 3; ativo #e5f1f7/#0a6fa0, inativo #dfe6eb/#4a6373 | CSS:214 | já existe | — |
| MM-ABA-10 | Contador recalculado ao marcar acompanhamento | comportamento | ✓ | M:211 | já existe | — |
| + | Hover da aba; alternância Cartões/Lista (E5: 4px/3px, seguindo as abas) | — | só BlueJus | CSS:204, 225 | — | — |

## 4. Filtros e busca

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-FIL-01 | Cartão da barra #fff, borda #dfe7ed, raio 4, sombra, padding 14/18 | contêiner | **✓ E5b** — `.mm-barra-cartao`: raio 4, sombra, padding 14/18 | FB:21 | não | — |
| MM-FIL-02 | Separador vertical 1×28 #e2e9ee entre abas e busca | decoração | **✓ E5b** — `.mm-barra-sep` 1×28 (some no celular) | — | não | — |
| MM-FIL-03 | Busca 36px, borda #e2e9ee, raio 4, bi-search, flex 1 1 220px | input | ≈ cápsula raio 20, ~31px, máx. 240px (componente compartilhado `filtro-tabela`) | FT css:20 | não | — |
| MM-FIL-04 | "Buscar por título, pasta ou cliente" — busca título, nº da pasta e cliente | input | ≈ "…título ou descrição"; busca título e descrição | M:103; REPO:338 | **sim** (consulta) | — |
| MM-FIL-05 | Filtra a cada tecla | comportamento | ✓ | FT js:154 | não | — |
| MM-FIL-06 | Foco da busca: `outline:none` | foco | ≈ BlueJus mantém anel (melhor) | — | não | — |
| MM-FIL-07 | Prioridade Normal/Alta/Urgente **da meta** | select | ≈ Normal/Prioridade/Urgente **da pasta** | M:111; REPO:349 | **sim** (Tarefa sem prioridade) | — |
| MM-FIL-08 | Prazo: Atrasadas / Vencem em 7 dias / Sem prazo | select | ≈ Vencidas / Próximas (7 dias) / Sem prazo | M:116 | não | — |
| MM-FIL-09 | Foco dos selects: nativo | foco | ≈ anel do Bootstrap | — | não | — |
| MM-FIL-10 | "Limpar filtros" (texto #0a6fa0, só com filtro) | botão | ≈ "Limpar tudo" junto dos chips | FT js:105 | não | — |
| MM-FIL-11 | Abas, busca e facetas numa linha só dentro do cartão | layout | ≈ com o select Status (só do BlueJus) a barra quebra em 2 linhas a 1440px | M | não | — |
| + | Select Status; chips de filtro ativo; abas quebram linha no celular (E5b, sem rolagem horizontal a 390px) | — | só BlueJus | M:106 | — | — |

## 5. Lista (grupos, linha, estados)

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-LIS-01 | Grade lista + trilho 340px, gap 16 | layout | ≈ 356px, gap 18 | CSS:251 | não | — |
| MM-LIS-02 | **Cada grupo é um cartão separado** (raio 4, sombra), gap 12 | contêiner | ≈ **raio 4 ✓ (E5)**; todos os grupos num cartão só | R:39; CSS:269 | não | — |
| MM-LIS-03 | Cabeçalho do grupo: padding 15/20, #f5f8fa, 12px negrito 1.4px #34505f, ponto 7px | cabeçalho | ≈ padding 11/20, #fafcfd, 11px | CSS:292 | não | — |
| MM-LIS-04 | Contador do grupo 11px, #e2e9ee, raio 3 | badge | ≈ raio 4, cores | CSS:312 | não | — |
| MM-LIS-05 | Grupo ATRASADAS #a3232b, 1º | grupo | ≈ #c0392f | AG:59 | não | — |
| MM-LIS-06 | VENCEM EM 7 DIAS #c08a12, 2º | grupo | ≈ "Próximos 7 dias" | AG:60 | não | — |
| MM-LIS-07 | AGUARDANDO REVISÃO, **3º** | grupo | ≈ "Enviadas para revisão", 5º | AG:63 | sim (Agrupador) | — |
| MM-LIS-08 | NO PRAZO verde, 4º | grupo | ≈ "Depois", cinza (decisão registrada no CSS) | AG:61 | não | — |
| MM-LIS-09 | SEM PRAZO, 5º | grupo | ✓ | AG:62 | não | — |
| MM-LIS-10 | CONCLUÍDAS sempre aberto | grupo | ≈ recolhido, 30 dias (decisão registrada) | R:42 | já existe | — |
| MM-LIS-11 | Aba Criei com os mesmos 6 grupos | grupo | ≈ BlueJus agrupa por etapa | AG:85 | sim (Agrupador) | — |
| MM-LIS-12 | **Numeração "N."** #0a6fa0, margin-right 10px, **1..n dentro de cada grupo** | número | **✓ E5b — R239** (P5, decisão do dono): número da meta **na pasta**, pela mesma regra da aba Metas da pasta (ordem de criação; `TarefaRepository::numerosNaPasta`); teste prova que os dois números batem. Substitui a posição no grupo do desenho | C:17; CSS `.mm-meta-num` | não | **P5: R239 pede `{nº na pasta} Título {nº global}`** |
| MM-LIS-13 | Linha: gap 14, padding 15/20, faixa `inset 4px` na cor do grupo, centralizada | linha | **✓ E5b** — faixa de 4px (border; o desenho usa `inset 4px`, mesmo resultado) | CSS:330 | não | — |
| MM-LIS-14 | Fundo da linha atrasada #fdf3f3 | estado | **✓ E5b** — `--mm-linha-atrasada-bg` #fdf3f3; o hover continua vencendo | — | não | — |
| MM-LIS-15 | Linha inteira clicável, abre a **pasta** | interação | ≈ só título (→ meta) e pasta são links | C:16 | não | — |
| MM-LIS-16 | **Hover**: fundo #3b8fc2, faixa #1f6f9c, sombra 0 4px 12px rgba(10,111,160,.16), z-index 1, tudo branco sem fundo/borda | hover | **✓ E5** (valores calculados idênticos; agora também sobre a linha rosada) | CSS `.mm-meta:hover` | não | — |
| MM-LIS-17 | Título 14.5px negrito, **CAIXA ALTA**, uma linha com reticências | texto | **✓ E5b** — caixa alta 14.5px/700, .2px, uma linha com reticências; nome inteiro no `title` | CSS:349 | não | — |
| MM-LIS-18 | Pasta: bi-folder2, **nº em negrito #0a6fa0** · CLIENTE | campo | ≈ bi-folder, nº sem negrito em #6b8494, cliente cortado em 38 | C:25 | não | — |
| MM-LIS-19 | "de {criador}" (bi-person) | campo | ✓ (aba Sou responsável) | C:31 | já existe | — |
| MM-LIS-20 | Prazo "vence em N dia(s) · dd/mm/aaaa" | campo | **✓ E5c** — "vence em N dia(s) · dd/mm/aaaa" (`TextoDoPrazoDaMeta`, regra do agrupador) | ENT:306 | não | — |
| MM-LIS-21 | Atrasado "N dia(s) atrasado" #a3232b negrito | estado | **✓ E5c** — "N dia(s) atrasado" #a3232b 700 só no balde Atrasadas (em revisão vencida mostra os dias sem destaque, como o dc) | CSS:387 | não | — |
| MM-LIS-22 | "vence hoje" | estado | **✓ E5c** — "vence hoje" | ENT:308 | não | — |
| MM-LIS-23 | "sem prazo definido" | estado | **✓ E5c** — "sem prazo definido" | C:45 | não | — |
| MM-LIS-24 | Concluída: prazo mostra "concluída" | estado | **✓ E5c** — "concluída"; a data do prazo fica no `title` | C:46 | não | — |
| MM-LIS-25 | Prazo não atrasado sempre #4a6373 normal | estado | **✓ E5c** — #4a6373 normal (`--mm-prazo-texto`), 12.5px | CSS:388 | não | — |
| MM-LIS-26 | Pílula de status 11px negrito, padding 3/9, **raio 3**, sem borda | pílula | **✓ E5b** — raio 3, sem borda, 11px/700/.6px, padding 3/9 | CSS:394 | não | — |
| MM-LIS-27 | ATRASADA #a3232b/#fbe7e7 | status | ✓ rótulo; ≈ fundo | C:11 | não | — |
| MM-LIS-28 | VENCE EM BREVE | status | **✓ E5b** — "Vence em breve" (warn), derivado do balde `proximas` do `AgrupadorDeMetas` | C:11 | não (vem do prazo) | — |
| MM-LIS-29 | EM REVISÃO | status | ✓ rótulo; ≈ cores | — | — | — |
| MM-LIS-30 | NO PRAZO | status | **✓ E5b** — "No prazo" (ok/verde, como o desenho), do balde `depois` | — | não | — |
| MM-LIS-31 | SEM PRAZO | status | **✓ E5b** — "Sem prazo" (cinza #5b7383/#eef2f5), do balde `sem_prazo` | — | não | — |
| MM-LIS-32 | CONCLUÍDA | status | ✓ | — | — | — |
| MM-LIS-33 | "criada dd/mm/aaaa" 11.5px | campo | ≈ acrescenta "· mod." | C:61 | já existe | — |
| MM-LIS-34 | Vazio: "Nenhuma meta com esses filtros." numa caixa | vazio | ≈ 5 variantes com ícone | R:14 | não | — |
| MM-LIS-35 | Foco na linha: div sem tabindex | foco | ≈ BlueJus: links focáveis (melhor) | — | não | — |
| MM-LIS-37 | **Número global da meta** depois do título (R239: `{nº na pasta} Título {nº global}`) | número | ∅ — **dependência de backend**: não existe sequência por escritório; o id da meta é sequência de todo o sistema e revelaria o volume de outros escritórios (PMC-035, já recusado) | — | **sim**: coluna + migration de sequência por tenant | R239 |
| MM-LIS-36 | Meta que vence hoje: pílula igual nos dois modos | estado | **✓ E5b** — os dois modos usam `StatusDoPrazoDaMeta` (regra do agrupador): quem vence hoje é "Vence em breve"/warn nos dois | C:10; L:34 | não | — |
| + | Prioridade da pasta na linha (E5: raio 3); contador de comentários; concluída mais clara (atende R238); nota do grupo; modo lista | — | só BlueJus | C:17; CSS:345 | — | R238 |

## 6. Marcador, ⋮ e itens de menu

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-MENU-01 | Marcador bi-bookmark(-fill) 30×30, **raio 4**; desligado #98a9b4, ligado #0a6fa0 | botão | ≈ **raio 4 ✓ (E5)**; cores #b7c4cd/#0f6fc4 | C:67; CSS:426 | já existe | — |
| MM-MENU-02 | Dica "Acompanhar esta meta" / "Parar de acompanhar" | dica | ≈ "Acompanhar" / "Deixar de acompanhar" (title nativo) | C:72 | não | — |
| MM-MENU-03 | Hover do marcador: só o branco do hover da linha | hover | ≈ BlueJus tem borda + cor própria (fora do hover da linha) | CSS:437 | não | — |
| MM-MENU-04 | Clique alterna e grava | interação | ✓ (no servidor) | M:180 | já existe | — |
| MM-MENU-05 | Botão ⋮ "Opções" 30×30, raio 4, #7d909d, hover #e5f1f7 | botão | **✓ E5c** — ⋮ "Opções" 30×30, raio 4, #7d909d; fundo #e5f1f7 só no foco do teclado — no mouse a linha em hover pinta tudo de branco, como o dc (L18); substitui a seta (o acesso à meta segue em "Abrir relatório completo" e no título) | C:75 | não | ver MENU-08 |
| MM-MENU-06 | Ação real do ⋮ no protótipo = `m.marcar` | defeito | não reproduzir | — | — | — |
| MM-MENU-07 | Itens do menu ⋮: nenhum no protótipo | menu | **✓ E5c** — menu Bootstrap (popper `fixed`, escapa do recorte do cartão) com os itens da especificação que já existem; resiste ao hover azul da linha | — | — | R118/R236/R223 |
| MM-MENU-08 | "Abrir relatório completo" (R118) | item | **✓ E5c** — → `tarefa_show` (a página da meta, o relatório completo) | — | já existe | R118 |
| MM-MENU-09 | "Ir para a aba Metas da pasta" (R118) | item | **✓ E5c** — → `/pasta/{id}?meta={id}#tarefas`: aba Metas aberta, meta realçada (realce do PMC-044; meta concluída abre no filtro "Concluídas") | — | não | R118 |
| MM-MENU-10 | "Editar nome" (R236) | item | **✓ E5c** — → `…&acao=renomear#tarefas`: a pasta abre a edição de nome do próprio ⋮ dela (mesmo form, mesmo POST `tarefa_renomear`, volta para a aba Metas da pasta). Oculto em pasta excluída, como na pasta | — | já existe | R236 |
| MM-MENU-11 | "Encaminhar via Chat I.A" (R223) | item | ∅ | — | **sim** | R223 |
| MM-MENU-12 | Menu de botão direito: nenhum | menu | ✓ (ambos sem) | — | — | — |

## 7. Trilho direito

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-TRI-01 | aside **sticky top 124px**, 340px | layout | ≈ **sticky ✓ E5b** (`top: calc(header + subnav + 16px)` = 118px; exigiu `overflow: visible` no `main` só nesta página — o AdminLTE prendia o sticky); largura 356 × 340 | CSS:251 | não | — |
| MM-TRI-02 | "Seu andamento": padding 20, raio 4, **faixa inferior 3px #1e7b45** | cartão | **✓ E5b** — faixa inferior 3px #1e7b45 | R:76 | não | — |
| MM-TRI-03 | Título 14px + "últimos 30 dias" | texto | **✓ E5c** — "Seu andamento" 14px/700 + "últimos 30 dias" 12px; cabeçalho de "Precisa de atenção" 14px/700, padding 13/16 | R:78 | já existe | — |
| MM-TRI-04 | "X de Y" 34px negrito + "concluídas" | número | **✓ E5c** — 34px/700, -.5px; "concluídas" 13px | CSS:455 | já existe | — |
| MM-TRI-05 | Barra 7px: trilho #e6edf2 **raio 4**, preenchimento #1e7b45 **raio 4**, transição .4s | barra | **✓ E5c** — preenchimento #1e7b45 (`--mm-faixa-andamento`), `transition: width .4s`; raios 4 (E5) | CSS:462 | já existe | — |
| MM-TRI-06 | Legenda sempre 3 itens com "(s)" | legenda | **✓ E5c** — sempre os 3 itens do dc, inclusive zerados: "N concluída(s)" · "N atrasada(s)" (pela regra, em qualquer aba — inclusive na Criei, que não tem grupo de atrasadas) · "N em revisão", 12.5px, pontos 7px nas cores das faixas. **Saiu** a fatia "em aberto" (só do BlueJus; é o resto do "X de Y") | OUT:99 | já existe | — |
| MM-TRI-07 | Base: todas as metas | regra | ≈ o que a aba exibe | OUT:121 | sim (regra) | — |
| MM-TRI-08 | "Precisa de atenção": **faixa inferior 3px #a3232b** | cartão | **✓ E5b** — faixa inferior 3px #a3232b | R:103 | não | — |
| MM-TRI-09 | Item = button de linha inteira, hover #eef6fb, abre a pasta | item | ≈ div, só o título é link, sem hover | R:109 | não | — |
| MM-TRI-10 | bi-exclamation-triangle-fill #a3232b | ícone | **✓ E5c** — #a3232b (`--mm-faixa-atencao`) | R:110 | não | — |
| MM-TRI-11 | "Título · Pasta NNN" | texto | **✓ E5c** — "Título · Pasta NNN", 13.5px/400 | R:112 | não | — |
| MM-TRI-12 | "prazo dd/mm · N dia(s) atrasado · de X" | texto | **✓ E5c** — texto igual; 12px, line-height 1.4, #5b7383 (`--mm-atencao-desc`) | R:113 | já existe | — |
| MM-TRI-13 | Máximo 4 itens | regra | ≈ máximo 5 | OUT:16 | não | — |
| MM-TRI-14 | Cartão sempre visível | estado | ≈ some sem atrasadas | R:102 | não | — |
| + | "Ver todas as N atrasadas"; cartão de pessoas | — | só BlueJus | R:121, 133 | — | — |

## 8. Modais, dicas, toasts, atalhos

| ID | Elemento | Tipo | BlueJus | Backend? |
|---|---|---|---|---|
| MM-MOD-01 | Modais: nenhum | — | ✓ | — |
| MM-MOD-02 | **Dica padronizada `bj-dica.js`** (fundo #e5f1f7, texto #0b4f73, borda #b9d6e8, raio 4, 280ms, reposiciona) | dica | ∅ (title nativo) — decisão do dono pendente desde a E1 (toca `base.html.twig`) | não |
| MM-MOD-03 | Toasts: nenhum | — | ✓ | — |
| MM-MOD-04 | Atalhos: nenhum | — | ✓ | — |

## 9. Elementos globais

| ID | Elemento | BlueJus | Backend? |
|---|---|---|---|
| MM-GLB-01 | Topo 60px **#0a6fa0**, sticky | ≈ 58px **#0078AA**, fixed | não |
| MM-GLB-02 | Hambúrguer bi-list | ✓ | — |
| MM-GLB-03 | Logo branca 30px | ✓ (32px) | — |
| MM-GLB-04 | Separador 1×22 + "Metas" 17px | ≈ "\|" em texto, .95rem | não (R157: nome do módulo indefinido) |
| MM-GLB-05 | bi-building + escritório 15px negrito, máx. 220px | ≈ .9rem, 180px, com menu de troca | — |
| MM-GLB-06 | Sino **bi-bell-fill**; badge "9+" #c0392b raio 8 | ≈ bi-bell; badge #dc3545 | já existe |
| MM-GLB-07 | Tela cheia | ✓ | — |
| MM-GLB-08 | Nome CAIXA ALTA + papel "USUÁRIO PRIMÁRIO" | ≈ sem o papel | sim (exibir papel; o dado existe) |
| MM-GLB-09 | Avatar 36px, **2 iniciais** | ≈ 34px, 1 inicial, foto | — |
| MM-GLB-10 | Subnav: 13px negrito .8px #34505f | ≈ 12px 600 | — |
| MM-GLB-11 | Hover do link: #0a6fa0 sem borda | ≈ #0078AA + borda | — |
| MM-GLB-12 | Nenhum link ativo | ✓ | — |

## 10. Chat I.A (`bluejus-central.js`) — tudo ∅, tudo depende de backend

MM-IA-01 botão flutuante (66×54, canto inferior direito) · 02 estados do ícone (calmo/não lidas/aberto) · 03 hover
(sobe 3px, escala 1.06) · 04 badge de não lidas (pulsa se urgente, "9+") · 05 chip "@N" · 06 dica "Chat I.A · N não
lidas" · 07 arrastar com posição guardada · 08 janela 380×580 · 09 cabeçalho #0a7aad (avatar com status, arrasta,
duplo clique maximiza, setas movem) · 10–14 botões ⋮ / destacar / minimizar / maximizar / fechar · 15 redimensionar
(8 alças) · 16 busca "Pesquisar usuário..." · 17 abas "Caixa de entrada" e "Painel" · 18 alerta de nova meta com
"Abrir meta" (R152) · 19 toast · 20–38 itens do ⋮ (Resolvidas ficam na caixa por · Marcar todas como lidas ·
Governança e acessos · Ocorrências do controller · Ver todas as notificações · Status · Volume · Receber toques ·
Alertas visuais · Avisar · Enter envia a mensagem · Receber ligações · Tamanho do texto · Limpar notificações lidas ·
Excluir todas as conversas · Restaurar posição · Restaurar tamanho · Abrir em janela separada · Ajuda).
Casca visual possível (botão + janela vazia com "sem conexão com a Central") — ver §19 do checklist da Pasta.

## 11. Contagens

| Região | ✓ | ≈ | ∅ |
|---|---|---|---|
| Cabeçalho | 2 | 3 | 1 |
| KPIs | 13 | 1 | 0 |
| Abas | 10 | 0 | 0 |
| Filtros | 3 | 8 | 0 |
| Lista | 21 | 15 | 1 |
| Marcador e menus | 7 | 3 | 1 |
| Trilho | 9 | 5 | 0 |
| Modais e dicas | 3 | 0 | 1 |
| Globais | 4 | 8 | 0 |
| Chat I.A | 0 | 0 | 38 |
| **Total** | **72** | **43** | **42** |

Evolução: levantamento 25 ✓ · 72 ≈ · 57 ∅ (154) → E5 27 · 72 · 56 (155) → E5b 53 · 58 · 46 (157) → **E5c 72 · 43 · 42 (157)** (contado no arquivo; linhas "✓ texto; ≈ cor" contam ✓, como no levantamento). A E5b levou 11 itens
de ∅ para ✓ e 13 de ≈ para ✓, e abriu 2 linhas: MM-LIS-37 (nº global, ∅, depende de backend) e MM-FIL-11 (≈).

## 12. Próximos candidatos (visuais, sem backend)

Depois da E5c sobram, sem pedir dado novo: cartão por grupo e cabeçalho do grupo (LIS-02/03/04), cores e nomes dos
grupos (LIS-05/06; LIS-07/08 tocam a regra do agrupador — perguntar), nº da pasta em negrito azul com bi-folder2
(LIS-18), linha inteira clicável (LIS-15 — o desenho abre a **pasta**; hoje o título abre a meta: decidir), "criada"
sem "· mod." (LIS-33), vazio único (LIS-34), item de "Precisa de atenção" como botão de linha inteira com hover
#eef6fb (TRI-09 — o desenho abre a pasta), cartão de atenção sempre visível (TRI-14), largura 340/gap 16 (LIS-01,
TRI-01), marcador #98a9b4/#0a6fa0 e dicas "Acompanhar esta meta"/"Parar de acompanhar" (MENU-01/02/03), H1 36px
(CAB-01), subtítulo (CAB-04), busca 36px (FIL-03 — componente compartilhado `filtro-tabela`).
**Não recomendados agora** (simulariam o que não existe): CAB-05 (Intelligence sem painel), MENU-11 e o bloco MM-IA.

## 13. Pendências de especificação desta tela

| # | Conflito | Desenho | Especificação do Farlei | Estado |
|---|---|---|---|---|
| P5 | Numeração da meta | 1..n por grupo | §3.5 R239: `{nº sequencial na pasta} Título {nº global}` | **DECIDIDA (14/10): R239** — nº na pasta ✓ (E5b); nº global ∅, depende de backend (MM-LIS-37) |
| P6 | Traço sob o título | azul #0a6fa0 sob "Minhas Metas" | §3.5 R129: traço **vermelho** sob "Metas" | **DECIDIDA (14/10): R129** — vermelho ✓ (E5b) |
| — | Concluída mais clara | o desenho não clareia | §3.5 R238 pede clarear | **Decisão do dono (14/10): preservar** — mantido, travado em teste (fundo suave, título apagado e riscado) |
| — | ⋮ da meta | sem menu (defeito) | R118/R236/R223 pedem itens | **Decisão do dono (14/10): não reproduzir o defeito.** Itens da spec seguem ∅ (MENU-07..11) |
| — | Nome do módulo | "Minhas Metas" | R157: decisão não registrada | aberto |

## 14. Notas da E5b

- **"No prazo" verde × decisão antiga do BlueJus.** O CSS registrava "terceiro tom dos prazos: cinza, não verde". O desenho
  de 13/10 (fonte oficial) pinta a pílula "No prazo" de verde, e a especificação do Farlei é omissa: segui o desenho na
  pílula. O **grupo** "Depois" (cinza) não mudou — MM-LIS-08 segue ≈.
- **Tela de Equipe** (`/tarefas/equipe`) tem lógica de status própria (`equipe.html.twig:61`, ainda "Pendente") — não faz
  parte de Minhas Metas; candidata a usar o mesmo `StatusDoPrazoDaMeta` quando a tela for trabalhada.
- **Modo lista** (tabela, só do BlueJus) recebeu o status derivado; o número na pasta e a caixa alta ficaram só no modo
  cartões (o equivalente do desenho).
- **Lição:** o teste de folha passou com as faixas do trilho erradas (a regra `.mm-cartao`, depois no arquivo, devolvia a
  borda de 1px) e com o sticky preso pelo AdminLTE. Os dois só apareceram medindo na tela.

## 15. Notas da E5c

- **Prazo da linha** vem de `TextoDoPrazoDaMeta` (novo), que só lê o balde de `AgrupadorDeMetas::classificar` e o prazo
  real. `Tarefa::getPrazoInfo` não mudou: a tela da meta, a de Equipe e o modo lista (só do BlueJus) seguem com o
  texto e as cores de antes — as regras `.mm-prazo.tom-*` ficaram para eles.
- **Legenda de "Seu andamento"**: o desenho mostra 3 itens fixos; a fatia "em aberto" (só do BlueJus) saiu, e os itens
  zerados passaram a aparecer. Se o dono quiser a fatia de volta, é uma linha em `MinhasMetasOutput::fatiasDoAndamento`.
- **⋮ sem backend novo**: "Editar nome" não edita na própria Minhas Metas — leva à aba Metas da pasta com
  `acao=renomear` e o `pasta-metas.js` aciona o "Editar nome" do ⋮ da linha (que só existe se a meta for editável).
  Editar ali mesmo pediria que o `tarefa_renomear` voltasse para Minhas Metas (mudança no controller) — fica como
  proposta, não feita.
- **Modal do ponto no dev**: sem batida no dia, ele abre por cima e toma o foco do campo de nome na pasta. Na medição
  foi removido do DOM (ponto é risco ALTO, sem interação); em uso normal só aparece até a primeira batida.
