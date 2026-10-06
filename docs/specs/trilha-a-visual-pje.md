# Trilha A — entrega visual do padrão PJe (Dashboard + Pasta)

Data: 05/10/2026 · Risco: BAIXO (telas) · Frentes: `visual-pasta` (A1–A6) e `visual-dashboard` (A7–A10)

> **Estado em 05/10 (noite): INTEGRADA no master (17 commits sobre `a458e58e`) como ENTREGA
> INTERMEDIÁRIA.** O dono autorizou publicar o progresso atual, mas **não aprovou este visual como versão
> final do redesign**: as divergências ainda visíveis frente ao Claude Design não estão aceitas, os itens C
> do §6 e as decisões do §5 seguem abertos, e as funções novas do desenho (inclusive IA, sem mock) entram
> na próxima rodada, que parte deste master. Deploy e smoke em produção: do dono. Suíte no master
> integrado: 5633/5633 (21.045 asserções, 03:27).

Fonte do desenho (pacote do Claude Design, **não versionado**, abrir sempre pela RAIZ do pacote):

- Pasta → `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html`
  (template nas linhas ~1045–2780; os estilos calculados em JS a partir da linha ~2780:
  `situacaoVals` ~5860, `prioStyle` ~6200, abas `renderVals` ~6073–6100, `indStyle` ~6495).
- Dashboard → `docs/design/claude-design-2026-10-05 (1)/01 - Dashboard 1.2.2.dc.html` +
  `design_handoff_dashboard/README.md` (tokens, tipografia, cards, filtros, tabela, responsividade).
- Regras transversais → `design_handoff_bluejus_intelligence/README.md` §"Quinas no padrão PJe".

Esta spec é o **alvo da revisão** (`feature-review-agent`). O que não está aqui não entra.

## 1. Regras absolutas

1. **O desenho manda** onde mostra algo; onde é omisso, vale a convenção do sistema
   (ver CLAUDE.md da raiz, "Implementar a partir de um desenho").
2. **Nenhuma função atual pode sumir, regredir ou quebrar.** O inventário de preservação (§2)
   é contrato: ids, classes, `data-*`, XHRs e fragmentos continuam iguais.
3. **Nada fake.** Nenhum botão, link, número ou texto que não corresponda a função/dado real.
   Elemento do desenho que depende de função nova **não é renderizado** (sem "em breve",
   sem botão desabilitado, sem `href="#"` morto).
4. **CSS escopado por classe raiz da tela** (`.ps-page`/`.ps-body` na Pasta, `.db-page` no
   Dashboard). Nada global. Nenhum `.card-header` novo (clearfix `::after` do AdminLTE vira
   terceiro item flex).
5. **Tema escuro preservado**: toda cor nova entra como token redefinido em
   `[data-bs-theme="dark"]`; nunca hex cravado em regra.
6. **Responsividade preservada** (breakpoints atuais continuam valendo).
7. **Fonte**: Arial já é a do sistema (`--jp-font-sans`); o desenho também é Arial. Não tocar.
8. **Cantos**: até 4px em cards, painéis, menus, botões, campos e abas; 3px onde era 5px;
   círculos (avatar, pontos) e pílulas (selos 999px) continuam redondos — o próprio desenho os
   mantém.
9. Testes só são alterados onde **o desenho manda** mudança de rótulo/arranjo; cada alteração
   é declarada na mensagem do commit. Teste vermelho fora disso é regressão.
10. Teste de arranjo novo usa combinador de **filho direto** (`A > B`), que distingue "ao lado"
    de "em algum lugar da página".

## 2. Inventário de preservação

### 2.1 Pasta (`pasta_show`)

Cabeçalho (`templates/pasta/_cabecalho.html.twig`):
- `#btn-alternar-status` com `data-url`, `data-csrf`, `data-situacao-atual`; o script em
  `show.html.twig` (~l.3845–3900) procura dentro dele o `<i>` e `.ps-arquivar-label` e reescreve
  `[data-campo="situacao"] .ps-situacao`. Se mudar de lugar, os três vão juntos ou o script é ajustado.
- `.pasta-prioridade-btn` + `.prioridade-opt` + `.ps-pop-check` (show ~l.3905).
- `.js-mover-para` com `data-marcadores-ativos` e `data-csrf-token`.
- `[data-ps-pop]` (pasta-show.js), `#psHistoricoAbrir`, `#psHistorico`/`#psHistoricoOverlay`
  (fora de `.ps-page`, por causa do `transform` dos painéis).
