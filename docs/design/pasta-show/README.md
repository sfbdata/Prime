# Handoff: redesenho da tela de Pasta (`pasta_show`) — BlueJus / Prime

## Visão geral

Redesenho da tela de detalhe de uma pasta jurídica (`app/templates/pasta/pasta_show.html.twig`)
e das suas seis abas: Dados da Pasta, Metas, Processo, Financeiro, Detalhes e Documentos.

Problemas da tela atual que este redesenho resolve:

1. A página era uma pilha vertical longa: dados → clientes → editor → timeline. O histórico
   automático ("Pasta atualizada", "Cliente da pasta alterado") ocupava mais espaço vertical que
   toda a informação do caso.
2. As seis ações do topo eram ícones sem rótulo, sem hierarquia e sem agrupamento.
3. Nada respondia "em que pé está o caso": prazos, situação financeira e metas exigiam clicar aba
   por aba.
4. Clientes competiam visualmente com os dados da pasta, ambos com o mesmo peso.

Decisões estruturais adotadas:

- Histórico automático do sistema saiu da página e virou **painel lateral** (drawer da direita),
  aberto pelo botão "Histórico".
- A aba **Dados da Pasta** virou duas colunas: anotações do caso no centro (zona quente), e trilho
  à direita com Próximos prazos, Clientes, Financeiro e Documentos — visíveis sem clique.
- A barra de ações do topo tem rótulo em texto, um único peso visual (nada em destaque azul) e as
  ações raras num menu `⋮`.
- Prioridade e etiquetas ficam juntas, editáveis na própria tela.
- Abas com aparência de controle segmentado (fundo cinza + pílula branca deslizante), porque
  usuários inexperientes não reconheciam as abas sublinhadas.

## Sobre os arquivos deste pacote

Os arquivos `.dc.html` são **referência de design em HTML** — protótipos que mostram aparência e
comportamento pretendidos. **Não são código de produção para copiar.** A tarefa é **recriar estes
desenhos no ambiente que o Prime já tem**: Symfony + Twig + Bootstrap 5.3 + Bootstrap Icons,
usando as variáveis `--bs-*` e as classes que já existem (`.fm-*` do gerenciador de arquivos,
`.jp-*`, `--jp-accent`), respeitando tema claro/escuro.

Para abrir: sirva a pasta por HTTP e abra `Pasta 1A.dc.html` (ele carrega `support.js` do lado).
Abrir por `file://` não funciona.

- `Pasta 1A.dc.html` — **o desenho aprovado**. Todas as seis abas, interações reais.
- `Pasta - 3 direções.dc.html` — as três direções exploradas antes da escolha (1A foi a escolhida).
  Serve como registro do porquê; não implemente 1B nem 1C.
- `assets/bluejus-white-transparent-nav.png` — logo já existente no repo em
  `bluejus-favicons/logo nav/`.

## Fidelidade

**Alta fidelidade (hifi).** Cores, tipografia, espaçamentos, raios, estados de hover e transições
são finais. Recrie fielmente, mas usando os componentes do Bootstrap que já estão no projeto —
não replique os estilos inline do protótipo (eles existem só porque o protótipo é um arquivo único).

Fonte: o protótipo está em **Source Sans 3** (aprovada pelo dono nesta rodada). Atenção: o
`app/public/css/app.css` hoje define `--jp-font-sans: Arial, sans-serif`, por decisão de 19/08/2026
("os usuários não aprovaram o Source Sans 3"). A aprovação desta rodada **reverte** essa decisão —
confirme com o dono antes de mexer no token. O comentário do próprio CSS registra que a tentativa
anterior falhou porque o `@font-face` apontava para `/fonts/opensans-regular.woff`, arquivo que
nunca existiu; ninguém viu Source Sans 3 de fato. Se for adotar, **hospede o arquivo da fonte**
junto ao app (não dependa do Google Fonts).

## O que é dado real e o que é proposta

Levantado lendo o repositório. Isto é o que separa "só template" de "mexe no back-end":

