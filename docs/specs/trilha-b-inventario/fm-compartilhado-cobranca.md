# Inventário — gerenciador de arquivos compartilhado (`fm`) × Cobrança

> Investigação read-only, 06/10/2026, base `master` em `9df09e82`. Insumo para a decisão de arquitetura
> da aba Documentos da Pasta (Trilha B §9.3). Nada aqui foi alterado em código; nada foi visto em
> navegador. Onde o achado é leitura de código sem conferência visual, isso está dito.

## 0. Resumo em uma tela

- **Consumidores reais do `pasta-arquivos.js`: só dois.** `pasta/show.html.twig` (`#documentos`) e
  `cobranca/objeto/show.html.twig` → `cobranca/caso/_documentos.html.twig` (`#tab-documentos`).
  Cliente, Tarefa e Financeiro só compartilham o **`#previewDocModal`/`visualizador-documento.js`**,
  não o `fm`.
- **A Cobrança praticamente não usa o `fm` em produção:** consulta no MCP de prod (06/10) →
  `cobranca_documento` = **2 linhas** (2 casos, 1 por caso), `cobranca_secao` = **0**. A Pasta tem
  **22.565** documentos, **646** seções e uma pasta com **1.128** documentos.
- **A Cobrança usa um subconjunto pequeno do contrato:** sem árvore (`data-arvore` ausente), sem
  checklist, sem pré-visualizador, sem editar, sem ordenar por cabeçalho, sem categoria, sem
  `#fmDestinoModal`, sem `.fm-col-principal`.
- **Defeito provável já em produção na Cobrança (leitura de código, conferir na tela):** desde
  `90c7f773` (27/08, publicado), `.fm-body` é grid de 2 colunas (`minmax(0,1fr) 356px`) em ≥1200px e
  o JS só liga `fm-body--sem-lateral` fora da raiz/busca. Na Cobrança não existe `#fmChecklist` nem
  `.fm-col-principal`: na raiz, `#fmGrupoPastas` cai na coluna 1 e **`#fmGrupoArquivos` na coluna de
  356px** (ou, sem pastas — o caso real em prod, 0 seções — os arquivos ficam na col. 1 com uma faixa
  vazia de 356px à direita). Ver §5.2.
- **Opinião (insumo, não decisão): (a) explorador próprio da Pasta**, deixando o `fm` congelado para a
  Cobrança, com os **endpoints e o contrato de servidor mantidos** e o visualizador/upload
  reaproveitados como módulos. Justificativa no §6.

---

## 1. Consumidores (grep completo)

Busca por `pasta-arquivos`, `fileManager`, `fm-`/`.fm`, `data-arvore`, `data-url-upload`,
`fm-arq-preview`, `previewDocModal`, `uploadDuplicadosAviso`, `VisualizadorDocumento`,
`enviarArquivoComProgresso`, `fmTab_`, `fmFolder_`, `fmOrdem`, `fmView` em `app/templates`,
`app/public`, `app/src`, `app/tests`, `e2e/`.

### 1.1 Carregam `pasta-arquivos.js` + `pasta-arquivos.css`

| Página | Markup do `fm` | JS | CSS | Gate |
|---|---|---|---|---|
| `pasta/show.html.twig` | l.310-631 (`#documentos` › `#fileManager`, `data-arvore="1"`) | l.5389 `/js/pasta-arquivos.js` (bloco `javascripts`, antes de `pasta-show.js`) | l.9 `/css/pasta-arquivos.css` | **nenhum no template**; o servidor barra por `canAccessResource(...,'pasta',id,'edit')` |
| `cobranca/objeto/show.html.twig` | l.520-523 inclui `cobranca/caso/_documentos.html.twig` | l.2371, dentro de `{% if has_permission('resources.cobranca.gerenciar') %}` (l.2333) | l.950 | markup: `podeGerenciarDocumentos` (ObjetoController l.158); leitor vê tabela read-only (`_documentos` l.167-189) |

### 1.2 Só compartilham o pré-visualizador (`#previewDocModal` + `visualizador-documento.js`)

- `pasta/show.html.twig` l.2327 (modal), l.2799-2804 (`VisualizadorDocumento.ligarModal`).
- `pasta/_dados_trilho.html.twig` l.224, `pasta/_financeiro.html.twig` l.317, 765, 939, 1196 —
  gatilhos `data-bs-target="#previewDocModal"` (mesma página da Pasta).
- `cliente/show.html.twig` l.418/565 e `tarefa/show.html.twig` l.997/1023 — modal próprio com os
  mesmos ids. **Não usam o `fm`.**
