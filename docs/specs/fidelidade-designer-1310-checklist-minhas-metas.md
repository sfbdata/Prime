# Checklist acumulado de Minhas Metas — Claude Designer (13/10) × BlueJus

> **Para quem:** o dono e quem continuar a frente `fidelidade-designer`. Plano e evidências em
> `fidelidade-designer-1310.md` (§9 para a E5). Este arquivo é **acumulado**: cada entrega atualiza a marca
> do elemento em vez de abrir lista nova. Levantado em 14/10/2026 (inventário read-only) e já atualizado com
> a E5 (`e227183b`). Marcas: ✓ conforme · ≈ diverge · ∅ ausente · **+** só o BlueJus tem (fora das contagens).
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
| MM-CAB-02 | Traço sob o título 34×3px, raio 2, **azul #0a6fa0** | decoração | ∅ | — | não | **P6: R129 pede traço VERMELHO** |
| MM-CAB-03 | "Atualizado às HH:MM", ponto 8px #22a05a com halo, 14px #5b7383 (P:45) | indicador | ∅ | — | não (hora em que a página foi gerada) | — |
| MM-CAB-04 | Subtítulo "O que está com você, o que você delegou e o que você acompanha." 13.5px #5b7383 | texto | ≈ texto termina em "…marcou para acompanhar — ou tudo junto."; #6b8494 | M:22 | não | — |
| MM-CAB-05 | Botão "BLUEJUS Intelligence" (bi-stars), 42px, borda #d6e0e7, raio 4; hover sobe 2px + sombra; **sem ação no protótipo** | botão | ∅ | — | sim: não há painel de inteligência de metas | — |
| MM-CAB-06 | Página fundo #f3f6f8, padding 24/22/32, max-width 1600px, gap 16 | layout | ≈ fundo do Bootstrap; sem max-width | B:101 | não | — |
| + | Breadcrumb Home › Minhas Metas | navegação | só BlueJus | M:25 | — | — |

## 2. KPIs

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-KPI-01 | Grade auto-fit minmax(220px,1fr), gap 18 | layout | ≈ 4 colunas fixas, gap 14 | CSS:122 | não | — |
| MM-KPI-02 | Cartão: #fff, borda #dfe7ed, **raio 4**, sombra 0 2px 10px, padding 22/22/24, min-height 160 | cartão | ≈ **raio 4 ✓ (E5)**; borda/sombra/padding/altura diferem | CSS:127 | não | — |
| MM-KPI-03 | Hover: sobe 3px, sombra 0 12px 26px rgba(10,111,160,.14) | hover | ≈ só borda + sombra, sem deslocamento | CSS:143 | não | — |
| MM-KPI-04 | Halo 130px no canto, radial + borda tracejada na cor do KPI | decoração | ∅ | — | não | — |
| MM-KPI-05 | Ícone em quadrado 46px raio 12 (bi-exclamation-octagon, bi-hourglass-split, bi-clipboard2-check, bi-calendar-x) | ícone | ∅ (ponto de 8px no rótulo) | M:51 | não | — |
| MM-KPI-06 | Rótulo 12px negrito, 1.6px, #34505f, sublinhado 20×2px na cor | texto | ≈ 10.5px/700, #7b93a2, ponto | CSS:152 | não | — |
| MM-KPI-07 | Número 46px negrito; **vermelho #a3232b quando Atrasadas > 0** | número | ≈ 26px/500, sempre #12242f | CSS:163 | não | — |
| MM-KPI-08 | Descrição 13px #5b7383 | texto | ≈ textos iguais; .78rem #6b8494 | M:39 | não | — |
| MM-KPI-09 | Faixa inferior 3px em gradiente | decoração | ∅ | — | não | — |
| MM-KPI-10 | ATRASADAS → resp + atrasada | atalho | ✓ | M:39 | já existe | — |
| MM-KPI-11 | VENCEM EM 7 DIAS → resp + 7d | atalho | ✓ | M:40 | já existe | — |
| MM-KPI-12 | AGUARDANDO SUA REVISÃO (o desenho leva à aba errada) | atalho | ≈ BlueJus leva a Criei (corrige o desenho) | M:41 | já existe | — |
| MM-KPI-13 | SEM PRAZO → resp + sem | atalho | ✓ | M:42 | já existe | — |
| MM-KPI-14 | Foco: anel nativo | foco | ✓ | — | não | — |
| + | Estado ativo `.is-ativo` | estado | só BlueJus | CSS:148 | — | — |

