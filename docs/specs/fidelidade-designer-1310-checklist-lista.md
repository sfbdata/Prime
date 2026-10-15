# Checklist da Lista do Expediente — Claude Designer (13/10) × BlueJus

> **Para quem:** o dono e quem continuar a frente `fidelidade-designer`. Plano, conflitos e decisões em
> `fidelidade-designer-1310/E8-PLANO.md`; evidência em `fidelidade-designer-1310.md` §13. Levantado em 14/10 (inventário
> read-only) e já com a E8. Marcas: ✓ conforme · ≈ diverge · ∅ ausente · **+** só o BlueJus tem (fora das contagens).
> **∅ não quer dizer "fora"**: a coluna *Backend?* diz o que falta. "Decisão" = escolha registrada do dono (14/10).
>
> Fonte: `03 - EXPEDIENTE Lista 1.1.dc.html` (D:linha). BlueJus: `templates/expediente/*`, `pasta/_tabela`, `_filtros`,
> `_card`, `_resp_*`, `public/css/expediente-lista.css`, `public/js/expediente-lista.js`.
> A especificação do Farlei diz desta tela (R271): "ajustes ainda não pedidos"; o §9 item 12 chama de inferência do
> Claude o trilho recolhível, a alternância tabela/lista e a troca de responsável na linha.

## 1. Página e cabeçalho

| ID | Elemento (D:L) | BlueJus | Backend? |
|---|---|---|---|
| LX-CAB-01 | Barra superior #0a76a8, 72px, sino "9+" (D:39-49) | ≈ global (`base.html.twig`), fora da E8 | não |
| LX-CAB-02 | Subnav EXPEDIENTE/DEMANDAS/PROCESSOS (D:50-54) | ≈ global | não |
| LX-CAB-03 | H1 40px normal #12242f + pasta azul que fecha e abre (D:57) | **✓ E8** (respeita "reduzir movimento") | não |
| LX-CAB-04 | Fundo #eef2f5, trilho e acervo como cartões brancos, borda #d6e0e7, raio 4 (D:17, 60, 79) | **✓ E8** | não |

## 2. Trilho de pastas e marcadores

| ID | Elemento (D:L) | BlueJus | Backend? |
|---|---|---|---|
| LX-TRI-01 | Trilho 250px / recolhido 64px (D:60) | ≈ 260 / 52 (comportamento de recolher mantido; inferência do §9) | não |
| LX-TRI-02 | Rótulos PASTAS / MARCADORES 11px 700 1.6px #4a6373 (D:62, 68) | **✓ E8** | não |
| LX-TRI-03 | "+" 26×26 tracejado #9fb6c5, azul; hover #e8f3f9 sólido (D:63, 69) | **✓ E8** | não |
| LX-TRI-04 | "Todas as pastas" 36px, ativo #e8f3f9/#0a6fa0 (D:65) | **✓ E8** (ativo com borda interna de 3px) | não |
| LX-TRI-05 | Item 34px 14px #243845; ícone na cor do marcador; selecionado: borda 3px + #eef6fb + negrito (D:73) | ≈ **decisão**: o fundo pastel do usuário fica; selecionado com borda 3px azul e negrito; ícone neutro (pastel como ícone ficaria ilegível). **E8b**: a marca agora aparece de fato (o `carregarPainel` a apagava); "Todas as pastas" selecionada como no dc (fundo #e8f3f9, texto azul, peso normal, sem faixa, 36px) | não |
| LX-TRI-06 | Contagem 22×18 #e9eff3/#34505f 11px 700 raio 3 (D:73) | **✓ E8**; recolhido: escondida (antes também) | não |
| LX-TRI-07 | Hover: "+" e lápis 22px sobre a contagem (D:73) | ≈ visual **✓ E8** (sem ocupar o nome; focáveis); o "+" cria **sub-marcador** (o do dc cria marcador de topo) — hierarquia mantida por decisão | não |
| LX-TRI-08 | Botão de recolher: círculo 28 #0a6fa0, `right:-14px`, sombra (D:76) | **✓ E8** | não |
| + | Sub-marcadores, gaveta no celular | só BlueJus (decisão: hierarquia mantida) | — |