- **Cobrança não tem `#previewDocModal`** e o `_documentos` não emite `.fm-arq-preview` — o nome do
  arquivo é um link de download (`_documentos` l.108).

### 1.3 Outros dependentes do markup/ids da aba Documentos da Pasta

- `#uploadDuplicadosAviso` (pasta/show l.374-379 do arquivo; dentro do `#fileManager`, entre
  `#fmUploadBar` e `#fmBody`) é preenchido pelo helper de upload **do template** (l.2626-2687), não
  pelo `pasta-arquivos.js`. Persiste em `sessionStorage['uploadDuplicados_<pastaId>']`.
- Painel "Documentos sugeridos" `#documentosSugeridos` (`pasta/_documentos_sugeridos.html.twig`)
  vem **imediatamente antes** do `#fileManager` e fora dele (teste trava isso). Seu JS recarrega na
  aba via `location.hash='documentos'` (`pasta-documentos-sugeridos.js` l.59-64) e
  `pasta-show.js` l.326-332 abre a aba por `#<hash>-tab`.
- Checklist (`#fmChecklist` › `#checklistLista`, `#checklistBadge`, `#checklistBarra`,
  `#btnChecklist*`, `#checklistModelosPainel`) mora **dentro** do `#fmBody`; o JS dele é inline na
  pasta/show (≈l.4193-4300, usa SortableJS carregado em l.4193).
- `#documentos-tab` (gerado em `_cabecalho.html.twig` l.461-480, `#pastaTabs[role=tablist]`) é alvo
  de `data-ps-ir-aba="documentos-tab"` em `_dados_trilho` l.205 e `_financeiro` l.369/697/700/1129,
  além do `pasta-arquivos.js`.
- `#editDocModal{id}` (pasta/show l.2352+) é aberto pelo `.fm-arq-editar`.

### 1.4 e2e

`e2e/tests/*` (12 specs) **não cobrem** documentos/`fm` em lugar nenhum. Zero cobertura de
navegador para o gerenciador, nas duas telas.

---

## 2. Contrato do `pasta-arquivos.js` (871 linhas)

### 2.1 Inicialização

- IIFE executada **no carregamento do script** (não em `DOMContentLoaded`): exige que o DOM do `fm`
  já exista acima do `<script>` e que `bootstrap` já esteja global (usa `new bootstrap.Modal` em
  l.566/602 no boot).
- Raiz fixa: `document.getElementById('fileManager')` (l.10). Sem ele, sai em silêncio.
- **Instância única por página.** Todos os elementos são buscados por **id global** com
  `document.getElementById` (l.29-44, 360, 371, 398, 565-569, 601-604, 619, 631, 826), não a partir
  da raiz. Duas instâncias na mesma página são impossíveis sem reescrita.
- Não expõe API: nenhum global, nenhum evento customizado disparado. Não há como configurar por JS.

### 2.2 `data-*` lidos na raiz `#fileManager`

| Atributo | Uso | Pasta | Cobrança |
|---|---|---|---|
| `data-pasta-id` | chave do `sessionStorage` (`fmFolder_<id>`, `fmTab_<id>`) | `pasta.id` | **`casoId`** |
| `data-url-upload` / `data-csrf-upload` | `enviarArquivos` → `window.enviarArquivoComProgresso` | `pasta_peticionar_upload` / `peticionar_upload_<id>` | `cobranca_documento_upload` / `cobranca_documento_upload_<id>` |
| `data-url-criar-secao` / `data-csrf-criar-secao` | Nova pasta | `pasta_secao_criar` / `pasta_secao_criar_<id>` | `cobranca_secao_criar` / `cobranca_secao_criar_<id>` |
| `data-url-reordenar-secoes` / `data-csrf-reordenar-secoes` | Sortable das pastas | `pasta_secoes_reordenar` / `reordenar_secoes_pasta_<id>` | `cobranca_secoes_reordenar` / `reordenar_secoes_cobranca_<id>` |
| `data-url-reordenar-docs` / `data-csrf-reordenar-docs` | Sortable dos arquivos | `pasta_documentos_reordenar` / `reordenar_docs_pasta_<id>` | `cobranca_documentos_reordenar` / `reordenar_docs_cobranca_<id>` |
| `data-url-renomear-tpl` | molde `__ID__` p/ cartão criado no cliente | `pasta_secao_renomear` | `cobranca_secao_renomear` |
| `data-url-excluir-tpl` | idem | `pasta_secao_excluir` | `cobranca_secao_excluir` |
| `data-url-mover-tpl` | idem (opcional; guarda em l.432) | `pasta_secao_mover` | **ausente** |
| `data-arvore` | `'1'` liga árvore: `paiId` na criação, "Mover para..." de pasta, breadcrumb multinível | `"1"` | **ausente** |

