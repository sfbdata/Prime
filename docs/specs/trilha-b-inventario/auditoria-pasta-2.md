# Auditoria 2 de fidelidade visual: Pasta × `02 - EXPEDIENTES 1.2.3.dc.html`

Feita em 05/10, só leitura, sem navegador. Nenhum arquivo do repositório foi alterado.

Cabeçalho, Dados e drawer foram auditados pelo orquestrador. Metas e Processo, Financeiro, e Detalhes e Push foram auditados por três subagentes read-only. Conferi por amostragem estes achados deles: Financeiro A1, Detalhes D1, Push P1/P2 e Metas M1. Todos se confirmaram.

> ⚠️ **A master andou durante a auditoria.** A base era 3a8769b0; ao fechar estava em **bc437d5e**, e um dos subagentes leu 11bf7d27. Há outra sessão ativa. Os números de linha podem ter deslocado alguns pontos; o **seletor** é a referência.

**Ficou de fora** o que já bate (Trilha A §6, Trilha B §3), o que é função nova (C/D/E) e o que depende de decisão do dono (§4 da Trilha B). O desenho só tem breakpoints em **980px** (`[data-r="grid2"]`, dc:65, já implementado em `.ps-grade`) e em **640px** (só a barra global, D-BARRA). Não existe regra de 720px no desenho da Pasta: os 720px são o `max-width` dos modais (dc:561, 602).

**Severidade:** A = visível a olho · B = fino.

---

## 0. Compartilhado (compositor usado em Dados, Detalhes e Financeiro)

| # | Onde (atual) | Atual | Desenho | Sev |
|---|---|---|---|---|
| X1 | Barra do editor: `.ps-compositor-caixa .editor-rico .ql-toolbar.ql-snow`, css:1216, tokens `--ps-editor-bar`/`-bd`, css:120–121 | fundo `#f6f9fb`, borda `#e6edf2`, padding 6px 9px. Isso desfaz o `editor-rico.css`, que já seguia o desenho | `.bjed-tb` (bj-editor.js:12): fundo `#eef2f5`, borda `#e0e7ec`, padding 6px 8px. Os valores `#f6f9fb`/`#e6edf2` vêm de `.bjed-find`/`docbar`, não da barra | B |
| X2 | Foco da caixa: `.ps-compositor-caixa:focus-within`, css:1215 | borda `#0a7aad`, anel `rgba(10,122,173,.14)` | `.bjed:focus-within`: borda `#8fbde6`, anel `rgba(15,111,196,.12)` (bj-editor.js:11) | B |
| X3 | Botão Enviar: `.ps-btn--primario`, css:353–364, no `.ps-compositor-rodape` | `#0a7aad`, hover `#086a96`, altura 34px, padding 0 13px, borda 1px | `.bjed-env`: `#0f6fc4`, hover `#0b5596`, padding 8px 18px, 13/600, sem borda | B |
| X4 | Rodapé: `.ps-compositor-rodape`, css:1192 | padding 9px 13px | `.bjed-ft`: 8px 13px | B |
| X5 | Texto digitado: `.ps-compositor-caixa .editor-rico .ql-editor`, css:1207/1218 | line-height 1.5, max-height 320px | `.bjed-c`: 1.55, 420px, `#12242f` | B |

O escopo é sempre `.ps-compositor`. Não tocar em `.ps-btn--primario` global.

---

## 1. Cabeçalho (comum às abas)

