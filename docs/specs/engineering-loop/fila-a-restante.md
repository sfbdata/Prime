# Fila A restante — reconciliação em 07/10/2026

Auditoria somente leitura. Fontes: os 8 `docs/specs/engineering-loop/inventario-*.md` (linhas com Classe
começando por "A": 148, o mesmo total da §2 do ledger), §3.1 e "Novas divergências" do
`docs/specs/engineering-loop-fidelidade-designer.md`, as duplicatas da §2 e `git log 607f2c29..HEAD`.
Conferência no código feita por grep no checkout principal (`master`).

**Atenção: o master andou durante a auditoria.** No início o HEAD era `5dcdb37c`. No fim era `5f9082e1`, com
`42c9fc71` (PAS-041) e `6340a902`/`e3cd352d`/`5f9082e1` (DASH-006/020/017) já integrados, e PAS-025 sem commit
na árvore (`_dados_anotacoes`, `show.html.twig`). Esses itens contam como **em andamento**, conforme a ordem
recebida.

## 0. Colisões de ID (corrigir no ledger)

O ledger reaproveitou IDs que o inventário já usava para outra coisa:

| ID | No inventário | No ledger (§3.1 / ciclo 1) |
|---|---|---|
| PAS-047 | Timeline: notificações ligadas à pasta (A, P3) — **pendente** | "Alertar" e sino só para quem edita — feito `25184023` |
| PAS-049 | Timeline: fundo escurecido (A, P5) — **pendente** | Escrita da aba Processo escondida — feito `08f91d55` |
| PMC-058 | Foto no avatar da janela Detalhes do cliente (A, P4) — **pendente** | "Adicionar nota técnica" a quem só lê — em andamento (PAS-A6) |
| IA-033 | Classe C (D-PRAZOS) | Cabeçalho do Push sem máscara — feito |
| IA-034 | Classe C (D-MASTER) | Handler da fila não reconfere `IA_HABILITADA` — em andamento (PAS-A6) |

Abaixo, os IDs do inventário levam o sufixo **i** (PAS-047i, PAS-049i, PMC-058i) e os do ledger o sufixo **n**.
Proposta: renomear os do ledger (por exemplo PAS-051, PAS-052, PMC-060, IA-040, IA-041).

## 1. Contagem por área

"Feito": consta como feito na §3.1 e o commit está em `607f2c29..HEAD`, inclusive por duplicata da §2
(FPN-021 = PMC-017, FPN-022 = EDT-010 = PMC-019, DOC-117 = SEC-004, DOC-119 = SEC-001). "Virou F": não está no
ledger, mas o código atual já cumpre o item. "Pendente" conta os itens que ainda são A; as linhas que
reclassifiquei para C/D (§3) ficam numa coluna à parte.

| Área | A no inventário | Feito | Em andamento | Virou F | Pendente A | Reclassificado C/D/B |
|---|---|---|---|---|---|---|
| Dashboard | 12 | 4 (008, 010, 025, 026) | 3 (006, 017, 020) | 0 | 1 | 4 (011, 012, 027, 028) |
| Pasta/Dados | 29 | 4 (001, 014, 038, 046) | 3 (015, 025, 041) | 0 | 21 | 1 (044) |
| Processo/Metas/Clientes | 24 | 10 (001, 004, 017, 019, 027, 028, 030, 044, 056, 038 parcial) | 2 (002, 003) | 0 | 12 | 0 |
| Financeiro/Push | 17 | 8 (006, 012, 014, 019, 021=, 022=, 031, 036) | 1 (010) | 1 (029) | 6 | 1 (013) |
| Documentos | 21 | 17 | 0 | 0 | 4 | 0 |
| Editor | 22 | 7 (009, 010= parcial, 011, 015, 017, 021, 022) | 6 (001–006) | 0 | 9 | 0 |
| IA | 14 | 13 (001–008, 010–014) | 0 | 0 | 0 | 1 (009) |
| Segurança | 9 | 8 (001–008) | 0 | 0 | 0 | 1 (011) |
| **Total do inventário** | **148** | **71** | **15** | **1** | **53** | **8** |
| Novos A do ledger (ciclo 1+) | 25 | 20 | 3 (PMC-057, PMC-058n, IA-034n) | 0 | 2 (DOC-108b, PMC-059) | 0 |