### 2.3 `data-*` por item

- **`.fm-pasta`**: `data-secao-id`, `data-nome`, `data-url-renomear`, `data-csrf-renomear`,
  `data-url-excluir`, `data-csrf-excluir`, `data-pai-id`, `data-url-mover`, `data-csrf-mover`,
  `data-subpastas`, `data-arquivos` (aviso de exclusão, D3). Cobrança só emite os 6 primeiros;
  `avisoExclusao` cai em "está vazia" (Number(undefined)||0) — na Cobrança o aviso **sempre diz
  vazia**, mesmo com arquivos (bug latente; em prod há 0 seções).
- **`.fm-arquivo`**: `data-secao` (`'geral'` ou id), `data-doc-id`, `data-ordem`, `data-nome`,
  `data-tamanho`, `data-data` (`Y-m-d H:i:s`), `data-tipo`, `data-mime`, `data-categoria` (só Pasta),
  `data-url-mover`, `data-csrf-mover`.
- **Contagem**: `[data-secao-contagem="<id>"]` (texto "N arquivo(s)").
- **Preview**: `.fm-arq-preview` com `data-url`, `data-nome`, `data-mime` (lidos pelo
  `visualizador-documento.js` via `relatedTarget`; download = `url.replace('/visualizar','/download')`).
- **Editar**: `.fm-arq-editar[data-target="#editDocModal{id}"]`.

### 2.4 Ids e classes de que o JS depende

Ids: `fmBody`, `fmPastas`, `fmGrupoPastas`, `fmChecklist`, `fmArquivos` (**obrigatório** — sem
null-check em l.60 e l.336), `fmArquivosVazio`, `fmArquivosTitulo`, `fmBusca`, `fmCrumbSep`,
`fmCrumbAtual`, `fmOrdenar`, `fmFileInput`, `fmUpload`, `fmUploadBar`/`fmUploadNome`/
`fmUploadProgresso`/`fmUploadContador` (obrigatórios se houver upload), `fmNovaPasta`,
`fmMoverModal`/`fmMoverLista`, `fmInputModal`/`fmInputCampo`/`fmInputTitulo`/`fmInputErro`/
`fmInputConfirmar`, `fmDestinoModal`/`fmDestinoCampo`/`fmDestinoTitulo`/`fmDestinoConfirmar`,
`previewDocModal`, `documentos-tab`.

Classes lidas/escritas: `.fm-pasta`, `.fm-pasta-abrir|renomear|mover|excluir|grip|nome`,
`.fm-arquivo`, `.fm-arq-grip|preview|editar|mover|local`, `.fm-lista-head`, `.fmh-btn[data-ordenar]`,
`.fmh-caret`, `.fm-crumb[data-nav="root"]`, `.fm-crumb-nivel[data-nivel]`, `.fm-view-btn[data-view]`,
`.dropdown`; estado: `fm-oculto`, `fm-grade`/`fm-lista`, `fm-body--sem-lateral`, `arrastando-so`,
`arrastando` (ghost), `fm-pastas-recebendo`, `fm-pasta-alvo`, `menu-aberto`, `ativo`, `ativa`.

O JS **gera HTML** (acoplado ao CSS): cartão de pasta novo (l.439-458), crumb (l.177-182), lista de
destinos (l.364-378), `<option>` do destino (l.611-614).

### 2.5 Dependências externas

- **Bootstrap 5** global: `Modal`, `Tab`, eventos `show.bs.dropdown`/`hide.bs.dropdown`,
  `shown.bs.tab`, `hidden.bs.modal`.
- **SortableJS** (`window.Sortable`, opcional): CDN jsdelivr 1.15.6, incluído pela própria página
  (Pasta l.4193; Cobrança l.2334). Sem ele, sem arrastar.
- **`window.enviarArquivoComProgresso(file, opts)`** definido pelo template (Pasta l.2690; Cobrança
  l.2337): `opts = {url, csrf, categoria:'DEMAIS', descricao, numero, secaoId, reduzir:false,
  onProgress, onComprimindo}` → Promise que resolve `{success:true,...}`, rejeita `Error(error)`.
  Na Pasta o mesmo helper também serve o modal Peticionar (`processarItemUploadPeca`, l.2725+) e
  registra `duplicadoDe[]` no `#uploadDuplicadosAviso`.
- `visualizador-documento.js` (indireto, via `#previewDocModal`).
- `localStorage` **global, sem namespace por tela**: `fmView`, `fmOrdem`.
- `sessionStorage`: `fmFolder_<data-pasta-id>`, `fmTab_<data-pasta-id>`.