- `#psMenuAcoes`: Vincular (`#modalVincularProcesso`), `#psMenuTrocarResponsavel`
  (pasta-show.js procura `.ps-cab-dados .pasta-resp-chip`), Excluir (POST + CSRF).
- `.pasta-resp-chip` com `data-url`/`data-resp-id`/`data-csrf` + `#pastaRespMenu` (compartilhado
  com a lista do Expediente — estilo só sob `.ps-page`).
- Setas `.ps-cab-nav > [data-nav]`, `<span>` inerte nas pontas (`PastaNavegacaoSetasTelaTest`).
- Restaurar + faixa de pasta excluída; menu ⋮ e Arquivar somem quando excluída.
- `#pastaTabs.ps-abas` com `.ps-abas-ind` e os sete `button.ps-aba#<id>-tab` como filhos
  diretos; classe `ps-abas--sem-js`; `#documentos-tab` é chamado por `pasta-arquivos.js`;
  `#hash` da URL abre a aba (pasta-show.js `abaDoFragmento`).

Dados: `#formTimelineMensagem`, `#timelineConteudo`, `#btnEnviarMensagem`, `#timelineMensagemErro`,
`#timelineList`, `#timeline-count`; `.btn-editar-msg-pasta`/`.btn-excluir-msg-pasta` com
`data-url`/`data-csrf`/`data-conteudo`; **o cartão da anotação também é montado em JS**
(show ~l.3290–3320) e a linha de cliente também (~l.1516) — espelhos mudam junto;
`#psAnotacoesMais`/`.ps-anotacao--extra`; `data-trilho` na ordem prazos·clientes·documentos;
`#clientesList`, `#clientesOutros`, `.cliente-principal`; `.ps-doc` + `#previewDocModal`.

Metas: `#modalCriarTarefa`, links `tarefa_show`.
Processo: `#processoTabContent` é **re-renderizado por XHR** (PastaController ~l.1124) — todo o
markup mora no parcial; `.js-ajax-processo-principal`, `.js-ajax-desvincular-processo`, `data-copy`, Peticionar.
Financeiro: `#financeiro-situacao-btn`, `#financeiro-probono-btn`, `#financeiro-valor-causa-*`,
`#financeiroRelatorio`, `#financeiro-docs-*`, `[data-trilho="arquivos"|"pagamentos"]`, `#modalNovoPagamento`.
Detalhes: `#formDetalhesObservacao`, `#detalhesObsLista`, botões por `data-url`.
Documentos: ids `fm*` (`#fileManager`, `#fmBody`, `#fmArquivos`, `#fmChecklist`, `#checklist*`,
`#btnChecklistModelos`…), modais `fm*Modal` fora da página.
Push: `.ps-push-lista > .ps-push-item > .ps-push-cab[data-push-url][aria-controls]`; abrir marca
como lida e remove `.ps-push-pip`.

Testes que travam: `PastaDadosArranjoTelaTest`, `PastaNavegacaoSetasTelaTest`,
`PastaShowClientesTest`, `PastaFinanceiroArranjoTelaTest`, `PastaObservacaoDetalhesControllerTest`,
`PastaShowDocumentosControllerTest`, `PastaChecklistModelosArranjoTelaTest`, `PastaPushProcessualTest`,
`PastaShowTokensDoDrawerTest` (tokens em `.ps-body`).

### 2.2 Dashboard (`/dashboard`)

- `DashboardController` lê `data_de`, `data_ate`, `responsavel`, `cargo`, `ordenar`, `direcao`; no XHR
  devolve só `dashboard/_resultado.html.twig`; acesso por módulo `bi`.
- `filtro-tabela.js` exige `[data-filtro-root][data-filtro-endpoint]`, `[data-filtro-form]`,
  `[data-filtro-resultado]`, `.js-filtro-campo` (evento `change`), `[data-filtro-chips]`,
  `.js-filtro-chip-remover`, `.js-filtro-limpar`, `th[data-ordenar]` **dentro** do resultado,
  `.js-filtro-calendario`; o fragmento é trocado por `innerHTML` (JS novo usa delegação).
- Casca: `[data-filtro-resultado]` em `display:contents`; `.db-cards-row`, `.db-filtro-wrap`,
  `.db-table-card` posicionadas por `order`; `.filtro-carregando > *`; `.sortable.asc|desc .sort-icon`.
