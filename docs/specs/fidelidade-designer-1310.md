# Frente 2 — Fidelidade visual ao Claude Designer (pacote de 13/10/2026)

> **Retomando esta frente?** Comece por `fidelidade-designer-1310/HANDOFF.md` (estado, decisões, próxima entrega e comandos).

> **Para quem:** o dono e qualquer sessão que continue esta frente sem ter visto a conversa.
> **Escopo:** só a camada visual (Twig/CSS/JS). Banco, migrations, autenticação, permissões, APIs,
> CI/CD e changelog estão fora — são da frente de engenharia, que corre em paralelo.
> **Branch / worktree:** `fidelidade-designer` em `.claude/worktrees/fidelidade-designer`, base
> `origin/master` @ `a9653e66` (= produção, Engineering Loop publicado). Banco de teste
> `saas_testfidelidade-designer`.

## 1. Fonte

`/home/prime/projetos/bluejus-designer/REPOSITORIO_BLUEJUS/` (README, `especificacao/`, `prototipo/`).
O Engineering Loop anterior convergiu contra o pacote de **05/10** (`docs/design/claude-design-2026-10-05 (1)/`,
não versionado). Por isso o inventário desta frente é o **delta 05/10 → 13/10** mais as telas novas.

| Arquivo do protótipo | 05/10 → 13/10 |
|---|---|
| `01 - Dashboard 1.2.2` | mudou: 17 pílulas (999px) → 4px; inclui `bj-dica.js` e `bj-contas.js` |
| `02 - EXPEDIENTES 1.2.3` | mudou: 48 trocas de canto + 7 (b) + 16 (c) + 7 (d) — ver §3.2 |
| `bluejus-central.js` | mudou: 7 cantos + `/paradas` em linguagem natural + `ehPrimario` inclui Master |
| `Agenda 1A` | mudou: tag de tipo 999px → 4px; inclui bj-dica/bj-contas |
| `Push Compartilhado` | só inclui `bj-dica.js` |
| `03 Lista 1.1`, `04 Minhas Metas`, `05 Perfis`, `06 Trabalho Externo`, `07 Peticionar`, `FinBlue` | **novos** |
| `bj-dica.js`, `bj-contas.js`, `bj-agente.js`, mapas | **novos** |
| demais `.js` (editor, processo, visualizar, docsug, link, intelligence, avançado, equipe, docs, support) | iguais |

## 2. Entregas

| # | Entrega | Tamanho | Estado |
|---|---|---|---|
| E1 | Dashboard / Intelligence: cantos de 4px nos 6 elementos que existem no BlueJus | P | ✅ esta branch |
| E2 | Pasta: cantos de 4px nos 26 seletores cujo par mudou no 02 (§5) | P | ✅ `83d6724d` |
| E3 | Pasta: nota técnica (fundo `#f6fafd`, borda `#b9d6e8`, raio 4, conector 1.5px `#c9dfec`) e marca IA (10.5px/700/.12em `#0a6fa0`, "Intelligence" 14.5px) | P | ✅ `53aa6163` (§7) |
| E4 | Pasta: observações do Financeiro sem rolagem interna; nome do responsável com reticências (170px); situação do processo legível. (Tirar "Como usar esta aba" **só com decisão** — P2) | P | ✅ `47662002` (§8) |
| E5 | Minhas Metas: cantos ≤ 4px, hover azul da linha, numeração por grupo | P | ✅ `e227183b` (§9); a numeração foi substituída na E5b (P5 → R239) |
| E5b | Minhas Metas: nº na pasta (R239), traço vermelho (R129), status de prazo derivados, linha atrasada, caixa alta, abas no cartão dos filtros, trilho fixo com faixas, indicadores com ícone e halo, "Atualizado às" | M | ✅ `3b5e1bcd` (§10) |
| E5c | Minhas Metas: textos e cores do prazo, cores das abas, tipografia e legenda do trilho, "Título · Pasta NNN", ⋮ "Opções" com as ações que já existem (R118/R236) | M | ✅ `9d098c55` (§11) |
| E6 | Pasta: Responsável na 1ª linha do cabeçalho; "Ação" e "Processo vinculado" como cards no trilho da aba Dados | M | ⛔ decisão — conflito com Farlei R087 (§6) |
| E7 | Diálogo de confirmação próprio com variante azul + troca dos ~20 `confirm()` nativos da Pasta | M | — |
| E8 | Lista do Expediente: hover azul, chips de prioridade/situação, menu de botão direito só com ações que já têm rota | M | — |
| E9 | Peticionar: barra do documento selecionado, ⋮/botão direito, número de ordem — com as rotas existentes | M | — |
| E10 | Agenda: tag de tipo (nome da `LegendaCor` pela cor) na lista e no detalhe | P | — |
| E11 | Perfis do escritório: lista no padrão (hover, cantos, ícones) | P | — |