| Elemento do desenho | Situação |
|---|---|
| Prioridade Normal / Prioridade / Urgente | Real — enum `App\Pasta\Entity\PrioridadePasta` |
| Metas com prazo, responsáveis, status, dias de atraso | Real — visto no print da aba Metas (domínio `App\Tarefa\*`, `AtualizarPrazoTarefaUseCase`, `PrazoNaoEditavelException`) |
| Checklist de documentação (marcar/desmarcar) | Real — `PastaChecklistItem` tem `titulo`, `concluido`, `ordem` e `toggle()` |
| Valor da causa, Média por CPF/CNPJ, rótulo que muda para PJ | Real — `PastaFinanceiroOutput` (inclusive a regra "ausência de dado vira travessão, nunca R$ 0,00") |
| Contrato pendente/assinado, Pró-bono | Real — vistos no print da aba Financeiro |
| Processos vinculados, Principal, Peticionar na Pasta | Real — print da aba Processo; `PastaProcesso` |
| Documentos: pastas, arquivos, categoria, busca, lista/grade, ordenação manual | Real — `pasta-arquivos.css` / `pasta-arquivos.js`, `PastaSecao`, `PastaDocumento` |
| Observações por aba (Detalhes, Financeiro) | Real — `PastaObservacaoDetalhes`, `PastaObservacaoFinanceira` |
| Card "Próximos prazos" no trilho de Dados | **Proposta**: é um resumo das metas abertas ordenadas por prazo. Precisa de consulta nova (ordenação por prazo + limite), não existe hoje nessa tela |
| Card "Andamento das metas" (barra 2/4) e "Precisa de atenção" | **Proposta**: agregados calculados sobre as metas da pasta |
| Card "Responsáveis nas metas" | **Proposta**: contagem por pessoa |
| Pagamentos da pasta (previstos, recebidos, vencimentos) | **Proposta**: precisa de modelo/consulta de parcelas; confirme se já existe algo em `App\\Pasta\\*` ou se vem do módulo de cobrança |
| Etiquetas visíveis no cabeçalho | Real como feature (`SincronizarMarcadoresDaPastaUseCase`), mas a exibição no cabeçalho é proposta |

Os textos e números do protótipo são dados de demonstração das pastas 1180 e 1183 (dos prints do
dono). Não trate como fixtures.

## Telas / abas

Container: `max-width: 1500px`, `padding: 20px 26px 60px`, fundo `#f4f7f9`.

### Chrome (topo) — NÃO REDESENHAR

Pedido explícito do dono: o menu superior fica **exatamente como está hoje**. No protótipo ele foi
recriado só para dar contexto: barra teal `#0c7a9c`, altura 56px, hambúrguer, logo, separador,
"Pastas", nome do escritório com ícone `bi-building`, `bi-bell` com badge "9+" `#e0472e`,
`bi-arrows-fullscreen`, nome do usuário em maiúsculas, avatar e `bi-caret-down-fill`.
Abaixo, barra branca de 44px com EXPEDIENTE / DEMANDAS / PROCESSOS, item ativo com
`box-shadow: inset 0 -2px 0 #0c7a9c`.

### Cabeçalho da pasta (comum a todas as abas)

Card branco, `border: 1px solid #dde5eb`, `border-radius: 14px`, `box-shadow: 0 1px 2px rgba(16,42,58,.04)`.

Linha 1 — voltar + trilha + ações:
- Botão "Pastas" com `bi-arrow-left`: altura 30px, borda `#cdd7de`, raio 8px, fonte 12.5px/500.
  (Substitui o "Voltar às Pastas" que estava no rodapé.)
- Breadcrumb `Home / Pastas / 1180`, 12.5px, `#6b8494`, atual em `#12242f`.
- À direita, na ordem exata aprovada: **Arquivar** (`bi-archive`), **Editar** (`bi-pencil`),
  **Histórico** (`bi-clock-history`, abre o drawer), **⋮**. Todos altura 34px, borda `#cdd7de`,
  raio 8px, texto 13px/500 `#455c6b`; hover troca borda e texto para `#0f6fc4`.
- Menu `⋮` (216px, raio 10px, sombra `0 10px 30px rgba(16,42,58,.14)`): Duplicar pasta,
  Vincular processo, Trocar responsável, separador, **Excluir pasta** em `#a3232b`.
  O "Excluir Pasta" vermelho do rodapé foi movido para cá.