Total que ainda falta fazer: **53 + 2 = 55 itens A**, mais o resto do EDT-010 (a @menção em Observações e
Financeiro: só a nota técnica foi feita, pelo PMC-019).

**Virou F (1):**
- **FPN-029** (recolher e expandir a análise da IA com a preferência salva). O IA-010 (`99cbb19a`) já faz isso,
  com a preferência no servidor (`ia_push_recolhidas()`, `preferencia_usuario`) em
  `templates/inteligencia/_analises_push.html.twig:14-41`. É melhor que o `localStorage` que a proposta pedia.

**Itens em que o ledger diz "feito", mas sobra trabalho:**
- **EDT-010:** `PastaMencoes.ligar` só é chamado no Registro (`show.html.twig:2954`) e na nota técnica
  (`nota-tecnica.js:44`). As observações de Detalhes e do Financeiro seguem sem @. Vira o item **EDT-010r**.
- **PMC-038:** só a moldura foi feita (`d8b7a015`). O corpo do modal é o PMC-059, que cobre também o PMC-039
  e o PMC-041.

**Conferidos no código e ainda pendentes:** todos os 53 da lista abaixo. Alguns exemplos da evidência:
- `bi-bell-fill` em `base.html.twig:218`;
- "Contexto da pasta" em `_documentos_sugeridos.html.twig:81`;
- `{% if false %}` em `show.html.twig:329`;
- 1600 ms em `pasta-show.js:225`;
- `d/m/Y H:i` em `resumo_impressao.html.twig:97`;
- `border-radius: 6px` e `rgba(12,32,45,.24)` em `pasta-show.css:4896/4914`;
- "Filtrar…" e `bi-person` em `_resp_menu`/`_resp_chip`;
- "Abrir em Metas" só no drawer (`_metas.html.twig:353`);
- primeiro nome + "+N" (`_metas.html.twig:~98`);
- resumo do processo em que o campo vazio some (`_processos_vinculados.html.twig:309-311`);
- `#b4460a`/`#e8873a` inline (`_processos_vinculados.html.twig:71-72`);
- nenhum `contextmenu` em `_detalhes_obs`;
- o confirm sem nome nem valor (`_financeiro_pagamentos_script.html.twig:254`);
- `push.total > 0` (`_push_processual.html.twig:89`);
- nenhum binding de `y` nem de Tab fora da lista em `editor-rico.js`;
- 0 `@media` em `editor-rico.css`;
- `tipoDe` sem `doc`/`tiff`;
- sem `juros_mensal`, `resposta_a`, `conteudo_anterior`, `nota_tecnica_versao` nem `foto_chave` em `src/`.

## 2. Itens A pendentes, agrupados em lotes

Regras seguidas:
- Dentro de uma onda, dois lotes nunca tocam o mesmo arquivo.
- A **onda 1** não toca os arquivos de IMP-A1, DASH-A2 nem PAS-A6 (`_cabecalho`, `resumo_impressao`,
  `pasta-show.css`, `_processos_vinculados`, `_financeiro*`, `show.html.twig`, `_dados_anotacoes`,
  `_detalhes_obs`, `_notas_tecnicas`, `_nota_tecnica_item`, `dashboard/*`).
- A **onda 2** só começa depois que os três lotes em andamento estiverem integrados.
- A **onda 3** são as migrations. Os lotes não dividem arquivos, mas as migrations são integradas uma de
  cada vez (§3.2 item 8 do ledger), na ordem da lista.

### Onda 1 — pode começar agora, em paralelo