### 2.6 Eventos

Escuta: `click`/`keydown` em `#fmPastas`; `click` em `#fmArquivos`, crumbs, `.fm-view-btn`,
`.fmh-btn`; `input` em `#fmBusca`; `change` em `#fmOrdenar`/`#fmFileInput`; `dragenter/over/leave/drop`
em `#fmBody` (arquivos do SO); `dragover` **em captura no `document`** (l.742, ativo só durante o
arraste de arquivo); `show/hide.bs.dropdown` no `fm`; `shown.bs.tab` no `#documentos-tab` e em todos
os irmãos `[data-bs-toggle="tab"]` dentro de `closest('[role="tablist"]')`. Não dispara nenhum.

### 2.7 Pedidos HTTP e respostas esperadas

| Ação | Corpo | Sucesso esperado | Erro |
|---|---|---|---|
| upload (via helper) | multipart `arquivo,_token,categoria,descricao,numero,secao_id?,reduzir_tamanho` | `2xx` + `{success:true}` (Pasta também `duplicadoDe[]`, `compressao`) | `{success:false,error}` |
| criar seção | form `_token,nome,paiId?` (paiId só com árvore e fora da raiz) | `2xx` + `{id,nome,paiId?,csrfRenomear,csrfExcluir,csrfMover?}` (sem checar `ok`) | `{erro}` |
| renomear | form `_token,nome` | `{ok:true,nome}` | `{erro}` |
| excluir seção | form `_token` | `{ok:true}` (Pasta + `subpastasRemovidas`,`arquivosRemovidos`, ignorados) | `{erro}` |
| mover seção (só árvore) | form `_token,destinoId` (`''` = raiz) | `{ok:true,paiId}` | `{erro}` |
| mover documento | form `_token,secao_id?` (ausente = geral) | `{ok:true}` | `{erro}` |
| reordenar docs/seções | **JSON** `{_token,ids:[int]}` | ignorado (fire-and-forget) | silencioso |
| excluir documento | `<form>` POST normal, não é do JS | redirect | flash |

Após upload o JS **recarrega a página** (l.670-673) gravando `fmFolder_`/`fmTab_` para voltar à
mesma pasta/aba. Editar/excluir documento também recarregam (redirect para `pasta_show` sem
fragmento; PastaController l.1816/1846) e dependem do mesmo `fmTab_` para reabrir a aba.

### 2.8 Diferenças por opção (o que `data-arvore` muda)

`temArvore` controla só: `entrar()` remonta cadeia pelos pais; `paiId` na criação; item "Mover
para..." no cartão criado no cliente (os cartões do servidor trazem ou não o item pelo template).
**Tudo o mais roda igual nas duas telas**, inclusive o que só a Pasta tem markup (checklist,
cabeçalho ordenável, preview, editar) — as funções simplesmente não acham os elementos.

---

## 3. Endpoints por consumidor (confirmados com `debug:router`)

### 3.1 Pasta

| Rota | Método/URL | Controller | Permissão | Tenant/IDOR | CSRF |
|---|---|---|---|---|---|
| `pasta_peticionar_upload` | POST `/pasta/{id}/peticionar/upload` | `Pasta\Controller\PeticionarController::upload` | `canAccessResource pasta edit` | ParamConverter `Pasta` + seção checada | `peticionar_upload_<id>` (falha 403) |
| `pasta_secao_criar` | POST `/pasta/{id}/secao` | `Pasta\Controller\PastaSecaoController::criar` | idem | `findByIdAndPastaAndTenant` p/ pai | `pasta_secao_criar_<id>` |
| `pasta_secao_renomear` | POST `/pasta/secao/{secaoId}/renomear` | `::renomear` | idem (da pasta da seção) | `em->find` (depende do TenantFilter) | `pasta_secao_renomear_<id>` |
| `pasta_secao_excluir` | POST `/pasta/secao/{secaoId}/excluir` | `::excluir` | idem | `em->find` | `pasta_secao_excluir_<id>` |
| `pasta_secao_mover` | POST `/pasta/secao/{secaoId}/mover` | `::mover` | idem | destino `findByIdAndPastaAndTenant` | `pasta_secao_mover_<id>` |
| `pasta_documento_mover_secao` | POST `/pasta/documento/{docId}/mover-secao` | `::moverDocumento` | idem | `em->find`; UseCase barra tenant e pasta diferentes | `pasta_doc_mover_<id>` |
| `pasta_documentos_reordenar` | POST `/pasta/{id}/documentos/reordenar` | `::reordenarDocumentos` | idem | UseCase | `reordenar_docs_pasta_<id>` (JSON) |
| `pasta_secoes_reordenar` | POST `/pasta/{id}/secoes/reordenar` | `::reordenarSecoes` | idem | UseCase | `reordenar_secoes_pasta_<id>` (JSON) |
| `pasta_documento_view` / `_download` | GET `/pasta/documento/{id}/visualizar` · `/download` | `App\Controller\PastaController` | — | — | — |
| `pasta_documento_edit` / `_delete` | POST `/pasta/documento/{id}/editar` · `/deletar` | `App\Controller\PastaController` | — | — | `delete_documento_<id>` |