## 3. Abas

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-ABA-01 | Trilho segmentado #eef2f5, **raio 4**, padding 4, gap 2, **dentro do mesmo cartão da barra de filtros** | contêiner | ≈ **raio 4 ✓ (E5)**; tem borda e fica em linha própria, fora do cartão | M:72; CSS:180 | não | — |
| MM-ABA-02 | Aba inativa 34px, **raio 3**, #34505f, 13px normal | aba | ≈ **raio 3 ✓ (E5)**; padding/peso/cor diferem | CSS:189 | não | — |
| MM-ABA-03 | Aba ativa #fff, **texto #0a6fa0**, negrito, sombra 0 1px 3px | estado | ≈ texto #12242f (só o ícone azul), borda | CSS:205 | não | — |
| MM-ABA-04 | Ícone na cor do texto | ícone | ≈ ícone inativo #8496a3 | CSS:212 | não | — |
| MM-ABA-05..08 | Sou responsável · Criei · Em acompanhamento · Todas | aba | ✓ | M:63-66 | já existe | — |
| MM-ABA-09 | Contador 11px negrito, **raio 3**; ativo #e5f1f7/#0a6fa0, inativo #dfe6eb/#4a6373 | badge | ≈ **raio 3 ✓ (E5)**; cores diferem | CSS:214 | já existe | — |
| MM-ABA-10 | Contador recalculado ao marcar acompanhamento | comportamento | ✓ | M:211 | já existe | — |
| + | Hover da aba; alternância Cartões/Lista (E5: 4px/3px, seguindo as abas) | — | só BlueJus | CSS:204, 225 | — | — |

