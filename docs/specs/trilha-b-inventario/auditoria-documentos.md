# Auditoria item a item — aba **Documentos** da Pasta × Claude Designer 1.2.3

> Auditoria READ-ONLY de 06/10/2026, feita para o §9.3 de `docs/specs/trilha-b-designer.md`.
> Ela aprofunda o que já está em `trilha-b-inventario/pasta.md` §8 e em `trilha-a-visual-pje.md` §6, sem repetir esses textos.
> Nenhum arquivo do repositório foi alterado, a não ser este.

**Fontes do desenho.** `docs/design/claude-design-2026-10-05 (1)/`:

- `02 - EXPEDIENTES 1.2.3.dc.html` (abreviado **dc**):
  - template da aba: l.2020–2297;
  - lógica: l.3050–3160 (modos, colunas, Organizar), l.3714–3765 (zip, abrir), l.3996–4200 (checklist e cobrança), l.4440–4442 (favoritos), l.4600–4960 (visor e `expVals`), l.6328–6420 (montagem dos dados);
  - visor: l.299–328;
  - estado inicial: l.2793.
- `bj-docsug.js` (regras de "Sugerir documentos"), `bj-processo.js` l.158–175 (determinações do juízo), `bj-visualizar.js`.
- `design_handoff_pasta_show/README.md` §"Aba 6 — Documentos".
- Capturas: `screenshots/02-explorer3.jpg`, `03-explorer3.jpg`, `pasta-dentro2.jpg`, `02-docsug2.jpg`, `icones-docs2.jpg`.

**Fontes do sistema.**

- `app/templates/pasta/show.html.twig`:
  - aba Documentos: l.309–631;
  - modais auxiliares: l.1022–1056;
  - `#previewDocModal`: l.2327–2346;
  - `editDocModal` por documento: l.2350–2401;
  - helper de upload e duplicados: l.2612–2804.
- `templates/pasta/_documentos_sugeridos.html.twig`.
- JS e CSS:
  - `public/js/pasta-arquivos.js` (871 l.);
  - `public/css/pasta-arquivos.css` (736 l.);
  - `public/css/pasta-show.css` l.3379–3391;
  - `public/js/pasta-documentos-sugeridos.js`;
  - `public/js/visualizador-documento.js`.
- Ícones: `src/Twig/ArquivoIconeExtension.php`.
- Controller e entidades:
  - `src/Controller/PastaController.php` l.1781–1846 (editar/excluir documento);
  - `src/Pasta/Entity/PastaDocumento.php`, `PastaSecao.php`;
  - `src/Pasta/Service/SugestorDeDocumentos.php`, `CatalogoDeDocumentos.php`.
- Rotas: `debug:router` (não há rota de zip, lote, cópia ou favorito de arquivo).

**Classes.**

- **A**: já existe, falta fidelidade.
- **B**: parcialmente implementado.
- **C**: novo e implementável com a infraestrutura atual.
- **D**: exige backend, modelo ou infra nova, mas é implementável de forma autônoma.
- **E**: depende do dono (Samuel), de credencial ou de serviço externo.
- **✓**: conforme, ou função que só o sistema tem e deve ser preservada.

---

## 0. Achados de base (leia antes da tabela)

1. **A referência é o 1.2.3, e ele diverge do README de agosto.** O `design_handoff_pasta_show/README.md` §Aba 6 descreve uma versão anterior:
   - raio de 14px;
   - botão "Enviar" com `bi-upload`;
   - alternador lista/grade de 32×32;
   - botão "Manual (arrastar)";
   - checklist como faixa;
   - pastas em cartões de 210px com ícone azul `#0f6fc4`.

   O 1.2.3, aprovado em 05/10, tem outro desenho:
   - raio de 4px;
   - **"Anexar"** com `bi-paperclip`, em maiúsculas;
   - menus **Organizar** e **Visualizar** (oito modos);
   - pastas **amarelas `#f2b93b` na mesma lista dos arquivos**;
   - **nenhum controle de ordem manual**.

   Quando os dois divergem, vale o **1.2.3**. As capturas `01-explorer3.jpg` e `02-explorer3.jpg` ainda mostram "Enviar", porque são de uma rodada anterior à do template.
2. **As "sugestões de limpeza" NÃO são IA** (dc l.4686–4697, `expLimpeza`). São quatro regras determinísticas:
   - hash igual;
   - 0 KB;
   - nome de "cópia do processo" com mais de 100 páginas ou mais de 50 MB;
   - arquivo com mais de 100 MB.

   O inventário `pasta.md` §8 as classificou como **E (IA)**. Esta auditoria as reclassifica como **C**, com a parte de "páginas" em **D**.
3. **"Exigido pelo juízo" também não depende de IA.** No desenho, ele vem de regex sobre o texto de despachos e decisões (`bj-processo.js` l.158–175). O filtro de verbos é `apresent|junt|traga|comprov|exib|acost|anex|regulariz` (`bj-docsug.js` l.67–75). A IA (`refinarIA`) só refina o resultado.

   O sistema já guarda o teor do Push em `PublicacaoDjen.texto` (`src/Djen/Entity/PublicacaoDjen.php:81`). Por isso o item é **D** (serviço novo no servidor), e não E. Só a camada de IA é E.
4. **No desenho, o checklist é uma FAIXA DE LARGURA TOTAL no topo** e aparece também dentro de subpastas (`pasta-dentro2.jpg`, dc l.2079).

   No sistema, uma decisão de implementação o moveu para uma **coluna direita de 356px**, que só aparece na raiz e fora da busca:
   - comentário em `show.html.twig:515–522`;
   - JS `pasta-arquivos.js:111–116`.

   Isso é um desvio do desenho aprovado: o desenho manda.
5. **O `fm` é compartilhado com a Cobrança.**
   - `templates/cobranca/caso/_documentos.html.twig:1–5`;
   - `templates/cobranca/objeto/show.html.twig:520, 950, 2371`.

   Testes que dependem dele:
   - `tests/Cobranca/Functional/ObjetoShowControllerTest.php`, `DocumentoCobrancaControllerTest.php`, `ObjetoShowContratoJsTest.php`;
   - `tests/Pasta/Unit/PastaShowRaiosDoGerenciadorTest.php`;
   - `tests/Functional/VisualizadorDocumentoTelasTest.php`.

   Qualquer item A, B ou C que mude o `fm` tem impacto na Cobrança, a menos que seja opt-in ou que o explorador seja próprio da Pasta.
6. **Desempenho já em risco hoje.** A tela renderiza **todos** os documentos da pasta e **um `editDocModal` por documento** (`show.html.twig:2350–2351`). Uma pasta com 200 documentos gera 200 formulários modais no DOM.
7. **Dados que NÃO existem no modelo** e que o desenho usa:
   - quem enviou o arquivo;
   - data de modificação (só existe `uploaded_at`);
   - número de páginas;
   - exclusão lógica ou lixeira;
   - favorito de arquivo;
   - estado do checklist (ativo/desativado + motivo);
   - registro de cobrança do checklist.

   O `sha256` existe e tem índice `(tenant_id, sha256)` (`PastaDocumento.php:17, 73`), mas fica **NULL nos arquivos antigos** até o dono rodar `app:documentos:calcular-hash` em prod (§9.5).

---

## 1. Estrutura e layout geral