**L1 · Editor** — `public/js/editor-rico.js`, `public/css/editor-rico.css` (+ testes novos do editor)

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| EDT-012 | Sinônimos (dicionário local `SIN`) no menu do botão direito | não | P3 | P |
| EDT-016 | "> " vira citação e "1)" vira numeração, com o chip "Formatação automática aplicada" + Desfazer | não | P4 | P |
| EDT-018 | "Citação" no seletor de estilo do parágrafo | não | P4 | P |
| EDT-019 | Ctrl+Y refaz também no Linux e no macOS | não | P5 | P |
| EDT-020 | Tab fora de lista insere 4 espaços não separáveis | não | P5 | P |
| EDT-023 | Botões da barra com 34×32 em `@media (hover:none)` | não | P4 | P |

**L2 · Visualizador e Sugerir** — `public/js/visualizador-documento.js`, `public/js/vendor/utif/` (novo),
`templates/pasta/_documentos_sugeridos.html.twig`

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| DOC-111 | `.doc` antigo: só o texto, com a faixa do desenho | não | P3 | P |
| DOC-112 | TIFF multipágina em canvas (UTIF local, sem CDN) | não | P3 | M |
| DOC-108b | `limparArvore` remove também `poster` e `background` | não | P4 | P |
| DOC-107 | Rótulo "Contexto do processo" no Sugerir | não | P4 | P |

**L3 · Metas da pasta** — `templates/pasta/_metas.html.twig`, `public/js/pasta-metas.js`,
`src/Tarefa/Controller/MetaNaListaController.php`, `src/Controller/TarefaController.php`

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| PMC-031 | Concluir e Reabrir por XHR, sem recarregar, com aviso + Desfazer | não | P3 | M |
| PMC-029 | "Abrir em Metas" também no ⋮ da meta | não | P4 | P |
| PMC-036 | Todos os responsáveis visíveis na linha | não | P4 | P |

**L4 · Timeline (fonte nova)** — `src/Pasta/Service/MontadorDaTimelineInteligente.php`,
`templates/pasta/_timeline_inteligente.html.twig`, `public/js/pasta-timeline.js`,
`src/Repository/NotificacaoRepository.php` (método novo)

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| PAS-047i | Notificações do usuário logado ligadas à pasta (pendente = não lida), cross-tenant | não | P3 | M |

**L5 · Miudezas** — `templates/pasta/_resp_menu.html.twig`, `templates/pasta/_resp_chip.html.twig`,
`templates/pasta/_tabela.html.twig` (o include do Expediente passa o contexto, para não tocar `show.html.twig`),
`templates/base.html.twig`

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| PAS-013 | Menu do responsável: ✓, "Filtrar responsável", chip vazio com `bi-person-dash` | não | P4 | P |
| FPN-035 | Sino do topo com `bi-bell` (sem `-fill`) | não | P4 | P |

### Onda 2 — depois de integrar IMP-A1, PAS-A6 e DASH-A2 (e o L1 antes do L6)

**L6 · show.html.twig, Registro e Observações** — `templates/pasta/show.html.twig`,
`templates/pasta/_dados_anotacoes.html.twig`, `templates/pasta/_detalhes_obs.html.twig`,
`public/js/pasta-dialogos.js` (novo, para não tocar `pasta-show.js`), `src/Controller/PastaController.php`
(só a rota legada) e `public/js/editor-rico.js` (só o EDT-014, depois do L1). **Não toca `pasta-show.css`**: se
precisar de CSS, espera o L7 ou usa o `<style>` do próprio modal. É um lote grande e de um escritor só; a ordem
interna proposta é modais → registro → observações → limpeza.

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| PMC-059 (+PMC-039, PMC-041) | Corpo do modal "Nova meta": rótulos 13px/700, contador, chips de responsáveis sobre o `<select>` | não | P3 | M |
| PMC-040 | Calendário próprio sobre `#tarefaPrazo` | não | P5 | M |
| PAS-019 | Editar dados: confirmar a troca do número | não | P4 | P |
| PAS-020 | Editar dados: pílula de estado, Salvar travado, bloco "Pendências" | não | P4 | P |
| PAS-021 | Editar dados: linha Responsável com "Trocar" | não | P4 | P |
| PAS-030 | Responder: a caixa abre sob o cartão | não | P3 | M |
| PAS-031 | Responder a uma resposta já começa com "@Nome " (token real) | não | P5 | P |
| PAS-032 | Edição do registro com o rodapé dentro do editor | não | P4 | P |
| PAS-033 | Dica "Publicado às … · última edição …" | não | P5 | P |
| PAS-034 | `confirmarPs`/`avisarPs` no lugar de `confirm`/`alert` | não | P4 | M |
| PAS-037 | Tirar a lixeira e o "· N min" da linha (só depois do PAS-025) | não | P5 | P |
| PAS-040 | Botão direito na observação abre o mesmo `.ps-obs-menu` | não | P4 | P |
| EDT-014 | "Inserir qualificação do cliente" no menu do editor (`data-*` só para quem vê o cliente) | não | P3 | P |
| DOC-124 | Remover o bloco `{% if false %}`, o JS legado e a rota `pasta_documento_upload` | não | P5 | M |