Ordem: primeiro o que é só CSS e não toca arquivo compartilhado (E2–E5), depois arranjo de tela
(E6–E9), por fim telas que nunca foram redesenhadas (E10–E11).

## 3. Diferenças restantes

### 3.1 Dependem de decisão do dono (não decidi sozinho)

- **Selo "9+" das notificações com 4px.** Só o protótipo do Dashboard mudou, mas no BlueJus o selo é
  global (`.navtop-badge` em `app.css`). Mudar lá altera todas as telas, e a regra de 05/10 é adotar
  os 4px tela a tela.
- **`bj-dica.js` global** (todo `title` vira dica no padrão). Mexe em `base.html.twig`, que é
  compartilhado. E o pacote se contradiz: o README diz "fundo azul com letra branca"; o `bj-dica.js`
  usa fundo `#e5f1f7` e letra `#0b4f73`.
- **`bj-contas.js`** (modal multiconta): o BlueJus já tem a troca de escritório no dropdown do topo.
  Refazer como modal é visual; "primário/secundário" é permissão (fora desta frente).
- **FinBlue** não é o BlueJus: o código mora em `/home/prime/projetos/finblue`. Fora desta frente, salvo ordem contrária.

### 3.2 Visuais puras ainda não feitas

- (a) **Pasta — cantos**: feito na E2 (§5). Ficaram de fora, de propósito: `fm-*` em `pasta-arquivos.css`
  (gerenciador antigo, congelado para a Cobrança), `.pasta-contagem` (CSS sem uso), `.pasta-resp-chip`
  (`_resp_estilo.html.twig`, compartilhado com a lista do Expediente → E8), `.ps-chip-vinc` (faixa do
  cabeçalho que o desenho trocou pelo andamento em fases → depende de backend), o chip de cliente montado
  em JS no modal de vínculo (`show.html.twig:1244`, sem par identificado no protótipo).
- **Central (chat)**: 7 trocas de canto — só onde a janela existe no BlueJus.
- **Dashboard × protótipo, fora dos cantos** (vistos na comparação de E1, não fazem parte do delta de
  13/10): o painel do protótipo tem a alternância "Escritório | Inteligência de Desempenho" e o KPI
  "Fila (backlog)"; o BlueJus mostra "Vencidas". Já existiam em 05/10 — conferir no ledger do
  Engineering Loop se são itens C/D antes de mexer.
- Itens de E2–E11 acima.

### 3.3 Dependem de backend (fora desta frente — registrar para a engenharia)

Stepper "Andamento da pasta" (10 fases, datas, reativação, "Avançar para X?") e tudo que depende dele;
registros automáticos do sistema na linha do tempo; apensos com tipo e "Vincular apenso"; Push por
processo/apenso; exclusão de comentário em 60 min (hoje 15); `/paradas` do chat Central; cadeado/sigilo
e destaque de linha da Lista; situações Suspenso/Cancelado; descrição e duplicar marcador; prioridade
por meta; classificação Master/Primário/Secundário e todo o painel Master (mapa, idades, contas, planos,
cotas); módulo Trabalho Externo inteiro.

## 4. E1 — evidência

- **Desenho:** `01 - Dashboard 1.2.2.dc.html` de 13/10, linhas 874, 997, 1077, 2618, 2626, 2642 (999px → 4px).
  Mantidos em 999px no mesmo arquivo: `qualBarra` (L2617), `sw()` (L2577), barra do ritmo (L1323).
- **Código:** `app/public/css/dashboard-inteligencia.css` — `.db-ia-av-switch`, `.db-ia-qual-trilho`,
  `.db-ia-dist-trilho`, `.db-ia-chip`, `.db-ia-nivel-chip`, `.db-ia-pergunta > summary`.
- **Fora do Dashboard do BlueJus:** os outros 11 elementos alterados no protótipo são do painel
  "Equipe" (visão, perfis, responsáveis, plano por colaborador, eu × média, contagem de pastas), que o
  BlueJus não tem nessa tela, e o selo "9+" (§3.1).
- **Teste:** `tests/Dashboard/Unit/DashboardCantosPjeIntelligenceCssTest.php` — vermelho antes (6
  falhas), verde depois (9/9); trava também os 3 que o desenho manteve em pílula. `tests/Dashboard`
  478/478 no banco da frente.
