# Design — Modo Lista (cartões) para as pastas do Expediente

**Data:** 2026-07-02 · **Branch:** `visual-lista-expediente` · **Risco:** BAIXO (visual/frontend; sem tocar em permissão, tenant, identidade ou controller)

## Objetivo

Adicionar um **toggle Tabela ↔ Lista** na listagem de pastas do Expediente. O modo
"Lista" apresenta cada pasta como um **cartão vertical largo** (1 por linha), moderno
e de leitura clara, mantendo **paridade total** com as ações inline que a tabela já
tem. O modo Tabela continua sendo o padrão; a escolha do usuário é lembrada.

## Decisões travadas no brainstorming

1. **Formato do modo lista:** cartões em lista vertical (1 por linha), não grade nem inbox.
2. **Ações inline:** paridade total — trocar responsável, arquivar (situação) e mover
   marcador acontecem dentro do cartão, como na tabela.
3. **Mobile:** o cartão novo **substitui** o card simples atual de mobile (`<768px`) —
   um único componente serve desktop-lista e mobile. Mobile ganha as ações inline.
4. **Campos do cartão:** os mesmos que a tabela já mostra (nenhum novo por ora). Se
   depois faltar algo, adiciona-se de forma incremental.

## Abordagem

**Renderizar os dois layouts no mesmo fragmento Twig e alternar via CSS + classe no
container.** Nada de mudança no `ExpedienteController`.

O `pasta/_tabela.html.twig` já entrega, no mesmo HTML da resposta AJAX: `<table>`
(desktop) + cards (mobile). Adiciona-se um **terceiro bloco** — a lista de cartões
para desktop — e um botão-toggle que apenas troca a classe do container
(`.pastas-view--tabela` / `.pastas-view--lista`). O CSS mostra um layout e esconde o
outro.

### Por que esta abordagem

- **Reaproveita a máquina existente:** filtros, chips, ordenação server-side,
  paginação e o recarregamento AJAX de `#pastas-resultado` continuam idênticos — o
  fragmento já volta pronto do servidor a cada troca de filtro/página.
- **Paridade "de graça":** os handlers de responsável/situação/marcador já são
  **delegados por classe** (`.pasta-resp-select`, `.js-toggle-situacao`, mover
  marcador) no shell persistente `#expediente-painel`. Se o cartão usar as **mesmas
  classes e `data-*`**, as ações inline funcionam sem JS novo de negócio.
- **Risco BAIXO:** zero mudança no controller/backend; superfície menor.

### Alternativas descartadas

- **(B) Controller decide o layout por `?view=`** — mexe no backend e obriga um reload
  AJAX só pra trocar de visual. Mais peças, sem ganho.
- **(C) Montar os cartões via JS a partir da tabela** — frágil, duplica a lógica de
  renderização em JS, difícil manter paridade.

### Custo/cuidado aceito

- Cada pasta aparece **2× no DOM** (linha da tabela + cartão). Com `PER_PAGE = 50` o
  impacto é irrelevante.
- **Nunca usar `id=` duplicado** entre os dois blocos — só classes e `data-*`. IDs
  únicos que hoje existam na linha precisam virar classe/`data-*` no cartão (ou ser
  suprimidos), senão o DOM fica com IDs repetidos.

## Layout do cartão (modo lista)

```
┌──────────────────────────────────────────────────────────────┐
│ ▏001   ACME LTDA  +1                        [🔴 URGENTE]  [Ativo]│
│ ▏      Execução Fiscal · nº 1002...                             │
│ ▏      [responsável ▾]   🏷 Urgente  +2      ✓ 3 metas · 2 ok  ⋮│
└──────────────────────────────────────────────────────────────┘
```

- **Barra lateral colorida pela prioridade** (cinza/amarelo/vermelho) para leitura
  instantânea; pulsa se urgente (reusa `.badge-urgente-pulso`).
- **Linha 1:** NUP + cliente (com `+N` para múltiplos) à esquerda; badges de
  prioridade e situação à direita.
