# Spec — Modo escuro/claro (tema)

> **Status:** planejado, não implementado. Risco: BAIXO (frontend puro).
> **Como executar:** leia esta spec inteira e implemente seguindo a ordem da seção
> "Execução". Não é preciso re-investigar — a auditoria já está condensada aqui.
> Rode tudo no container: `docker exec jusprime_php_dev bash -c 'cd app && ...'`.

## Objetivo
Alternância de tema claro/escuro no sistema, com:
- **Conversão completa** (inclui os templates com `<style>` embutido).
- **Toggle discreto dentro do menu do usuário** (dropdown do avatar) — sem ícone novo na barra.
- **Padrão claro** no 1º acesso; escolha salva no navegador (`localStorage`), não por usuário/backend.

## Por que é viável com baixo esforço de base
O projeto usa **Bootstrap 5.3.3 + AdminLTE 4**, com modo escuro nativo via
`data-bs-theme="dark"` no `<html>`. ~70-75% da UI (cards, tabelas, dropdowns,
list-groups, `text-bg-*`, `text-muted`, `bg-body-tertiary`) adapta sozinha. Já existe
`<meta name="color-scheme" content="light dark">` e o `<body class="... bg-body-tertiary">`.
O trabalho real são as **cores fixas**: ~65 em `app/public/css/app.css` e ~617 em
`<style>` embutidos de 27 templates.

## Estratégia central (chave da manutenção)
**Não criar paleta escura paralela.** Substituir cada cor clara fixa pela **variável de
tema do Bootstrap** correspondente, que já inverte sob `data-bs-theme="dark"`. Marca
(`#0078AA`) e cores de status (verde/vermelho/laranja, kanban) **permanecem**.

### Mapa de substituição (aplicar em app.css e nos `<style>` dos templates)
| Cor fixa (uso) | Trocar por |
|---|---|
| `#fff`/`#ffffff`/`white` como **fundo** de painel | `var(--bs-body-bg)` |
| `#f8f9fa`/`#fafafa`/`#f5f7f9`/`#f4f6f9` (fundo sutil) | `var(--bs-tertiary-bg)` |
| `#454545`/`#333`/`#222` (texto/título) | `var(--bs-body-color)` |
| `#666`/`#777`/`#6c757d`/`#999`/`#aaa` (texto secundário) | `var(--bs-secondary-color)` |
| `#ddd`/`#dde3e8`/`#dee2e6`/`#e8ecf0`/`#f0f0f0`/`#eef0f2` (bordas) | `var(--bs-border-color)` |
| hovers azul-claros `#f0f7fb`/`#e8f4fb`/`#e6f3fa` | `var(--bs-secondary-bg)` |
| `#0078AA` (marca), status, kanban, `rgba(0,0,0,.x)` sombras | **manter** |

- `#fff` como **texto** sobre fundo colorido (ex.: header azul) → **manter**.
- Onde não houver var perfeita → regra pontual em bloco `html[data-bs-theme="dark"] { ... }`.
- Onde houver `!important` (sidebar/header em app.css) → manter o `!important` ao trocar.

## Execução (ordem sugerida, em lotes/commits)

### Lote 1 — Fundação (`app/templates/base.html.twig`)
- `<html lang="pt-BR" data-bs-theme="light">` (linha ~2).
- **Anti-flash:** `<script>` no topo do `<head>` (após a meta color-scheme, linha ~6):
  lê `localStorage.getItem('tema')` e, se `=== 'dark'`, faz
  `document.documentElement.setAttribute('data-bs-theme','dark')` **antes** do render.
- **Toggle** no dropdown do usuário (`.usuario-dropdown`, ~linha 221): item
  `<button type="button" class="dropdown-item" id="toggleTema">` com ícone lua/sol +
  `<span id="toggleTemaLabel">` ("Tema escuro"/"Tema claro") + `<hr class="dropdown-divider">`.