| ID | Item | Desenho (dc) | Sistema hoje | Classe | Observação |
|---|---|---|---|---|---|
| DOC-01 | Cartão da aba com faixa de cabeçalho | l.2022–2024: card `#fff`, `border:1px solid #dde5eb`, `border-radius:4px`, `box-shadow:0 1px 2px rgba(16,42,58,.04)`; faixa `padding:15px 20px; background:#f5f6f7; border-bottom:1px solid #dfe4e8` com `gap:10px`, `flex-wrap:wrap` | `.fm` sem cartão nem faixa; toolbar com `padding:10px 4px 14px` (`pasta-arquivos.css:17–22`, `show.html.twig:316–332`) | **A** | — |
| DOC-02 | Ordem dos blocos | Cabeçalho → **checklist (faixa, `margin:16px 20px`)** → explorador (`display:flex; gap:14px; padding:6px 20px 0`; lista + painel opcional de 250px) → rodapé (l.2079, 2181, 2295) | Cartão "Documentos sugeridos" fora do `fm` → toolbar → barra de upload → aviso de duplicado → grade `minmax(0,1fr) 356px` [lista \| checklist] (`show.html.twig:315–626`; `pasta-arquivos.css:505–514`) | **A** | Desvio descrito no §0.4 |
| DOC-03 | Checklist visível em qualquer nível | Visível também dentro de subpasta (`pasta-dentro2.jpg`) | Some fora da raiz e durante a busca (`pasta-arquivos.js:111–116`) | **A** | — |
| DOC-04 | Rodapé com totais | `padding:14px 20px 18px; 12.5px; #6b8494`. Raiz: "N arquivos e M pastas · T no total, contando subpastas". Na pasta: "Nome · N itens nesta pasta" (l.2295, 4868) | Não existe | **C** | O total é `pasta.documentos\|length`, que já está na aba |
| DOC-05 | Título e contagem na faixa | `bi-folder2-open` 15px `#8496a3` + "Documentos" 14px/600 + contagem 11px `#7b93a2` `tabular-nums` (l.2025–2028) | Botão de breadcrumb "Documentos", 1.05rem/600, sem contagem (`show.html.twig:334`; css l.27–33) | **A** | — |
| DOC-06 | Selo da aba "Documentos 224" | dc l.6079 | `_cabecalho.html.twig` (contagem `pasta.documentos\|length`, `mostraZero`) | **✓** | — |

## 2. Toolbar

| ID | Item | Desenho | Sistema | Classe | Tokens e observação |
|---|---|---|---|---|---|
| DOC-07 | Busca em pílula | l.2031–2035, 4838–4842 | `input[type=search]` com borda, `padding:8px 12px 8px 34px`, `.9rem`, foco `.2rem rgba(primary,.18)`, placeholder "Buscar arquivos..." (css l.65–93) | **A** | **Tokens:** altura 32px; `padding:0 6px 0 11px`; `background:#f1f5f8`; sem borda; raio 999; `min-width:230px`; ícone 12px `#8496a3`; texto 13px `#12242f`. **Foco:** `background:#fff`, `box-shadow:0 0 0 2px rgba(15,111,196,.35)`. **Comportamento:** placeholder **"Buscar arquivos e pastas…"**; botão limpar ⊗ 22px círculo `#dfe7ed`/`#455c6b` quando há texto; Esc limpa |
| DOC-08 | Botão "Nova pasta" | l.2036 | `btn btn-sm btn-primary` (azul cheio) (`show.html.twig:343`) | **A** | **Tokens:** neutro claro, altura 32, `padding:0 13px`, `background:#e5f1f7`, `color:#0b5f86`, 13px/500, raio 4, `bi-folder-plus` 14px. A hierarquia do sistema está **invertida** |
| DOC-09 | Botão principal "Anexar" | l.2037 | `btn-outline-primary` "Enviar" com `bi-upload` (`show.html.twig:344`) | **A** | **Tokens:** `background:#0a7aad`, branco, 13px/600, **MAIÚSCULAS**, `letter-spacing:.03em`, `box-shadow:0 1px 2px rgba(0,0,0,.25)`, `bi-paperclip` 13px; hover `#0b5596`. Rótulo **"Anexar"** (o README de agosto dizia "Enviar"; vale o 1.2.3) |
| DOC-10 | Menu **Organizar** | l.2038–2063, `expOrgVals` l.3140–3160 | `<select id="fmOrdenar">` com 9 opções, incluindo "Manual (arrastar)" e Categoria (`show.html.twig:350–360`) | **B** | **Botão:** `bi-sliders` 14px, altura 32, `padding:0 11px`, 12.5px/600 `#455c6b`; aberto ou com filtro: `#e5f1f7`/`#0a7aad`; selo "1" 18px `#0a7aad` quando há filtro. **Popover:** 280px, raio 4, `box-shadow:0 1px 3px rgba(0,0,0,.16)`, `padding:8px`. **Seções** (rótulo 10.5px/700, `.08em`, maiúsculas, `#7b93a2`): Ordem das colunas (linhas `#f6f9fb` com setas 26px); Classificar por (pílulas altura 28, ativa `#0a7aad`/branco, inativa `#f0f4f7`/`#455c6b`); Tipo de documento (com contagem); Restaurar padrão (`bi-arrow-counterclockwise`). A ordenação existe; colunas e filtro, não |
| DOC-11 | Menu **Visualizar** | l.2064–2076; `EXP_MODOS` l.3051–3054 | Alternador lista/grade de 2 botões, ativo `--bs-primary` (`show.html.twig:346–349`; css l.98–115) | **B** | Botão com o ícone do modo atual + "Visualizar" + `bi-chevron-down`. Popover de 230px com 8 `menuitemradio` (marcados por ponto de 6px `#0a7aad`) + separador + `menuitemcheckbox` "Painel de detalhes" (`bi-layout-sidebar-reverse`) |

## 3. Navegação e "árvore"

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-12 | Trilha dentro da pasta | Linha própria abaixo da faixa (l.2202–2211, 4869–4870) | Trilha na toolbar "Documentos › a › b", níveis como `btn-link` (`pasta-arquivos.js:162–183`) | **A** | **Tokens:** botão ↑ 28×28 `#eef2f5`/`#34505f`, raio 4, título "(Backspace)"; `bi-folder2-open` 14px `#f2b93b`; níveis 13px/600 `#0a7aad`; atual 700 `#243845`, `max-width:320px` com ellipsis; separador `bi-chevron-right` 10px `#9aabb6`; `border-bottom:1px solid #eef2f5`. **Falta no sistema:** botão ↑ e Backspace/Alt+←/↑ |
| DOC-13 | Árvore lateral de diretórios | **Não existe no desenho**: só a trilha | Também não existe; a árvore real vem de `PastaSecao.pai` (subpastas aninhadas, ciclo bloqueado) | **✓** | Não inventar painel de árvore |
| DOC-14 | Abrir pasta ou arquivo | Clique **seleciona**; **duplo clique** ou Enter abre (l.4758, 4909); Espaço visualiza | Clique simples entra na pasta (`pasta-arquivos.js:257–266`); clique no nome do arquivo abre o preview | **C** | Muda a semântica para a do Windows |

## 4. Itens: linhas, cartões e ícones