Nota: `criar` devolve `csrfUpload` com a chave `upload_documento_pasta_<id>`, que **não** é a do
upload (`peticionar_upload_<id>`). Inofensivo hoje porque o JS ignora esse campo; não reaproveitar.

### 3.2 Cobrança (`App\Cobranca\Controller\DocumentoCobrancaController`, prefixo `/cobrancas`)

Todas as mutações: `tenantComCapacidade('resources.cobranca.gerenciar')` → JSON 403;
`findOneByIdDoTenant` → 404 (anti-IDOR explícito, sem depender do filtro); CSRF nomeado por ação.

| Rota | URL | CSRF | Resposta |
|---|---|---|---|
| `cobranca_documento_upload` | POST `/casos/{id}/documentos` | `cobranca_documento_upload_<id>` | 201 `{success:true,documento:{...}}`; categoria `'DEMAIS'`→lowercase→`Outro`; ignora `numero` |
| `cobranca_secao_criar` | POST `/casos/{id}/secoes` | `cobranca_secao_criar_<id>` | 201 `{id,nome,ordem,csrfUpload,csrfRenomear,csrfExcluir}` (sem `paiId`/`csrfMover`) |
| `cobranca_secao_renomear` | POST `/secoes/{secaoId}/renomear` | `cobranca_secao_renomear_<id>` | `{ok,nome}` |
| `cobranca_secao_excluir` | POST `/secoes/{secaoId}/excluir` | `cobranca_secao_excluir_<id>` | `{ok}` |
| `cobranca_documento_mover` | POST `/documentos/{docId}/mover` | `cobranca_doc_mover_<id>` | `{ok}` |
| `cobranca_documentos_reordenar` | POST `/casos/{id}/documentos/reordenar` | `reordenar_docs_cobranca_<id>` (JSON) | `{ok}` |
| `cobranca_secoes_reordenar` | POST `/casos/{id}/secoes/reordenar` | `reordenar_secoes_cobranca_<id>` (JSON) | `{ok}` |
| `cobranca_documento_excluir` | POST `/documentos/{docId}/excluir` | `delete_documento_cobranca_<id>` | redirect `cobranca_objeto_show` |
| `cobranca_documento_download` | GET `/documentos/{docId}/download` | — | `tenantComModulo`; anexo |

O docblock do controller (l.38-47) declara o contrato como "o MESMO do `pasta-arquivos.js`, reusado
sem edição". A Cobrança **não tem rota de visualizar** (sem preview) nem de mover seção (sem árvore).

---

## 4. Testes que tocam o `fm` (o que quebra se o markup mudar)

### 4.1 Pasta — asseveram seletores do `fm`

| Teste | Seletores travados |
|---|---|
| `tests/Pasta/Functional/PastaShowDocumentosControllerTest.php` | `.fm-arquivo[data-nome][data-secao]` (l.121-122); `[role="tablist"] #documentos-tab` (l.152); `.fm-pasta[data-secao-id][data-pai-id]` (l.191-194); `data-subpastas`/`data-arquivos` (l.242-250); `#fmArquivos .fm-arquivo .fm-arq-nome` com classe `fm-arq-preview` e href `/visualizar`, sem link direto de download (l.267-294); `#previewDocModal .modal-footer #previewDocDownload` (l.314); `#fmArquivos .fm-lista-head .fmh-btn[data-ordenar]` e `[aria-sort]` (l.336-343); `.fm-arq-tipo .fm-badge-cat` = `data-categoria` (l.358-364); `#fmOrdenar option` (l.383); `#fmBody > .fm-col-principal > #fmGrupoPastas|#fmGrupoArquivos`, `#fmBody > #fmChecklist`, ids do checklist dentro de `#fmChecklist` (l.411-431); barra do checklist (l.438-473) |
| `tests/Pasta/Functional/PastaChecklistModelosArranjoTelaTest.php` | `.fm-checklist-progresso > #btnChecklistModelos`, `> .fm-checklist-acao`, `#fmChecklist .fm-checklist-corpo > #checklistModelosPainel` (l.84-106) |
| `tests/Pasta/Functional/PastaDocumentosSugeridosTest.php` | `#documentos > #documentosSugeridos + #fileManager`; `#fileManager #documentosSugeridos` = 0 (l.306-307) |
| `tests/Pasta/Unit/PastaShowRaiosDoGerenciadorTest.php` | lê as **folhas**: `.ps-page .fm { --fm-radius:4px; --fm-radius-sm:3px }` em `pasta-show.css` e `.fm { --fm-radius:14px; --fm-radius-sm:9px }` intacto em `pasta-arquivos.css` |
| `tests/Functional/VisualizadorDocumentoTelasTest.php` | `#previewDocModal`, `#previewDocConteudo`, `#previewDocNome`, `#previewDocDownload` em pasta/cliente/tarefa |
| `tests/Pasta/Functional/PastaFinanceiroMenusTelaTest.php` (l.226, 252), `PastaDadosArranjoTelaTest.php` (l.743) | `[data-bs-target="#previewDocModal"]`, `#documentos-tab` |
| `tests/Arquitetura/VisualizadorDocumentoArquiteturaTest.php` | regras do módulo do visualizador (sandbox, CSP, sem cópia do preview em telas) |