- Avatar: `app_profile_foto_serve` + fallback `.collab-avatar-inicial`. Tema escuro existe.
- **Manter `<table>`** (ordenação e testes dependem); linha de Total em `<tfoot>`, nunca no `tbody`.
- Testes: `tests/Dashboard/Functional/DashboardControllerTest.php` (403/redirect, `.db-stat-card`,
  `td:nth-child(3)`, `'Cargo'`, `'Pastas Criadas'`, `th[data-ordenar]`, `sortable`/`desc`,
  `i.sort-icon`, XHR `asc`, `data-filtro-root`, `js-filtro-campo`, `'Desempenho por Advogado'`,
  `.collab-avatar-inicial`, `img[src*=...]`); `tests/Dashboard/Unit/ObterDadosDashboardUseCaseTest.php`.
- **Fora do escopo de hoje**: `filtro-tabela.js` (compartilhado), `_partials/_filtro_barra.html.twig`
  (compartilhado), `base.html.twig`, período padrão (decisão do dono pendente).

## 3. Entregas

### Pasta

**A1 — tokens e cantos** (`public/css/pasta-show.css`, só CSS)
- `--ps-bg: #eaeef1` (fundo da página do desenho, `body{background:#eaeef1}`); os três `background:#f4f7f9`
  cravados em `.ps-body*` passam para o token.
- `--ps-accent: #0a7aad`; `--ps-accent-hover: #086a96`; `--ps-accent-bg: #e5f1f7`; `--ps-hover: #eef5fa`
  (hover de item de menu do desenho). Escuro: re-derivar sobre o novo acento.
- Tokens novos (declarados agora, usados pelas entregas seguintes): `--ps-cab-bg: #f5f6f7`,
  `--ps-cab-border: #dfe4e8` (cabeçalho do painel principal), `--ps-trilho-cab-bg: #eef2f5`,
  `--ps-menu-tarja: linear-gradient(135deg,#0d80a3,#0b6688)`, `--ps-shadow-menu: 0 1px 3px rgba(0,0,0,.16)`,
  `--ps-pend: #c0392f`.
- Raios: 14/11/10/9/8/7/6 → 4; 5 → 3; 999 e 50% ficam. Lista: `.ps-card`, `.ps-btn`, `.ps-pop`,
  `.ps-pop-item`, `.ps-cab-excluida`, `.ps-abas`, `.ps-abas-ind`, `.ps-abas--sem-js .ps-aba.active`,
  `.ps-aba`, `.ps-aba-badge` (3), `.ps-paineis > .tab-pane:not(#dados)`, `.ps-compositor-caixa`,
  `.ps-anotacao-acao`, `.ps-anotacoes-mais`, `.ps-btn-mini`, `.ps-drawer-fechar`, `.ps-fin-bloco`,
  `.ps-fin-lapis`.
- Aceite: suíte `--filter Pasta` verde sem alterar teste; `PastaShowTokensDoDrawerTest` verde.

**A2 — abas no padrão PJe + linha vermelha de pendência** (`_cabecalho.html.twig`, CSS)
- Trilho: fundo `#eef2f5`, borda `#e0e7ec`, raio 4, padding 4, gap 3, `flex:1 1 600px` (ocupa a linha).
- Aba: `padding:9px 10px`, `flex:1 1 auto`, `justify-content:center`, texto **maiúsculas** 12.5px,
  `letter-spacing:.03em`, peso 500 `#4f6878` / ativa 600 `#0a7aad`; ícone 14px `#8496a3` / ativa acento.
- Selo: 10.5/600, raio 3, `#6b8494` em `#e2e8ed` / ativa acento em `#e5f1f7`.
- Indicador: deixa de ser pílula; vira `border-bottom: 2.5px solid #0a7aad`, fundo transparente, sem
  sombra. O JS continua medindo `top/height/width/transform` — o CSS só muda a aparência.
- Linha vermelha (`.ps-aba--pend`): barra absoluta `left:14%; right:14%; bottom:2px; height:2.5px;
  border-radius:2px; background:var(--ps-pend)`, com animação suave (`metaAlerta 7s`), desligada em
  `prefers-reduced-motion`. **Só quando há pendência real**, e **não na aba ativa**:
  Metas → há tarefa com `status != 'concluida'`; Processo → nenhum processo vinculado;
  Financeiro → `situacaoContrato == 'PENDENTE'` e não pró-bono; Push → `push.naoLidas > 0`.
  O `title` da aba ganha " · N pendente(s)" vindo do dado.
