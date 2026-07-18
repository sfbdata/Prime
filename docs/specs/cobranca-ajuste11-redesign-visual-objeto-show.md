# Ajuste 11 — Redesign visual da página do objeto (`cobranca_objeto_show`)

> **Risco:** BAIXO (só apresentação — Twig/CSS). Nenhuma regra de negócio, saldo, exigibilidade,
> acordo, permissão, DTO, entidade ou migration muda. **A única fonte de risco é acoplamento:** o
> `show.html.twig` carrega ~630 linhas de JS coladas à marcação (ids, `data-*`, classes). O redesign
> **preserva integralmente esse contrato** — muda o *envelope visual*, não os ganchos.
> **Origem:** pedido do humano (2026-07-17): *"recriar a `cobranca_objeto_show` com melhor UI/UX; o que
> está aí é provisório para mostrar o que o sistema tem; quero a versão final melhorada e alinhada com o
> propósito."* Direção escolhida em brainstorming: **visual + refinar fluxo, mantendo a espinha do
> Ajuste 10.**
> **Migration:** NENHUMA.
> **Mockup aprovado:** montado no OpenPencil nos dois temas e aprovado pelo humano (2026-07-18).
> Arquivos: `tmp/openpencil/cobranca-objeto-show.fig` (fonte) + `…-light.png` / `…-dark.png` (render 2×).

## 1. Objetivo

O Ajuste 10 acertou a **arquitetura de informação** (organizar pelo trabalho: quanto deve → o que
exige atenção → de quem → o que fazer). O que ficou foi a **pele**: componentes Bootstrap crus
(`btn-outline-*`, `badge text-bg-*`, `nav-tabs`), tudo empilhado numa coluna só, com o dinheiro tendo o
mesmo peso visual de um botão.

Este ajuste dá à página um **sistema visual próprio de "cockpit financeiro-jurídico"** e distribui o peso
em **duas colunas**, sem mexer em nada de negócio. Três dores nomeadas pelo humano:

| Dor | Causa hoje | Resposta do redesign |
|---|---|---|
| **Aparência genérica** | Só primitivos Bootstrap; sem tokens próprios | Sistema visual próprio: hero escuro do dinheiro, números em fonte mono (cara de extrato), cores semânticas, cartões com sombra suave em vez de contorno cinza |
| **Hierarquia fraca** | 4 métricas do mesmo tamanho; "próxima ação" perdida no meio | **Total em aberto** vira herói absoluto; **Próxima ação** ganha cartão de destaque no topo do trilho direito; alertas viram faixas acionáveis |
| **Densidade/poluição** | Tudo numa coluna, muito contorno competindo | Layout **2 colunas**: esquerda = o trabalho (dívida + extrato); direita = contexto (próxima ação, pessoa, ações do caso). Mais respiro no topo antes da dívida |

**Fora de escopo:** qualquer regra de negócio; os modais internos (permanecem os mesmos); permissões;
o comportamento do `pasta-arquivos.js`, do gerador de acordo e das prévias de pagamento.

## 2. O que NÃO pode mudar (contrato preservado)

O redesign é reescrita de **marcação + CSS**. Tudo abaixo é ganchos de JS que **têm de continuar
existindo com os mesmos nomes** (ver `show.html.twig` blocos `javascripts`):

- **Abas:** container `#objetoTabs`; painéis `#tab-cobranca`, `#tab-documentos`, `#tab-historico`;
  botão `#documentos-tab` (o `pasta-arquivos.js` restaura a aba por esse id).
- **Modais e seus ids/`data-*`:** `#modalRegistrarPagamento` (+ `data-acao="receber"`,
  `data-obrigacao-id`, `data-valor-centavos`, `data-bruto-centavos`), `#modalCriarAcordo`
  (+ checkboxes `name*="obrigacoesSubstituidasIds"`, `#parcelasContainer`, `#acordoData1`, etc.),
  `#modalEditarObrigacao`/`#modalExcluirObrigacao`/`#modalCorrigirPagamento`/`#modalRomperAcordo`/
  `#modalCancelarAcordo`/`#modalEncerrarVinculo` (todos reutilizáveis: a `action` vem do `data-acao-url`
  da linha), `#modalRegistrarTentativa`, `#modalConcluirAcao`, `#modalDefinirAcao`,
  `#modalJudicializar`, `#modalEncerrarCaso`, `#modalAlterarPessoa`, `#modalVincularPessoaObjeto`,
  `#modalNovaPessoa`.
- **Seleção de dívida:** `#secao-divida`, checkboxes `.jp-check`, linhas `.jp-obr`,
  `#barraSelecaoDivida` (+ `[data-selecao-qtd]`/`[data-selecao-total]`), botões
  `[data-acao="acordar"|"acordar-selecionadas"|"limpar-selecao"]`.