## 3. Barra do acervo, filtros, paginação

| ID | Elemento (D:L) | BlueJus | Backend? |
|---|---|---|---|
| LX-BAR-01 | Barra #f7f9fb, borda #e6edf2, 12/22 (D:80) | **✓ E8** | não |
| LX-BAR-02 | Título 17px normal + pasta; clique volta ao acervo zerado (D:81) | ≈ visual ✓; o clique de "voltar com filtros zerados" não foi feito (comportamento novo) | não |
| LX-BAR-03 | Nova Pasta 36px #e2e9ee/#243845 700; hover azul, cresce, sombra (D:82) | **✓ E8** (abre o modal de sempre) | não |
| LX-BAR-04 | Situação: botão com ponto pulsante e pop de 5 opções (D:83-95) | ≈ select nativo no visual do dc; Suspenso/Cancelado não existem | **sim** (Suspenso/Cancelado) |
| LX-BAR-05 | Busca 34px #edf2f6 sem borda, raio 4 (D:100) | **✓ E8** (o foco continua visível — o dc tira) | não |
| LX-BAR-06 | "Sem responsável" no filtro de responsável (D:102-107) | **✓ E8** — `responsavel=sem`, preso ao escritório, com testes (decisão do dono) | feito |
| LX-BAR-07 | Responsável em pop com avatar, busca e cabeçalho azul (D:102-107) | ≈ select nativo no visual do dc | não |
| LX-BAR-08 | Prioridade em pop com bandeiras, NORMAL/ALTA/URGENTE (D:108) | ≈ select; **rótulo do sistema** ("Prioridade") por decisão | não |
| LX-BAR-09 | Período num bloco só, "a" entre as datas (D:109) | ✓ **E8b** — bloco de 34px no fundo do campo, "a" #7d909d (era "–") | não |
| LX-BAR-10 | Limpar: ícone `bi-x-circle` (D:110) | ≈ chips de filtro + "limpar" (só BlueJus, mais informativo) | não |
| LX-BAR-11 | Visualizar: 5 modos + "Pastas por página" 25/50/100 (D:112-129) | ≈ 2 modos (tabela/cartões) — novos modos não autorizados; 25/100 | **sim** (`PER_PAGE=50`) |
| LX-BAR-12 | Paginação 26px sem borda, ativa #0a6fa0, hover cresce, sempre visível (D:131-135) | **✓ E8** (+ a de rodapé, só BlueJus) | não |
| LX-BAR-13 | Resumo "1 de 50 · Total: 1.203 pastas" (D:139) | **✓ E8** (antes contava só a página) | não |

## 4. Tabela