| ID | Item | Desenho | Sistema | Classe | Tokens e observação |
|---|---|---|---|---|---|
| DOC-15 | Pastas e arquivos na **mesma lista**, pastas primeiro | Pasta: `bi-folder-fill` **`#f2b93b`** com `filter:drop-shadow(0 1px 0 rgba(160,110,10,.35))`; Tipo = "Pasta de arquivos" (l.4706–4707, 4729–4743; `02-explorer3.jpg`) | Dois grupos titulados, "Pastas" e "Arquivos". Pastas como cartões de 210px com borda, ícone `--bs-primary` 1.9rem, alça e ⋮ (`show.html.twig:387–426`; css l.142–175) | **A** | — |
| DOC-16 | Linha do modo Detalhes | l.4734, 2245–2249 | Grade `34px 1fr 130px 96px 118px 44px`, `padding:9px 12px`, nome 600 (css l.284–290) | **A** | **Tokens:** grade `minmax(140px,1fr) 150px 90px 110px` (`COLS_PAD`, l.3080–3081), `gap:12px`, `padding:3px 10px`, `min-height:30px`, raio 3. Nome 13px/500 `#1d3441` (pasta 600). Células 12.5px `#5f7684`. Tamanho à direita. Tamanho e data em **Arial**. Ícone 17px. **Sem alça e sem ⋮** |
| DOC-17 | Coluna "Tipo" × "Categoria" | "Tipo" = tipo pela extensão: "Documento PDF", "Documento do Word", "Planilha do Excel", "Imagem PNG", "Pasta compactada", "E-mail do Outlook", "Assinatura digital"… (`expTipo` l.4675–4680). Categoria só no modo Conteúdo e no painel | Coluna "Categoria" com a categoria **jurídica** em pílula (Procuração, Identificação, Gratuidade…) (`show.html.twig:483`) | **B** | Seguir o desenho e esconder a categoria tira uma função real. **Perguntar ao dono** antes de definir: 5ª coluna opcional ou só no painel |
| DOC-18 | Ícones por tipo | Ícone grande ≥52px = "estilo Office" (folha branca + selo com sigla: PDF `#d93025` "PDF", Word `#185abd` "W", Excel `#107c41` "X", PPT `#c43e1c` "P", ZIP `#c99a06`, RAR `#7d3c98`, PNG/JPG `#0f7b8c`, TXT `#5f7684`, MP4 `#6b3fa0`; `fi()` l.3056–3070; `03-explorer3.jpg`). Ícone pequeno <52px = `bi-file-earmark-*-fill` (PDF `#d93a2b`, Word `#1f5fbf`, Excel `#1e7b45`, imagem `#7a4bb0`, zip `#b7791f`, PPT `#c0571d`, áudio/vídeo `#7a4bb0`, EML/MSG `#0a7aad`, P7S/P7M `#1e7b45`; l.4675–4680) | `bi-filetype-*` em contorno (PDF `#e2564d`, Word `#2b7cd3`, planilha `#1f9d55`, imagem `#7b5ea7`, zip `#b08900`, áudio `#c2410c`, vídeo `#be123c`) (`ArquivoIconeExtension.php:42–83`) | **A** | A função Twig é usada também por Cobrança, Financeiro e trilho: um mapa novo deve ser opt-in |
| DOC-19 | Estados da linha | Hover `#eef5fa`. Selecionada `#dcebf6` + `inset 0 0 0 1px #9fc3e3`. Alvo de soltar `#cfe6f7` + `inset 0 0 0 1.5px #0a7aad`. Duplicada `#fffaf0` + `inset 3px 0 0 #e9a23b` (l.4733) | Hover `--fm-hover`; não há estado de seleção; alvo de soltar só no cartão de pasta (`.fm-pasta-alvo`, css l.718–736) | **A** | — |
| DOC-20 | ⋮ por linha | **Não existe**: as ações vêm do botão direito e da barra de seleção | Menu ⋮ com Baixar/Visualizar/Editar/Mover/Excluir em toda linha (`show.html.twig:486–503`) | **A** | **Perguntar ao dono**: tirar o ⋮ deixa o celular (touch) sem ações. Recomendação: manter o ⋮ só em `(hover:none)` |
| DOC-21 | Clique no nome | Seleciona; duplo clique abre | `<a href>` real que abre o preview; Ctrl+clique abre em nova aba (`show.html.twig:465–478`; js l.336–347) | **C** | Preservar o `<a>` (Ctrl/meio-clique) |
| DOC-22 | Subtítulo do item | Só em Blocos ("Tipo · tamanho") e Conteúdo ("Tipo · Categoria"); lateral com data e tamanho no modo Conteúdo (l.4730, 2250) | "Nº x · descrição" sob o nome (`show.html.twig:479`) | **B** | Levar "Nº/descrição" para o modo Conteúdo e o painel |
| DOC-23 | **Favoritos** de arquivo/pasta | Estrela por linha, 20×20, 13px: desligada `#b4c2cc` com opacidade .55; ligada `#f0b400`. Favoritos sobem ao topo. Toast "★ … foi para o topo" (l.4440–4442, 4743, 4820) | Não existe | **D** | **Simulado:** `localStorage 'bj-docs-favoritos-v1'` por usuário/pasta. **Real:** tabela `pasta_documento_favorito (tenant_id, user_id, documento_id \| secao_id, marcado_em)`, com precedente em `PastaFavorita` |
| DOC-24 | Contagem dentro da pasta | Sub "22 arquivos" / "Vazia" (l.4706) | "N arquivos" contando só os arquivos diretos (`show.html.twig:406`) | **A** | `data-subpastas`/`data-arquivos` já existem (l.399–400) |

## 5. Modos de exibição, Organizar e colunas

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-25 | **Oito modos** (padrão **Detalhes**) | Ver a lista de modos abaixo da tabela (`EXP_MODOS` l.3051; `expVals` l.4701–4723; estado inicial `expModo:'det'`, l.2793) | Lista e grade (158px, ícone 2.6rem, nome com `line-clamp`) (css l.365–401) | **B** | Persistir o modo como o sistema já faz (`localStorage 'fmView'`) |
| DOC-26 | Colunas móveis e redimensionáveis | Ver o detalhe abaixo da tabela (l.3079–3139, 2214, 4856–4863) | Colunas fixas | **C** | Preferência por navegador (localStorage) é aceitável; a infra de preferência do usuário existe (Dashboard) |
| DOC-27 | Classificar | Nome, Tipo, Tamanho, Modificado; pastas sempre antes; data e tamanho começam decrescentes; seta `bi-chevron-up/down` 10px `#0a7aad` (l.4710, 4862–4863) | `ordenarPorColuna` + `SENTIDO_INICIAL`, mesma regra; carets `bi-caret-*-fill`; opções a mais: **Manual** e **Categoria** (`pasta-arquivos.js:207–240`) | **A** | **Preservar a ordem Manual** (persistida em `pasta_documentos_reordenar`/`pasta_secoes_reordenar`): o desenho 1.2.3 não a mostra |
| DOC-28 | Cabeçalho da lista | 11px/700, `.08em`, maiúsculas, `#7b93a2`; `padding:8px 10px`; `border-bottom:1px solid #e6edf2`; `user-select:none` (l.2213) | `.74rem`/700 (css l.266–276) | **A** | — |
| DOC-29 | **Painel de detalhes** | Ver o detalhe abaixo da tabela (l.2271–2289, 4952–4953) | Não existe | **C** | Os dados existem (`data-*`), e Descrição/Número podem entrar |

Detalhe de DOC-25 — os oito modos:

| Modo | Coluna mínima | Ícone | Particularidade |
|---|---|---|---|
| Extra grandes (`xg`) | 168px | 72px | grade |
| Grandes (`g`) | 128px | 52px | grade |
| Médios (`m`) | 104px | 38px | grade |
| Pequenos (`p`) | 210px | 18px | grade |
| Lista | 220px | 16px | fluxo em colunas (`grid-auto-flow:column`), linhas = ⌈n/3⌉ |
| Detalhes | — | 17px | modo padrão |
| Blocos | 270px | 40px | — |
| Conteúdo | — | 32px | linha com `border-bottom:1px solid #f1f4f7`, `padding:10px 12px` |