**L7 · Cabeçalho, CSS da pasta e aba Processo** — `templates/pasta/_cabecalho.html.twig`,
`public/js/pasta-show.js`, `public/js/pasta-clientes.js`, `public/css/pasta-show.css`,
`templates/pasta/resumo_impressao.html.twig`, `templates/pasta/_processos_vinculados.html.twig`

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| PMC-007 | Resumo Órgão · Valor · Distribuição sempre visível, "Não informado" no vazio | não | P4 | P |
| PMC-025 | Rótulos de Vincular, Peticionar e Nova meta abaixo de 768px | não | P4 | P |
| PMC-026 | Interruptor Administrativo com tokens e par escuro (sai o inline) | não | P4 | P |
| PAS-002 | Selo "ATIVO" passageiro ao reativar | não | P4 | P |
| PAS-022 | X de fechar nos popovers (`popovers()`; o menu da observação fica no L6) | não | P4 | M |
| PAS-023 | Arraste extraído para um utilitário e aplicado ao modal e às tarjas | não | P5 | M |
| PAS-024 | Quinas da Timeline em 4px | não | P4 | P |
| PAS-049i | Timeline sem o fundo escurecido (mantendo o clique fora) | não | P5 | P |
| EDT-007 | Copiar link: ícone `bi-check2` e 900 ms | não | P5 | P |
| EDT-008 | Tarja "Impresso em" com segundos | não | P5 | P |

**L8 · Push** — `templates/pasta/_push_processual.html.twig`, `templates/pasta/_push_teor.html.twig`,
`src/Pasta/Controller/PastaPushProcessualController.php` (montagem da aba),
`src/Processo/Repository/NotaTecnicaRepository.php` (busca por lista de publicações)

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| FPN-020 | Notas técnicas penduradas no cartão com o teor fechado (uma consulta, tenant + processo da pasta) | não | P3 | M |
| FPN-032 | Filtros também com a lista vazia | não | P5 | P |

**L9 · Dashboard × Agenda** — `templates/dashboard/_extras.html.twig`, `src/Controller/AgendaController.php`
(+ o repositório da Agenda)

| ID | O quê | Migration | Prior. | Esf. |
|---|---|---|---|---|
| DASH-009 | Link de "Eventos na agenda" por pessoa (filtro participante + período, mesma régua do contador) | não | P3 | M |

### Onda 3 — com migration (integrar uma de cada vez, nesta ordem)