| ID | Elemento (D:L) | BlueJus | Backend? |
|---|---|---|---|
| LX-TAB-01 | Cabeçalho #f4f7f9, 11px 700 1.3px #4a6373, ativo azul, sombra (D:146-150) | **✓ E8** | não |
| LX-TAB-02 | Cabeçalho fixo (`sticky top:124px`) que rola junto na horizontal (D:145, `syncCab`) | ≈ não fixa. Medido 15/10: três contêineres de rolagem entre o `<thead>` e a janela (`.table-responsive` x/y auto, `.expediente-content` auto, `main.app-main` auto do AdminLTE). Caminho: cabeçalho clonado com `scrollLeft` sincronizado, como o `syncCab` do dc — entrega própria, mexe em layout herdado | não |
| LX-TAB-03 | Colunas 96 · 2fr · 1.5fr · 120 · 220 · 76 · 190 · 116, mín. 1100 (D:146) | ≈ **✓ E8** nas fixas; **E8b**: Cliente e Ação em 2:1.5 da sobra (185/139 a 1440px). Fica ≈ por um ponto: os mínimos do dc (`minmax(220px…)`, `minmax(190px…)`) somam 1228px e estouram a tabela do próprio dc a 1440px (Situação cortada, medido), contra o `min-width:1100px` dele; aplicá-los traria rolagem lateral a 1440px. **Decisão do dono** | não |
| LX-TAB-04 | Linha: padding 14, divisória #eef2f5 (D:154) | **✓ E8** | não |
| LX-TAB-05 | Hover azul #3b8fc2, faixa 4px #1f6f9c, sombra, tudo branco (D:28-35) | **✓ E8** (a lápide fica de fora: não abre por clique) | não |
| LX-TAB-06 | NUP 14px 700 #0a6fa0 (D:155) | **✓ E8** (+ estrela de favorito, só BlueJus) | não |
| LX-TAB-07 | Cadeado na linha (D:155) | ∅ | **sim** (sigilo por funcionário, §3.12) |
| LX-TAB-08 | Cliente 14px 700 #12242f, reticências (D:156) | **✓ E8** (mostra o identificador da pasta, regra do BlueJus) | não |
| LX-TAB-09 | Selo "Nx" = pastas com o mesmo nome e CPF (D:156) | ∅ | **sim** (cálculo inexistente) |
| LX-TAB-10 | Ação 13px #1f3644 (D:157) | **✓ E8** | não |
| LX-TAB-11 | Prioridade: chip 24px raio 3, bandeira, cores do `PRIO` (D:158, 431) | **✓ E8** (rótulo do sistema; sem o pulso antigo do urgente) | não |
| LX-TAB-12 | Responsável: botão 34px, avatar 28, hover branco com borda #cfe0ea (D:159-160) | **✓ E8** | não |
| LX-TAB-13 | Menu de responsável 300px, cabeçalho azul e busca (D:161-166) | ≈ menu compartilhado com a Pasta (`_resp_menu`), não mexido | não |
| LX-TAB-14 | Metas: ícone + nº de metas **abertas** em azul; "–" (D:168) | ≈ **decisão**: nº = **total**; visual do dc (azul, sem fundo), com cor de alerta para revisão/todas concluídas | não |
| LX-TAB-15 | Marcador: chip 24px, pasta cheia, 11px 700, máx 150px (D:169) | **✓ E8** (fundo = pastel do usuário; nomes de todos no `title`) | não |
| LX-TAB-16 | Pop "MARCADOR" de um só marcador + "Remover marcador" (D:170-181) | ≈ **decisão**: vários marcadores e hierarquia — o modal "Mover para" de sempre | não |
| LX-TAB-17 | Situação: ponto 7px pulsante + rótulo 12.5px 700 na cor (D:182, 432) | **✓ E8** (Ativo/Arquivado; lápide "Excluída" em vermelho; duplo clique mantido) | não |
| LX-TAB-18 | Situações Suspenso e Cancelado (D:432) | ∅ | **sim** |
| LX-TAB-19 | Destaque de linha (6 cores, `localStorage`) (D:154, 376) | ∅ — inferência do protótipo, contradiz o destaque do Farlei (§3.7) | **sim** + decisão |
| LX-TAB-20 | Modo "Lista simples" (D:189-201) | ∅ — novo modo não autorizado | não |
| LX-TAB-21 | Cartões e cartões grandes, raio 4 (D:202-213) | ≈ raio 4 **✓ E8**; layout do cartão é o do BlueJus | não |

## 5. Estados, menus, avisos

