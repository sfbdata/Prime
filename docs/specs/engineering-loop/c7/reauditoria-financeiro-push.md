# Reauditoria C7: Financeiro, Push processual e Notificações (FPN)

Auditoria somente leitura em 13/10/2026, HEAD `81ed4da1`. `dc` = `02 - EXPEDIENTES 1.2.3.dc.html`.
Base: `c6/reauditoria-financeiro-push.md`. Commits do C6 na área: `27cdae74`, `85686de6` (S6-PUSH), `4baade61`,
`a0ef20d2` (S6-MISC), `720086bd` (S6-OBS), `7265636b`, `3a6d3278` (S6-M1), `03d1fda5`, `20217298` (S6-SEC, rotas
de observação). O visual foi conferido lendo CSS e template, **não na tela**.

## Conferência

| Item | Veredito | Evidência |
|---|---|---|
| C6-FPN-01: notas em ordem cronológica, a nova no fim | ✓ | `NotaTecnicaRepository.php` (`emOrdemCronologica`: a consulta pega as N mais novas em DESC e inverte a página, de modo que o teto continua cortando as antigas); `nota-tecnica.js` (`insertAdjacentHTML('beforeend')`). O compositor fica depois da lista (`_notas_tecnicas.html.twig:81-98`). Vale para a aba Processo, o teor e o bloco pendurado. |
| C6-FPN-02: `.text-orange` global | ✓ | `app.css:258-259` (claro `#e8590c`, escuro `#ff8c4a`). A especificidade é igual à de `.notif-icon` e a regra vem depois, então vence o azul no dropdown. Na página `/notificacoes` (`index.html.twig:155`) nenhuma regra de cor disputa o `<i>`. `app.css` está carregado no `base` (`:91`) e no Peticionar (`:23`). |
| C6-FPN-03: Copiar texto com o teor inteiro | ✓ | Rota `pasta_push_texto` (`PastaPushProcessualController.php`, `texto()`): GET com a guarda do teor (tenant 404, VER 403, publicação de processo da pasta 404) e sem flush. Usa `resumirTexto(..., PHP_INT_MAX)`. JS: `pasta-push.js` (`copiarTextoDaPublicacao`), com `ClipboardItem` dentro de `try` (`85686de6`). Observação: o texto sai com os espaços colapsados, sem quebra de parágrafo. O desenho também colapsa no Registro (dc:5958); não é divergência. |
| C6-FPN-04: Adicionar nota sem marcar como lida | ✓ | `_push_processual.html.twig:164-168,238-254` (`penduraNotasPush`, `ocultoSeVazio`); `_notas_tecnicas.html.twig:42-52`; `pasta-push.js` §7 (`ajustarNotasPenduradas` por `ps:notas-mudou` e `MutationObserver` em `aria-expanded`). Com `penduradas=1`, o teor não duplica o bloco. Quem só vê não ganha o bloco vazio. |
| C6-PMC-05 (no `nota-tecnica.js` compartilhado): bloqueado clicável | ✓ | `_nota_tecnica_item.html.twig` (`.js-nota-bloqueado`, `data-aviso`, sem `disabled`); aviso neutro em `nota-tecnica.js` (`85686de6`). |
| C6-SEC-02b: lista do "@" do compositor financeiro | ✓ | `_financeiro.html.twig:167` (`origem=financeiro`); `ListarMencionaveisDaPastaUseCase` (passo 5, `VisibilidadeDoFinanceiroDaPasta`). `pasta-mencoes.js:209` já trata `&q=`. A caixa de edição reusa a mesma URL (`_financeiro_obs_script.html.twig:56,472`). |
| S6-M1 / PAS-045b: antes e depois do Financeiro na Timeline | ✓ | `PastaObservacaoFinanceira::editarConteudo` só guarda o "antes" quando o texto muda; `EditarObservacaoFinanceiraUseCase`; `MontadorDaTimelineInteligente` (`antes` só com `editadaEm`); a migration `Version20261012230000` é aditiva e anulável. |
| C6-SEC-01: posse nas rotas de observação do Financeiro | ✓ | `PastaController.php:2064-2067` e as rotas de editar e excluir: posse antes do 403; `findOneBy(id, pasta, tenant)` dá 404 para id de outra pasta. |

Placar: **8 conferidos: 8 ✓, 0 ◐, 0 ✗.**

## Regressões