| Lote | ID | O quê | Arquivos | Migration | Prior. | Esf. |
|---|---|---|---|---|---|---|
| M1 | FPN-005 | Responder a um registro financeiro | `PastaObservacaoFinanceira.php`, `EnviarObservacaoFinanceiraUseCase.php`, `_financeiro.html.twig`, `_financeiro_obs_script.html.twig` | sim (`resposta_a_id`) | P2 | M |
| M2 | PAS-039 | Responder nas observações de Detalhes (depois do L6) | `PastaObservacaoDetalhes.php` + UseCase, `_detalhes_obs.html.twig` | sim (`resposta_a_id`) | P2 | M |
| M3 | EDT-010r | @menção nas Observações e no Financeiro (depois do M1 e do M2) | UseCases das observações, `_detalhes_obs`, `_financeiro`, `pasta-mencoes.js` | não (conferir se a notificação é genérica) | P2 | M |
| M4 | PAS-043 | Timeline "Enquanto você estava fora" (depois do L4) | entidade nova `PastaTimelineVisita`, Montador, `pasta-timeline.js` | sim (tabela nova) | P2 | M |
| M5 | PAS-005 = PMC-052 + PMC-058i | Foto do cliente: menu, ajuste, avatar da janela (depois do L7) | `Cliente.php`, UseCase/Controller novos, `_cabecalho.html.twig`, `cliente/_resumo.html.twig` | sim (`foto_chave`, `foto_atualizada_em`) | P2 | G |
| M6 | PAS-045 | "Ver alteração" (Antes e Depois) na Timeline | `PastaMensagem.php`, `EditarMensagemPastaUseCase.php`, Montador | sim (`conteudo_anterior`) | P3 | M |
| M7 | FPN-009 + FPN-008 | Sufixo "· juros N% a.m."; confirm do lançamento com nome e valor | `PastaPagamento.php`, `RegistrarParcelamentoDaPastaUseCase.php`, `PastaPagamentoLinhaOutput.php`, `_financeiro_pagamentos_script.html.twig` | sim (`juros_mensal`) | P4 | P/M |
| M8 | PMC-020 | Versões da nota técnica (só gravar) | `NotaTecnica.php`, `EditarNotaTecnicaUseCase.php`, entidade/repositório novos | sim (tabela nova) | P4 | M |

Conferência: 6 + 4 + 3 + 1 + 2 = 16 itens na onda 1; 16 + 10 + 2 + 1 = 29 na onda 2; 10 na onda 3, somando o
EDT-010r e o FPN-008. Dá 55 + EDT-010r = 56, ou 53 do inventário + DOC-108b + PMC-059 + EDT-010r, com o
PMC-039 e o PMC-041 dentro do PMC-059.

## 3. Itens A que, olhando de perto, são C ou D (ou B)

| ID | Nova classe | Motivo |
|---|---|---|
| SEC-011 | C | Já está na §3.4 como decisão. Passar a "lida" para o POST muda o momento em que a publicação conta como lida. |
| FPN-013 | C | A proposta faz a "Lida" virar POST explícito, que é a mesma decisão do SEC-011. Também depende do D "Lida por escritório ou por pessoa". |
| IA-009 | B/C | É duplicata do **FPN-030**, que o mesmo ciclo classificou como B: só faz sentido com a IA ligada e exige auditoria da edição. O template registra "Edição… não existe no sistema". Falta decidir quem edita e como a edição é auditada. |
| DASH-011 | D | A própria proposta pede para "confirmar com o Farlei que 'Dados da pasta' = `PastaMensagem`". A métrica não está definida. |
| DASH-012 | D | Depende do DASH-011 (a lista "Relatório" e o bloco "Comentários" usam a mesma definição). |
| DASH-028 | C | Já está na §3.4: abrir com 2,65 s a cada carga atrasa o conteúdo. |
| DASH-027 | C | Mesma natureza do DASH-028 (5,4 s e sons) e ligada ao DASH-029 (C, rotulagem do Modo avançado). |
| PAS-044 | D/C | São regras de produto: o que "Validar atualização" faz (o desenho diz que ainda não grava no Cadastro), o estado auditado das sugestões e as regex tiradas do protótipo. Esforço G, com tabela de estado. Sem especificação, vira decisão minha. |

**Limítrofes (continuam A, mas a ratificação deve acompanhar o lote):**
- **PMC-039:** o contador é A; o limite (600 no desenho × 5000 hoje) é C.
- **PMC-052/PAS-005:** a própria proposta liga a troca de foto (PII) ao D-CLI-AUDIT.
- **PMC-020:** grava versões que o desenho não mostra; quem as vê e por quanto tempo ficam é decisão.
- **PMC-007:** o comentário do template afirma o desvio como intencional ("esta entrega não exibe"), sem
  decisão registrada. Pela regra "o desenho manda", segue A.
- **DOC-107:** o inventário já pede ratificar se a troca do rótulo foi intencional.