- **Comparação na tela, 1440×900:** servidor embutido apontando para a worktree, painel aberto, Modo
  avançado ligado **só no navegador** (classe no DOM; nenhuma preferência gravada). Raio calculado pelo
  navegador: 4px nos 6 seletores (23 nós visíveis), 999px no interruptor, na barra do ritmo e no
  preenchimento. Captura lado a lado com o protótipo no mesmo viewport: ✓ chips de contagem, ✓ chips
  de nível, ✓ botão "Modo avançado", ✓ interruptor continua arredondado. O modal de alerta do ponto foi
  removido do DOM para a captura, sem registrar nada.
- **Não visto:** celular (o raio não tem regra de mídia) e tema escuro (as variáveis de tema não tocam
  no raio).

## 5. E2 — evidência

- **Desenho:** `02 - EXPEDIENTES 1.2.3.dc.html` de 13/10. Pareamento linha a linha contra o de 05/10:
  48 trocas reais (44 de 999px→4px, 4 de 3px→4px — estas 4 são a nota técnica, que vai na E3). O
  protótipo manteve 24 pílulas (interruptores, preenchimentos de barra, selos de status em JS, "9+").
- **Mapa:** cada seletor do BlueJus pareado pela assinatura do estilo (padding, cor, altura) e pelo texto
  do elemento. Duas correções sobre o inventário automático, conferidas no protótipo: `.ps-mrel-situacao`
  **muda** (é o `pill` de `metaVals`, L3689), e os preenchimentos `.ps-pag-barra-cheia`,
  `.ps-metas-barra-cheia`, `.pex-ck-barra-fill` **continuam** pílula (L3617, L6890, L6823) — só os trilhos
  mudaram.
- **Código:** 26 regras em `pasta-show.css` (14), `pasta-ia.css` (2), `pasta-ia-agentes.css` (3),
  `pasta-documentos-sugeridos.css` (1), `pasta-explorador.css` (5), `pasta-checklist.css` (1). Só o valor do
  raio; nenhum HTML, JS, rota ou dado.
- **Teste:** `tests/Pasta/Unit/PastaCantosPjeCssTest.php` — 26 vermelhos antes, 38/38 depois (trava também
  os 12 que continuam pílula). `tests/Pasta` + `tests/Inteligencia`: 3458/3458 no banco da frente.
- **Tela, 1440×900, Pasta 297 (id 464) × protótipo (Pasta 1180), 7 abas:** raio calculado pelo navegador
  nos 16 elementos visíveis com esses dados — 4px em todos os que mudaram, 999px nos 4 que ficam. Os
  outros 22 dependem de estado que a pasta não tem (Push com itens, meta aberta, análise de IA, busca de
  cliente, pagamentos); para eles vale o teste da folha.
- **Estados:** filtro de metas em repouso/hover/foco = 4px; o protótipo também não define hover nem foco
  próprio nesse filtro (só `transition`), então o contorno padrão do navegador no foco é igual nos dois.
  Busca dos documentos: hover e foco = 4px; no foco, fundo branco e anel `0 0 0 2px rgba(15,111,196,.35)`,
  idêntico ao `style-focus-within` do protótipo (L2133). Contador do filtro de metas já era 4px.
- **Visto na comparação e fora da E2** (vai ao checklist §6): andamento em fases; Responsável na linha 1;
  botão "Cadastro" ao lado das abas; botão flutuante do Chat I.A.; botão de marcador a mais no checklist
  do BlueJus; caixas de seleção nas linhas de documentos; faixa "Ganhe espaço" (só com duplicados).
- **Não visto:** celular e tema escuro (o raio não tem regra de mídia nem de tema nesses seletores).

## 6. Checklist acumulado da Pasta

`fidelidade-designer-1310-checklist-pasta.md` — 377 elementos da Pasta (cabeçalho, 7 abas, trilho,
modais, menus ⋮ item a item, menus de botão direito, editor, visualizador, Chat I.A/Central, globais),
cada um com ✓/≈/∅, onde mora no BlueJus, se precisa de backend e o ID do ledger anterior.
Contagem inicial: **216 ✓ · 48 ≈ · 113 ∅**. A §19 dele separa os ausentes por natureza e diz o que dá para
criar já como casca visual; e registra os 3 conflitos entre o desenho de 13/10 e a especificação do Farlei
(que o README do pacote manda prevalecer): faixa do cabeçalho (R087), "Como usar esta aba" (R215) e
andamento em fases (sem pedido).