| # | Onde (atual) | Atual | Desenho (dc) | Sev |
|---|---|---|---|---|
| C1 | Menu do responsável `#pastaRespMenu`: `_resp_estilo.html.twig:28–75`, `_resp_menu.html.twig` | genérico do Expediente: raio 12px; busca solta "Filtrar…" com raio 8px; opção raio 9px, .84rem; ativa com `--bs-primary-bg-subtle`, sem ✓; avatar 24px; largura 230–300px; sombra `0 8px 28px .18` | dc:1214–1229, 2979: 310px, raio 4px, sombra `0 1px 3px rgba(0,0,0,.16)`; **tarja em gradiente** com busca branca de 36px dentro ("Filtrar responsável", 13.5px); opção 7px 9px, 13px, peso 500 (ativa 700), raio 4px; ativa `#e3f0f8` com `bi-check2` 15px `#0a7aad`; nomes em CAIXA ALTA; avatar 28px | **A** |
| C2 | Modal Editar dados `#modalEditarPasta`: `show.html.twig:~3776–3860` | Bootstrap genérico: 480px, `h6` "Editar Pasta", labels `form-label-sm`, botões `btn-secondary`/`btn-primary` sm | dc:560–590: 720px, raio 4px, sombra `0 24px 60px -20px rgba(10,40,60,.45)`; cabeçalho com gradiente `180deg #0d80a3→#0b6f8f`, círculo de 38px com `bi-folder2-open`, título 15/700 em maiúsculas com .04em e subtítulo `#d7eef6`; rótulos 10.5/700, maiúsculas, .08em, `#6b8494`; rodapé com "Fechar" (`#eef2f5`/`#34505f`, 34px, 13/700) e "Salvar". **Só a moldura**: o conjunto de campos (Ação só leitura, Tipo de documento) é D-META | **A** |
| C3 | "Sem responsável" no chip: `_resp_chip.html.twig:17–18` | `bi-person`, `--bs-tertiary-bg`, `text-muted` | dc:2984–2985: `bi-person-dash`, `#eef3f6` + `inset 0 0 0 1px #d8e2e8`; nome 14.5/700 `#6b8494` | B |
| C4 | Selo da aba: `_cabecalho.html.twig:466` (`if aba.contagem`) | Metas e Documentos escondem o 0 | dc:6074–6079: Metas `String(length)` mostra "0"; Documentos também. Push só com >0, que já bate | B |
| C5 | `PASTA nnnn`: `_cabecalho.html.twig:43` | separados por espaço (~4px) | dc:1052: `inline-flex`, baseline, `gap:6px` | B |
| C6 | Divisória das setas: `.ps-cab-nav`, css:387 | `border-right` `--ps-div-strong` `#eef2f5` | dc:1083: `#e6edf2` (dá para usar `--ps-editor-bar-bd` ou um token próprio) | B |
| C7 | Interruptor de Favoritos: `<style>` no `_cabecalho.html.twig:525` | trilho `#cfd9e0` fixo, sem par escuro | o desenho é `#cfd9e0`; no escuro fica claro demais. Usar token com par escuro | B (escuro) |
| C8 | Valor vazio na faixa ("Nenhum processo.", "Vincule…"): `.ps-dado--vazio`, css:843 | `#8496a3` | dc:1194 e `cliAcaoStyle` (dc:5793): `#8a9aa6` | B |

---

## 2. Aba Dados