### 4.2 Cobrança

| Teste | O que trava |
|---|---|
| `tests/Cobranca/Functional/DocumentoCobrancaControllerTest.php` | `#fileManager` presente para gestor (l.44) e ausente para leitor (l.297); contrato JSON de upload/seção/mover/reordenar; IDOR; CSRF; 403 |
| `tests/Cobranca/Functional/ObjetoShowControllerTest.php` l.242-260 | `ul[role="tablist"] #documentos-tab` |
| `tests/Cobranca/Functional/ObjetoShowContratoJsTest.php` l.78-80 | `#documentos-tab` como gancho |

**Nenhum teste da Cobrança asserta classes internas `fm-*`**: só a existência de `#fileManager` e do
`#documentos-tab`. **Nenhum teste lê o `pasta-arquivos.js`** (comportamento do JS = zero cobertura).
Nenhum e2e. Ou seja: mudar o JS ou o CSS do `fm` pode quebrar a Cobrança **sem nenhum teste ficar
vermelho** — o único guarda é o `PastaShowRaiosDoGerenciadorTest` (raios).

---

## 5. CSS

### 5.1 `pasta-arquivos.css` (736 linhas)

- Praticamente tudo prefixado `.fm`/`.fm-*`; não há regra em `body`, `:root`, `.btn`, `.modal`
  genéricos. Exceções/escopo global:
  - `.fm-oculto { display:none !important }` (l.435) — utilitário global usado pelo JS; qualquer
    outro componente que use essa classe herda o `!important`.
  - `#checklistModelosLista` (l.635) — id da Pasta, sem `.fm` na frente.
  - `.fm .dropdown-menu`, `.fm [hidden]`, `.fm-upload-bar .progress` (estilizam Bootstrap dentro do fm).
  - Classes `fm-*` são nomes globais: o CSS vale onde quer que a classe apareça, inclusive nos
    modais `#fmInputModal`/`#fmMoverModal` fora do `.fm`.
- **`.fm-body` grid 2 colunas** (l.505-514, `minmax(0,1fr) 356px`, colapsa < 1200px l.707-712) foi
  feito para o checklist da Pasta mas vale para a Cobrança.
- Checklist e modelos (l.516-705) — só a Pasta usa; carregado na Cobrança à toa.

### 5.2 Defeito provável na Cobrança (≥1200px) — **conferir na tela, não visto**

Commit `90c7f773` (27/08, em `origin/master`, logo em prod). `render()` faz
`elBody.classList.toggle('fm-body--sem-lateral', !(naRaiz && !buscando))` **sem checar se
`#fmChecklist` existe**. Na Cobrança, na raiz e sem busca, a classe sai → grid de 2 colunas com os
filhos diretos `#fmGrupoPastas` e `#fmGrupoArquivos` (lá não há `.fm-col-principal`): os arquivos
iriam para a coluna de 356px quando houver pastas; sem pastas (`#fmGrupoPastas` escondido — o caso
de prod, 0 seções) os arquivos ficam na coluna 1 com 356px vazios à direita. Correção mínima
possível, se o dono quiser: `comChecklist = !!elChecklist && naRaiz && !buscando` (no JS) — mas isso
é editar o `fm` compartilhado, decisão do orquestrador.

### 5.3 Sobrescritas externas