- **Marcador de erro B5:** `[data-modal-erro]` / `[data-modal-erro-acao]` (reabre o modal que falhou).
- **Âncora `#secao-divida`** (alertas e redirects `#secao-divida` dependem dela).

> Regra de ouro do plano: **primeiro reveste, sem apagar nenhum id/classe/`data-*`; só depois, se sobrar,
> remove-se o que comprovadamente não é gancho.** Um smoke com o gerador de acordo, o "Receber" na linha e
> o upload de documento é obrigatório antes do commit (o mapeamento pega ~80%, o smoke pega o resto).

## 3. Sistema visual (tokens)

> **Decisão do humano (2026-07-18): manter a identidade visual do sistema.** O mockup do OpenPencil usou
> azul/índigo, mas é **referência de LAYOUT** — a cor é **recolorida para o verde do módulo**. Nada de
> paleta índigo nova.

**Convenção (a mesma do `cobrancas.css` de hoje):** temar por **`--bs-*`** + o accent local do módulo
**`--jp-accent`** (verde: `#1f7a4d`, escuro `#4cc38a`; `--jp-accent-rgb` para rgba translúcido). Tema
escuro via `html[data-bs-theme="dark"]`; fundo de painel usa rgba translúcido, não hex claro (ver memória
`project_tema_escuro`). Só se cria token local novo (`--jp-*`) quando não houver `--bs-*` equivalente —
**não** introduzir um sistema `--cob-*` paralelo com hex fixos claro/escuro.

Mapa papel → token (não hex fixo, salvo o hero):

| Papel | Token |
|---|---|
| Fundo da página | `--bs-body-bg` |
| Superfície (card) | `--bs-body-bg` elevado / `--bs-tertiary-bg` |
| Traço/hairline | `--bs-border-color` |
| Texto forte / médio / fraco | `--bs-body-color` / `--bs-secondary-color` / `--bs-tertiary-color` |
| **Ação / accent (verde)** | `--jp-accent` + `rgba(var(--jp-accent-rgb), α)` para tints |
| Vencido / dívida | `--bs-danger` (+ `--bs-danger-bg-subtle`/rgba p/ tint da linha) |
| Alerta | `--bs-warning` (+ subtle) |
| Entrou / pago | `--jp-accent` (o verde É a cor de dinheiro-que-entra do módulo) |
| Hero (painel premium) | gradiente **verde-escuro** próprio (ex.: `#0F3D2E → #14533B`), texto branco, dinheiro branco, "recuperado" no mint `--jp-accent`, "vencido" em `--bs-danger` |

**Botões, para não colidir tudo em verde:** "Registrar pagamento", "Concluir ação", "Fazer acordo com
estas" e "Receber" = accent **sólido** (ação primária de dinheiro); "Acordar", "Novo acordo", "Nova
obrigação", "Trocar", "Reagendar", "Abrir acordo" = **outline neutro** (`--bs-border-color` + texto
`--bs-body-color`). Semânticas (vermelho vencido, âmbar alerta) permanecem.

**Tipografia:** UI em Source Sans 3 (stack do app); **números/dinheiro/datas em fonte monoespaçada**
(`font-variant-numeric: tabular-nums` já é usado no módulo — o mono nos valores dá cara de extrato e alinha
as colunas). Escala: rótulos 11–12px (maiúsculas, `letter-spacing` leve), corpo 13–14px, títulos de seção
~16px, nome do título ~22px, **herói do dinheiro ~40px bold**.

**Raios:** cartões ~1rem, botões/linhas .5rem, pills 999px. **Sombra:** elevação suave nos cartões
(substitui o contorno cinza; respeitar o tema). **Faixa vencida:** tint danger translúcido + "espinha" de
3px `--bs-danger` à esquerda da linha (o módulo já usa `box-shadow: inset 3px 0` — reusar o padrão).

## 4. Layout

Grid de **2 colunas** dentro do `container-fluid`, com `#secao-divida` preservado:

- **Coluna principal (~62%, esquerda):** hero do dinheiro → alertas → abas → conteúdo da aba Cobrança
  (`_divida` + `_movimentos`). Documentos e Histórico seguem nas outras abas, intactos.
- **Trilho direito (~38%):** cartão **Próxima ação** (destaque) → **Pessoa cobrada** (era o
  `_pessoa_card`, agora no trilho) → **Ações do caso** (Judicializar, Encerrar — antes no dropdown `⋯`).

**Responsivo:** abaixo de ~992px o grid colapsa para **uma coluna**, com o trilho direito indo para baixo
do hero (ou logo após os alertas). O hero e a fila da dívida nunca perdem prioridade. Nada de scroll
horizontal.

### 4.1 Mapa área → componente atual

