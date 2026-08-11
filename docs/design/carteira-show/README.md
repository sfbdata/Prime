# Redesign da `cobranca_carteira_show` — estado em 10/08/2026

Ponto de retomada da frente de redesenho da **lista de objetos cobrados** de uma carteira.
Escrito porque o OpenPencil e o VSCode fecharam no meio do trabalho: o documento do OpenPencil
não foi salvo, então **o desenho só existe nos `.jsx` desta pasta**.

## O que já está no código (não commitado quando isto foi escrito)

Conserto cirúrgico + correção da fonte, com a suíte completa verde (**3451 testes**):

| Mudança | Arquivos |
|---|---|
| Fonte do sistema: `@font-face` de `'Open Sans'` apontava para `/fonts/opensans-regular.woff`, que **nunca existiu** — tudo caía no Arial. Trocado pelo Source Sans 3 **variável** (200–900) que o `base.html.twig` já baixava numa versão só-peso-400. Token novo `--jp-font-sans`. | `app/public/css/app.css`, `app/templates/base.html.twig` |
| `layout_peticionar.html.twig` redefine `--jp-font-sans` localmente: é a única tela que carrega Open Sans de verdade (Google Fonts) e ali a fonte **nunca esteve quebrada**. | `app/templates/layout_peticionar.html.twig` |
| Coluna "Saldo exigível" passou a usar `.jp-money` (tabular-nums). | `_resultado_casos.html.twig` |
| Identificador do objeto virou `<a href>` real — antes a lista era **inalcançável pelo teclado**. | `_resultado_casos.html.twig`, `cobrancas.css` |
| Estilos inline e larguras em px viraram classes (`.cobranca-col-*`, em `rem`). | 3 templates |
| Caixa de métricas própria (`.carteira-resumo`) no lugar da `.caso-header` emprestada da tela de Caso; saldo com primazia sobre a contagem. | `show.html.twig`, `cobrancas.css` |
| "Dados atualizados até" virou selo (`.carteira-chip`) na linha do cliente. Segue **dentro de `.content-header`**, que é onde `CarteiraDadosAtualizadosNaTelaTest` o procura. | `show.html.twig`, `cobrancas.css` |
| Teste novo, provado por mutação (link vira `<span>` → falha; link aponta para outro id → falha). | `app/tests/Cobranca/Functional/ListaCarteiraAcessivelTest.php` |

**O dono avaliou esse resultado como "melhorou nada de mais"** e pediu redesenho de arranjo — daí
os wireframes abaixo.

## O que NÃO foi feito, e por quê

- **`confirm()` nativo do "Excluir este documento?"** ficou. Medido: **63 ocorrências de `confirm()`
  nos templates** e nenhum componente de modal de confirmação no projeto. Trocar em 1 lugar deixaria
  62 telas com diálogo do navegador e 1 diferente. É frente própria.
- **Métrica "vencido" no topo** não foi implementada: o `CarteiraDetalheOutput` não expõe agregado de
  vencido (só `saldoConsolidado`, `totalObjetos`, `totalCasos`). O "vencido" existe **por linha**
  (`caso.temVencido`). Somar isso é query nova + UseCase. Os wireframes mostram esse KPI — ele é
  **proposta, não dado existente**.

## O achado que motiva o redesenho: não há paginação nem ordenação

Medido no controller, não suposto:

- `CarteiraController::index()` (lista de **carteiras**) **é paginado**: `POR_PAGINA`,
  `total_paginas`, e usa `_partials/_filtro_paginacao.html.twig`.
- `CarteiraController::show()` (lista de **objetos cobrados** — a tela em questão) chama
  `montarVisaoCarteira->executar($carteira, $busca)` **sem limite**: renderiza todas as linhas.
- Não há ordenação. Numa tela de cobrança, não dá para perguntar "quem me deve mais".
- O CSS já tem `.cobranca-row-link .sort-icon { opacity: .35 }` — ícone de ordenação foi previsto e
  nunca ligado nesta lista.

Volume real (banco `saas_ux`, que é o que o app dev lê — **não** o `saas`):

| Carteira | Objetos cobrados (dev) | Em produção |
|---|---|---|
| TOP LIFE II | 121 | 229 |
| TOP LIFE I | 81 | 230 |
| AMLI BR 060 | 51 | — |

Ou seja: em produção a tela monta **~230 linhas de uma vez**, cada uma com instância de tooltip.

## Os wireframes

Ambos são **wireframes de arranjo**, não especificação visual.

| Arquivo | Arranjo | Estado |
|---|---|---|
| `arranjo-a.jsx` + `arranjo-a.png` | **Lista em primeiro plano.** Lista ocupa a largura inteira; Configuração e Documentos viram abas irmãs. Faixa de KPIs com VENCIDO, ordenação no cabeçalho da coluna, paginação no rodapé. | ✅ renderizado e exportado |
| `arranjo-b.jsx` + `arranjo-b.png` | **Lista + trilho lateral.** Lista com 800px; Configuração e Documentos num trilho compacto de 324px, visíveis sem clique. | ✅ renderizado e exportado |

O documento editável está em **`carteira-show.fig`**, nesta mesma pasta, com os dois arranjos
(A em `x=0`, B em `x=1260`). Os `.jsx` continuam sendo a fonte reproduzível — se o `.fig` se perder
de novo, cola e volta.

### Como retomar

1. Abrir o OpenPencil e abrir o `carteira-show.fig`. (O MCP recusa com o app fechado, e a
   ferramenta proíbe o agente de abrir o app sozinho.)
2. Se precisar recriar do zero: colar cada `.jsx` em `mcp__open-pencil__render` (A em `x=0`,
   B em `x=1260`).
3. **Salvar com `save_file` logo após renderizar.** A primeira versão deste trabalho se perdeu
   inteira porque o app fechou antes de qualquer save.

**Armadilha medida:** o `arranjo-b.jsx` inteiro numa chamada só morre com `RPC timeout (30s)` — o
JSX é grande demais. Quebre em duas: primeiro o frame raiz + header + KPIs, depois o `CorpoB` via
`render` com `parent_id` do raiz. Foi assim que ele renderizou. O arranjo A, menor, passa inteiro.

**Limitação do renderer:** `counterAlign` não é suportado (ignorado com warning). O alinhamento
vertical dentro das células foi simulado com padding uniforme; na implementação real quem
centraliza é o `align-middle` do Bootstrap.

## Decisão pendente do dono

Escolher entre A e B. A pergunta que separa os dois: **Configuração e Documentos precisam estar
visíveis o tempo todo?** Se sim, B. Se não, A dá quase 400px a mais para a lista.

Depois da escolha, a implementação da paginação/ordenação **não é só template**: mexe em
`MontarVisaoCarteiraUseCase`, no repositório e no controller, e precisa continuar respeitando o
contrato do `filtro-tabela.js` (no XHR o `show()` devolve **só** o fragmento `_resultado_casos`).