Linha 2 — identidade:
- `PASTA 1180` em 12.5px/500, `letter-spacing: .08em`, `#6b8494`, `tabular-nums`.
- Selo de **prioridade** clicável (`bi-flag-fill` + rótulo + `bi-chevron-down`), pílula 3px/9px:
  Normal cinza (`#4a6274` / `#eef2f5` / `#dde5eb`), Prioridade âmbar (`#8a5a12` / `#fdf1dc` / `#f0dcb4`),
  Urgente vermelho (`#a3232b` / `#fdeceb` / `#f2c9c6`). Abre popover de 176px com as três opções e ✓ na atual.
- Separador vertical 1px `#dde5eb`, depois as **etiquetas** da pasta como pílulas
  (`bi-tag-fill` + nome) e um botão tracejado `+ Etiqueta`.
- Título da ação: 28px/600, `line-height: 1.25`, `letter-spacing: -0.015em`, `margin: 14px 0 26px`,
  **`max-width: 50vw`** — o dono quer uma linha só, quebrando apenas se passar de metade da tela.
- Linha de dados (`display:flex; gap:32px 44px; flex-wrap:wrap`), rótulos 10.5px/700,
  `letter-spacing:.1em`, uppercase, `#7b93a2`; valores 14.5px. Ordem aprovada:
  **Cliente principal · Responsável · Processo vinculado · Última movimentação · Situação**.
  "Situação" fecha a linha, com bolinha 8px `#1f9d61` + "Ativo" em `#186c47`/600.
  (O selo verde "Ativo" que ficava solto no topo foi para cá.)
- "Processo vinculado" mostra o número do processo principal quando existe (link, `tabular-nums`,
  com classe · tribunal embaixo) e só cai em "— vincular" quando não há nenhum. **Header e aba
  Processo leem a mesma fonte de dados** — não podem divergir.

Linha 3 — abas (controle segmentado):
- Trilho `background:#eef2f5`, `border:1px solid #e0e7ec`, `border-radius:11px`, `padding:4px`,
  `position:relative`.
- Indicador que **desliza**: div absoluto, `background: rgba(255,255,255,.96)`,
  `border:1px solid #d3dde5`, raio 9px,
  `box-shadow: 0 1px 3px rgba(16,42,58,.12), 0 6px 14px rgba(16,42,58,.06), inset 0 1px 0 rgba(255,255,255,.9)`,
  `backdrop-filter: blur(6px)`,
  `transition: transform .62s cubic-bezier(.22,1.2,.28,1), width .62s cubic-bezier(.22,1.2,.28,1)`.
  Posição/largura medidas do botão ativo (`offsetLeft`/`offsetWidth`), recalculadas no resize.
- Botões: `padding:9px 15px`, 13.5px, ícone 14px (ativo em `#0f6fc4`, inativo `#8496a3`),
  ativo 600 `#12242f`, inativo 500 `#5f7684`, `transition: color .34s`.
- Ícones: Dados `bi-folder2-open`, Metas `bi-check2-square`, Processo `bi-bank`,
  Financeiro `bi-cash-coin`, Detalhes `bi-info-circle`, Documentos `bi-paperclip`.
- Badges (contagens) vêm dos dados, **nunca literais** — foi um bug corrigido duas vezes na revisão.

Troca de aba: o painel entra com `@keyframes` `opacity 0→1` +
`translate3d(0,10px,0) scale(.992)→none`, `.46s cubic-bezier(.32,.72,0,1)`.
(No protótipo há dois keyframes idênticos alternados só para re-disparar a animação; em React/Vue
use uma `key` ou uma classe de transição.)

### Aba 1 — Dados da Pasta

Grid `minmax(0,1fr) 356px`, `gap:18px`, `align-items:start`.

Coluna central — **Anotações do caso**:
- Cabeçalho: título 14px/600, contagem, "Ctrl + Enter envia" à direita.
- Compositor sobre fundo `#fafcfd`: caixa branca raio 9px, borda `#d3dde5`; barra de ferramentas
  com dropdown "Normal" + ícones `bi-type-bold`, `bi-type-italic`, `bi-type-underline`,
  `bi-type-strikethrough`, `bi-palette`, `bi-list-ol`, `bi-list-ul`, `bi-quote`, `bi-eraser`
  (28×28, raio 6px). Rodapé: "Visível para a equipe" + botão **Enviar** `#0f6fc4`.