- Selo da aba Push passa a mostrar **não lidas** (o desenho). O teste existente
  `PastaPushProcessualTest:54` continua verde porque o fixture nasce não lido; só o TestDox dele é
  ajustado para dizer o que agora prova. A `.ps-contagem` do painel continua sendo o **total** —
  dois números para a mesma aba, de propósito: o selo responde "o que ainda não vi".
- Aba ativa com pendência: o sublinhado fica vermelho de 3px (`:has()`, aprimoramento progressivo),
  espelhando o `indStyle` do desenho.
- **Modo compacto** (revisão I1): o JS mede o trilho sem a classe e, se os sete rótulos não cabem,
  liga `.ps-abas--compactas` — aba inativa só com ícone e selo, como `tabsCompactas` no desenho. As
  abas nunca encolhem abaixo do próprio rótulo (`min-width: max-content`): sem compacto, o trilho rola.
- **As regras de pendência vivem no servidor** (`PastaPendenciasOutput`, com teste unitário), não no
  Twig (revisão M2). Financeiro conta também os **pagamentos vencidos** quando o contrato está pendente
  (revisão I2 — o desenho manda e o dado existe): "contrato de honorários pendente de assinatura e N
  pagamento(s) vencido(s)".
- Aceite: testes existentes de `#pastaTabs` verdes; teste novo `#pastaTabs > #tarefas-tab.ps-aba--pend`
  com e sem meta aberta (e o caso misto, 1 aberta entre 3); equivalente para Push com `naoLidas`.

**A3 — cabeçalho** (`_cabecalho.html.twig`, CSS, script em `show.html.twig` ~l.3845,
`PastaVizinhasOutput`, `PastaRepository::vizinha()`)
- Linha 1: Voltar (sem borda, hover `#e5f1f7`/`#0a7aad`) · "PASTA {nup}" 15/700 · divisória ·
  prioridade (raio 3, maiúsculas 11/600 `.05em`) · etiquetas (raio 3) · "+" 24×24 tracejado ·
  espaço · setas ‹ › (sem borda, divisória à direita) · ⋮.
- Título `<h1>` = **identificador da pasta**: `clientePrincipal.nomeExibicao` ou `nomeCliente`
  (em produção 1.200/1.201 têm `nomeCliente`; 49 têm cliente principal), em MAIÚSCULAS, 28/600,
  `max-width:min(50vw,640px)`. Ícone de pessoa 38px em círculo (`linear-gradient(135deg,#e3f1f7,#cfe6f0)`,
  cor `#0c7a9c`) à esquerda, **não clicável** (a foto do cliente é função nova).
- Linha sob o título: documento do cliente principal via `documento_br_rotulo`/`documento_br`
  (12px `#8496a3`), **só quando há cliente vinculado**.
- Ao lado do nome: "Movimentado hoje, HH:MM" / "Movimentado em dd/mm/aaaa" a partir de
  `pasta.modificadoEm` (é o mesmo dado que hoje já aparece como "Última movimentação" — semântica
  inalterada), ponto verde com pulso.
- Faixa de dados em grade `repeat(auto-fit,minmax(min(100%,220px),1fr))`, fundo branco, raio 4:
  **Processo vinculado · Ação · Responsável** (+ **Cobrança**, que o desenho é omisso e o sistema
  já tem). Rótulo 10.5/700 `.1em` `#7b93a2` com ícone `#0c7a9c`. Processo: número 14.5/700 + sub
  (classe · tribunal) + "trocar" (vai à aba Processo) + chip "N vinculado(s)" quando há mais de um,
  expandindo os demais `pastaProcessos` (número, classe, tribunal). "Cliente principal" sai da
  faixa (virou o título). "Última movimentação" sai da faixa (foi para o lado do nome).
- **Situação** sai da faixa e vai para a direita da linha das abas: pílula de 44px de altura,
  13.5/700 — Ativo `#e6f4ec`/`#186c47` ponto `#1f9d61`; Arquivado `#eef2f5`/`#4f6878`. É um menu
  com **só Ativo e Arquivado** (Suspenso/Cancelado são função nova) ligado ao `#btn-alternar-status`
  existente; o script de ~l.3845 passa a atualizar rótulo, cor e ponto da pílula.