Nenhuma. Arquivos compartilhados que conferi:
- `nota-tecnica.js` e `_notas_tecnicas`: a aba Processo continua sem `ocultoSeVazio`, então o bloco não esconde.
- `PastaController.php`: as rotas de observação mudaram só na guarda.
- `pasta-mencoes.js`: a URL com `?origem=` monta o `&q=`.

Um ponto latente, que **não** é regressão. `urlPadrao()` (`pasta-mencoes.js:42-46`) pega o **primeiro**
`[data-mencionaveis-url]` do DOM. A edição de um card do Registro (`show.html.twig:2778`) liga a menção sem
`_mencoesUrl` e depende disso. Hoje o compositor do Registro (`show.html.twig:214`) vem antes do Financeiro (`:239`),
então a lista sai certa. Se o compositor do Registro deixar de ser renderizado ou mudar de posição, a edição do
Registro passa a usar a lista filtrada do Financeiro (mais estreita, sem vazamento). Para endurecer: definir
`textareaEd._mencoesUrl` explicitamente em `show.html.twig:2778`.

## Pendências A

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho | Código | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-FPN-05 | A | P5 | P | não | Minutos do Editar e do Excluir no ⋮ do Relatório financeiro | dc:5958: `pm = comPerm(n, criado)` é recalculado a cada abertura do menu ("Editar · N min" / "· bloqueado") | `_financeiro.html.twig:1173-1177` lê o número do texto fixo `.ps-anotacao-janela` (`:1479-1480`; o card montado em JS sai com "· 15 min" fixo em `_financeiro_obs_script.html.twig:198`). Passada a janela sem recarregar, o menu segue "Editar · N min" liberado e o servidor recusa. | Mesma solução de Detalhes (C6-PAS-05). (1) `data-janela-ate="{{ obs.criadaEm\|date('U') + comentario_janela_minutos() * 60 }}"` no `<article>` (`:1441`). (2) `criadaEmEpoch` no JSON de `pasta_financeiro_observacao_enviar` (`PastaController.php:2102-2114`, hoje só `criadaEmTs` em ATOM) e `data-janela-ate` no card do JS (`_financeiro_obs_script.html.twig:171`). (3) Em `prepararMenuObs`, `minutosRestantesObs(registro)` (teto, de 1 a 15; 0 = vencida), copiado de `_detalhes_obs.html.twig:609-614`. Com 0, os dois itens viram "· bloqueado" com `OBS_MOTIVO_PRAZO`. Item que nasceu bloqueado não reabre. Opcional: atualizar também o "· N min" do card. | Tela: `#financeiro-obs-<id>[data-janela-ate]` igual a epoch + 900. Functional: o JSON do enviar traz `criadaEmEpoch`. Unit de JS (padrão `PastaPushMenuContextoJsTest`): `prepararMenuObs` chama `minutosRestantesObs` e não lê `.ps-anotacao-janela`. |