## 7. E3 — evidência

- **Desenho:** `02 - EXPEDIENTES 1.2.3.dc.html` de 13/10, pareado token a token com o de 05/10: L1246
  (marca), L1796/1798/1812 (nota no Processo), L2638/2640/2654 (nota no teor do Push — mesmo CSS).
- **Código:** `nota-tecnica.css` — tokens `--ps-nota-bg` `#f6fafd`, `--ps-nota-border` `#b9d6e8`,
  `--ps-nota-conector` `#c9dfec` (tema claro); `.ps-nota` e `.ps-nota-editor` com 4px; conector 1.5px.
  Selo, avatar, botões e campos da nota seguem com 3px, como no desenho. Os três tokens só são usados por
  caixa, editor e conector (conferido). `pasta-ia-agentes.css` — token novo `--ia-marca-cab` (`#0a6fa0`;
  no escuro, o mesmo `#6fc3e0` de antes), porque `--ia-azul-txt` também pinta o painel de agentes;
  rótulo com `gap: 2px; line-height: 1.1`; BLUEJUS 10.5px/700/.12em; Intelligence 14.5px.
- **Tema escuro:** o desenho não tem; os tokens do escuro ficaram como estavam (convenção do sistema).
- **Teste:** `tests/Pasta/Unit/PastaNotaTecnicaEMarcaIaCssTest.php` — 5 vermelhos antes, 5/5 depois (41
  asserções; trava também que o selo e `--ia-azul-txt` não mudaram). `tests/Pasta` + `tests/Inteligencia`
  + `tests/Processo`: 3677/3677 no banco da frente.
- **Tela, 1440×900, valores calculados pelo navegador, BlueJus × protótipo:**

  | Elemento | BlueJus | Protótipo |
  |---|---|---|
  | Caixa da nota | `rgb(246,250,253)`, borda 1px `rgb(185,214,232)`, 4px | idem |
  | Caixa do editor | idem | idem |
  | Selo NOTA TÉCNICA | `rgb(10,95,134)`, 3px | idem |
  | Conector | declarado 1.5px, calculado 1px `rgb(201,223,236)`, curva 6px | idem (o Chromium arredonda a borda para o pixel do dispositivo nos dois) |
  | BLUEJUS | 10.5px/700, espaçamento 1.26px, `rgb(10,111,160)` | idem |
  | Intelligence | 14.5px/700, `rgb(18,36,47)` | idem |
  | Botão da marca: repouso / hover | transparente / `rgb(229,241,247)`, 4px | idem |
  | Botão da marca: foco | contorno do navegador + fundo `#e5f1f7` | contorno do navegador, sem fundo (o anel declarado não renderiza) → PZ-CAB-50, aberto |

  O dev não tem nenhuma nota técnica: o editor foi aberto pelo botão real ("Adicionar nota técnica", sem
  salvar) e o cartão da nota foi inserido **só no DOM do navegador**, com a marcação do template. Nada
  foi gravado. No protótipo a nota foi salva pelo próprio protótipo (localStorage do navegador de teste).
- **Visto na comparação e fora da E3** (ao checklist): situação do processo impressa crua
  ("EM_ANDAMENTO", PZ-PRO-41); aviso "Integração com o PJe não configurada" do protótipo (PZ-PRO-15);
  faixa "Notas técnicas 0" do BlueJus (PZ-PRO-39, já C).

## 8. E4 — evidência

- **Desenho:** `02 - EXPEDIENTES 1.2.3.dc.html` de 13/10 — L1890 (relatório do Financeiro perdeu
  `max-height:520px; overflow-y:auto; overscroll-behavior:contain`) e L3084 (`respNomeStyle` ganhou
  `max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap`). Situação: o cartão do
  processo do protótipo mostra "Em andamento".