- Ações do cabeçalho vão para dentro do ⋮ (tarja azul `--ps-menu-tarja` com "Pasta {nup}",
  sombra `--ps-shadow-menu`, largura 260, itens raio 4 hover `#eef5fa`): Editar dados
  (`#modalEditarPasta`) · Histórico (`#psHistoricoAbrir` **mantém o id**) · Vincular processo ·
  Trocar responsável · seção "Compartilhar": Copiar link (real, `navigator.clipboard` + URL da pasta)
  · Arquivar/Desarquivar (o `#btn-alternar-status` pode viver aqui OU na pílula — um só botão com o
  id) · Excluir pasta. **Fora**: Duplicar, Mover carteira, Timeline inteligente, Link do Push,
  Imprimir, Acompanhar, Favoritos, Cadeado, Cadastro, Intelligence.
- Setas: rótulo "Pasta anterior: NOME (pasta N)" / "Próxima pasta: NOME (pasta N)" —
  `PastaRepository::vizinha()` passa a selecionar `nomeCliente`; `PastaVizinhasOutput` ganha os nomes.
  A posição "N de M" **não entra** (o sistema não tem esse número).
- Testes atualizados de propósito (o desenho manda): ordem das ações do topo, contagem do menu
  (continua sem item inerte), ordem da faixa `['processo','acao','responsavel']`, título = identificador,
  rótulos das setas. Teste novo: `.ps-abas-faixa > .ps-situacao-wrap #btn-alternar-status`.

**A4 — aba Dados: "Registro dos expedientes" em linha do tempo** (`_dados_anotacoes`, espelho JS
em `show.html.twig` ~l.3290, `_dados_trilho`, CSS)
- Título "Registro dos expedientes"; cabeçalho do painel `--ps-cab-bg`/`--ps-cab-border`, padding 15/20,
  h2 14/600, contagem 11/700.
- Lista agrupada por dia: pílula de dia 13/700 `#3c4a54` em `#dde3e8` centralizada; fundo da área
  `#eef2f5`; linha vertical 2px `#d3dbe1` à esquerda; cartão branco raio 4 com seta; autor 13/700,
  hora à direita 12 `#5f7684`; foto do colaborador (`fotosResponsaveis[item.usuarioId]`) ou iniciais.
  **Tudo escopado em `.ps-registro`**: as observações do Financeiro usam as mesmas classes com a
  estrutura antiga (Twig + JS) e não mudam nesta trilha (revisão B1 de A4–A6).
- O compositor (Quill) continua igual; só a moldura ganha os tokens.
- Trilho: cabeçalhos dos cartões `--ps-trilho-cab-bg`, h2 13.5/700, contagem em `#e5f1f7`/`#0b5f86`;
  ícone do tipo de arquivo via `arquivo_icone()` no cartão Documentos.
- A pílula do dia é "5 out 2026" (o desenho monta `dia + mês abreviado + ano`), não "Hoje"; o
  mesmo mês abreviado em português no Twig e no JS. A pílula precede o primeiro registro do dia e
  é IRMÃ do cartão (`.ps-dia + .ps-anotacao`), escondida junto com ele quando ele está entre os
  "anteriores". O marcador "editado" fica ao lado do autor (o JS de edição passa a inseri-lo ali).
  Os atalhos "metas"/"todos" dos cartões do trilho viram o chevron › do desenho (botão).
- Aceite: testes `:340`, `:283`, `:665` verdes; novo `#timelineList > .ps-dia + .ps-anotacao`
  (dois registros em dias diferentes = duas pílulas; no mesmo dia = uma);
  o item criado por JS (sem recarregar) tem o mesmo markup do Twig.

**A5 — Processo, Metas, Detalhes e Push em cartão** (classes e CSS; o parcial do Processo continua
autossuficiente para o XHR).
- Processo: cartão com cabeçalho na faixa, "Vincular processo" (suave) e "Peticionar" (primário em
  maiúsculas); linha do tempo com a pílula "Vinculado em dd/mm/aaaa" (`PastaProcesso.vinculadoEm`, dado
  real) e um cartão por processo (número 17/500, selo Principal, Classe/Assunto/Tribunal/Situação com
  "Não informado" onde o cadastro está vazio, ações tornar principal/abrir/desvincular). Fora: dados do
  PJe, campos editáveis, "Ver todas as informações", pasta administrativa, ⋮, botão direito.