Nos três modos de grade, o nome usa `line-clamp:2` e `overflow-wrap:anywhere`.

Detalhe de DOC-26 — colunas:

- **Ordem:** muda pelas setas do Organizar ou arrastando o título (indicador de inserção `box-shadow:±7px 0 0 -5px #0a7aad`).
- **Largura:** alça de 7px com linha de 1px `#dfe7ed`. Limites: tipo 80–360, tamanho 60–200, data 80–240. Duplo clique restaura.
- **Persistência:** `localStorage 'bj-docs-colunas'`.
- **Ao clicar no título:** classifica.

Detalhe de DOC-29 — painel de detalhes:

- **Caixa:** `<aside>` de 250px, `position:sticky; top:12px`, `#f7fafc`, raio 4, `padding:18px 16px`.
- **Topo:** ícone de 64px (vários itens: `bi-stack` 56px `#0a7aad`); nome 13.5px/700.
- **Propriedades** (12px, rótulo `#7b93a2`, valor `#1d3441`/600):

  | Seleção | Propriedades |
  |---|---|
  | Arquivo | Tipo, Categoria, Tamanho, Modificado |
  | Pasta | Tipo, Conteúdo |
  | Vários | Pastas, Arquivos, Tamanho dos arquivos |

- **Vazio:** `bi-layout-sidebar-reverse` 26px `#b8c6cf` + "Selecione uma pasta ou arquivo para ver os detalhes." (12.5px `#6b8494`).

## 6. Seleção

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-30 | Seleção simples, Ctrl (alterna) e Shift (intervalo a partir da âncora) | l.4748–4753 | Não existe | **C** | — |
| DOC-31 | **Laço** (retângulo) | `rgba(15,111,196,.12)` com borda `1px rgba(15,111,196,.6)`, raio 2. Começa no espaço vazio da linha (fora do nome e do ícone); Ctrl soma à seleção; rola sozinho a 40px da borda (l.4874–4900, 2253) | Não existe | **C** | Conflita com o arraste do Sortable (alça) e com a área de soltar arquivos: definir os gestos |
| DOC-32 | Teclado | Ver a lista de atalhos abaixo da tabela (l.4902–4931) | Só Enter/Espaço no cartão de pasta (`pasta-arquivos.js:267–270`) | **C** | — |
| DOC-33 | **Barra de seleção** | `#eaf3fb`, raio 4, `padding:5px 8px`. ✕ "Limpar (Esc)" 28px. "N selecionados · X KB" 13px/700 `#0b4f86`. Botões: Baixar (.zip), Copiar, Recortar, Renomear (só com 1 item), Selecionar tudo, Excluir — altura 28, 12.5px/600 `#1d4f78` (Excluir `#a3232b`), hover `#fff` (l.2194–2199, 4844–4853) | Não existe | **B** | O que já tem rota: Renomear, Recortar→mover, Excluir, Selecionar. Baixar .zip é D; Copiar (duplicar) é D |

Atalhos de DOC-32:

| Tecla | Ação |
|---|---|
| Ctrl+A | selecionar tudo |
| Del | excluir |
| F2 | renomear |
| Enter | abrir |
| Espaço | visualizar |
| Esc | limpar a seleção |
| Backspace / Alt+← / Alt+↑ | subir um nível |
| Ctrl+C / Ctrl+X / Ctrl+V | copiar / recortar / colar |
| Setas | navegar; nos modos de grade o passo é o nº de colunas medido |
| Shift+setas | estender a seleção |

O item focado rola até ficar visível.

## 7. Menus, botão direito e avisos

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-34 | **Menu de contexto do item** | Ver a lista abaixo da tabela (l.2254–2264, 4809–4826, 4934–4936) | Só o dropdown ⋮ do Bootstrap | **B** | O subconjunto com rota é C; zip D; Copiar D; Favorito D; Chat I.A E |
| DOC-35 | Menu de contexto com vários itens | "Baixar como .zip (N)", Compartilhar links, Recortar, Copiar, Copiar caminhos, "Excluir N itens", Propriedades (l.4797–4808) | Não existe | **C** | O zip fica em D (DOC-46) |
| DOC-36 | Menu de contexto do fundo | Nova pasta (cria e já entra em renomear), Colar, Selecionar tudo, Classificar por nome/data/tamanho/tipo, Mostrar/ocultar painel (l.4826–4834) | Não existe | **C** | — |
| DOC-37 | "Compartilhar link" | Copia `/pastas/{n}/documentos/{nome}` — URL fictícia (l.4813) | Existe `pasta_documento_view`, que exige login e permissão por pasta | **C** | Rótulo honesto: **"Copiar link"** (interno). Link público para terceiros = fora de escopo e dono |
| DOC-38 | "Copiar caminho" | "BlueJus › Pasta 1180 › Documentos › nome" (l.4773) | `caminhoLegivel()` já existe (`pasta-arquivos.js:75–82`) | **C** | — |
| DOC-39 | "Encaminhar via Chat I.A" | l.4817 | Não existe | **E** | Chat I.A e provedor de IA |
| DOC-40 | **Toast** de ação | `fixed; bottom:24px`; centro; `#12242f`; branco 13px; raio 4; `box-shadow:0 14px 30px -12px rgba(0,0,0,.5)`; botão **"Desfazer"** `#7cc4ff`/700; some em 4,2s (l.2266–2268, 4772) | `alert()` nativo nos erros (js l.394, 413, 475, 519, 534, 662) e flash + reload no excluir/editar (`PastaController.php:1814, 1845`) | **C** | O toast é C; o Desfazer exige DOC-58 (D) |

Itens do menu de contexto (DOC-34), na ordem:

- **Abrir** — Enter.
- **Visualizar** — Espaço.
- **Baixar** — vira **"Baixar como .zip"** quando o item é pasta.
- **Compartilhar link**.
- *separador*
- **Recortar** — Ctrl+X.
- **Copiar** — Ctrl+C.
- **Colar** — Ctrl+V, só em pasta.
- **Copiar caminho**.
- **Encaminhar via Chat I.A**.
- **Marcar ou tirar dos favoritos**.
- *separador*
- **Renomear** — F2.
- **Excluir** — Del, em vermelho `#a3232b`.
- *separador*
- **Propriedades**.

Tokens do menu: 240px; `position:fixed`, limitado à viewport; raio 4; `box-shadow:0 1px 3px rgba(0,0,0,.16)`; `padding:5px`. Itens com `padding:7px 10px`, 13px, ícone 14px, atalho em Arial 11px `#8496a3`, desabilitado `#b3c0c9`. Separador de 1px `#eef2f5`, com `margin:4px 8px`.