- **Código:**
  - `pasta-show.css` — `.ps-fin-obs-rolagem` só com o canto de baixo (saíram altura máxima, rolagem e a
    barra própria); `.ps-cab-dados .pasta-resp-chip .pasta-resp-nome` com 170px e reticências — só no
    cabeçalho, porque o chip é o mesmo da lista do Expediente. O nome inteiro continua no DOM e no `title`.
    O `scrollTop = 0` do JS das observações ficou inócuo; o `scrollIntoView` passa a rolar a página.
  - `SituacaoProcessoRotulo` (domínio Processo): mapeamento explícito `EM_ANDAMENTO`→"Em andamento",
    `ENCERRADO`→"Encerrado", `SUSPENSO`→"Suspenso", `PARALISADO`→"Paralisado" (os 4 valores dos selects de
    cadastro); valor fora da lista volta exatamente como veio (o Datajud grava texto livre). Filtro Twig
    `situacao_processo` e o resumo do PDF (`PastaResumoController`) usam a mesma classe, para tela,
    Copiar/Compartilhar/Imprimir resumo e PDF dizerem o mesmo. A cor verde continua decidida pelo código.
    **Nenhum dado gravado muda**; o contexto da IA (`MontadorDeContextoDaPasta`) segue com o código.
  - Foco da marca BLUEJUS Intelligence: **sem mudança** — decisão do dono (P4). Teste trava que o
    `:focus-visible` mantém o fundo e que nenhuma regra `.ps-cab-ia…` zera o `outline`.
- **Testes:** `SituacaoProcessoRotuloTest` (10: 4 conhecidos, 4 desconhecidos, filtro); 
  `PastaFinanceiroResponsavelFocoCssTest` (3; 2 vermelhos antes); `PastaProcessoCartaoTelaTest` +2 casos
  (situação legível com o dado intacto no banco; desconhecida como veio) e a expectativa de "Copiar resumo"
  atualizada para "Situação: Em andamento" (o mesmo teste prova que o PDF diz igual). `lint:twig` e
  `lint:container` OK. `tests/Pasta` + `tests/Processo` + `tests/Inteligencia` + `tests/Twig`: 3730/3730.
- **Tela, 1440×900, BlueJus × protótipo:**

  | Ponto | BlueJus | Protótipo |
  |---|---|---|
  | Nome do responsável | 170px, reticências, 14.5px/700 ("THÂMARA THAYS S…"); `title` com o nome inteiro | `max-width` 170px, reticências, 14.5px/700 (o nome da demonstração é curto, 123px) |
  | Hover / foco do chip | fundo de destaque; contorno do navegador; nome segue em 170px | — |
  | Situação | "Em andamento", verde (`--ativa`) | "Em andamento", verde |
  | Observações do Financeiro | 13 cartões (12 copiados **só no DOM**): lista 1528px, sem rolagem interna; página 1232→2564px | `max-height: none`, `overflow-y: visible`, sem rolagem interna |
  | Foco da marca | contorno do navegador + fundo `#e5f1f7` | contorno do navegador (anel declarado não renderiza) — divergência aceita |

- **Visto e fora da E4:** a dica do chip no protótipo é "Responsável pela pasta: X · clique para alterar"
  (no BlueJus, só o nome) → PZ-CAB-06; "Honorários contratuais" × "Valor da causa" (PZ-FIN-03, C) e o
  placeholder do relatório (PZ-FIN-05, C) seguem como estavam.

## 9. E5 — evidência (Minhas Metas)

- **Desenho:** `04 - Minhas Metas 1.0.dc.html` de 13/10 — L17-18 (hover da linha), L54 (KPI 4px), L68-70 (abas
  4px/3px), L81 (cartão de grupo 4px), L86 (número `{{ m.num }}.` #0a6fa0, margin-right 10px; JS L159:
  `num: j + 1` dentro de cada grupo), L93 (status 3px), L96-98 (botões 4px), L108 (barra e preenchimento 4px).
- **Código:** `minhas-metas.css` — raios de 13 regras; tokens `--mm-linha-hover-bg` #3b8fc2,
  `--mm-linha-hover-barra` #1f6f9c, `--mm-num` #0a6fa0 (escuro: o hover igual, o número no azul do escuro);
  `.mm-meta:hover` colocado **depois** das regras de tom (senão `.tom-ok` vencia o fundo) e o conteúdo da linha
  em branco por especificidade, sem `!important`. `_resultado.html.twig` passa `num: loop.index` nos dois laços
  (grupos abertos e recolhidos); `_meta_cartao.html.twig` põe `<span class="mm-meta-num">` dentro do link do
  título, como o desenho põe dentro do título. Sem dado, rota ou JS novo.
- **Escopo:** o modo **cartões** (padrão) é o equivalente do desenho e recebe hover e número; o modo **lista**
  (tabela, só do BlueJus) recebe só os cantos. `/tarefas/equipe` reaproveita o CSS: lá muda só o canto do status
  (999px→3px), coerente com o painel Equipe do Dashboard de 13/10, que também trocou pílula por 4px.