- Metas: cartão "Metas da pasta" + "Nova meta" (`#modalCriarTarefa`); lista sobre fundo cinza, uma
  linha por meta com borda e selo pelo estado REAL — concluída (verde, riscada), atrasada (vermelha =
  aberta com prazo no passado), aberta (âmbar; "Pendente"/"Para Revisão"). Fora: filtros, "Precisa
  de atenção", numeração, renomear, resolver na lista, sino, encaminhar.
- Detalhes: faixa com Criado em · Modificado em · Criado por e o painel "Relatório inicial de
  Atendimento" (compositor + lista de sempre; os itens mantêm o markup porque o JS da tela também os
  monta). Push: cabeçalho na faixa, título "Movimentações recebidas", tipo da publicação em pílula
  (sem linha do tempo com IDs, que é função nova).
- Financeiro e Documentos continuam no cartão neutro (A6); as demais abas saem dele.

**A6 — Financeiro e Documentos**: só tokens e raios (a A1 já cobre a maior parte); não reorganizar.

### Dashboard (frente `visual-dashboard`)

**A7 — casca e tokens** (`public/css/dashboard.css` novo, `templates/dashboard/index.html.twig`,
`public/css/filtro-tabela.css`)
- Mover o `<style>` inline para `dashboard.css`, escopado em `.db-page` (classe no `<section>` raiz);
  tokens do README §Tokens no claro + override `[data-bs-theme="dark"] .db-page` (padrão de
  `pasta-show.css`); raio 4px; ordem título → filtros → cards → tabela (só `order`); H1 30/700;
  restilizar `.db-page .filtro-barra` **sem tocar** o parcial global.
- Correção do ícone de data duplicado no Firefox em `filtro-tabela.css` (~l.83–104), conforme
  `design_handoff_dashboard/correcao-filtro-data.css` — afeta todas as telas com filtro de data
  (correção de defeito, não redesenho).
- Aceite: `--filter Dashboard` verde; teste novo de arranjo `[data-filtro-root] > .db-filtro-wrap` e
  `[data-filtro-root] > [data-filtro-resultado] > .db-cards-row`.

**A8 — 4 cards** (`DashboardOutput`, `ObterDadosDashboardUseCase`, `_resultado.html.twig`, CSS)
- `DashboardOutput::totalPastasCriadas` (soma de `pastasCriadas` das linhas, **parâmetro novo no fim,
  default 0**) e `metasConcluidas`/`metasTotal` (de `countMetasGlobal`, que já devolve ambos).
- Cards na ordem do desenho: Pastas criadas · Metas ativas · Demandas urgentes · Meta global (legenda
  "X de Y metas concluídas"); glifos SVG do desenho, número 50/700 tabular, rótulo 12/700 maiúsculas
  `.075em`, hover só em `(hover:hover) and (pointer:fine)`, entrada animada + contagem (`data-alvo`),
  tudo desligado em `prefers-reduced-motion`.
- Aceite: unitário `totalPastasCriadas` = soma das linhas (com e sem filtro; 0 sem colaboradores);
  funcional `.db-cards-row > * > .db-stat-card` com 4 itens, o primeiro contendo "Pastas criadas".

**A9 — tabela** (`_resultado.html.twig`, CSS, 2 testes de rótulo)
- Rótulos do desenho: "Desempenho" e "Colaborador" (sentence case nas demais: "Total metas",
  "Pastas criadas"…); cabeçalho `#f6f9fb` 11/700 maiúsculas; coluna ordenada em `#0c7a9c`; hover
  `#f4f9fc` + `inset 3px`; pílulas de vencidas/prazos sem borda com ponto; **zero em cinza `#aebdc7`**
  no lugar de "—"; avatar 34px com gradiente; primeira coluna sticky; nome com reticências + `title`;
  `<tfoot>` com linha de Total (soma via `|reduce` sobre `porAdvogado`); contador `porAdvogado|length`;
  nota de rodapé (texto do desenho).
- Testes atualizados de propósito: `'Desempenho por Advogado'` → `'Desempenho'`; `'Pastas Criadas'` →
  `'Pastas criadas'`. Novos: `table > tfoot > tr` com a soma; `table > tbody > tr` sem a linha de Total.