## 8. Upload e download

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-41 | Upload pelo botão | "Anexar" (l.2037) | `#fmUpload` → `pasta_peticionar_upload`, categoria `DEMAIS`, destino = pasta atual (`pasta-arquivos.js:631–699`) | **A** | Só a aparência (DOC-09) |
| DOC-42 | Upload arrastando do computador | Não aparece no explorador (só no visor, simulado) | Existe: área de soltar no `fm-body` com realce `arrastando-so` (js l.640–659; css l.421–427) | **✓** | Função só do sistema: preservar |
| DOC-43 | Progresso do upload e atualização da lista | Sem interface de progresso | Barra com nome, % e contador i/N; erro por arquivo; **recarrega a página** ao fim (`show.html.twig:365–370`; js l.661–699) | **B** | Preservar a barra (convenção). Inserir a linha sem recarregar exige que o upload devolva os dados da linha (rotas e tokens) |
| DOC-44 | Aviso de duplicado no escritório | — | "Arquivo idêntico já existe no escritório" com link para a outra pasta (`duplicadoDe`; sha256; mesmo tenant; permissão por pasta) (`show.html.twig:372–379, 2622–2690`) | **✓** | Função só do sistema: preservar o `#uploadDuplicadosAviso` |
| DOC-45 | Download individual | Menu de contexto, barra de seleção, visor | ⋮ → Baixar (`pasta_documento_download`) e rodapé do preview | **A** | Faltam os novos pontos de entrada |
| DOC-46 | **Baixar em lote ou pasta como .zip** | Nome `Pasta {n} - {subpasta} - dd-mm-aaaa.zip`; mantém a estrutura das subpastas, nomes únicos e `LEIA-ME.txt` (l.3714–3760; montado no navegador) | Não existe; não há rota | **D** | **Proposta:** endpoint POST com ids, montado no servidor com `ZipArchive` (extensão `zip` presente no container), lendo pela abstração de storage (R2). **Exige:** permissão por pasta, teto de tamanho e quantidade, auditoria, e streaming para não estourar memória |

## 9. Visualizador

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-47 | Visor em tela cheia | `fixed; inset:0; z-index:190; background:#525659`. Cabeçalho `min-height:56px`, `#0a7aad`, branco: ícone do tipo 20px, nome 15px/600, meta 12px (opacidade .85) "Tipo · tamanho · data · Pasta N · Cliente" (l.299–306, 4651) | Modal Bootstrap `modal-xl modal-dialog-scrollable`; cabeçalho `bi-eye` + nome; rodapé Baixar/Fechar (`show.html.twig:2327–2346`) | **A** | `#previewDocModal` também é usado no trilho de Dados e em outras telas (`VisualizadorDocumentoTelasTest`) |
| DOC-48 | Anterior/Próximo | ← → e "i de N" em Arial 13px; lista = arquivos visíveis no nível atual; setas do teclado (l.307–311, 4653–4654, 4672) | Não existe | **C** | — |
| DOC-49 | Zoom | − / + de 10 em 10, de 50% a 200%, botões 34px (l.312–318, 4656) | Não existe | **C** | Os renderizadores rodam em iframe sandbox; o zoom precisa de escala por tipo |
| DOC-50 | Imprimir e abrir em nova aba | Ícones 34×34 brancos, raio 3, hover `rgba(255,255,255,.14)`; impressão por iframe oculto (l.4659–4663) | Só Baixar | **C** | — |
| DOC-51 | Soltar arquivo no visor | Adiciona à lista local, **sem enviar** (simulado, l.4665) | — | **C** | Se for feito, vira upload real: **perguntar** |
| DOC-52 | Formatos que exigem conversão no servidor (DOC, PPT, RTF, ODP, HEIC, TIFF de várias páginas, MSG) | `bj-visualizar.js:5` | O sistema lê no navegador PDF, imagem, áudio, vídeo, DOCX, planilha, texto, ODT, RTF, PPTX, EML, ZIP e XML | **E** | LibreOffice na imagem = D-LIBRE (dono) |

## 10. Criar, renomear, mover e excluir

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-53 | Nova pasta inline | Cria "Nova pasta (n)" no topo, já em modo renomear (l.4827) | Modal `#fmInputModal` → `pasta_secao_criar` (JSON) (js l.398–416; `show.html.twig:1022–1033`) | **B** | Só front |
| DOC-54 | Renomear inline (F2) | Campo de altura 24px, `inset 0 0 0 1.5px #0a7aad`, 12.5px; Enter salva, Esc cancela, perder o foco salva (l.2234, 4837–4838, 4941–4944) | Pasta: modal → `pasta_secao_renomear` (JSON). Arquivo: modal `editDocModal` (POST de formulário + redirect; preserva a extensão) (`PastaController.php:1781–1816`) | **B** | Para o arquivo, falta resposta JSON no `editDocumento` (código legado sem UseCase) |
| DOC-55 | Mover | Arrastar o(s) selecionado(s) para uma pasta, em qualquer modo, **pastas inclusive**; Recortar/Colar; toast com o destino (l.4760–4765, 4784, 4787–4795) | Arquivo: menu → `#fmMoverModal`, ou arraste pela alça até um cartão de pasta. Pasta: menu → `#fmDestinoModal` (`pasta_secao_mover`). Arrastar pasta para pasta foi **removido em 21/08** (`pasta-arquivos.js:809–815`) | **B** | A remoção de 21/08 foi técnica (conflito com o Sortable); um explorador sem Sortable resolve. Registrar. Lote = N chamadas ou endpoint de lote |
| DOC-56 | Copiar/Colar (**duplicar** arquivo, nome "(cópia n)") | l.4786–4796 | Não existe | **D** | UseCase novo: copiar o objeto no storage + linha nova + o mesmo `sha256`. Atenção à sincronização com o Drive (`driveFileId` é UNIQUE) |
| DOC-57 | Excluir (um, lote, pasta) | Del ou menu; **sem confirmar**; toast "Excluído: x" + Desfazer (l.4781) | Arquivo: `confirm()` + formulário POST + reload (`show.html.twig:495–500`). Pasta: `confirm()` com contagem (subpastas, arquivos) + JSON (js l.484–520) | **B** | Lote = C. Tirar o `confirm()` = decisão do dono (frente dos 63 `confirm()`) |
| DOC-58 | **Desfazer exclusão** (lixeira) | Simulado: `expDel` em memória | Exclusão física: a linha sai no flush e o arquivo logo após o commit (`PastaController.php:1837–1843`) | **D** | **Persistência real:** `excluido_em`/`excluido_por` em `pasta_documento` e `pasta_secao`, filtro, purga posterior, remoção física adiada. Pegada larga: Cobrança, Drive, purga de tenant |

## 11. Busca e filtros

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-59 | Busca | Filtra o **nível atual**; dobra acentos; `_ - .` contam como espaço; também casa o fim do nome sem extensão. Realce `<mark>` `#fde68a` raio 2. "N resultados para "q"" 12.5px `#5f7684`. Vazio: `bi-search` 22px `#b8c6cf` + "Nenhum arquivo ou pasta com esse nome." (l.4719–4721, 3071–3077, 2185–2186) | **Global** (todas as subpastas), com selo do caminho de cada resultado; `toLowerCase`, sem dobrar acento; sem realce nem contagem; vazio genérico (`pasta-arquivos.js:132–151`) | **B** | Manter o escopo global (função do sistema) e trazer normalização, realce e contagem |
| DOC-60 | A busca inclui pastas | Placeholder "…arquivos e pastas" | As pastas somem durante a busca (js l.121–126) | **C** | — |
| DOC-61 | **Filtro por tipo** | Todos, Pastas, PDF, Word, Excel, Imagens, Compactados, Outros; cada um com contagem e desabilitado (`#aab8c1`) quando zero; chip "Mostrando somente [X ×]" de altura 24, raio 999, `#e5f1f7`/`#0b4f86`/700 (`TIPOS_DOC` l.3082–3087; l.2048–2054, 2184) | Não existe | **C** | Agrupamento pela extensão no cliente |