- **Testes:** `tests/Tarefa/Unit/MinhasMetasCantosHoverCssTest.php` (16; todos vermelhos antes) e
  `TarefaMinhasControllerTest::testNumeracaoRecomecaPorGrupo` (três metas em dois grupos → `[['1.','2.'],['1.']]`;
  vermelho antes). `tests/Tarefa` + `tests/Twig`: 344/344; `lint:twig` OK.
- **Tela, 1440×900, valores calculados, BlueJus × protótipo:**

  | Ponto | BlueJus | Protótipo |
  |---|---|---|
  | KPI / grupo / abas / aba / botões / barra | 4 / 4 / 4 / 3 / 4 / 4px | 4 / 4 / 4 / 3 / 4 / 4px |
  | Status | 3px | 3px |
  | Número | "1." "2." `rgb(10,111,160)`, margin-right 10px | "1. 2. 3. 4." / "1." / "1." (recomeça por grupo) |
  | Hover da linha | fundo `rgb(59,143,194)`, faixa `rgb(31,111,156)`, sombra `rgba(10,111,160,.16) 0 4px 12px`, z-index 1, filhos todos `rgb(255,255,255)`, status sem fundo | idem (a faixa é `inset 4px` no desenho; no BlueJus, border de 3px — MM-LIS-13) |
  | Foco por teclado no título | contorno do navegador; a linha não muda | o desenho não define foco (linha é `div` sem tabindex) |
  | `/tarefas/equipe` | status 3px nas 26 linhas | — |

  Nesta conta de dev só o grupo "Atrasadas" vem aberto; o reinício por grupo está provado pelo teste funcional e
  medido no protótipo.
- **Inventário completo:** `fidelidade-designer-1310-checklist-minhas-metas.md` — 155 elementos, 27 ✓ · 72 ≈ ·
  56 ∅ (38 deles do Chat I.A.), com os conflitos de especificação desta tela (P5, P6) e os próximos candidatos.
- **Visto e fora da E5** (no checklist): fundo rosado e faixa de 4px da linha atrasada; caixa alta no título;
  KPIs com ícone, halo e número de 46px; abas dentro do cartão dos filtros; "Atualizado às"; botão BLUEJUS
  Intelligence (sem painel — não recomendado); ⋮ no lugar da seta; Chat I.A.; defeito do BlueJus na pílula de quem
  vence hoje (MM-LIS-36).

## 10. E5b — evidência (Minhas Metas, continuação)

**Decisões do dono (14/10) aplicadas:** P5 → R239 · P6 → R129 · R238 preservado · ⋮ defeituoso do desenho não reproduzido
· divergência de "vence hoje" corrigida pela regra existente. P1, P2, P3 seguem **abertas**.

- **Número na pasta (R239).** Investigado: a meta não tem campo de número; a aba Metas da pasta já numera pela ordem de
  criação na pasta (`PastaMetasResumoOutput::emOrdemDeCriacao`). `TarefaRepository::numerosNaPasta` aplica a mesma regra
  em uma consulta de leitura para a lista inteira (raiz e junção em `Tarefa`, presas ao escritório pelo TenantFilter; só
  metas da mesma pasta). **Número global: dependência de backend** — não existe sequência por escritório; o id é global
  e revelaria volume de outros escritórios (PMC-035). Não exibido; registrado como MM-LIS-37.
- **Status de prazo.** `AgrupadorDeMetas::classificar` (a regra que já escolhia o grupo, agora pública e pura) +
  `StatusDoPrazoDaMeta` (só traduz o balde): Atrasada · Vence em breve · No prazo · Sem prazo · Em revisão · Concluída,
  nos dois modos. Meta que vence hoje → "Vence em breve"/warn nos dois (antes: vermelho num, âmbar no outro).
- **Visual** (`minhas-metas.css`, `minhas.html.twig`, `_resultado`, `_meta_cartao`, `_metas_lista`): linha atrasada
  #fdf3f3 e faixa 4px; título caixa alta 14.5px/700 numa linha (nome inteiro no `title`); concluída segue apagada e
  riscada (R238); pílula sem borda + tom cinza; abas, separador, filtros e modo no `.mm-barra-cartao` dentro da raiz do
  filtro (o `filtro-tabela.js` compartilhado não mudou); trilho `sticky` a `calc(header + subnav + 16px)` com faixas
  de 3px; indicadores com halo, ícone, rótulo sublinhado, número 46px (vermelho só com atrasadas) e faixa; traço
  vermelho sob o título; "Atualizado às" = hora da leitura no servidor, atualizada a cada recarga parcial.