| ID | Elemento (D:L) | BlueJus | Backend? |
|---|---|---|---|
| LX-EST-01 | Vazio: `bi-folder-x` 28px + "Nenhuma pasta encontrada com esses filtros." (D:214) | **✓ E8** | não |
| LX-EST-02 | Dica padronizada (`bj-dica.js`) (D:312, 355) | ∅ — global, pendente desde a E1 (`base.html.twig`) | não |
| LX-EST-03 | Aviso flutuante canto inferior direito #0f202b, ✓ #4fd18a, 2,8 s (D:311) | **✓ E8** (ao copiar) | não |
| LX-EST-04 | Esc fecha menus (D:360) | **✓ E8** | não |
| LX-EST-05 | F2 = editar nome com o menu aberto (D:360) | ∅ — a rota `pasta_edit` reativaria pasta arquivada; inferência não autorizada | **sim** |
| LX-EST-06 | Assistente de pastas e Chat I.A. Central (`bj-agente.js`, `bluejus-central.js`) | ∅ | **sim** (`/api/agente/*`) |
| LX-CTX-01 | Botão direito na linha: Abrir pasta (D:272-293) | **✓ E8** | já existe |
| LX-CTX-02 | … Abrir em nova aba | **✓ E8** | já existe |
| LX-CTX-03 | … Alterar responsável | **✓ E8** (aciona o chip da linha; fora na lápide) | já existe |
| LX-CTX-04 | … Alterar marcador | **✓ E8** (aciona o botão da linha; fora na lápide) | já existe |
| LX-CTX-05 | … Copiar número | **✓ E8** | não |
| LX-CTX-06 | … Copiar nome do cliente | **✓ E8** (copia o identificador da pasta) | não |
| LX-CTX-07 | … Editar nome rápido (F2) | ∅ | **sim** |
| LX-CTX-08 | … Trancar / Destrancar (só primário) | ∅ | **sim** |
| LX-CTX-09 | … Destacar linha | ∅ | **sim** |
| LX-CTX-10 | Botão direito no marcador: Ver pastas (D:233-243) | **✓ E8** | já existe |
| LX-CTX-11 | … Novo marcador | **✓ E8** (+ "Novo sub-marcador", só BlueJus) | já existe |
| LX-CTX-12 | … Editar | **✓ E8** | já existe |
| LX-CTX-13 | … Duplicar | ∅ (sem descrição de marcador; não autorizado) | parcial |
| LX-CTX-14 | … Excluir | **✓ E8** (pelo modal Editar e a janela da E7) | já existe |

## 6. Modais

| ID | Elemento (D:L) | BlueJus | Backend? |
|---|---|---|---|
| LX-MOD-01 | Nova Pasta 520px arrastável, cabeçalho azul (D:218-232) | ≈ modal Bootstrap de sempre (não mexido) | não |
| LX-MOD-02 | … "PASTA {próximo NUP}" e cliente obrigatório com sugestões | ≈ **decisão**: número só no servidor, cliente opcional | — |
| LX-MOD-03 | Novo/Editar marcador 420px: descrição, 10 cores saturadas, nome único (D:244-258) | ≈ modais de sempre; descrição, cores e unicidade | **sim** |
| LX-MOD-04 | Excluir marcador: alertdialog vermelho, "Excluir mesmo assim" (D:259-271) | ✓ **E8b** — moldura do dc medida valor a valor (430px, faixa 5px #c0392b, círculo 64px #fbecec, título 17px centrado, botões 38px no centro, sem X, fundo rgba(14,37,51,.38)); conteúdo da E8 (sub-marcadores entre parênteses, sem travessão). O negrito em "irreversível" e "N pasta(s)" do dc não entra: a janela escreve texto puro (`textContent`) | não |
| LX-MOD-05 | Sigilo: "Pasta N protegida", Entendi / Pedir acesso (D:294-310) | ∅ | **sim** |

## 7. Contagens (contado no arquivo, 15/10, depois da E8b; depois da E8 era 36 · 22 · 13)

| Região | ✓ | ≈ | ∅ | Total |
|---|---|---|---|---|
| Página e cabeçalho | 2 | 2 | 0 | 4 |
| Trilho | 5 | 3 | 0 | 8 |
| Barra, filtros, paginação | 7 | 6 | 0 | 13 |
| Tabela | 10 | 6 | 5 | 21 |
| Estados e avisos | 3 | 0 | 3 | 6 |
| Menus de botão direito | 10 | 0 | 4 | 14 |
| Modais | 1 | 3 | 1 | 5 |
| **Total** | **38** | **20** | **13** | **71** |

Antes da E8 (o mesmo inventário, sem as marcas da E8): ver `E8-PLANO.md`. Os ∅ seguem na lista com a dependência — nenhum foi descartado.