## Novas

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho | Código | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C7-FPN-01 | A | P3 | M | não | Aba Financeiro oferece escrita a quem só vê a pasta e na lápide | O desenho não trata perfil de leitura; vale a convenção do sistema já aplicada a Detalhes (`_detalhes_obs.html.twig:59,78-86,1128-1158`: compositor só para quem escreve, nota de leitura na lápide, Editar/Excluir fora para quem não escreve), ao Push (FPN-014c/040L), ao Registro (PAS-025c) e a Documentos (DOC-118) | `_financeiro.html.twig:160-180`: o compositor sai sempre, inclusive na lápide. `:1483`: Responder só esconde na lápide. `:246-251` e `:1166-1170`: "Editar/Excluir · bloqueado" com motivo de **autoria/prazo** para quem não tem permissão e na lápide (motivo falso, o defeito que o S6-DET corrigiu em Detalhes). Lápis de honorários (`:103`), "+" de parcela (`:498`), menu de Pagamentos (`:461-475`) e itens de arquivo também saem. Todas as rotas exigem `edit` (`PastaController.php:1842,1994,2037,2069,2132,2178`; `PastaPagamentoController.php:61,111,172,207,248`), e a lápide é recusada pelo `PastaSomenteLeituraListener`. O include é `only` (`show.html.twig:239-245`) e não recebe `podeEditarPasta`. | Passar `podeEditarPasta` ao include, ou calcular `can_access_resource('pasta', pasta.id, 'edit')`. Definir `podeEscreverFin = podeEditar and not pasta.estaExcluida`. Sem ele: (a) compositor fora; na lápide, a mesma nota `ps-compositor--leitura` de Detalhes adaptada ("o relatório financeiro fica só para leitura"); (b) Responder fora (botão do card e item do menu); (c) Editar e Excluir **fora** do ⋮, junto com o separador, em vez de "· bloqueado"; (d) lápis de honorários, "+ parcela", itens de escrita do menu de Pagamentos e de arquivos fora. Ficam Copiar, Ver todos, Imprimir extrato e baixar. O JS já tolera a falta do `#formFinanceiroObservacao` (`_financeiro_obs_script.html.twig:55,78`, `if (formObs)`); a edição perde a lista do "@" sem a caixa (`urlMencionaveis` vazio), o que não importa porque quem não escreve não edita. | Functional (padrão `PastaFinanceiroRegistroMenuTelaTest`): usuário com VER e sem EDITAR não recebe `#formFinanceiroObservacao`, `.btn-responder-obs`, `.js-fin-obs-editar`, `#financeiro-valor-causa-lapis` nem `.ps-fin-btn-add`; editor recebe tudo; na lápide sai a nota de leitura. Provar reintroduzindo o defeito. |
| C7-FPN-02 | A | P5 | P | não | "Editar · bloqueado" e "Excluir · bloqueado" do ⋮ do Relatório financeiro não são clicáveis | dc:5958: `editar = () => { if (!pm.pode) { this.gmAviso(pm.motivo); return; } … }`; dc:5904 (`comExcluir`: o mesmo `gmAviso(pm.motivo)`). O item bloqueado é clicável e diz o motivo | `_financeiro.html.twig:1143-1145` (`item.disabled = !pode`): o clique é engolido e o motivo fica só no `title`, que não aparece no toque nem no teclado. É o mesmo defeito que o C6-PMC-05 corrigiu na nota técnica (`.js-nota-bloqueado`, aviso neutro). | Em `pintarItemObs`, trocar `disabled` por só `aria-disabled` com `data-aviso = motivo`. No clique, se `aria-disabled`, fechar o menu e chamar `avisarPs(motivo)` neutro, como em `nota-tecnica.js`. Combinar com C6-FPN-05, que mexe na mesma função, e com C7-FPN-01: o bloqueado só existe para quem escreve, por autoria ou prazo. A mesma classe existe em Detalhes (`_detalhes_obs.html.twig:1150-1157`, `pintarItemObs` `:616-622`), que é da área Pasta/Dados e fica para o auditor dela. | Tela: o item bloqueado não tem o atributo `disabled` e traz `data-aviso`. Unit de JS: o clique em `[aria-disabled]` chama `avisarPs` com o motivo. |

Vistos e **não** registrados porque já estão classificados: Encaminhar (C), Destacar (sem lastro), o cabeçalho
"Notas técnicas · N" (C), "Já lida" × "Marcar como não lida" (ratificar), o trilho do Push (B), FPN-011 (aba oculta
por permissão, C/D-FIN).

## Matriz atualizada

O denominador cresce com C6-FPN-05, C7-FPN-01 e C7-FPN-02, todos em Interações. C6-FPN-01 a 04 viram F.

| Dimensão | Conferidos OK (F) | Total | C6 |
|---|---|---|---|
| Visual | 90 | 106 | 88/106 |
| Interações | 64 | 95 | 63/92 |
| Funções | 39 | 60 | 38/60 |
| Responsivo | 11 | 12 | 11/12 |
| Dark mode | 14 | 14 | 14/14 |
| **Total** | **218** | **287** | 214/284 |

## Resumo

- Conferidos: 8, todos ✓. Regressões: 0. Há um ponto latente em `pasta-mencoes.js` (`urlPadrao`).
- A pendente: C6-FPN-05 (P5).
- A novos: C7-FPN-01 (P3, escrita do Financeiro para quem só vê e na lápide) e C7-FPN-02 (P5, bloqueado clicável).
  C6-FPN-05 e C7-FPN-02 mexem na mesma função (`prepararMenuObs`/`pintarItemObs`): vale fazer num lote só, com o
  C7-FPN-01.
- Rastreabilidade: o ledger ainda não renomeou FPN-040 para **FPN-040L** (a proposta é do C6).