- **Achados na tela que o teste não via (corrigidos):** (1) faixas do trilho saíam com 1px — `.mm-cartao`, depois no
  arquivo, devolvia a borda → seletor de duas classes; (2) o sticky não grudava — o AdminLTE (CDN) tem
  `@media (min-width:992px){ .sidebar-expand-lg.layout-fixed .app-main { overflow:auto } }`, que vira o contêiner do
  sticky → `body.layout-fixed .app-main:has(.mm-page) { overflow: visible }`, só nesta página; (3) no celular as 4 abas
  (725px) estouravam o cartão → quebram linha abaixo de 576px.
- **Testes:** `StatusDoPrazoDaMetaTest` (8: 7 casos + agrupador e pílula concordam); `MinhasMetasCantosHoverCssTest`
  (+7 → 23); `TarefaMinhasControllerTest` +5 (número na pasta = número da aba Metas da pasta, sem "Nº" global; status
  iguais nos dois modos; abas no cartão; indicadores; "Atualizado às" na página e no XHR; faixas do trilho). Prova por
  reintrodução: trocar o tom de "Vence em breve" derruba 2 testes unitários e 1 funcional. `tests/Tarefa` +
  `tests/Twig` + `tests/Dashboard` + aba Metas da Pasta: 851/851; `lint:twig` e `lint:container` OK.
- **Tela, 1440×900 (valores calculados):** linha atrasada `rgb(253,243,243)`, faixa 4px; hover sobre ela
  `rgb(59,143,194)`; título 14.5px/700 uppercase nowrap ellipsis; pílula "Atrasada" sem borda, 3px; indicador 160px,
  raio 4, número 46px/700 `rgb(163,35,43)` (há atrasadas), ícone 46px raio 12, halo 130px tracejado; cartão da barra
  raio 4, padding 14/18; traço 34×3 `rgb(192,57,47)`; faixas `3px rgb(30,123,69)` e `3px rgb(163,35,43)`; trilho a
  **118px** depois de rolar 1200px (lista alongada só no DOM); sem rolagem horizontal a 1440, 1100 e 390px.
  "Atualizado às": forçado para 00:00 no navegador, voltou para a hora da nova leitura depois de filtrar.
  Pílulas sem dado no dev (o dev não tem meta com prazo futuro): cores medidas em amostras só no DOM — Vence em breve
  `rgb(138,90,18)/rgb(253,241,220)`, No prazo `rgb(24,108,71)/rgb(226,244,234)`, Sem prazo `rgb(91,115,131)/rgb(238,242,245)`;
  com dado real, provadas pelo teste funcional.
- **Checklist de Minhas Metas:** 27 ✓ · 72 ≈ · 56 ∅ (E5) → **53 ✓ · 58 ≈ · 46 ∅** (157, contado no arquivo).

## 11. E5c — evidência (Minhas Metas, textos de prazo, abas, trilho e ⋮)

Escopo: §10 do `fidelidade-designer-1310/HANDOFF.md`. Só visual com dado que já existe; nenhuma rota, permissão,
migration ou entidade. P1, P2 e P3 seguem **abertas**.

- **Prazo da linha (LIS-20..25).** `TextoDoPrazoDaMeta` (novo) traduz o balde de `AgrupadorDeMetas::classificar` e o
  prazo real nos textos do dc (`prazoTxt`): "vence em N dia(s) · dd/mm/aaaa", "vence hoje", "sem prazo definido",
  "concluída", "N dia(s) atrasado"; só a do balde Atrasadas é #a3232b/700, o resto #4a6373 normal (em revisão vencida
  mostra os dias sem destaque, como o dc). `Tarefa::getPrazoInfo` intocado (tela da meta, Equipe, modo lista).
- **Abas (ABA-02/03/04/09).** Tokens `--mm-aba-*`: inativa 34px 13px/400 #34505f sem borda; ativa branca, #0a6fa0 700,
  sombra 0 1px 3px; ícone herda a cor; contador 11px/700 #dfe6eb/#4a6373 e ativo #e5f1f7/#0a6fa0.
- **Trilho (TRI-03/04/05/06/10/11/12).** Títulos 14px/700; "X de Y" 34px/700; barra #1e7b45 com `transition: width .4s`;
  legenda fixa "concluída(s) · atrasada(s) · em revisão" (dc `andLeg`) — a fatia "em aberto" (só do BlueJus) saiu, e as
  atrasadas contam pela regra, não pelo grupo (a aba Criei não tem grupo de atrasadas); "Título · Pasta NNN" 13.5px;
  ícone #a3232b; descrição 12px #5b7383. TRI-07/09/13/14 (regras e destino do clique) não mexidos.