## 12. Duplicados e limpeza

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-62 | Selo **"Idêntico"** (mesmo hash na mesma pasta) | Ver os tokens abaixo da tabela (l.4690–4692, 4744–4745) | `sha256` gravado em todos os caminhos e indexado; não é mostrado na lista | **C** | Os arquivos antigos têm sha NULL até o backfill (§9.5). Selo sem lastro = nada |
| DOC-63 | "Possíveis arquivos duplicados" por nome (≥85% parecido) | `similar()` ≥ 0,85 e nomes diferentes; texto "a ≈ b (N% parecido; confira se é o mesmo documento)" (`bj-docsug.js:107–109`; dc l.2158–2160, 4043) | `SugestorDeDocumentos::similaridade()` existe (l.159) mas não lista duplicados | **C** | — |
| DOC-64 | Faixa **"Ganhe espaço"** (sugestões de limpeza) | Ver as regras e os tokens abaixo da tabela (`expLimpeza` l.4689–4697; l.2217–2235, 4945–4952) | Não existe | **C** | **Não é IA** (o `pasta.md` §8 dizia E). Rótulo honesto: evitar `bi-stars` ou explicar que são regras |
| DOC-65 | Nº de páginas do PDF | Usado na regra "cópia do processo" (`EXP_META.pag`) | Não é guardado | **D** | Coluna `paginas` extraída no upload (pdfinfo/Ghostscript) + backfill no comando de hash |
| DOC-66 | Selo de sugestão na linha | "Vazio" / "Ver no PJe" / "Muito grande", `bi-trash3`: `#8f2f28` sobre `#fdecea`, `inset 0 0 0 1px #f0b8b2` (l.4745) | Não existe | **C** | — |

Tokens do selo "Idêntico" (DOC-62):

- Caixa de altura 19px, `padding:0 6px`, raio 3.
- Texto 10px/800, `.05em`, maiúsculas, `#8a4b00`, sobre `#fff1d6`, com `inset 0 0 0 1px #f0c77a`; ícone `bi-files`.
- `title`: "Conteúdo idêntico a: … Sugestão: manter "o mais antigo"".
- A linha fica `#fffaf0` com barra `#e9a23b` (DOC-19).

Regras da faixa "Ganhe espaço" (DOC-64), avaliadas por arquivo:

1. **Duplicado por hash:** dentro de cada grupo, fica o mais antigo.
2. **Vazio:** 0 KB.
3. **Cópia do processo:** o nome casa `/processo|autos|integra|íntegra|completo|CNJ/` **e** o arquivo tem mais de 100 páginas ou mais de 50 MB.
4. **Muito grande:** mais de 100 MB.

Comportamento da faixa:

- O texto diz "… · libera cerca de X".
- **Revisar** abre a lista com caixas de marcar.
- O botão final é "Excluir N selecionado(s) · X", com `confirm()` + toast + Desfazer.
- O ✕ dispensa a faixa.
- Só aparece na raiz.

Tokens da faixa:

- Fundo `#fffaf0` com `inset 0 0 0 1px #f0d7a6`; ícone `bi-stars` `#b46a00`; texto 12.5px `#5c3d00`.
- "Revisar": altura 28, `#b46a00`, hover `#94570a`, raio 3.
- Itens: fundo branco com `inset 0 0 0 1px #f1e2c4`.
- Excluir: `#a3232b`, altura 30.

Persistência do "dispensar":

- **No desenho:** só o estado do componente.
- **No sistema:** `sessionStorage`, ou tabela por pasta (D) se precisar durar.

## 13. Checklist de documentação

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-67 | Faixa do checklist no topo | `margin:16px 20px`, raio 4, `box-shadow:0 1px 3px rgba(0,0,0,.16)`. Cabeçalho `padding:13px 16px; #fafcfd; border-bottom:#eef2f5`: `bi-check2-square` 14px `#8496a3`; título 13.5px/600; selo "3/5 itens"; barra 150×6 `#e7edf2` (l.2079–2087) | Coluna direita de 356px; `h6` `.82rem`; barra com `flex` (`show.html.twig:525–550`; css l.517–569) | **A** | Ver §0.4 |
| DOC-68 | Itens em grade fluida | `flex:1 1 260px`. Linha `padding:11px 16px`, bordas direita e inferior `#f1f4f7`; concluída com fundo `#fcfdfe`. **Caixa de 19px**, raio 3, borda 1.5px `#c3d2dd`, que vira `#1f9d61` cheia com `bi-check2` 11px branco. Texto 13.5px `#243845`, com ellipsis; concluído `#6b8494` riscado (`text-decoration-color:#c3d2dd`) (l.2172–2179, 6339–6346) | Lista vertical; ícones `bi-check-circle-fill` verde / **`bi-x-circle-fill` vermelho** em `fs-5`; `.82rem`; rolagem a 340px (`show.html.twig:551–575`; css l.702–711) | **A** | — |
| DOC-69 | Marcar e desmarcar de verdade | `toggle` | `pasta_checklist_toggle` | **✓** | — |
| DOC-70 | Botões do cabeçalho | Ver os tokens abaixo da tabela (l.2088–2093) | Botões de 26px transparentes com borda: **Modelos** (`bi-bookmarks`), Editar, Adicionar (`show.html.twig:540–549`) | **A** | **Preservar Modelos** (só o sistema tem) |
| DOC-71 | Adicionar item | Faixa inline `#f6f9fb`, campo de altura 32 com `inset 0 0 0 1px #d3dde5`, placeholder "Nome do documento, ex.: Certidão de casamento" + botão "Adicionar" `#0a7aad` 12.5px/700; Enter confirma, Esc cancela (l.2166–2170) | `input-group-sm` + botões ✓/✗ (`show.html.twig:577–584`) | **A** | — |
| DOC-72 | Modo edição | Só a lixeira 26×26 (fundo branco, `#b03a2e`, anel `#f1c9c4`) (l.2177) | Edição inline do título + lixeira + **alça para reordenar** (`show.html.twig:559–569`) | **A** | Preservar renomear e reordenar (só o sistema tem) |
| DOC-73 | Desativar checklist com motivo | Ver o detalhe abaixo da tabela (l.2093–2117, 4048–4099) | Não existe | **D** (+E) | **Simulado:** `localStorage 'bj-ck-cfg-v1'`. **Real:** campos na `pasta` (desativado em, por, motivo) + notificação + cron. A política (quem recebe, texto "BlueJus IA") é do dono |
| DOC-74 | Selo **"sem anexo"** | Item marcado sem arquivo correspondente; regex por item: procura / rg\|cnh\|identidade / resid\|endereco / contrato / hipossuf\|gratuidade\|pobreza; 1ª palavra com 5 letras ou mais (`ckAuditar` l.4141–4146) | Não existe | **C** | O cálculo é puro (nome dos arquivos × título do item). A notificação à controladoria é D/E |
| DOC-75 | Selo **"cobrado Nx"** e cobrança automática | Ver o detalhe abaixo da tabela (l.3996–4199, 6334–6338) | Não existe | **D** (+E) | **Simulado:** `localStorage 'bj-ck-cob-v1'` + `BJCentral.notificar`. **Real:** tabela `pasta_checklist_cobranca` + agendador + notificações. Tom das mensagens e escalonamento = dono |
| DOC-76 | Pendência da aba (linha vermelha) | "documento cobrado e ainda não anexado / item marcado sem anexo" (`tabPendencias` l.5236–5242) | `PastaPendenciasOutput` não tem `documentos` | **D** | Depende de DOC-75. A parte "sem anexo" poderia sair de DOC-74 (C), mas o texto do desenho junta as duas |

Tokens dos botões do cabeçalho do checklist (DOC-70):

| Botão | Tokens |
|---|---|
| ✦ Sugerir documentos | altura 28, `#f1ecfc`/`#5b3fb0`, 12px/700 |
| ✎ Editar | 28×28, `#e5f1f7`/`#0b5f86`, raio 4; ativo `#0a7aad` branco |
| + Adicionar | mesmo estilo do ✎ |
| Interruptor "Ativo" | 32×18; verde `#1f9d61` / cinza `#cfd9e0`; bolinha de 14px; transição `.2s` |