**A10 — segmentado de período e "Atualizado às"** (`public/js/dashboard.js` novo, `index.html.twig`,
`_resultado.html.twig`)
- Botões Este mês / 60 dias / 90 dias / Este ano preenchem `data_de`/`data_ate` reais e disparam
  `change` (o `filtro-tabela.js` recarrega); o ativo é calculado comparando as datas. **O período
  padrão NÃO muda** (decisão do dono pendente): sem datas, nenhum botão fica ativo.
- "Atualizado às HH:MM" renderizado no servidor **dentro do fragmento** (renova a cada XHR).
- Aceite: funcional com `?data_de&data_ate` mostra o botão correspondente ativo; sem datas, nenhum ativo.

## 4. Fora desta trilha (não implementar)

Números clicáveis, tendências, troféu, histórico de cards, menu ⋮ do Dashboard, preferências por
usuário, sons, Intelligence/Modo avançado, acesso restrito, zerar, meta de pastas, calendário/selects
próprios, cards no celular, PDF (`@media print`); nota técnica, responder/destacar comentários,
Suspenso/Cancelado, cadeado, ocultar abas, campos PJe, linha do tempo PJe com IDs, visualizador
Word/Excel, duplicados, seleção em lote, honorários/parcelas, metas novas, Chat, IA, link público,
Connect, foto do cliente, "N de M", Duplicar/Mover pasta.

## 5. Divergências desenho × dado real (decisões desta trilha)

| Ponto | Decisão |
|---|---|
| Título com CPF do cliente | Título = identificador (`nomeCliente`/cliente principal); CPF só com cliente vinculado |
| "Última movimentação" | É `modificadoEm`, como hoje; só muda de lugar e de formato |
| Selo da aba Push | Passa a ser **não lidas** (desenho); o painel segue mostrando o total |
| Rótulo das setas ‹ › | O desenho diz "Cliente anterior: NOME (pasta N)"; aqui é "Pasta anterior: NOME (pasta N)", porque o `nomeCliente` é o **identificador** da pasta (decisão do dono, 01/09), não necessariamente um cliente |
| Pendência de Financeiro | Contrato pendente sem pró-bono, **somando pagamentos vencidos** (como o desenho) |
| "Vinculado em dd/mm/aaaa" (aba Processo) | Só quando o vínculo tem `vinculadoPor` (feito pela tela); o legado recebeu `NOW()` da migration e os vínculos do DJEN não têm autor — ficam com o rótulo neutro do desenho, "Processo vinculado" |
| Prazo das metas | "vence dd/mm" · "N dias em atraso" · concluída: **revisto na Trilha B (L5)** — `data_conclusao` existe (296/296 em prod), então: concluída até o dia do prazo → "concluída no prazo dd/mm/aaaa" (desenho); depois → "concluída com N dia(s) de atraso" (desenho omisso, convenção); sem `data_conclusao` → "prazo dd/mm/aaaa"; sem prazo → sem rótulo. Compara DATAS, sem hora (`PastaMetasResumoOutput::rotuloPrazoConcluida`) |
| Cartão do processo sem "Ação" | A ação é da pasta (já está no cabeçalho); o cartão mostra só o que o cadastro do processo tem |
| Detalhes | A lista de observações mantém o markup antigo (o JS a monta); só a moldura e o título mudam — virar linha do tempo é da Trilha B |
| "Apensados" | O sistema não tem tipo de apenso → chip "N vinculado(s)" |
| Contrato "assinado" | Valores reais `PENDENTE`/`REGULAR`; a linha vermelha usa `PENDENTE` |
| "Cabeçalho azul" | Tarjas azuis nos menus; a barra global não é tocada |
| Raio no README do Dashboard (16/10/8) | Vale o dc 1.2.2 e o padrão aprovado: 4px |
| Período padrão "Este mês" | Não muda hoje (decisão do dono) |
| Demandas urgentes sem período (README) | Mantém o comportamento atual (aplica período) |
| Primeiro clique de ordenação `desc` | Não entra hoje (`filtro-tabela.js` é compartilhado) |
| Trilho "Próximos prazos" | Faixas do dc 1.2.3 (vermelho ≤ 3 dias, âmbar ≤ 7, cinza acima) e os textos "Falta(m) N dia(s)" · "Vence hoje" · "Vencido há N dia(s)" — o desenho escreve "Faltam 1 dia"; aqui a concordância é corrigida |
| Etiquetas | Chip na cor do `Marcador.cor` (via `color-mix`); marcador sem cor fica cinza neutro |
| Clientes do trilho | Avatar com iniciais e "abrir" como pasta (desenho); estrela (principal) e desvincular, que o desenho não tem, continuam no hover — tirá-las apagaria o único caminho de trocar o principal |
| Aba Metas — trilho | "Andamento", "Precisa de atenção" e "Responsáveis nas metas" vêm de `PastaMetasResumoOutput` (só conta `pasta.tarefas`); sem o ícone de conversar (Chat I.A) |
| Aba Detalhes | Grade do desenho: relatório em linha do tempo + "Registro da pasta" (criada/modificada/por, "Ver histórico do sistema" abre o drawer que já existe) + "Como usar esta aba"; "Pontos do relatório inicial" (IA) fora |
| Financeiro — relatório | Observações em linha do tempo (Twig + JS espelhados); cartões Arquivos/Pagamentos com cabeçalho limpo (sem faixa), como o desenho |
| Push | Linha do tempo por data; o título do cartão é o **órgão** (o DJEN não entrega o texto da movimentação em separado — o teor abre no cartão), a pílula é o tipo, "Novo" = não lida; a coluna direita (monitoramento, avisos, cliente) é função nova |
| Barra global (topo + Expediente/Demandas/Processos) | **Não tocada** — é `base.html.twig`/`app.css`, de todas as telas. Divergências medidas: fundo `#0078AA` × `#0c7a9c`, altura 58 × 56, logo 32 × 27px, avatar 34 × 30px, nome 0.9rem/700 × 14px/600 maiúsculas, sub-nav 12px × 13px/.06em com ativo `#0078AA` × `#0c7a9c`. Mudar é decisão do dono (vale para o sistema inteiro) |
| "3 de 7" entre as setas | Posição da pasta no acervo é consulta nova (COUNT ordenado) — registrado como C, custo baixo |