- `pasta-show.css` l.3379-3385 (A6): `.ps-page .fm { --fm-radius:4px; --fm-radius-sm:3px }`,
  `.ps-page .fm-checklist`, `.fm-checklist-acao`, `.fm-modelo-aplicar`, `.fm-modelo-icone`,
  `.ps-page #documentos .card` — escopadas na Pasta, travadas por teste.
- `cobrancas.css`: **nenhuma** regra `fm`. A Cobrança usa o `fm` cru (raios 14/9).
- `pasta-documentos-sugeridos.css`: declara explicitamente não tocar em `.fm`.
- `visualizador-documento.css`: só o modal.

---

## 6. Riscos de regressão e opções de arquitetura

### 6.1 Riscos concretos

1. **Ids globais**: o JS acha tudo por `getElementById`. Um explorador novo na Pasta que reutilize
   qualquer id `fm*` enquanto o `pasta-arquivos.js` ainda é carregado na página faz os dois brigarem
   (o fm inicializa sobre o markup novo). Se a Pasta trocar de componente, **tirar o `<script>` do
   `pasta-arquivos.js` da pasta/show** (e o `<link>` do CSS, ou manter só se ainda usar classes).
2. **Chaves de storage sem namespace**: `fmTab_<id>`/`fmFolder_<id>` usam o id da Pasta numa tela e o
   id do **Caso** na outra → colisão quando Pasta N e Caso N são abertos na mesma aba do navegador
   (abre a aba Documentos sozinha no Objeto). `fmOrdem`/`fmView` são compartilhados: escolher
   "Categoria" na Pasta deixa o `<select>` da Cobrança em branco (opção inexistente). Um explorador
   novo deve usar chaves próprias (ex.: `pastaDocs:*`), e o retorno pós-reload da Pasta deve ser
   reimplementado nele (hoje editar/excluir/upload dependem do `fmTab_` para voltar à aba).
3. **Retorno à aba**: `#documentos-tab` dentro de `[role="tablist"]` é contrato de **três** lados
   (fm, `data-ps-ir-aba`, `pasta-show.js#abaDoFragmento`) + testes. Não renomear.
4. **Helper de upload da página** (`window.enviarArquivoComProgresso`) é usado também pelo modal
   Peticionar e alimenta `#uploadDuplicadosAviso`. Mudar sua assinatura quebra o Peticionar.
5. **Preview**: `.fm-arq-preview` + `data-url/nome/mime` + `#previewDocModal` + `relatedTarget`. O
   download é derivado por `replace('/visualizar','/download')` — URL do novo explorador precisa
   manter esse par ou passar `data-download`.
6. **Contrato de servidor**: os 8 endpoints de cada lado e o formato `{ok}`/`{erro}`/`{success,error}`
   são compartilhados por design (docblock do `DocumentoCobrancaController`). Mudar o formato da Pasta
   não afeta a Cobrança (rotas distintas), mas mudar o **JS** afeta as duas.
7. **Desempenho**: o `fm` renderiza **todos** os documentos no HTML e o `render()` percorre e
   reanexa todos a cada tecla/clique (l.134-151, 200). Em prod há pasta com **1.128** arquivos. Um
   explorador novo com 8 modos/seleção por laço precisa pensar nisso (ex.: virtualização ou paginar
   por pasta).
8. **Sem rede de proteção**: nenhum teste de comportamento do JS; nenhum e2e; smoke é do dono.

### 6.2 O que precisa permanecer compatível (para a Cobrança, se o `fm` for mantido)

Ver lista final do §7. Pode ser refatorado livremente **na Pasta** tudo que só a Pasta usa:
checklist dentro do `fm-body`, `.fm-col-principal`, cabeçalho ordenável, categoria, preview,
editar, `#fmDestinoModal`, árvore — desde que os testes da Pasta (§4.1) sejam reescritos junto, de
forma deliberada.

### 6.3 Opções (insumo, não decisão)

**(a) Explorador próprio da Pasta; `fm` congelado para a Cobrança.**
- Prós: a Cobrança tem **2 documentos e 0 seções em prod** e usa um subconjunto pequeno — o risco de
  mantê-la no `fm` atual é quase nulo; o redesenho (8 modos, Organizar, colunas móveis, painel de
  detalhes, seleção em lote/laço, duplicados) é muito maior que o `fm` (871 linhas, instância única,
  ids globais, HTML gerado em string) e não cabe nele sem reescrita; os endpoints da Pasta já existem
  e servem o novo componente sem mudança; resolve de quebra a colisão de storage; o
  `PastaShowRaiosDoGerenciadorTest` já expressa a política "o `pasta-arquivos.css` não muda por causa
  da Pasta".