Detalhe de DOC-73 — desativar o checklist:

- O interruptor pede um **motivo obrigatório**, entre quatro: "Não se aplica…", "Controlados em outro sistema", "Administrativa/consultiva", "Encerrada".
- Aparece o aviso em roxo `#faf8ff`, com "Atualizar o checklist" / "Manter ativo" / "Desativar".
- Estado desativado: `bi-pause-circle` + "por X em dd/mm" + **Reativar**.
- Notifica a controladoria.
- Lembra de revisar a cada **30 dias**.

Detalhe de DOC-75 — cobrança automática:

| Regra | Valor |
|---|---|
| Teto | 2 cobranças por dia por pasta |
| Quando envia | só em dias úteis |
| Peso | procuração e contrato = 3; identidade e hipossuficiência = 2 |
| Tom | sobe a cada aviso; no 4º aviso, a coordenação é avisada |
| Previsão | o responsável informa uma data e a cobrança pausa até lá |
| Selo | 9px `bi-megaphone-fill`; âmbar `#a8661a`; vermelho `#c0392b` a partir de 3 envios ou quando "sem anexo" |

## 14. Documentos sugeridos (✦)

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-77 | Posição | Botão no cabeçalho do checklist; o painel abre **dentro** do cartão do checklist (l.2089, 2112) | Cartão próprio **acima** do `fm`, com título "Documentos sugeridos", subtítulo e botão `bi-list-check` "Sugerir documentos" (`_documentos_sugeridos.html.twig:20–36`) | **A** | Viável quando o checklist sair do `fm`. Contratos: `#documentosSugeridos`, `data-url-adicionar`, `data-csrf` |
| DOC-78 | Marca ✦ / "BlueJus IA" / "Análise documental do processo" | l.2089, 2113–2117 | Texto honesto: "Sugestões pelo catálogo… nada aqui é exigência do juízo" (`_documentos_sugeridos.html.twig:1–17, 60–63`) | **E** | Decisão de rótulo do dono (regra do §0: rótulo honesto). Sem IA, o ✦ engana |
| DOC-79 | **"Exigido pelo juízo"** (🔴 `#a3232b`/`#fdeceb`) | Determinações extraídas de despachos, decisões, intimações e citações; item com "Origem: Decisão de dd/mm (ID x)" clicável e prazo (`bj-docsug.js:67–75, 88`; `bj-processo.js:158–175`; dc l.4028–4031) | Não existe; o Sugestor exclui de propósito (`SugestorDeDocumentos.php:14–17`) | **D** | **Base:** `PublicacaoDjen.texto` (teor do Push). Serviço de regex no servidor + "Origem" que abre `_push_teor`. Fase lida das movimentações e confiança "Média" na mesma frente. A camada IA (`refinarIA`) = **E** |
| DOC-80 | **Organização sugerida para esta fase** | Botão-link (`bi-chevron-right/down`) que mostra chips `bi-folder2` `#d39222` com a lista de pastas por fase (ver abaixo da tabela) (`bj-docsug.js:111–114`; dc l.2161–2162) | Não existe | **C** | Lista estática; o Catálogo já tem a fase. **Não** inventar "criar estas pastas" (o desenho não tem) |
| DOC-81 | Conflito de nº de processo | Aviso âmbar `#fff7e6`/`#7a4b00` quando aparece outro número CNJ no nome da pasta ou de algum arquivo (`bj-docsug.js:77–84`; dc l.2118) | Não existe | **C** | — |
| DOC-82 | "Atualizar", "Prazos em curso", "Já existente → Processo: …" | l.2115, 2123; `localizar` com `an.docs` | O painel tem grupos, chips, itens e "Adicionar N faltante(s)" (B37), sem esses três | **B** | "Atualizar" é C (recarrega). Prazos e "Processo:" dependem de DOC-79 (D) |

Pastas da organização sugerida (DOC-80):

1. Base: 01 Processo, 02 Petições, 03 Decisões, 04 Documentos das partes, 05 Provas.
2. Extras da fase. Exemplo do cumprimento de sentença: 06 Prazos, 07 Cálculos, 08 Cumprimento, 09 Pagamentos.
3. Por último: Encerramento.

## 15. Estados, responsividade, tema e fidelidade transversal

| ID | Item | Desenho | Sistema | Classe | Observação |
|---|---|---|---|---|---|
| DOC-83 | Estados vazios | **Pasta vazia:** `bi-folder2-open` 30px `#f2b93b` + "Pasta vazia. Arraste arquivos para cá ou use Colar (Ctrl+V)." (`padding:36px 16px`, 13px `#6b8494`, `max-width:420px`). **Busca sem resultado:** ver DOC-59 (l.2238, 4871–4872) | Um estado só: `bi-cloud-arrow-up` + "Nenhum arquivo aqui" (`show.html.twig:507–511`) | **A** | O texto do desenho fala em "carregada do sistema", o que é simulado: não copiar |
| DOC-84 | Erros e carregamento | Toast | `alert()` nativo em seis pontos do JS; reload ao fim do upload | **C** | Junto da frente dos `confirm()`/`alert()` |
| DOC-85 | Responsivo e toque | Sem regra específica para o explorador: toolbar com `flex-wrap`; modo Lista com rolagem horizontal; menu de contexto limitado à viewport (l.4935). Só `[data-r=grid2]` muda a ≤980px | ≤768px esconde as colunas (fica Nome + ⋮); ≤1199px o checklist desce (css l.456–470, 713–718) | **C** | Laço e botão direito não existem no toque: manter ⋮ e toque longo como convenção. Testar em 375px |
| DOC-86 | Tema escuro | O desenho só tem tema claro, com hex fixos | O `fm` usa `--bs-*`; o `.ds-sug` tem `[data-bs-theme="dark"]` (`pasta-documentos-sugeridos.css:49`) | **A** | Requisito: todo token novo precisa do par escuro (README "Antes de começar" 3) |
| DOC-87 | Tipografia numérica | Tamanho, data, contagens, atalhos e "i de N" em `Arial,Helvetica,sans-serif` (l.2045, 2245–2247) | Herda a fonte do corpo | **A** | — |
| DOC-88 | Raios 4px / 3px / 999px | dc em toda a aba | `.ps-page .fm { --fm-radius:4px; --fm-radius-sm:3px }` (`pasta-show.css:3379–3391`) | **✓** | Parcial: as pastas ainda são cartões |
| DOC-89 | Editor de peças | **Ausente** na aba. `bj-editor.js` é o editor de anotações e observações, não de peças | Peças em `/pasta/{id}/peticionar` (Quill, `editor-rico.js`, `pasta_peticionar_texto`, `pasta_documento_editar_texto`, `pasta_documento_exportar_texto/{formato}`, `pasta_peticionar_upload_imagem`); a peça aparece na lista como arquivo comum | **✓** | Não há o que fazer na aba; preservar |
| DOC-90 | Volume e desempenho | "Sem mostrar mais: mostra tudo"; virtualizar ou paginar se houver centenas (README §Aba 6) | Renderiza todos os documentos + **1 modal de edição por documento** (`show.html.twig:2350–2351`) | **C** | Um modal único reutilizável, preenchido por `data-*`. Medir uma pasta grande em prod pelo MCP |

---

## 16. Funções que o sistema TEM e o desenho não mostra (não podem se perder)