## 6. Auditoria de fidelidade × `02 - EXPEDIENTES 1.2.3.dc.html` (05/10, pós-smoke)

Conferência item a item da Pasta contra o `.dc.html` (template com estilos inline + estilos
calculados no script), feita depois do primeiro smoke do dono. **A e B corrigidos em `2d53a4d1`**;
as decisões novas estão na tabela do §5. O que ficou **C** (depende de função nova — nada é
fingido na tela):

- **Cabeçalho:** foto do cliente (botão), detalhes do cliente pelo botão direito, *BlueJus
  Intelligence*, botão *Cadastro* (cadastro mestre por CPF), "3 de 7" entre as setas, chip de
  apensados (tipo de apenso), selo carimbado de Suspenso/Cancelado/Arquivado e as duas situações
  novas, cadeado (acesso por pessoa), menu ⋮: Timeline inteligente, Duplicar, Mover para outra
  carteira, Link externo do Push, Imprimir resumo, Acompanhar alterações, Favoritos.
- **Registro / observações:** responder, encaminhar via Chat I.A, destacar com cor, etiqueta
  (Combinado/Ligação), presença do autor, "mostrar mais/menos" em textos longos, janela de edição
  de 15 min (hoje 24 h no servidor), menu do botão direito.
- **Trilho de Dados:** ⋮ por prazo (agenda .ics, alertar responsável), busca inline para vincular
  cliente, selo "CPF duplicado", ícone de cadastro completo/incompleto por cliente.
- **Metas:** filtros, numeração local/global, renomear na lista, sino de alerta, ⋮, drawer
  "Relatório da meta", "Precisa de atenção" com conversar no chat.
- **Processo:** dados do PJe (órgão, valor, distribuição, marcas, "Ver todas as informações",
  editar/atualizar), "Administrativo sem processo", ⋮ e botão direito, nota técnica, "Ação" por
  processo.
- **Financeiro:** selo "Assinado" por arquivo, ⋮ dos arquivos, modal "Adicionar pagamento" com
  cálculo de parcelas/juros, "Concluir edição" dos lançamentos.
- **Push:** análise por IA, filtros, criar tarefa, encaminhar, marcar lida, documento do PJe, coluna
  de monitoramento/avisos/avisar cliente.
- **Documentos:** explorador no padrão Windows (oito modos, Organizar, colunas móveis, painel de
  detalhes, seleção em lote/laço, duplicados, sugestões de limpeza, checklist por IA) — o
  gerenciador atual (`fm`, compartilhado com a Cobrança) recebeu só tokens e raios.
- **Barra global:** ver a linha do §5 — decisão do dono.