| # | Onde (atual) | Atual | Desenho (dc) | Sev |
|---|---|---|---|---|
| D1 | Responder: `.ps-registro .ps-anotacao-acao--responder`, css:1375 | sempre visível em todo cartão | dc:19 + 1311 (`data-acao`): `opacity:0`, aparece no `:hover`/`:focus-within` do cartão (.15s); `@media (hover:none)` sempre visível. O lápis segue sempre visível (sem `data-acao`) | **A** |
| D2 | Clientes do trilho: `_dados_trilho.html.twig:161–180` (`clientes-outros-toggle` + `collapse`), css:1998–2009 | só o principal aparece; os outros ficam atrás de "mostrar outros N" | dc:1376–1389: todos os clientes listados direto (principal primeiro, fundo `#fbfdfe`). A rolagem de 232px pode continuar na lista inteira | **A** |
| D3 | Ícone do documento no trilho: `.ps-doc-icone`, css:1729 | caixa 25px, ícone 22px | dc:6176 `this.fi(d.ext, 30)` → dc:3060–3062: caixa 30×30, ícone 30px, `line-height:1` | **A** |
| D4 | Histórico (drawer), hora: `_historico_drawer.html.twig:70`, `.ps-hist-hora`, css:1829 | hora numa coluna à direita | dc:2774–2775: quando fica **embaixo** do texto, 11.5px `#8496a3`, margin-top 2px | **A** |
| D5 | Drawer, cabeçalho: `.ps-drawer-cab`, css:1788–1808 | 16px 20px, borda `#eef2f5`; ícone + h2 14/600 + pílula de contagem; fechar sem borda (`bi-x-lg`) | dc:2757–2762: 20px 22px, borda `#e4eaef`; h2 15/600 + subtítulo "N alterações registradas automaticamente" (12.5 `#6b8494`), sem ícone; fechar com borda 1px `#dde5eb`, fundo branco, `#2a4353`, "×" 15px, padding 6px 11px, hover borda/cor de destaque | B |
| D6 | Drawer, dia e item: `.ps-hist-dia`/`.ps-hist-item`/`.ps-hist-corpo`, css:1811–1828 | dia 10.5/700 .1em, padding 12px 20px 6px; item 8px 20px sem divisória; texto 13px | dc:2764–2774: corpo padding 4px 22px 28px; dia 11/**500**, .11em, `#7b93a2`, padding 16px 0 7px; item 9px 0 com `border-bottom #f4f7f9`; texto 13.5px | B |
| D7 | Contagem do registro: `_dados_anotacoes.html.twig:46` | `title="Registros"` | dc:1289: "Registros principais (respostas não contam)" | B |
| D8 | "Abrir cliente" no hover: css:1997 | só fundo e cor | dc:6127–6129: ícone vira `bi-folder2-open`, `translateY(-1px) scale(1.15) rotate(-6deg)` e `drop-shadow`. O transform dá para fazer só com CSS; a troca do ícone exige um segundo `<i>` | B |
| D9 | `.ps-vinc-vazio > i`, css:1667 | `#a8661a` sem par escuro | — | B (escuro) |

Já batem: compositor (exceto o §0), linha do tempo, ponto, seta, pílula do dia, faixa de resposta, cabeçalhos do trilho, prazos (pip, selo, ⋮ de 22px), busca inline, documentos (exceto o tamanho do ícone) e "Ver anotações anteriores".

---

## 3. Aba Metas (subagente)

| # | Onde | Atual | Desenho | Sev |
|---|---|---|---|---|
| M1 | `_metas.html.twig:26` | contagem ao lado de "Metas da pasta" | dc:1417–1425: sem contagem (o total já está em "Todas N") | **A** |
| M2 | "Precisa de atenção": `.ps-trilho .ps-card-cab` (css:1109–1116) vence `--painel` | fundo `#eef2f5`, borda `#e0e7ec`, h2 700 .01em, min-height 46px | dc:1540–1541: `#f5f6f7`, `#dfe4e8`, h2 13.5/**600** | B |
| M3 | `.ps-meta-num`, css:2872 | `#0b5f86` | dc:1432: `#0b6a96` | B |
| M4 | `.ps-meta-linha i`, css:2879 (calendário) | 12px, opacity .8 | dc:1443: 11px, sem opacity | B |
| M5 | `.ps-pop--meta`, css:2940 | padding 6px | dc:3582: 5px | B |
| M6 | `.ps-pop--meta .ps-pop-item i` | `#5f7684` | dc:1460/3554: herda `#243845` | B |
| M7 | `.ps-pop--sino`, css:2967 | sombra `0 1px 3px .16` | dc:4366: `0 10px 30px rgba(16,42,58,.2)` | B |
| M8 | `.ps-pop--sino .ps-pop-secao`, css:2968 | 6/10/4, .08em, `#8496a3` | dc:292: 6/10/5, .06em, `#7b93a2` | B |
| M9 | "Nova meta" (`.ps-btn--primario`) | gap 7px, ícone 14px, hover `#086a96` | dc:1425: gap 8px, 13px, hover `#0b5f86` | B |
| M10 | `_metas.html.twig:41`, `title` | "Nova meta" | "Nova meta para esta pasta" | B |
| M11 | vazio do filtro (`.ps-vazio`) | padding 40px 24px | dc:1514: 52px 24px | B |
| M12 | `.ps-meta-linha > .ps-meta-prazo--atraso`, css:2880 | `--ps-danger-dot` `#c0392f` também no escuro | token com par escuro (`#f08a84`) | B (escuro) |

---

## 4. Aba Processo (subagente)

| # | Onde | Atual | Desenho | Sev |
|---|---|---|---|---|
| P1 | `processo/_notas_tecnicas.html.twig:31–42` + `nota-tecnica.css:59–79` | faixa "NOTAS TÉCNICAS 0 · aviso · Adicionar" sob **todo** cartão | dc:1693/1710: o bloco só aparece com nota ou com o editor aberto; a entrada é o ⋮, que já tem "Adicionar nota técnica" (`_processos_vinculados:143`). **Confirmar com o dono**, porque a faixa carrega o aviso "a nota é do processo" | **A** |
| P2 | `.ps-notas` + `::before`, nota-tecnica.css:38, 47–53 | margem 6/0/14/30; conector -2px/20px/raio 4px | dc:1694–1695: -4/0/10/30; -6px/22px/raio 6px | B |
| P3 | ordem no `_notas_tecnicas:44` × `:56` | editor antes das notas | notas primeiro, depois o editor | B |
| P4 | Cancelar da nota, nota-tecnica.css:219 | `.ps-btn` com moldura | dc:1715: `#e6edf2`, sem borda, `#243845`, hover `#dbe4ea`; hover do Salvar `#0b5596` | B |
| P5 | `.ps-nota-texto`, nota-tecnica.css:145 | `#243845` | dc:1705: `#1d2f3a` | B |
| P6 | `.ps-card-cab--painel` em Processo | gap 10px | dc:1576: 12px | B |
| P7 | Peticionar (`--ps-shadow-caps`, css:115) | sombra `rgba(10,122,173,.25)`, ícone 14px, hover `#086a96` | dc:1583: `0 1px 2px rgba(0,0,0,.25)`, 13px, `#0b5596` | B |
| P8 | `.ps-processos .ps-copiar`, css:2688 | `<i>` solto | dc:1596: alvo de 28×28, raio 4px, hover `#e5f1f7`/`#0a7aad` (só CSS no `<i>`) | B |
| P9 | ponto da situação ativa, css:2671 | anel de 3px | dc:1617: sem anel, só as ondas | B |
| P10 | `.ps-processo-mais:hover`, css:2719 | `#e1eef6` | dc:1641: `#d6eaf4` | B |
| P11 | `.ps-processo-detalhes`, css:2732 | `#f7fafc` | dc:1648: `#f5f8fa` | B |
| P12 | `.ps-processo-menu`, css:2790/2799 | 250px, sombra .14 | dc:4366: 230px, sombra .2 | B |
| P13 | `.ps-dado--vazio` no cartão (`_processos_vinculados:180/190/196`) | 14px/500 `#8496a3` | dc:2871: 13.5/500, **itálico**, `#9aabb6` (token com par escuro; escopar em `.ps-processos`) | B |
| P14 | `_processos_vinculados:220, 262` | "Distribuição" | dc:2897: "Última distribuição". **Só trocar se o dado for de fato a última distribuição**, senão registrar no §5 | decisão |

---

## 5. Aba Financeiro (subagente)

| # | Onde | Atual | Desenho | Sev |
|---|---|---|---|---|
| F1 | `.ps-fin-corpo`, css:2065 (column + flex-end) | o selo `inline-flex` vira item de flex e **estica na largura** do card (conferido: sem `align-self`) | dc:1738/1745: linha, `align-items:center`, gap 9px; chip na largura natural. Usar o modificador `.ps-fin-corpo--selo` nos dois primeiros blocos (`_financeiro:41,63`) | **A** |
| F2 | ícone dos arquivos: `.ps-fin-doc .ps-doc-ext` 24×29, css:2151; Twig :284, JS `criarItemDoc` :715 e renomear :913 | caixinha com a sigla | dc:~6549 `fi(ext,30)`: ícone colorido do tipo, 30px. Reusar `arquivo_icone()`, como o trilho (ver D3) | **A** |
| F3 | `.ps-fin-rodape`, css:2169 | `border-top` | dc:1855/1923: sem borda | **A** |
| F4 | "Adicionar pagamento" (`.ps-btn.ps-fin-btn-largo`, twig:456) | neutro com moldura, 34px | dc:1924: 32px, sem borda, `#e5f1f7`/`#0b5f86`, 13/500, gap 7px, ícone 12px, hover `#d8eaf3`/`#0a7aad` (token novo com par escuro) | **A** |
| F5 | vazio de Pagamentos, `_financeiro_pagamentos:15–20` | `.ps-vazio` com ícone; o total e "Próximos" somem | dc:1873–1880, 1886–1890, 1920: total "R$ 0,00 recebidos de R$ 0,00" com barra, "nenhum pago", linha "Nenhum lançamento. Use Adicionar pagamento." (14px 17px, 12.5px `#8496a3`) | **A** |
| F6 | `PastaPagamentoLinhaOutput.php:45` | sem estado "Vencida" (`tom` ok/proximo) | dc:3440: "Vencida" `#b03a2e`/`#fbeceb`/borda `#f1c9c4`, texto "venceu dd/mm/aaaa · há N dias". O dado já existe (`estaVencido`), e `.ps-selo--urgente` também. É regra de **apresentação** no DTO e exige ajustar o teste | **A** |
| F7 | `.ps-fin-selo--neutro`, css:2109 | `#4a6274`/`#eef2f5` | dc:6567: `--ps-text-3`/`--ps-bg`, borda `#dde5eb` | B |
| F8 | `.ps-fin-switch-trilho`, css:2197 | `--ps-border`, .2s | dc:6314: `--ps-border-ctl`, .26s `--ps-ease` | B |
| F9 | nota do "Reduzir" (`.ps-fin-sub`, twig:381 e JS:657) | margin-top 3px; "— recomendado" | dc:1860/6562: 5px; ", recomendado para contratos assinados." | B |
| F10 | lista de arquivos (`#financeiro-docs-lista`, `.ps-fin-doc`, css:2140–2179) | borda `#eef2f5`, linha 9px 17px, separador `#f4f7f9` | dc:1836/6329: borda `#e7edf2`, 9px 11px, `#f1f4f7` (token novo com par escuro) | B |
| F11 | `.ps-pag-selo` | sem borda, 3px 9px | dc:3434–3441: 2px 8px + borda do tom (`--ps-ok-border`/`--ps-warn-border`), escopada | B |
| F12 | `.ps-pag-linha`, css:2239 | gap 10px, borda também na 1ª linha, sem hover | dc:3464/1893: gap 12px, borda só entre linhas, hover `#f6f9fb` | B |
| F13 | `.ps-pag-barra`, css:2223 | `#eef2f5` | dc:1878: `#e7edf2` | B |
| F14 | "já pagos", `_financeiro_pagamentos:37–39` | some com zero | dc:3502: sempre, com "nenhum pago" | B |
| F15 | contagem de Arquivos, twig:257–259 e JS:678–682 | some com zero | dc:1831: sempre, inclusive 0 | B |
| F16 | `.ps-fin-doc-vazio`, css:2153 | 16px 17px | dc:1851: 14px 12px | B |
| F17 | "ver todos" no cabeçalho de Pagamentos, twig:402–405 | link no cabeçalho; lista todos os pendentes | dc:1865–1871/3512: só ⋮ (o item já existe no menu, twig:426); mostra os 3 próximos não pagos. O alternador do link precisa ir para o menu (`_financeiro:1008–1017`, `_pagamentos_script:52–57`) | B |
| F18 | `PastaPagamentoLinhaOutput.php:62–68` | "atrasado N dias", "amanhã", "em N dias" até 30 | dc:3440–3441: "venceu … · há N dias", "em N dia(s)" até 7, sem "amanhã" (junto com F6) | B |
| F19 | placeholder, twig:155 | "Registrar combinado, honorário…" | dc:1787: "Adicionar observação…". Confirmar se a dica atual foi escolhida de propósito | B |

---

## 6. Aba Detalhes (subagente)

| # | Onde | Atual | Desenho | Sev |
|---|---|---|---|---|
| T1 | `.ps-detalhes-rolagem`, css:3107 (`_detalhes_obs:56`) | `max-height:520px` com rolagem interna (conferido) | dc:2316: sem teto, cresce com a página | **A** |
| T2 | edição de observação (JS `_detalhes_obs:290–303`) | Salvar (primário) + Cancelar (com moldura) fora do editor, à esquerda | `bj-editor`, modo edição: no rodapé do editor, "Visível para a equipe" · espaço · Cancelar (transparente `#4f6878`) · Salvar (`#0f6fc4`) à direita | **A** |
| T3 | `.ps-btn span {display:none}` <768px, css:1849 | "Ver histórico do sistema" (e "Adicionar nota técnica" do Push) vira só ícone | o rótulo nunca some. Exceção para `.ps-btn--largo span` / `.ps-btn--nota span` | **A** (<768px) |
| T4 | `.ps-btn--largo`, css:3116 | `justify-content:center`, padding 0 13px | dc:2386: alinhado à esquerda, 0 12px | **A** |
| T5 | `[data-bs-theme=dark] .ps-avatar--24` (css:863) vence `.ps-avatar--neutro` (3095) | "Criada por" fica azul no escuro | neutro nos dois temas | B (escuro) |
| T6 | `.ps-registro .ps-anotacao-texto`, css:1318 | `#2b3a44` | `bj-rich` `#243845` | B |
| T7 | `.ps-como-usar`, css:3068 (compartilhada com Metas) e strong, 3119 | 16px 17px; strong 600 | dc:2399: 17px; negrito 700 | B |
| T8 | iniciais das observações (`_detalhes_obs:68–70,147`) | primeiro + último nome | dc:6475: duas primeiras palavras. Discutível: perguntar | B / decisão |

---

## 7. Aba Push (subagente)

| # | Onde | Atual | Desenho | Sev |
|---|---|---|---|---|
| U1 | `.ps-push-ponto` `top:25px` relativo ao `<li>` (css:2424), que inclui a pílula do dia | no 1º cartão de cada dia o ponto fica **ao lado da pílula**, não do cartão | dc:2511–2512: wrapper `position:relative;margin-top:10px` só em volta do cartão, ponto em `top:13px` (alinhado à seta). Envolver ponto + `.ps-push-cab` (`_push_processual`); `pasta-push.js` não muda | **A** |
| U2 | `.ps-push-item--nova .ps-push-ponto`, css:2435 | ponto **verde** | dc:5372: cartão com `inset 3px 0 0 #0a7aad` e ponto sem mudança; o verde é "polo ativo" na legenda | **A** |
| U3 | `.ps-push-titulo`, css:3124 | pílula azul única, 10.5px, 2px 8px | dc:5365/5378: 10px/800, .05em, 2px 7px, **cor por tipo**: Intimação `#b42318`/`#fdecea`; Decisão/Despacho/Sentença `#0b5f86`/`#e3f0f8`; Citação `#1e7b45`/`#e6f4ec`; demais `#4f6878`/`#eef2f5` (modificador no Twig a partir de `tipoComunicacao`) | **A** |
| U4 | vazios, `_push_processual:92–110` | `.ps-vazio` com ícone, título e nota | dc:2424/5426: linha simples, 28px 20px, 13px `#6b8494`. A forma é sanável; trocar o **texto** pede ok do dono (o atual explica a OAB monitorada) | **A** |
| U5 | ponto, css:2424–2433 | 12px, left 16px | dc:5373: 16px (left equivale a 13px no `li`) | B |
| U6 | `.ps-push-cab`, css:2436–2453; `.ps-push-corpo`, 2478 | gap 8px, 12px 14px, raio 4px, sombra .08; corpo `center` | dc:5372/2523: gap 7px, 12px 14px 9px, raio 3px, sombra `.12`; `flex-end` | B |
| U7 | `.ps-push` sem `overflow:hidden` | cantos do cabeçalho e da lista vazam sobre o raio | dc:2411: `overflow:hidden` (conferir se o ⋮ das análises de IA não é cortado) | B |
| U8 | `.ps-card-cab--faixa h2`, css:2622; contagem `_push_processual:21` | .01em; contagem só com >0 | dc:2413: sem letter-spacing; sempre `push.total` | B |
| U9 | botão de lida (`_push_teor:73–74`, `pasta-push.js:162–164`) | "Marcar como não lida" + `bi-envelope` | dc:5384: "Lida" + `bi-check2-all` (o desfazer pode ir para o `title`). Decisão leve | B |
| U10 | notas técnicas no teor (nota-tecnica.css) | mesmos itens de P4/P5 | idem | B |
| U11 | `.ps-paineis` margin-top 18px (css:1069) | 18px também no Push | dc:6595: 16px no Push | B |

---

## Contagem

| Aba | A | B | Total |
|---|---|---|---|
| Compartilhado (compositor) | 0 | 5 | 5 |
| Cabeçalho | 2 | 6 | 8 |
| Dados (inclui o drawer) | 4 | 5 | 9 |
| Metas | 1 | 11 | 12 |
| Processo | 1 | 13 | 14 |
| Financeiro | 6 | 13 | 19 |
| Detalhes | 4 | 4 | 8 |
| Push | 4 | 7 | 11 |
| **Total** | **22** | **64** | **86** |

**Dependem de ok do dono antes de mudar:**
- P1 (faixa das notas técnicas)
- P14 (rótulo da distribuição)
- F19 (placeholder do Financeiro)
- T8 (iniciais das observações)
- U4 (só o texto do vazio)
- U9 (rótulo "Lida")

---

## LOTES (sem sobreposição de arquivo)

Quase todo item toca `pasta-show.css`. Para não ter dois escritores no mesmo arquivo, o trabalho vai em **duas fases**.

**Fase 1: lote CSS único (sequencial).** Um implementador, só `pasta-show.css`. Ele cria **antes** as classes e os tokens que os lotes de template vão usar (contrato abaixo).

- **L0 · `app/public/css/pasta-show.css`**
  - itens: X1–X5, C6, C8, D1, D3, D5, D6, D8, D9, M2–M9, M11, M12, P6–P13, F1, F3, F4, F7, F8, F10–F13, F16, T1, T3–T7, U2, U5–U8, U11, e o CSS de C1 (`.ps-body .pasta-resp-menu…`, escopado, sem tocar `_resp_estilo`, que é do Expediente);
  - tokens novos, cada um com par escuro: `#e7edf2`, `#d8eaf3`, hover `#f6f9fb`, vazio itálico `#9aabb6`, prazo em atraso, trilho do interruptor e tons do tipo do Push;
  - **contrato de classes** a publicar no commit:

    | Classe | Uso |
    |---|---|
    | `.ps-fin-corpo--selo` | selos Contrato e Pró-bono (F1) |
    | `.ps-fin-btn-add` | botão "Adicionar pagamento" (F4) |
    | `.ps-push-cartao` | wrapper do ponto + cartão (U1) |
    | `.ps-push-titulo--intimacao/--decisao/--citacao/--outro` | pílula do tipo (U3) |
    | `.ps-push-vazio-linha` | vazio em linha (U4) |
    | `.ps-pag-vazio-linha` | vazio de Pagamentos (F5) |
    | `.ps-hist-sub` | subtítulo do drawer (D5) |
    | `.ps-hist-fechar` | botão fechar do drawer (D5) |
    | `.ps-modal-pasta` (+ `-cab`, `-rotulo`, `-rodape`) | moldura do Editar dados (C2) |
    | `.ps-anotacao-edicao-rodape` | rodapé de edição em Detalhes (T2) |

**Fase 2: lotes de template (paralelos, cada um com arquivos exclusivos).** Só começam depois do L0 commitado.

| Lote | Arquivos exclusivos | Itens |
|---|---|---|
| L1 · Cabeçalho/modal/drawer | `pasta/_cabecalho.html.twig`, `pasta/_resp_chip.html.twig`, `pasta/_resp_menu.html.twig` (só o ✓/placeholder; verificar se o Expediente aceita, senão é flag por contexto), `pasta/_historico_drawer.html.twig`, `pasta/show.html.twig` (só `#modalEditarPasta`) | C2, C3, C4, C5, C7, D4, D5, D6 |
| L2 · Dados | `pasta/_dados_trilho.html.twig`, `pasta/_dados_anotacoes.html.twig`, `pasta/_cliente_linha.html.twig` e o espelho JS da linha de cliente | D2, D7, D8 (2º ícone) |
| L3 · Metas | `pasta/_metas.html.twig` | M1, M10 |
| L4 · Processo + notas | `pasta/_processos_vinculados.html.twig`, `processo/_notas_tecnicas.html.twig`, `public/css/nota-tecnica.css` | P1 (após ok), P2–P5, U10, P14 (após ok). Mantém o contrato `.js-nota-*` com o `nota-tecnica.js`, que o teor do Push também usa |
| L5 · Financeiro | `pasta/_financeiro.html.twig`, `_financeiro_pagamentos.html.twig`, `_financeiro_pagamentos_script.html.twig`, `src/Pasta/DTO/PastaPagamentoLinhaOutput.php` + o teste dele | F1, F2, F4, F5, F6, F9, F14, F15, F17, F18, F19 (após ok) |
| L6 · Detalhes | `pasta/_detalhes_obs.html.twig` | T1 (tirar a classe, se a regra ficar), T2, T8 (após ok) |
| L7 · Push | `pasta/_push_processual.html.twig`, `pasta/_push_teor.html.twig`, `public/js/pasta-push.js` | U1, U3, U4, U8, U9 (após ok) |

**Antes de cada lote**, o implementador confere o HTML gerado (contar as tags, porque a suíte é cega para HTML desbalanceado). Arranjo testável com combinador de filho direto:
- U1: `.ps-push-cartao > .ps-push-ponto`;
- D2: todos os `.cliente-linha` fora de `.collapse`.

Cor, raio e sombra seguem invisíveis para o PHPUnit, então ficam para o smoke do dono.