1. **Ordem manual por arrastar**, de arquivos e de pastas, persistida (`pasta_documentos_reordenar`, `pasta_secoes_reordenar`; Sortable pela alça).
2. **Categoria jurídica** do documento:
   - coluna e ordenação por categoria;
   - **modal Editar** com nome-base (a extensão é preservada), categoria, descrição e número;
   - subtítulo "Nº · descrição".
3. **Busca global** em todas as subpastas, com o caminho de cada resultado.
4. **Upload arrastando do computador** para a pasta aberta, com barra de progresso por arquivo e erro por arquivo.
5. **Aviso de duplicado no escritório** (`duplicadoDe`, sha256, mesmo tenant, permissão por pasta), com link para a outra pasta.
6. **Modelos de checklist**: listar, aplicar, salvar, renomear e excluir. Também: reordenar itens por arrastar e renomear item inline.
7. **Mover pasta** para outra pasta, com o caminho legível e ciclo bloqueado. **Excluir pasta** com aviso de contagem (subpastas e arquivos).
8. Persistência da **pasta aberta** e da **aba** após reload (`sessionStorage fmFolder_/fmTab_`). Modo e ordem ficam em `localStorage`.
9. **Visualizador seguro** e amplo:
   - iframe sandbox;
   - tetos contra zip bomb;
   - formatos: PDF, imagem, áudio, vídeo, DOCX, planilha com abas, texto, ODT, RTF, PPTX, EML, listagem de ZIP e XML.
10. Nome como **`<a href>` real**: Ctrl/meio-clique abre em nova aba e funciona sem JS.
11. **Tema escuro** em todo o `fm`.
12. **Compartilhamento com a Cobrança** (o mesmo JS e CSS).
13. **Documentos sugeridos** gravando de verdade no checklist ("+ Checklist", "Adicionar N faltante(s)").
14. **Peças de texto** (Peticionar) e **sincronização com o Google Drive** (`driveFileId` UNIQUE). Qualquer operação nova (copiar, renomear, mover, lixeira) precisa manter o Drive coerente.
15. **Auditoria** (`PastaDocumento implements Auditavel`) e remoção física só depois do commit (INV-6).

## 17. O que no desenho é SIMULADO e que persistência teria no sistema

| Simulação no desenho | Chave ou mecanismo | Persistência real proposta | Item |
|---|---|---|---|
| Favoritos | `localStorage 'bj-docs-favoritos-v1'` | Tabela `pasta_documento_favorito` por usuário e tenant | DOC-23 |
| Colunas (ordem e largura) | `localStorage 'bj-docs-colunas'` | `localStorage` (por navegador, aceitável) ou preferência do usuário | DOC-26 |
| Mover, criar e renomear | `expDentro` em `localStorage 'bj-exp-dentro-v1'`, `expNomes`, `expNovas` | Rotas existentes (`pasta_secao_*`, `pasta_documento_mover_secao`, `pasta_documento_edit` em JSON) | DOC-53–55 |
| Excluir e Desfazer | `expDel` em memória | Lixeira: `excluido_em`/`excluido_por` + purga | DOC-58 |
| Copiar/Colar | Linha nova em memória | UseCase de cópia + storage | DOC-56 |
| Zip | Montado no navegador com bytes de demonstração | Endpoint `ZipArchive` com streaming | DOC-46 |
| Hash e páginas | `EXP_META` fixo + `crypto.subtle` no blob local | `sha256` (já existe) + coluna `paginas` | DOC-62/65 |
| Checklist ativo/desativado | `localStorage 'bj-ck-cfg-v1'` | Campos na `pasta` + notificação | DOC-73 |
| Cobrança do checklist | `localStorage 'bj-ck-cob-v1'` + `BJCentral.notificar` | Tabela `pasta_checklist_cobranca` + agendador | DOC-75/76 |
| Contexto do processo | `localStorage 'bj-proc-ctx-v1'` | `processo_contexto` versionado (o próprio desenho sugere) | DOC-79 |
| Links compartilhados | URL `/pastas/{n}/documentos/{nome}` fictícia | `pasta_documento_view` (interno, com autenticação) | DOC-37 |
| Soltar no visor | Blob local, sem envio | Upload real (perguntar) | DOC-51 |

## 18. Contagem por classe

| Classe | Qtde | IDs |
|---|---|---|
| **A** | 28 | 01, 02, 03, 05, 07, 08, 09, 12, 15, 16, 18, 19, 20, 24, 27, 28, 41, 45, 47, 67, 68, 70, 71, 72, 77, 83, 86, 87 |
| **B** | 14 | 10, 11, 17, 22, 25, 33, 34, 43, 53, 54, 55, 57, 59, 82 |
| **C** | 29 | 04, 14, 21, 26, 29, 30, 31, 32, 35, 36, 37, 38, 40, 48, 49, 50, 51, 60, 61, 62, 63, 64, 66, 74, 80, 81, 84, 85, 90 |
| **D** | 9 | 23, 46, 56, 58, 65, 73, 75, 76, 79 |
| **E** | 3 | 39, 52, 78 (mais as partes E dentro de 73, 75 e 79) |
| **✓** | 7 | 06, 13, 42, 44, 69, 88, 89 |

## 19. Decisões a PERGUNTAR ao dono antes de implementar

1. **DOC-20** — Tirar o ⋮ das linhas, como no desenho. O toque perde as ações; a recomendação é manter o ⋮ só em `hover:none`.
2. **DOC-17** — A categoria jurídica sai da lista? Recomendação: 5ª coluna opcional no Organizar, mais o painel.
3. **DOC-57** — Excluir sem `confirm()`, só com Desfazer. Depende da lixeira (DOC-58) e da frente dos `confirm()`.
4. **DOC-78** — Marca ✦ / "BlueJus IA" sobre regras sem IA.
5. **DOC-27** — Onde fica a **ordem Manual** no Organizar, já que o 1.2.3 não a mostra. Recomendação: "Classificar por: Manual" como 1ª pílula.
6. **DOC-73 e DOC-75** — Políticas de desativar o checklist e de cobrança: tom, quem é notificado, 30 dias.
7. **DOC-51** — Soltar arquivo no visor = upload real?
8. **DOC-02/03** — Confirmar a volta do checklist para a faixa do topo. Pelo desenho é a regra, mas a mudança anterior foi deliberada.

## 20. Testes sugeridos por grupo

- **Arranjo** (combinador de filho direto, como `CarteiraArranjoTelaTest`):
  - faixa do checklist antes do explorador e fora de `.fm-body > *:last-child`;
  - painel de detalhes como irmão da lista;
  - pastas e arquivos no mesmo contêiner da lista.
- **Contratos (não regredir):**
  - `data-*`/ids do `#fileManager`;
  - `.fm-arq-preview`;
  - `#previewDocModal`;
  - `#uploadDuplicadosAviso`;
  - os testes da Cobrança listados no §0.5.
- **Unit puro:**
  - regras de limpeza (DOC-64), **incluindo o caso em que o filtro remove tudo e o caso de sha NULL**;
  - similaridade ≥ 0,85 (DOC-63);
  - "sem anexo" (DOC-74);
  - organização por fase (DOC-80);
  - regex de determinações sobre um teor real do Push (DOC-79).
- **Functional, para cada endpoint novo** (zip, lote, cópia, favorito, lixeira, renomear em JSON):
  - tenant cruzado (404);
  - IDOR por permissão POR PASTA, provado com o recurso irmão;
  - CSRF;
  - teto do zip.
- **JS:** não existe harness de teclado/laço no PHPUnit. O smoke de seleção, laço, atalhos e visor é **do dono** no navegador.