## 4. Filtros e busca

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-FIL-01 | Cartão da barra #fff, borda #dfe7ed, raio 4, sombra, padding 14/18 | contêiner | ∅ (barra solta) | FB:21 | não | — |
| MM-FIL-02 | Separador vertical 1×28 #e2e9ee entre abas e busca | decoração | ∅ | — | não | — |
| MM-FIL-03 | Busca 36px, borda #e2e9ee, raio 4, bi-search, flex 1 1 220px | input | ≈ cápsula raio 20, ~31px, máx. 240px (componente compartilhado `filtro-tabela`) | FT css:20 | não | — |
| MM-FIL-04 | "Buscar por título, pasta ou cliente" — busca título, nº da pasta e cliente | input | ≈ "…título ou descrição"; busca título e descrição | M:103; REPO:338 | **sim** (consulta) | — |
| MM-FIL-05 | Filtra a cada tecla | comportamento | ✓ | FT js:154 | não | — |
| MM-FIL-06 | Foco da busca: `outline:none` | foco | ≈ BlueJus mantém anel (melhor) | — | não | — |
| MM-FIL-07 | Prioridade Normal/Alta/Urgente **da meta** | select | ≈ Normal/Prioridade/Urgente **da pasta** | M:111; REPO:349 | **sim** (Tarefa sem prioridade) | — |
| MM-FIL-08 | Prazo: Atrasadas / Vencem em 7 dias / Sem prazo | select | ≈ Vencidas / Próximas (7 dias) / Sem prazo | M:116 | não | — |
| MM-FIL-09 | Foco dos selects: nativo | foco | ≈ anel do Bootstrap | — | não | — |
| MM-FIL-10 | "Limpar filtros" (texto #0a6fa0, só com filtro) | botão | ≈ "Limpar tudo" junto dos chips | FT js:105 | não | — |
| + | Select Status; chips de filtro ativo | — | só BlueJus | M:106 | — | — |

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
| MM-LIS-12 | **Numeração "N."** #0a6fa0, margin-right 10px, **1..n dentro de cada grupo** | número | **✓ E5** (dentro do link do título; também nos grupos recolhidos) | C:17; CSS `.mm-meta-num` | não | **P5: R239 pede `{nº na pasta} Título {nº global}`** |
| MM-LIS-13 | Linha: gap 14, padding 15/20, faixa `inset 4px` na cor do grupo, centralizada | linha | ≈ border-left **3px**, align flex-start | CSS:330 | não | — |
| MM-LIS-14 | Fundo da linha atrasada #fdf3f3 | estado | ∅ | — | não | — |
| MM-LIS-15 | Linha inteira clicável, abre a **pasta** | interação | ≈ só título (→ meta) e pasta são links | C:16 | não | — |
| MM-LIS-16 | **Hover**: fundo #3b8fc2, faixa #1f6f9c, sombra 0 4px 12px rgba(10,111,160,.16), z-index 1, tudo branco sem fundo/borda | hover | **✓ E5** (valores calculados idênticos; a faixa é border de 3px, ver LIS-13) | CSS `.mm-meta:hover` | não | — |
| MM-LIS-17 | Título 14.5px negrito, **CAIXA ALTA**, uma linha com reticências | texto | ≈ .9rem/600, caixa normal, quebra | CSS:349 | não | — |
| MM-LIS-18 | Pasta: bi-folder2, **nº em negrito #0a6fa0** · CLIENTE | campo | ≈ bi-folder, nº sem negrito em #6b8494, cliente cortado em 38 | C:25 | não | — |
| MM-LIS-19 | "de {criador}" (bi-person) | campo | ✓ (aba Sou responsável) | C:31 | já existe | — |
| MM-LIS-20 | Prazo "vence em N dia(s) · dd/mm/aaaa" | campo | ≈ sem a data | ENT:306 | não | — |
| MM-LIS-21 | Atrasado "N dia(s) atrasado" #a3232b negrito | estado | ✓ texto; ≈ cor | CSS:387 | não | — |
| MM-LIS-22 | "vence hoje" | estado | ≈ "Vence hoje" | ENT:308 | não | — |
| MM-LIS-23 | "sem prazo definido" | estado | ≈ "sem prazo" | C:45 | não | — |
| MM-LIS-24 | Concluída: prazo mostra "concluída" | estado | ≈ "prazo dd/mm/aaaa" | C:46 | não | — |
| MM-LIS-25 | Prazo não atrasado sempre #4a6373 normal | estado | ≈ 1–3 dias âmbar 600 | CSS:388 | não | — |
| MM-LIS-26 | Pílula de status 11px negrito, padding 3/9, **raio 3**, sem borda | pílula | ≈ **raio 3 ✓ (E5)**; ainda com borda 1px | CSS:394 | não | — |
| MM-LIS-27 | ATRASADA #a3232b/#fbe7e7 | status | ✓ rótulo; ≈ fundo | C:11 | não | — |
| MM-LIS-28 | VENCE EM BREVE | status | ∅ ("Pendente") | C:11 | não (vem do prazo) | — |
| MM-LIS-29 | EM REVISÃO | status | ✓ rótulo; ≈ cores | — | — | — |
| MM-LIS-30 | NO PRAZO | status | ∅ ("Pendente") | — | não | — |
| MM-LIS-31 | SEM PRAZO | status | ∅ ("Pendente") | — | não | — |
| MM-LIS-32 | CONCLUÍDA | status | ✓ | — | — | — |
| MM-LIS-33 | "criada dd/mm/aaaa" 11.5px | campo | ≈ acrescenta "· mod." | C:61 | já existe | — |
| MM-LIS-34 | Vazio: "Nenhuma meta com esses filtros." numa caixa | vazio | ≈ 5 variantes com ícone | R:14 | não | — |
| MM-LIS-35 | Foco na linha: div sem tabindex | foco | ≈ BlueJus: links focáveis (melhor) | — | não | — |
| MM-LIS-36 | Meta que vence hoje: pílula igual nos dois modos | estado | ≈ **defeito do BlueJus**: cartão mostra "Pendente" em vermelho (`info.cor=='danger'`, C:10), lista em âmbar (`dias<0`, L:34) | C:10; L:34 | não | — |
| + | Prioridade da pasta na linha (E5: raio 3); contador de comentários; concluída mais clara (atende R238); nota do grupo; modo lista | — | só BlueJus | C:17; CSS:345 | — | R238 |

## 6. Marcador, ⋮ e itens de menu

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-MENU-01 | Marcador bi-bookmark(-fill) 30×30, **raio 4**; desligado #98a9b4, ligado #0a6fa0 | botão | ≈ **raio 4 ✓ (E5)**; cores #b7c4cd/#0f6fc4 | C:67; CSS:426 | já existe | — |
| MM-MENU-02 | Dica "Acompanhar esta meta" / "Parar de acompanhar" | dica | ≈ "Acompanhar" / "Deixar de acompanhar" (title nativo) | C:72 | não | — |
| MM-MENU-03 | Hover do marcador: só o branco do hover da linha | hover | ≈ BlueJus tem borda + cor própria (fora do hover da linha) | CSS:437 | não | — |
| MM-MENU-04 | Clique alterna e grava | interação | ✓ (no servidor) | M:180 | já existe | — |
| MM-MENU-05 | Botão ⋮ "Opções" 30×30, raio 4, #7d909d, hover #e5f1f7 | botão | ≈ trocado por bi-chevron-right "Abrir a meta" (**raio 4 ✓ E5**) — divergência já decidida antes | C:75 | não | ver MENU-08 |
| MM-MENU-06 | Ação real do ⋮ no protótipo = `m.marcar` | defeito | não reproduzir | — | — | — |
| MM-MENU-07 | Itens do menu ⋮: nenhum no protótipo | menu | ∅ | — | — | R118/R236/R223 |
| MM-MENU-08 | "Abrir relatório completo" (R118) | item | ∅ aqui; existe no ⋮ da Pasta | — | já existe | R118 |
| MM-MENU-09 | "Ir para a aba Metas da pasta" (R118) | item | ∅ | — | não | R118 |
| MM-MENU-10 | "Editar nome" (R236) | item | ∅ aqui; existe na Pasta | — | já existe | R236 |
| MM-MENU-11 | "Encaminhar via Chat I.A" (R223) | item | ∅ | — | **sim** | R223 |
| MM-MENU-12 | Menu de botão direito: nenhum | menu | ✓ (ambos sem) | — | — | — |

## 7. Trilho direito

| ID | Elemento | Tipo | BlueJus | Onde | Backend? | Spec |
|---|---|---|---|---|---|---|
| MM-TRI-01 | aside **sticky top 124px**, 340px | layout | ≈ não fixo, 356px | CSS:251 | não | — |
| MM-TRI-02 | "Seu andamento": padding 20, raio 4, **faixa inferior 3px #1e7b45** | cartão | ≈ sem faixa (o raio é o do cartão do trilho) | R:76 | não | — |
| MM-TRI-03 | Título 14px + "últimos 30 dias" | texto | ✓ texto; ≈ tamanho | R:78 | já existe | — |
| MM-TRI-04 | "X de Y" 34px negrito + "concluídas" | número | ≈ 1.625rem/500 | CSS:455 | já existe | — |
| MM-TRI-05 | Barra 7px: trilho #e6edf2 **raio 4**, preenchimento #1e7b45 **raio 4**, transição .4s | barra | ≈ **raios 4 ✓ (E5)**; verde #1f9d61, sem transição | CSS:462 | já existe | — |
| MM-TRI-06 | Legenda sempre 3 itens com "(s)" | legenda | ≈ 4 fatias, esconde zeradas | OUT:99 | já existe | — |
| MM-TRI-07 | Base: todas as metas | regra | ≈ o que a aba exibe | OUT:121 | sim (regra) | — |
| MM-TRI-08 | "Precisa de atenção": **faixa inferior 3px #a3232b** | cartão | ≈ sem faixa | R:103 | não | — |
| MM-TRI-09 | Item = button de linha inteira, hover #eef6fb, abre a pasta | item | ≈ div, só o título é link, sem hover | R:109 | não | — |
| MM-TRI-10 | bi-exclamation-triangle-fill #a3232b | ícone | ≈ #c0392f | R:110 | não | — |
| MM-TRI-11 | "Título · Pasta NNN" | texto | ≈ sem "· Pasta" | R:112 | não | — |
| MM-TRI-12 | "prazo dd/mm · N dia(s) atrasado · de X" | texto | ✓ texto; ≈ cor | R:113 | já existe | — |
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
| Cabeçalho | 0 | 3 | 3 |
| KPIs | 4 | 7 | 3 |
| Abas | 5 | 5 | 0 |
| Filtros | 1 | 7 | 2 |
| Lista | 8 | 24 | 4 |
| Marcador e menus | 2 | 4 | 5 |
| Trilho | 0 | 14 | 0 |
| Modais e dicas | 3 | 0 | 1 |
| Globais | 4 | 8 | 0 |
| Chat I.A | 0 | 0 | 38 |
| **Total** | **27** | **72** | **56** |

Antes da E5: 25 ✓ · 72 ≈ · 57 ∅. A E5 levou LIS-12 (∅→✓) e LIS-16 (≈→✓), trouxe o raio para o valor do desenho
em 9 linhas que seguem ≈ por outras propriedades, e acrescentou LIS-36 (≈, defeito do BlueJus).

## 12. Próximos candidatos (visuais, sem backend)

KPIs (KPI-01/03/04/05/06/07/09) · abas dentro do cartão dos filtros (ABA-01/03/04, FIL-01/02) · cartão por grupo e
cabeçalho (LIS-02/03/04) · faixa de 4px e fundo das atrasadas (LIS-13/14) · caixa alta e nº da pasta em negrito
(LIS-17/18) · textos e cores de prazo e as pílulas VENCE EM BREVE / NO PRAZO / SEM PRAZO derivadas do prazo real
(LIS-20..31) · trilho sticky com faixas de 3px e itens clicáveis (TRI-01/02/08/09/11/14) · "Atualizado às" (CAB-03)
· menu ⋮ só com ações que já existem (MENU-08/09/10; "Encaminhar" fica de fora).
**Não recomendados agora** (simulariam o que não existe): CAB-05 (Intelligence sem painel) e o bloco MM-IA.

## 13. Pendências de especificação desta tela

| # | Conflito | Desenho | Especificação do Farlei | Estado |
|---|---|---|---|---|
| P5 | Numeração da meta | 1..n por grupo (implementado na E5, a pedido do dono) | §3.5 R239: `{nº sequencial na pasta} Título {nº global}` | **ABERTA** |
| P6 | Traço sob o título | azul #0a6fa0 sob "Minhas Metas" | §3.5 R129: traço **vermelho** sob "Metas" | **ABERTA** — não implementado |
| — | Concluída mais clara | o desenho não clareia | §3.5 R238 pede clarear | BlueJus já segue a especificação; **mantido** (não regredir) |
| — | ⋮ da meta | sem menu (defeito) | R118/R236/R223 pedem itens | aberto como ∅ (MENU-07..11) |
| — | Nome do módulo | "Minhas Metas" | R157: decisão não registrada | aberto |