- **⋮ "Opções" (MENU-05, 07..10).** Dropdown do Bootstrap (popper `fixed`, escapa do `overflow:hidden` do cartão) com
  "Abrir relatório completo" (`tarefa_show` — mantém o acesso que a seta dava), "Ir para a aba Metas da pasta"
  (`/pasta/{id}?meta={id}#tarefas`, realce do PMC-044) e "Editar nome" (`…&acao=renomear`: o `pasta-metas.js` aciona
  o "Editar nome" do ⋮ da linha, que só existe se a meta for editável; oculto aqui em pasta excluída, a mesma regra).
  O realce agora abre no filtro "Concluídas" quando a meta já foi concluída. Por que Bootstrap e não o `ps-pop`: o
  `ps-pop` é aberto pelo `pasta-show.js`/`pasta-metas.js`, que não carregam em Minhas Metas — trazê-los seria carregar
  a pasta inteira por um menu. "Encaminhar via Chat I.A" (R223) segue ∅, sem backend.
- **Revisão read-only** (feature-review-agent) achou e foi corrigido: legenda "0 atrasada(s)" falsa na aba Criei
  (médio); cor/hover novos do ⋮ vazando para a seta do modo lista e da Equipe; teste com saída por `skip`; comentário
  e checklist prometendo fundo no ⋮ aberto (no mouse a linha em hover pinta tudo de branco, como o dc). Ficaram para
  o smoke do dono: (a) o foco do campo de nome durante a rolagem suave do realce; (b) o "Precisa de atenção" ainda
  conta dias por `getPrazoInfo` (não zera a hora do prazo) — no dev os 106 prazos são à meia-noite e as contas batem.
- **Testes:** `TextoDoPrazoDaMetaTest` (11), `MinhasMetasAbasTrilhoPrazoCssTest` (15), `PastaMetaRealceVindoDeMinhasMetasJsTest`
  (2), `TarefaMinhasControllerTest` +5 (textos de prazo; ⋮ e seus destinos; sem "Editar nome" em pasta excluída;
  trilho; legenda na aba Criei), uma expectativa ajustada em `PastaMetaNovaRealceJsTest`. Prova por reintrodução: três
  mutações (destaque por `dias < 0`; "Editar nome" sempre; atrasadas pelo grupo) — cada uma derrubou o teste dela.
  Suíte completa antes da revisão: **OK 9306/9306**; depois das correções: `tests/Tarefa` 360/360 e a rodada
  Tarefa+Pasta+Twig+Dashboard **3708/3708**; `lint:twig` e `lint:container` OK.
- **Tela, 1440×900 (valores calculados), BlueJus × protótipo:** aba inativa `rgb(52,80,95)` 13px/400 34px, ícone na
  mesma cor; contador `rgb(74,99,115)/rgb(223,230,235)` 11px/700 padding 1/6; ativa `rgb(10,111,160)`/700, fundo branco,
  sombra `rgba(18,36,47,.12) 0 1px 3px`, contador `rgb(10,111,160)/rgb(229,241,247)`; prazo atrasado "75 dia(s)
  atrasado" `rgb(163,35,43)`/700 (protótipo: o mesmo; "vence em 4 dia(s) · 15/10/2026" e "concluída" `rgb(74,99,115)`/400);
  ⋮ `rgb(125,144,157)` 30×30 raio 4; "0 de 2" 34px/700/-.5px; barra `rgb(30,123,69)` `width .4s`; legenda 12.5px com
  pontos `rgb(30,123,69)`/`rgb(163,35,43)`/`rgb(10,111,160)`; "DETALHAMENTO DA DEMANDA · Pasta 1160" 13.5px; ícone
  `rgb(163,35,43)`; descrição `rgb(91,115,131)` 12px. Menu aberto: `position: fixed`, fundo branco, vai até 712px com o
  cartão terminando em 691px (não recortado), itens com as cores de menu sobre a linha azul em hover. Pasta 304 com
  `?meta=181&acao=renomear#tarefas`: aba Metas ativa, linha realçada, edição do nome aberta, URL limpa para
  `/pasta/304#tarefas`; sem `acao`, só o realce. Celular 390px: sem rolagem horizontal, menu dentro da tela (102–345px).
  O dev só tem 2 metas, ambas atrasadas: os outros textos de prazo estão provados pelo teste funcional.
- **Checklist de Minhas Metas:** 53 ✓ · 58 ≈ · 46 ∅ (E5b) → **72 ✓ · 43 ≈ · 42 ∅** (157, contado no arquivo).