- **Linha 2:** ação / processo principal (classe+assunto ou nome da ação).
- **Linha 3 (ações inline):** `<select>` de responsável, marcadores (chip + `+N` +
  mover) e o link de metas — **mesmas classes/`data-*` da tabela** para paridade.
- Cores via variáveis do Bootstrap (compatível com tema claro/escuro), como o filtro.

## Toggle, persistência e ordenação

- **Botão-toggle segmentado Tabela | Lista** na **linha de resultados do `_tabela`**
  (a mesma que mostra "X resultado(s)" e a paginação), com ícones `bi-table` /
  `bi-card-list`. Fica dentro de `#pastas-resultado`, então re-renderiza a cada swap
  AJAX — ok, pois o estado vem do `localStorage` e o handler é delegado no shell.
- **Persistência em `localStorage`** (chave `pastasView`), no mesmo padrão do tema
  claro/escuro. Default = **Tabela**. A classe de view é **reaplicada a cada recarga
  AJAX** por um snippet idempotente no fragmento (o `_tabela` re-executa a cada swap).
- **Handler do toggle delegado no `#expediente-painel`** (persistente), nunca
  re-ligado dentro do `_tabela` — evita empilhar listener a cada troca de resultado
  (mesma armadilha já documentada do listener de responsável).
- **Ordenação no modo lista:** como não há cabeçalhos de coluna, um **dropdown
  "Ordenar por"** (campo + asc/desc) visível no modo lista seta os mesmos hidden
  `ordenar`/`direcao` e chama `recarregarResultadosPastas` — reusa a ordenação
  server-side já existente (`PastaRepository::aplicarOrdenacao()`).

## Arquivos

- **Novo** `app/templates/pasta/_card.html.twig` — o componente de cartão, usado pelo
  modo lista (desktop) e pelo mobile.
- **Editar** `app/templates/pasta/_tabela.html.twig` — envolver os resultados num
  container com a classe de view; incluir o bloco de cartões (loop chamando `_card`);
  CSS do cartão + do toggle; snippet que aplica a view do `localStorage`. Remover o
  card mobile antigo (substituído pelo `_card`).
- **Editar** `app/templates/pasta/_tabela.html.twig` (linha de resultados) —
  botão-toggle segmentado + dropdown "Ordenar por" (modo lista). *(O `_filtros` só é
  tocado se for mais natural colocar o "Ordenar por" junto dos demais filtros.)*
- **Editar** `app/templates/expediente/index.html.twig` — handler delegado do toggle
  no `#expediente-painel`; salvar/restaurar a view escolhida (junto do estado já
  persistido em `sessionStorage`/`localStorage`).

## Testes

Sem UseCase novo (frontend/visual, risco BAIXO).

- **Functional (PHPUnit):** estender `ExpedienteFiltroPastasControllerTest` para
  garantir que a resposta AJAX do fragmento contém o bloco de cartões (`_card`) e o
  botão-toggle. Não quebrar os testes de filtro/ordenação existentes (suíte hoje
  1061/1061 verde).
- **Smoke E2E (Playwright, `e2e/`):** alternar Tabela↔Lista; conferir persistência
  após reload; validar que uma ação inline (trocar responsável) funciona no cartão.

## Fora de escopo

- Novos campos/dados no cartão além dos que a tabela já mostra (incremental depois).
- Kanban / agrupamento por status ou marcador.
- Mudanças no controller, no repositório ou no modelo de dados.

## Armadilhas conhecidas (do histórico da tela)

- `_tabela` re-executa a cada swap AJAX → listeners de negócio ficam **delegados no
  shell**; snippets no fragmento têm de ser **idempotentes**.
- `escHtml` não existe no escopo do shell — usar helper local se precisar escapar em JS.
- Não deixar a tabela "sumir atrás de Erro ao carregar" — o `carregarPainel` já é
  blindado com try/catch + checagem de `r.ok`; manter esse cuidado ao mexer no swap.