- Lista: avatar 30px com iniciais, autor 13.5px/600, data 12px `#7b93a2`, selo opcional
  (Combinado / Ligação) em `#0f6fc4` sobre `#e8f2fa`, corpo 14.5px `line-height:1.6` `#243845`.
- Rodapé "Ver anotações anteriores (6)".

Trilho direito, nesta ordem: **Próximos prazos** (bolinha colorida + título + responsável/data +
selo de dias restantes: vermelho ≤2 dias, âmbar ≤8, cinza acima), **Clientes** (avatar, nome,
CPF em `tabular-nums`, selo Principal âmbar, link "abrir", botão `+ Vincular`) e
**Documentos** (3 itens + link "todos"). O card "Financeiro do caso" foi removido deste trilho —
o financeiro vive na sua própria aba.

### Aba 2 — Metas

Grid igual. Painel central:
- Filtros em pílula com contagem: Abertas / Atrasadas / Concluídas / Todas. Ativo:
  borda + texto `#0f6fc4`, fundo `#e8f2fa`.
- Botão **Criar meta** azul (`bi-plus-lg`), altura 32px.
- Linha da meta: `border-left: 3px solid` verde `#1f9d61` (concluída) / vermelho `#c0392f`
  (atrasada) / âmbar `#c8952a` (pendente); título 14.5px/600 (concluída fica riscada em `#6b8494`);
  metadados com `bi-person` (criador), `bi-people` (responsáveis), `bi-calendar-event` (prazo —
  "63 dias em atraso" em `#c0392f`/600); à direita selo de status e datas
  "24/06/2026 · mod. 02/07/2026" em 11px `tabular-nums`; `⋮` por linha.
- **Não existe botão de concluir meta nesta tela** (pedido explícito). Concluir acontece onde já
  acontece hoje.
- Vazio por filtro: `bi-check2-square` 30px + "Nenhuma meta neste filtro".

Trilho: **Andamento das metas** (26px `tabular-nums` "2/4" + barra 7px que anima em `.6s`,
legenda com três bolinhas), **Precisa de atenção** (só atrasadas, `bi-exclamation-triangle-fill`
`#c0392f`), **Responsáveis nas metas**.

### Aba 3 — Processo

Painel de largura total.
- Cabeçalho: "Processos vinculados" + contagem; à direita **Vincular processo** (neutro,
  `bi-link-45deg`) e **Peticionar na pasta** (azul, `bi-file-earmark-text`).
- Linha: número **formatado** `1059316-72.2022.4.01.3400` em 17px `tabular-nums` como link
  (hoje aparece cru, `10593167220224013400`), botão copiar `bi-copy`, selo
  **Principal** (`bi-star-fill`, `#2a5f8f` / `#eaf2fa` / `#cfe0f0`).
- Três campos rotulados: **Classe** ("Cumprimento de sentença" — hoje aparece "CUMPRIMENTO"),
  **Tribunal** ("TJDFT"), **Situação** ("Em andamento" com bolinha verde — hoje aparece
  "EM_ANDAMENTO"). Normalizar esses enums para rótulo legível é parte da tarefa.
- Ações: **Abrir** (`bi-box-arrow-up-right`) e desvincular (`bi-x-lg`, vermelho).
- Nota de rodapé explicando o papel do processo principal.

### Aba 4 — Financeiro

**Atualizada em 28/08/2026** (ver "Mudanças desta revisão" no fim do README). A estrutura abaixo é
a vigente.

Faixa de status no topo, **grade de 4 cards iguais**
(`grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px`) — cada um card branco
padrão (raio 14px, borda `#dde5eb`), rótulo uppercase numa linha de 18px de altura e o conteúdo
alinhado na mesma altura entre os quatro:

1. **Contrato** — selo Pendente/Assinado que **é o próprio botão**: clicar alterna o estado
   (mesmo comportamento do pró-bono). Altura 30px, raio 999px, verde quando assinado, vermelho
   quando pendente. O `title` explica a ação ("Contrato pendente — clique para marcar como
   assinado"). Não há mais link "marcar como assinado".
2. **Pró-bono** — selo clicável idêntico: "Pró-bono ativo" (verde) ⇄ "Não é pró-bono" (cinza).
3. **Valor da causa** — 24px/600 `tabular-nums`, lápis de editar no canto do rótulo,
   sublinha de apoio "valor cadastrado na pasta".
4. **Média por CPF** — card bege (`#fdf8ef` / borda `#ecdfc7`, rótulo `#a08444`), 24px/600,
   sublinha "4 clientes vinculados". Sem cliente vinculado: travessão + "Vincule o cliente para
   calcular", conforme o DTO.

Abaixo, grid `minmax(0,1fr) 356px`.

Coluna central — **Relatório financeiro** (nome usado na tela de produção; antes "Observações
financeiras"): mesmo compositor das anotações; lista com
`max-height: 390px; overflow-y: auto; overscroll-behavior: contain`.

Trilho direito:

- **Arquivos** — card próprio, no máximo 3 arquivos nesta área. Cabeçalho com contagem e botão
  **Adicionar**; lista compacta (ícone 24×29, nome com ellipsis, "412 KB · 19/06/2026 · autor",
  selo **Assinado** quando aplicável, `⋮`); rodapé com o switch **"Reduzir tamanho ao enviar"**
  (38×22, verde `#1f9d61` ligado, knob 18px, `.26s`) e uma linha de apoio que muda com o estado —
  ligado: "um PDF assinado digitalmente pode perder a assinatura"; desligado: "envios mantêm o
  arquivo original". Vazio: "Nenhum arquivo financeiro anexado."
  **Não há mais gerenciador de arquivos nesta aba** — nada de busca, filtros por categoria,
  "mostrar mais 5" ou dropzone. O gerenciador completo continua sendo a aba Documentos.
- **Pagamentos** — card com total recebido (22px `tabular-nums`) sobre o previsto, barra de
  progresso 6px, seção "Próximos vencimentos" com a nota "1 já pago", linhas
  descrição / vencimento / valor / selo de estado (Pendente âmbar, Pago verde) e botão
  **Adicionar pagamento** de largura total. Link "ver todos" no cabeçalho.
  Dados de demonstração: entrada de R$ 1.500 paga + 3 parcelas de R$ 1.300 = R$ 5.400 previstos.

### Aba 5 — Detalhes

Grid `minmax(0,1fr) 356px`.
- Central: **Observações da pasta** — compositor igual; cada observação com avatar, autor, data,
  selo opcional ("Atendimento"), `⋮`, e corpo com `padding-left:14px; border-left:3px solid #dbe9f2`,
  parágrafos 14.5px `line-height:1.65`, `max-width:86ch`.
  Observações longas (relato de atendimento) mostram 3 parágrafos e expandem com
  **"continuar lendo (N parágrafos)"** / "mostrar menos". Isto substitui o scroll interno da caixa
  azul de hoje, que escondia o conteúdo dentro de um scroll dentro da página.
- Trilho: **Registro da pasta** (Criada em, Modificada em, Criada por com avatar) + botão
  "Ver histórico do sistema"; e um card curto explicando qual observação vai em qual aba.
- Essas três datas devem vir da mesma fonte que alimenta o cabeçalho e o drawer — na revisão elas
  divergiram e foi tratado como defeito.

### Aba 6 — Documentos

Painel de largura total.
- Toolbar: trilha "Documentos" + contagem 224; busca em pílula (min 230px); **Nova pasta**
  (`bi-folder-plus`); **Enviar** (azul, `bi-upload`); alternador **lista/grade** segmentado
  (32×32, ativo com fundo `#0f6fc4` e ícone branco); botão de ordenação "Manual (arrastar)"
  (`bi-arrows-move`).
- **Checklist de documentação**: faixa com `bi-check2-square`, título, selo "3/5 itens"
  (âmbar incompleto, verde completo), barra de progresso 150×6px, botões editar e `+`;
  itens em grade fluida (`flex: 1 1 260px`) com caixa de marcar 19px que **funciona**
  (o `toggle()` existe na entidade), texto riscado quando concluído.
- **Pastas**: grade `repeat(auto-fill, minmax(210px,1fr))`, `gap:12px` — mesmos valores do
  `.fm-pastas` real. Card: `bi-folder-fill` 22px `#0f6fc4`, nome 13px/600 com ellipsis,
  contagem "22 arquivos"; hover levanta 1px com sombra.
- **Arquivos**: colunas `minmax(0,1fr) 150px 90px 110px 40px` — NOME / CATEGORIA / TAMANHO /
  MODIFICADO / `⋮`. Ícone por tipo com cor: PDF `#c0392f`, DOCX `#2a5f8f`, XLSX `#1f7a4d`,
  PNG `#6b4b8a` (`bi-filetype-*`). Categoria como pílula cinza. Linha com hover `#f7fafc`.
- Em **grade**: cards `minmax(158px,1fr)`, ícone 34px, nome com
  `-webkit-line-clamp:3` + `overflow-wrap:anywhere` (nomes longos com underscore transbordavam).
- Rodapé com "N arquivos nesta pasta · 224 no total, contando subpastas".
- Aqui o dono pediu **sem "mostrar mais": mostra tudo**. Isso vale para os arquivos da pasta atual;
  se a pasta atual tiver centenas de arquivos, virtualize ou pagine no servidor — não renderize
  centenas de linhas com tooltip cada.

## Interações e comportamento

| Interação | Comportamento |
|---|---|
| Trocar de aba | Indicador desliza (`.62s`, spring); painel entra com fade + subida `.46s` |
| Botão Histórico | Drawer 430px da direita, overlay `rgba(12,32,45,.36)`, sombra `-14px 0 40px rgba(12,32,45,.18)`; fecha no overlay e no × |
| Drawer de histórico | Eventos agrupados por dia (HOJE, 26/08 · 18/08/2026 · 14/08/2026), bolinha 7px `#c3d2dd`, "**Autor** ação" + hora |
| Selo de prioridade | Popover com Normal / Prioridade / Urgente, ✓ na atual; troca fecha o popover |
| Menu ⋮ | Abre/fecha e fecha o popover de prioridade (e vice-versa) |
| Filtros de metas | Filtram a lista |
| Checklist de documentação | Marca/desmarca, recalcula "3/5 itens" e a barra |
| Switches (reduzir tamanho, pró-bono) | Knob desliza `.26s`; textos de apoio mudam com o estado |
| Contrato | O próprio selo é o botão: clique alterna Pendente ⇄ Assinado (igual ao pró-bono) |
| "Continuar lendo" | Expande a observação inteira e vira "mostrar menos" |
| Lista/grade em Documentos | Troca a apresentação dos arquivos, mantém as pastas em grade |
| Hover geral | Botões neutros: borda e texto vão para `#0f6fc4`. Cards de pasta: sobem 1px com sombra |

Estado necessário (nomes do protótipo): `tab`, `histOpen`, `menuOpen`, `prioOpen`, `prio`,
`metaFilter`, `docView`, `obsAbertas[]`, `checklist[].ok`,
`reduzir`, `proBono`, `contrato`, e a geometria medida do indicador de abas.

## Tokens

Cores:

| Uso | Hex |
|---|---|
| Fundo da página | `#f4f7f9` |
| Fundo de card | `#fff` |
| Fundo suave (compositor, faixas) | `#fafcfd` |
| Borda de card | `#dde5eb` |
| Borda de controle | `#cdd7de` |
| Divisor forte / fraco | `#eef2f5` / `#f4f7f9` |
| Texto principal | `#12242f` |
| Texto de corpo | `#243845` |
| Texto secundário | `#455c6b` |
| Texto de apoio | `#6b8494` |
| Rótulo uppercase | `#7b93a2` |
| Texto fraco / placeholder | `#8496a3` / `#95a6b2` |
| Azul de ação (accent) | `#0f6fc4` — hover `#0b5596` |
| Azul claro de fundo ativo | `#e8f2fa` |
| Teal do topo (existente) | `#0c7a9c` |
| Verde (ok / ativo) | texto `#186c47`, ponto `#1f9d61`, fundo `#e2f4ea`, borda `#bde3cd` |
| Âmbar (pendente) | texto `#8a5a12`, ponto `#c8952a`, fundo `#fdf1dc`, borda `#f0dcb4` |
| Vermelho (atraso / destrutivo) | texto `#a3232b`, ponto `#c0392f`, fundo `#fdeceb`, borda `#f2c9c6` |
| Badge de notificação | `#e0472e` |

Tipografia (Source Sans 3): 28/600 título da ação · 20–26/600 números do trilho ·
14.5/600 título de meta · 14/600 título de card · 13.5/500–600 corpo de lista ·
13/500 botões · 12.5 apoio · 11 selos · 10.5/700 `letter-spacing:.1em` uppercase rótulos.
Números sempre com `font-variant-numeric: tabular-nums` (equivale ao `.jp-money` que já existe).

Espaçamento: 4 / 6 / 8 / 10 / 12 / 14 / 18 / 20 / 26 / 32 / 44 px. Grid principal `gap:18px`,
trilho `gap:14px`, padding de card 15–20px.

Raios: 14px card · 11px trilho de abas e faixas · 9px caixa interna, pílula de aba · 8px botão ·
7px botão de ícone · 6px ferramenta do editor · 999px selos e busca.

Sombras: card `0 1px 2px rgba(16,42,58,.04)` · popover `0 10px 30px rgba(16,42,58,.14)` ·
drawer `-14px 0 40px rgba(12,32,45,.18)` · hover de pasta `0 6px 18px rgba(16,42,58,.09)`.

Easing: `cubic-bezier(.32,.72,0,1)` para entradas e switches; `cubic-bezier(.22,1.2,.28,1)`
(spring) só para o indicador de abas.

## Assets

- Logo: `assets/bluejus-white-transparent-nav.png`, copiado de
  `bluejus-favicons/logo nav/bluejus-white-transparent-nav.png` no repo. Use o do repo.
- Ícones: **Bootstrap Icons** — a mesma família que o CSS do projeto já usa (`.bi` em
  `pasta-arquivos.css`, `cobrancas.css`, `app.css`). O protótipo carrega da CDN 1.11.3; no app,
  use o pacote que já está instalado.
- Nenhuma imagem além do logo.

## Antes de começar

1. `app/templates/` **não está versionado** no repositório — o redesenho foi feito a partir do CSS
   público, do domínio em `app/src` e de prints. Quem implementar precisa abrir o Twig real.
2. Os 63 `confirm()` nativos do projeto continuam sendo frente própria (registrado no
   `docs/design/carteira-show/README.md`). Não troque um só aqui — "Excluir pasta" segue com o
   diálogo atual até essa frente existir.
3. Tema escuro: o protótipo é só claro. Ao portar, troque os hex por `--bs-*` onde houver
   equivalente e use os tints translúcidos que o projeto já adota (`rgba(var(--jp-accent-rgb), .12)`).
4. Confirme com o dono a reversão da fonte para Source Sans 3 antes de mexer em `--jp-font-sans`.

## Arquivos

- `Pasta 1A.dc.html` — desenho aprovado (todas as abas).
- `Pasta - 3 direções.dc.html` — as três direções da rodada 1 (registro histórico).
- `support.js` — runtime do protótipo. Não é parte do design.
- `assets/bluejus-white-transparent-nav.png` — logo.

## Mudanças desta revisão — 28/08/2026

Atualização pontual em cima do handoff já entregue. **O resto do documento continua válido**;
só a aba Financeiro e um card da aba Dados mudaram. Quem já começou a implementar não precisa
refazer nada fora destes pontos:

1. **Aba Financeiro reorganizada** a partir da tela de produção enviada pelo dono: faixa superior
   com quatro cards de status/valor (Contrato, Pró-bono, Valor da causa, Média por CPF), alinhados
   entre si.
2. **Card Pagamentos** (novo) no trilho direito: recebido/previsto com barra, próximos vencimentos
   com selo de estado, botão Adicionar pagamento. **Depende de back-end** — ver a tabela "O que é
   dado real e o que é proposta".
3. **Gerenciador de arquivos removido do Financeiro.** No lugar, card **Arquivos** no trilho, com
   no máximo 3 itens, botão Adicionar e o switch "Reduzir tamanho ao enviar". Sem busca, filtros,
   paginação ou dropzone nesta aba.
4. **Contrato virou selo clicável** — o link "marcar como assinado / marcar como pendente" saiu.
5. "Observações financeiras" passou a se chamar **Relatório financeiro**, como na produção.
6. **Card "Financeiro do caso" removido** do trilho da aba Dados da Pasta.
