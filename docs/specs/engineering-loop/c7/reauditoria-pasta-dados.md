# Reauditoria C7 (convergência): PASTA (cabeçalho, Dados, Registro, Detalhes, Timeline), prefixo PAS

Auditoria somente leitura em 12/10/2026 sobre o master `d985225e`, sem navegador, sem container e sem banco. Base:
`c6/reauditoria-pasta-dados.md`. Lotes da área: S6-SHOW (`64927a09`, `cccddbb5`), S6-DET (`2c2bec4a`, `3dc0e749`),
S6-TL (`b4077ad7`), S6-JS (`4c2a991c`, `242ac329`), S6-OBS (`abda487d`, `260bbaaa`; C6-PAS-07 de Detalhes) e S6-M1
(`7265636b`, `3a6d3278`; PAS-045b). Os caminhos são relativos a `app/`. `dc` = `02 - EXPEDIENTES 1.2.3.dc.html`.

## Conferência dos itens A do C6

| ID | Veredito | Evidência |
|---|---|---|
| C6-PAS-01 (regressão P1) | ✓ | `PastaController.php:1094` devolve `criadaEmEpoch` (`getTimestamp()`); `show.html.twig:3025` usa `Number(data.criadaEmEpoch) + janela*60`. Não sobrou nenhum `Number(data.criadaEmTs)` |
| C6-PAS-02 | ✓ | `_detalhes_obs:59` `podeEscreverObs = podeEditarPasta and not obsPastaLapide`; compositor `:82`, nota só na lápide `:78-81`, vazio `:112`, lápis/lixeira `:1019, 1050`, Responder `:1077, 1100`, Citar `:1109`, Editar/Excluir `:1130`; o cartão montado em JS segue a mesma regra (`:316, 351, 357, 369`) |
| C6-PAS-03 | ✓ | Lixeira `hidden` (`:1066-1074`; JS `:327-334`), sem `.ps-anotacao-janela`, lápis com `title="Editável por mais N minuto(s)"` (`:1058`; JS `:320`) |
| C6-PAS-04 | ✓ | `:1047` "Publicado às HH:MM · última edição dd/mm/aaaa às HH:MM"; o JS de salvar espelha o texto (`:600-603`) |
| C6-PAS-05 | ✓ | `data-janela-ate`/`data-do-autor` no artigo (`:1031-1032`; JS `:305-309`, que também usa `criadaEmEpoch`); `pintarJanelaObs` recalcula ao abrir (`:609-641`, chamado em `:653`) |
| C6-PAS-06 | ✓ | `MontadorDaTimelineInteligente.php:317` `isResposta() ? 'Resposta' : 'Registro'` |
| C6-PAS-07 (Registro e Detalhes) | ✓ | `autorFotoUrl` pela mesma regra do Twig (`findFotoPorColaboradores`), `<img>` com `esc()` (`show.html.twig:3031`; `_detalhes_obs:314`) |
| C6-PAS-08 | ✓ | `_timeline_inteligente.html.twig:55` diz "telefone" |
| C6-PAS-09 | ✓ | `_cabecalho:511-516` (o "0" some), `mostraZero` foi apagado (`:532`), e o comentário do Imprimir foi atualizado (`:240-243`) |
| PAS-022r (X) | ✓ | `pasta-show.js` `MENUS_COM_X = '#psRegMenu, .ps-obs-menu, #pastaRespMenu'`, com o X na abertura via MutationObserver. Fecha por Esc sintético, como o desenho (registrado no ledger) |
| PAS-023r (arraste) | ✓ | `pasta-show.js`: menu sem tarja pelo 1º filho `.ps-pop-secao`, Registro e observação pelo título, lista do responsável pela faixa da busca, drawer e Timeline pelo cabeçalho; posição zerada ao abrir e ao fechar (`242ac329`) |
| PAS-034b | ✓, com regressão | `confirm` do Restaurar → `confirmarPs` (`_cabecalho:162-165, 673-692`); `alert` do Excluir → `avisarPs` (`:284`); `alert` dos Favoritos → `avisarPs` (`:700-723`); "Descartar" do Editar dados → `confirmarPs` (`show.html.twig:3813-3841`). A hospedagem dentro do modal aberto quebra com a Nova meta: ver C7-PAS-01. O aviso de número errado tem cor diferente do desenho: ver C7-PAS-02 |
| PAS-045b (migration) | ✓ | `Version20261012230000` (2× `ADD … TEXT DEFAULT NULL`, com `down`); `PastaObservacaoDetalhes/Financeira::editarConteudo` (só guarda o anterior quando o texto muda); os UseCases usam esse método; `Montador:326-327` `antes`/`alteradoEm`. ⏳ Em prod, o Samuel aplica a migration no deploy |

**Contagem:** 14 ✓ (PAS-034b com ressalva por C7-PAS-01), 0 ◐, 0 ✗.

## Regressões