| Área do mockup | De onde vem hoje | Muda o quê |
|---|---|---|
| Hero (Total/Vencido/Honorários/barra recuperado) | `.jp-resumo` (cockpit) em `show.html.twig` | Vira painel escuro; "Próxima ação" **sai** do cockpit e vira cartão no trilho |
| Alertas | bloco `caso.alertas` | Só reveste (faixa com ícone + ação); ações e âncoras iguais |
| Abas | `#objetoTabs` (3 abas) | Vira controle segmentado; ids idênticos |
| Dívida em aberto | `_partials/_divida.html.twig` | Reveste linhas (`.jp-obr`); **editar/excluir vão para um menu `⋯` na linha** (hoje são 2 ícones soltos) |
| Acordo vigente | grupo de acordo em `_divida` | Vira cartão com barra de progresso; ações e ids iguais |
| Barra "fazer acordo com estas" | `#barraSelecaoDivida` | Só reveste; contrato de seleção igual |
| O que já entrou | `_partials/_movimentos.html.twig` | Só reveste (linhas com ícone circular) |
| Próxima ação (trilho) | `.jp-resumo-acao` do cockpit | **Promovida** a cartão de destaque no trilho |
| Pessoa cobrada (trilho) | `_partials/_pessoa_card.html.twig` | Movida para o trilho; mesmos modais (Trocar/Envolvidos/Nova/Vincular) |
| Ações do caso (trilho) | dropdown `⋯` do header (Judicializar/Encerrar) | Viram linhas explícitas no trilho; o "Encerrar" desabilitado continua **ensinando** a condição |

## 5. Decisões de UX (mudanças de fluxo, dentro do escopo aprovado)

1. **Editar/excluir obrigação → menu `⋯` na linha.** Hoje são dois ícones sempre visíveis por linha
   (poluição). Viram um único `⋯` que abre os mesmos `#modalEditarObrigacao`/`#modalExcluirObrigacao`
   (os `data-*` migram para o item do menu; nenhum contrato de POST muda).
2. **Próxima ação promovida ao trilho** como cartão de destaque — é metade da resposta "o que fazer
   agora"; no cockpit atual ela divide espaço com métricas.
3. **Ações raras (Judicializar/Encerrar) saem do dropdown do header** para um cartão "Ações do caso" no
   trilho, explícitas mas fora do caminho do dia a dia. Mantêm os gates atuais (Judicializar exige módulo
   `pastas` e ausência de pasta; Encerrar exige `prontoParaEncerrar`, desabilitado ensina o que falta).
4. **Registrar contato** permanece no header (ação frequente).

Nada acima altera regra, permissão ou o que o servidor valida — só **onde** o gatilho vive.

## 6. Implementação (arquivos)

- `app/templates/cobranca/objeto/show.html.twig` — nova casca (grid 2 colunas), header, hero; move
  `_pessoa_card` para o trilho; mantém blocos `javascripts`/`stylesheets` **sem alteração de lógica**.
- `app/templates/cobranca/objeto/_partials/_divida.html.twig` — reveste linhas; junta editar/excluir no `⋯`.
- `app/templates/cobranca/objeto/_partials/_movimentos.html.twig` — reveste linhas do extrato.
- `app/templates/cobranca/objeto/_partials/_pessoa_card.html.twig` — reveste como cartão do trilho.
- `public/css/cobrancas.css` — tokens `--cob-*` (claro/escuro) + as classes novas (`.cob-hero`,
  `.cob-grid`, `.cob-rail`, revestimento de `.jp-*` etc.). **Sem tocar** `pasta-arquivos.css`.
- **Sem PHP.** Todos os dados já vêm de `MontarDetalheObjeto`/`CasoDetalheOutput`/`ObjetoDetalheOutput`;
  o redesign não pede nenhum campo novo.

## 7. Verificação

- Suíte: `tests/Cobranca` e `tests/Pasta` (o `pasta-arquivos.js` é compartilhado) verdes.
- **Smoke manual obrigatório** (dado de PROD em dev é de teste): abrir um objeto e exercitar —
  (a) "Receber" na linha pré-preenche o modal certo; (b) gerador de acordo soma/gera/fecha e o
  "Fazer acordo com estas" abre com a seleção; (c) editar/excluir pelo `⋯`; (d) upload de documento
  restaura a aba Documentos; (e) reabertura de modal com erro (B5); (f) os dois temas.
- `/review` (feature-review-agent) contra esta spec antes do commit.

## 8. Aparência ainda "mockup" (vira definitivo no código)

- Glifos de ícone do mockup (⚖ ⚑ ✎ ⋯) → Bootstrap Icons reais do app.
- Fontes do mockup (Ubuntu/Ubuntu Mono, únicas do host OpenPencil) → tipografia real do app, **mantendo a
  ideia da mono nos números**.
- Espaço vazio no pé do trilho é natural (trilho mais curto que a coluna de trabalho).