- **JS do toggle** no bloco `<script>` inline existente (~linha 336, vanilla JS):
  ao clicar, inverte `data-bs-theme` no `documentElement`, atualiza ícone+label e grava
  `localStorage.setItem('tema', ...)`. Inicializa label conforme o tema atual.

### Lote 2 — Esqueleto custom (`app/public/css/app.css`)
Aplicar o mapa no chrome: `.app-header.navtop` (mantém `#0078AA`),
`.app-sidebar.pje-sidebar` (fundo/borda/texto → vars; inclusive a regra
`.pje-sidebar * { color:#454545 }` → `var(--bs-body-color)`), `.navtop-*`, `.notif-*`,
`.usuario-dropdown*`, busca da sidebar, `.btn-action*`, scrollbars. Ao final, um bloco
`html[data-bs-theme="dark"] { ... }` só para ajustes finos (hovers, sombra do sidebar
aberto, contraste de badge sobre fundo escuro).

### Lote 3+ — Sweep dos templates com `<style>` (mesmo mapa, in-loco)
**Não mover estilos nem quebrar arquivos** (respeita "nunca mover e reescrever junto"
do `app/src/CLAUDE.md`). Só trocar cor fixa → var. Smoke visual após cada lote.
- **Hotspots:** `expediente/index`, `tenant/sedes`, `pasta/show`, `tarefa/show`,
  `kanban/board`, `kanban/index`, `agenda/index`, `dashboard/index`, `profile/index`,
  `pasta/_tabela`, `_partials/modal_mover_marcador`.
- **Médios/leves:** `pasta/demandas`, `pasta/_timeline`, `pasta/_financeiro`,
  `pasta/_detalhes_obs`, `ponto/index`, `ponto/_justificativa_calendario`,
  `cliente/show`, `cliente/index`, `servicedesk/show`, `processo/new`, `processo/index`,
  `tenant/users`, `tenant_role/_form`, `registration/index`, demais com `<style>`.
- **EXCEÇÃO:** `ponto/folha_pdf.html.twig` é documento de impressão/PDF → **fica claro**,
  não converter.
- `agenda` e `servicedesk` (migração de domínio planejada) por último.

> Dica de varredura: `grep -rn "#fff\|#fafafa\|#f8f9fa\|background:\|color:\|border:" app/templates/<arquivo>`
> para listar candidatos; aplicar o mapa com julgamento (fundo vs texto vs borda).

## Testes
- **Functional (smoke)** — WebTestCase numa rota logada: asserir `data-bs-theme` no
  `<html>`, presença do script anti-flash (`localStorage`/`tema`) e do botão
  `id="toggleTema"` no menu do usuário.
- **Lint Twig** dos arquivos tocados: `php bin/console lint:twig templates/...`.
- **Suíte:** `php bin/phpunit` — atenção: há **13 erros pré-existentes** em
  `tests/Expediente/Unit/` (`MoverPastaMarcadoresUseCaseTest`,
  `RemoverMarcadorDaPastaUseCaseTest` — classes inexistentes), **alheios** a este tema.
- **Verificação manual (essencial)** — alternar para escuro e percorrer: login,
  dashboard, expediente (tabela de pastas), detalhe de pasta (abas + timeline), detalhe
  de meta, agenda, kanban, ponto, notificações (sininho + página), perfil,
  usuários/sedes. Conferir contraste (sem texto escuro em fundo escuro, sem painel
  branco "estourado"); header/sidebar coerentes.

## Decisões de design registradas
- Header permanece **azul da marca** nos dois modos (branco sobre azul já contrasta).
- Persistência por **navegador** (`localStorage`); "por usuário" (campo em `User`) é
  evolução futura e seria risco ALTO (toca identidade).

## Git / commit
Frontend; risco BAIXO. Commitar em lotes lógicos (fundação, app.css, sweep por tier),
mensagens imperativas pt-BR ≤72 chars. Git de escrita é do humano (montar e entregar).