| ID | Commits | O que quebrou | Evidência |
|---|---|---|---|
| C7-PAS-01 | `4c2a991c` (S6-JS, `hospedarNoModalAberto`) × `64927a09` (S6-SHOW, C6-PMC-01: Nova meta sem trava) | `confirmarPs` move o `#psConfirmar` para dentro do **primeiro** `.modal.show`. Com a Nova meta aberta ou recolhida, que agora convive com a página, esse modal é o `#modalCriarTarefa`. Ele tem `pointer-events: none`, e o `.ps-cf-fundo` não redefine a propriedade, então **herda `none`**. O resultado é que a janela "Excluir registro/observação?" (Registro, Detalhes) e o Restaurar aparecem, mas **o mouse não clica em Cancelar nem em Excluir: o clique atravessa para a página por baixo**. Só o teclado funciona. Além disso, o fundo fica preso no contexto de empilhamento do modal (`z-index:1049`). Cenário típico: "Criar meta a partir deste registro" e, com a janela ainda aberta, excluir um registro | `pasta-dialogos.js:349-356, 375`; `show.html.twig:4112-4115` (`#modalCriarTarefa { pointer-events:none; z-index:1049 }`), `:4340-4343` (`.ps-cf-fundo` sem `pointer-events`) |

Não achei nenhuma outra regressão. A Timeline, a lápide, o cabeçalho e o Registro continuam como o C6 conferiu. O Esc sintético do X também
fecha outras janelas que escutam Esc no `document` (o drawer do Histórico, por exemplo), mas isso é o comportamento do desenho e já está
registrado no ledger.

## Pendências A

| ID | Situação |
|---|---|
| C6-PAS-10 (C) | Continua com o Samuel: a visita da Timeline é gravada ao abrir, e o desenho grava ao fechar. Não é A |
| PAS-045b em prod | O código está feito. Falta só aplicar a migration no deploy (humano) |

Nenhuma A do C6 ficou aberta nesta área.

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho | Código | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C7-PAS-01 | A (regressão) | P2 | P | não | Janela de confirmação inclicável com a Nova meta aberta | — (convenção PAS-034: a janela própria funciona como o `confirm`) | `pasta-dialogos.js:349-356`; `show.html.twig:4112` | Em `hospedarNoModalAberto`, só hospedar em modal que prende o foco: `document.querySelector('.modal.show:not([data-bs-focus="false"])')`. A Nova meta não prende o foco, e por isso não precisa de hospedagem. Como reforço, `pointer-events:auto` no `.ps-cf-fundo` | Estático (folha JS, como `PastaJanelasRestantesFolhaTest`): o seletor exclui `[data-bs-focus="false"]`, e o CSS do `.ps-cf-fundo` tem `pointer-events: auto`. Mutação: voltar a `'.modal.show'` derruba o teste |
| C7-PAS-02 | A | P5 | P | não | Aviso "Número não confere" do Excluir sai como **erro** (ícone vermelho de exclamação); o `gmAviso` do desenho tem uma variante só, a neutra (check verde) | dc:4337 (`gmAviso`), dc:596 (`bi-check-circle-fill`, `#5fd39a`) | `_cabecalho:284` (`avisarPs(…, 'erro')`) | `avisarPs('Número não confere. A pasta não foi excluída.')` sem o tipo, como o S6-PUSH fez na nota bloqueada (`85686de6`). O erro dos Favoritos não aparece no desenho e continua como erro | Estático: o `onsubmit` do `.js-excluir-pasta` não contém `'erro'` |
| C7-PAS-03 | A | P5 | P | não | O prompt do Excluir não mostra o cliente: o desenho diz "Excluir a pasta N (cliente)?". Já constava como nota no PAS-034b do C6, sem ID, e não entrou em lote | dc:4335 (`'Excluir a pasta ' + n + ' (' + cur[1] + ')?'`) | `_cabecalho:284` | `data-cliente="{{ cliente ? cliente.nome : '' }}"` no form (`cliente` já existe em `:29`) e `n + (c ? ' (' + c + ')' : '')`. O "lixeira por 30 dias" continua fora, como desvio registrado | Funcional: o form tem `data-cliente` igual ao nome do cliente principal |

## Matriz atualizada

Calculei por deltas sobre a matriz do C6 (180/212):

| Dimensão | OK | Total | Como chegou |
|---|---|---|---|
| Visual | 72 | 78 | 66 + C6-PAS-03, 04, 06, 07, 08 + PAS-022 fechados (+6). O total ganha C7-PAS-02 e C7-PAS-03 |
| Interações | 61 | 63 | 57 + C6-PAS-01, C6-PAS-05, PAS-023 e a volta do PAS-025 (+4). O total ganha C7-PAS-01 e o PAS-034b, que entrou em lote. O PAS-034b fica fora do OK enquanto houver C7-PAS-01 |
| Funções | 38 | 54 | 35 + C6-PAS-01, C6-PAS-02 e PAS-045b (+3). C6-PAS-10 (C) continua aberto |
| Responsivo | 10 | 10 | sem mudança |
| Dark mode | 12 | 12 | sem mudança: os trechos novos usam tokens que já existiam (`--ps-aviso-*`, `--ps-cf-*`, com o par escuro em `show.html.twig:4339`) |
| **Total** | **193** | **217** | 88,9 % (no C6: 84,9 %) |

## Resumo

- Conferência: 14 ✓, 0 ◐, 0 ✗.
- Regressões: 1, a **C7-PAS-01** (P2): a janela de confirmação não recebe cliques quando a Nova meta está aberta.
- Pendências A: 0. A migration do PAS-045b em prod fica com o humano, e o C6-PAS-10 (C) com o Samuel.
- Novas: 3 A (P2 ×1, P5 ×2), nenhuma com migration. Todas são em `pasta-dialogos.js`/`show.html.twig` (C7-PAS-01) e em
  `_cabecalho.html.twig` (C7-PAS-02 e 03, que dá para fazer num lote só).