- Contras: duas implementações de arrastar/reordenar/mover/criar seção para manter; o defeito do
  §5.2 continua na Cobrança até alguém corrigir o `fm`; testes da Pasta que travam `fm-*` (§4.1) têm
  de ser reescritos; a Pasta precisa reimplementar o retorno pós-reload (ou, melhor, não recarregar).

**(b) Evoluir o `fm` com opções opt-in (`data-*`).**
- Prós: um código só; Cobrança ganharia melhorias "de graça".
- Contras: o JS não tem estrutura para isso (IIFE sem API, ids globais, `render()` monolítico); cada
  opção nova roda também na Cobrança, que **não tem nenhum teste de comportamento** — regressão
  silenciosa é o modo de falha esperado (o §5.2 é exatamente isso: uma mudança da Pasta, de 27/08,
  que vazou para a Cobrança sem teste algum notar); o desenho (8 modos, laço, painel) multiplicaria
  os ramos `if (temX)`.

**(c) Extrair um núcleo comum (ex.: módulo de dados/ações: árvore, mover, reordenar, upload; UI
separada por tela).**
- Prós: melhor fatoração a longo prazo; o contrato JSON já é idêntico dos dois lados.
- Contras: exige reescrever o `fm` da Cobrança para usar o núcleo — mexe na tela que ninguém pediu
  para mexer e que não tem testes; custo alto para um consumidor com 2 documentos; pode ser feito
  **depois** de (a), extraindo do explorador novo quando ele estabilizar.

**Leitura do investigador:** (a) agora, com estes cuidados: tirar o `pasta-arquivos.js` da
pasta/show quando o novo entrar; ids e chaves de storage próprios (sem prefixo `fm`); manter
`#documentos`, `#documentos-tab`, `#previewDocModal`/`.fm-arq-preview`-equivalente via
`data-bs-target`, `#uploadDuplicadosAviso`, `#documentosSugeridos` antes do explorador, e os
endpoints da Pasta; e, à parte, propor ao dono a correção de uma linha do §5.2 na Cobrança (com teste
de arranjo). (c) fica como evolução posterior, se a Cobrança passar a usar documentos de verdade.

---

## 7. Contratos que não podem quebrar

**Enquanto a Cobrança usar o `fm`:**
1. `app/public/js/pasta-arquivos.js` e `app/public/css/pasta-arquivos.css` como estão (ou mudanças só
   com teste da Cobrança); `.fm { --fm-radius:14px; --fm-radius-sm:9px }` (teste de folha).
2. Markup de `cobranca/caso/_documentos.html.twig`: `#fileManager` + os `data-*` da §2.2 (sem árvore),
   ids `fm*` da §2.4, `#fmInputModal`, `#fmMoverModal`.
3. `window.enviarArquivoComProgresso` definido na `objeto/show` antes do script; SortableJS antes.
4. `#documentos-tab` dentro de `ul[role="tablist"]#objetoTabs`.
5. Rotas `cobranca_documento_*`, `cobranca_secao_*`, `cobranca_secoes_reordenar`,
   `cobranca_documentos_reordenar` e o formato `{success,error}` / `{ok}` / `{erro}` / JSON
   `{_token,ids}`; `#fileManager` só para `resources.cobranca.gerenciar`.

**Na Pasta, qualquer que seja o componente:**
6. Painel `#documentos` com `aria-labelledby="documentos-tab"`; `#documentos-tab` em
   `#pastaTabs[role="tablist"]`; `data-ps-ir-aba="documentos-tab"` e `#<hash>-tab` continuam abrindo.
7. `#previewDocModal` com `#previewDocConteudo`, `#previewDocNome`, `#previewDocDownload` e gatilho com
   `data-url/data-nome/data-mime` (download = `/visualizar`→`/download` ou `data-download`).
8. `#uploadDuplicadosAviso` (+ `#uploadDuplicadosLista`, `#uploadDuplicadosFechar`,
   `data-url-pasta-tpl`) e o helper `window.enviarArquivoComProgresso` (também do Peticionar).
9. `#documentosSugeridos` imediatamente antes do explorador e fora dele.
10. Rotas da Pasta (§3.1) com os CSRFs nomeados atuais; reordenação de seções/arquivos e a árvore
    (`paiId`, mover seção, aviso de exclusão com subpastas/arquivos).
11. Ids do checklist (`#checklistLista`, `#checklistBadge`, `#checklistBarra`, `#btnChecklist*`,
    `#checklistModelosPainel`, `#checklistModelosLista`) — o JS inline da pasta/show depende deles.
12. Excluir/editar documento continuam como POST com CSRF `delete_documento_<id>` e o usuário volta
    à aba Documentos (hoje via `fmTab_`; no novo, por mecanismo próprio ou fragmento `#documentos`).
