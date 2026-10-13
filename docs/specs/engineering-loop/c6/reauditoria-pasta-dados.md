# Reauditoria C6: PASTA (cabeçalho, Dados, Registro, Detalhes e Timeline), prefixo PAS

Auditoria somente leitura feita em 12/10/2026 sobre o master `4a98cc6b`, sem navegador, sem container e sem banco.
Cor, raio, sombra e dark mode foram conferidos **lendo o CSS e os templates**, não na tela. Os caminhos do código
são relativos a `app/`. Abreviações: `dc` = `02 - EXPEDIENTES 1.2.3.dc.html` e `central` = `bluejus-central.js`.

## Fontes lidas

**Desenho**
- `dc:556-600`: modal Editar dados.
- `dc:1286-1330`: Registro, com cartão, caixa de resposta e editor.
- `dc:2300-2402`: Detalhes, com cartão da observação, ⋮, "Registro da pasta" e "Como usar esta aba".
- `dc:4320-4345`: `menuPasta`, nas ações duplicar e excluir.
- Busca de `editDica`/`editadoDica`/`respTxt`/`respPara` em `dc:5942-5975`, mais `dc:6467` e `dc:6522`, que mostram que Detalhes usa os mesmos `regsItens`/`regsLinha` do Registro.
- `central:3345-3452`: Timeline (`tlEventos`, `tlRender`, `tlCard`, `tlFechar`).
- `central:6632-6760`: arraste global e "X de fechar em toda janelinha".
- `design_handoff_bluejus_intelligence/README.md:145-163` (X, Quinas, Arraste) e `:194-198`.

**Ledger e fila**
- `engineering-loop-fidelidade-designer.md` §3.1 (linhas PAS-*, L6, L7, M2, M4, M6, PAS-025b/c, 013b, 022b, 034c), "Novas divergências" e §3.3/§3.4.
- `fila-a-restante.md` §0 e lotes L1, L2, L6, L7, M2, M4, M5 e M6.
- `inventario-pasta-dados.md`, inteiro.

**Código**
- `templates/pasta/_cabecalho.html.twig` (1-741, inteiro)
- `_dados_anotacoes.html.twig` (inteiro)
- `_dados_trilho.html.twig:1-110`
- `_detalhes_obs.html.twig:1-147, 268-352, 920-1061`
- `_timeline_inteligente.html.twig` (inteiro)
- `_resp_menu.html.twig` e `_resp_chip.html.twig` (inteiros)
- `show.html.twig:255-312, 2380-3120, 3540-3870`
- `resumo_impressao.html.twig` (por grep)
- `public/js/pasta-show.js:180-330`, `pasta-timeline.js:1-120, 370-437`, `pasta-impressao.js:1-60, 340-405`
- `public/css/pasta-show.css`: Timeline em 5000-5300, `.ps-pop-x` em 558-584 e contagem de tokens claro/escuro
- `src/Controller/PastaController.php:1036-1095, 2178-2300`
- `src/Pasta/Service/MontadorDaTimelineInteligente.php:1-330`
- `RegrasDaTimelineInteligente.php`, por grep
- Entidades `PastaObservacaoDetalhes`/`Financeira`, por grep
- `git log 607f2c29..HEAD`: 199 commits

## Conferência dos "feitos"

Legenda: ✓ confirmado · ◐ parcial · ✗ não implementado ou regrediu.

| ID | O que o ledger diz | Veredito | Evidência |
|---|---|---|---|
| PAS-001 | Botão direito no nome abre Detalhes + dica | ✓ | `_cabecalho:319-320` (`data-cliente-id`), `:350-352` (dica), `:644-664` (350 ms, `hover:none` esconde); `pasta-clientes.js:397` |
| PAS-002 | Selo ATIVO por 3,3 s | ✓ | `_cabecalho:394-404` (modelo), `:731-735` (`setTimeout(…, 3300)`) |
| PAS-013/013b | ✓, "Filtrar responsável", chip vazio | ✓ | `_resp_menu:14-56`; `_resp_chip:12-25`; `_resp_script:37-41`; o Expediente passa `respContexto: 'expediente'` (`_tabela:137, 216`) |
| PAS-014 (+PAS-050) | Escrita escondida de quem não pode | ✓ no cabeçalho | `_cabecalho:40-41, 60-90, 186-192, 221-229, 259-285, 589` (`can_access_resource`). **Fora do cabeçalho, Detalhes escapou:** ver C6-PAS-02 |
| PAS-015 (=EDT-001) | Janela Imprimir com prévia, opções, PDF e Copiar | ✓ | `pasta-impressao.js:1-20, 345-353` intercepta o item do ⋮. O comentário em `_cabecalho:238-239` ficou desatualizado (C6-PAS-09) |
| PAS-019 | Confirmar a troca do número | ✓ | `show.html.twig:3764-3775`, com o texto do desenho (dc:4287) |
| PAS-020 | Pílula, Salvar travado e Pendências | ✓ | `show.html.twig:3586-3598, 3693-3699, 3732-3762` |
| PAS-021 | Responsável com "Trocar" | ✓ | `show.html.twig:3662-3675, 3797-3808` |
| PAS-022 (+022b) | X em toda janelinha | ◐ | Os `.ps-pop` têm o X (`pasta-show.js:217-221, 258-297`). **Faltam** o menu do Registro `#psRegMenu` (`_dados_anotacoes:198`, role=menu), o ⋮ da observação `.ps-obs-menu` (`_detalhes_obs:999`, role=menu) e a lista do responsável `#pastaRespLista` (role=listbox). O desenho põe X em todo `role=menu/dialog/listbox` maior que 150×80 sem botão de fechar (`central:6713-6756`; README:145-146) |
| PAS-023 | Janelinhas arrastáveis | ◐ | O escopo da fila foi entregue: tarja dos `.ps-pop`, modal Editar dados e janela do cliente (`pasta-show.js:299-317`; `pasta-clientes.js:205`). **Não arrastam** os menus sem tarja (prioridade `#psPrioridade` e ⋮ do prazo), o menu do Registro, o ⋮ da observação, a lista do responsável, o drawer do Histórico e o painel da Timeline. O README:162-163 e `central:6632-6650` arrastam todo role menu/dialog/listbox ou janela fixa |
| PAS-024 | Timeline em 4px | ✓ | `pasta-show.css:5029-5031` |
| PAS-025 (+025b, 025c) | Menu do botão direito + ⋮ no Registro, lápide só leitura | ◐ **regressão nos cartões recém-enviados** | O menu existe (`_dados_anotacoes:195-217`; `show.html.twig:2539-2702`) e a lápide está certa (`_dados_anotacoes:117-120, 200-216`). Mas o cartão montado em JS grava `data-janela-ate="NaN"`, ver C6-PAS-01 |
| PAS-030 | Caixa de resposta sob o cartão | ✓ | `show.html.twig:2405-2537`; CSS `_dados_anotacoes:75-101` com as medidas de dc:1319-1323 |
| PAS-031 | "@Nome " ao responder a uma resposta | ✓ | `_dados_anotacoes:295-303`; `show.html.twig:2523-2532` (menção real em `_mencoes`) |
| PAS-032 | Edição dentro do editor | ✓ | `show.html.twig:2728-2770` |
| PAS-033 | Dica "Publicado às … · última edição …" | ✓ no Registro | `_dados_anotacoes:264-267`; `show.html.twig:2909-2914`. Em Detalhes não foi feito: C6-PAS-04 |
| PAS-034 (+034c) | Janela própria no lugar de confirm/alert | ✓ no Registro e em Detalhes | `show.html.twig:2386-2403`; `_detalhes_obs:148-164`. O resto continua aberto como PAS-034b (abaixo) |
| PAS-037 | Sem lixeira e sem "· N min" na linha; minutos na dica do lápis | ✓ no Registro | `_dados_anotacoes:269-292`. Em Detalhes não foi feito: C6-PAS-03 |
| PAS-038 + PAS-047L | "Alertar <nome>" no ⋮ do prazo, só para quem edita | ✓ | `_dados_trilho:39-58, 84-93` |
| PAS-039 | Responder em Detalhes | ✓ | `_detalhes_obs:99-123, 393-537, 979-989`; `PastaController:2199-2210` (pai preso a pasta e tenant) |
| PAS-040 | Botão direito na observação | ✓ | `_detalhes_obs:557, 669-690` |
| PAS-041 (+EDT-009) | ⋮ da observação: Citar, Criar meta, Editar/Excluir, Imprimir | ✓ | `_detalhes_obs:999-1050, 609-665`. Os minutos ficam congelados: C6-PAS-05 |
| PAS-043 (M4) | "Enquanto você estava fora" | ✓ funcional, com desvio não ratificado | `MontadorDaTimelineInteligente:202-205`; `pasta-timeline.js:10-12, 87`. A visita é gravada ao **abrir**, e o desenho grava ao **fechar** (`central:3398`, `tlFechar`): C6-PAS-10 |
| PAS-045 (M6) | "Ver alteração" | ✓ no Registro | `Montador:291-292`; `pasta-timeline.js:392-397, 410-422`. Detalhes e Financeiro continuam no PAS-045b |
| PAS-046 | Detalhes e Financeiro na Timeline | ✓ | `Montador:134-163` (Financeiro atrás de `podeVer`). O título das respostas está errado: C6-PAS-06 |
| PAS-047i | Notificações do usuário ligadas à pasta | ✓ | `Montador:165-180`; `_timeline_inteligente:15-16` |
| PAS-049i | Timeline sem fundo escurecido | ✓ | `pasta-show.css:5006-5017` (overlay transparente que ainda recebe o clique fora) |
| PAS-048 | Testes | não conferido | O briefing proíbe rodar a suíte. Os testes existem: `tests/Pasta/Functional/PastaTimelineControllerTest.php:471`, por exemplo |

**Contagem:** 23 confirmados (✓), 3 parciais (◐: PAS-022, PAS-023 e PAS-025, este pela regressão), 0 não implementados.
PAS-048 é só de testes e ficou fora da conta.

## Regressões

| ID | Commit | O que quebrou | Evidência |
|---|---|---|---|
| C6-PAS-01 | `b1155da8` (menu do registro), agravado por `a86ffdd7` (PAS-037) | O cartão de um registro **recém-enviado** é montado em JS com `data-janela-ate="${Math.floor(Number(data.criadaEmTs) / 1000) + 15 * 60}"`. Só que `criadaEmTs` é uma string ATOM (`2026-10-12T10:00:00-03:00`), então `Number(...)` dá `NaN`. Na abertura do menu, `minutosRestantesReg` faz `!ate`, que é verdadeiro, e devolve 0. O menu mostra então **"Editar · bloqueado" e "Excluir · bloqueado"** com o motivo falso "O prazo de 15 minutos … terminou". Desde o PAS-037 a lixeira do cartão fica `hidden`, e por isso **excluir o registro que você acabou de publicar só funciona depois de recarregar a página**. O lápis continua funcionando | `show.html.twig:3025` (cálculo), `:2571-2575` (leitura), `:2594-2607` (pintura); `PastaController.php:1087` (`DateTimeInterface::ATOM`); `show.html.twig:2977` já faz `new Date(data.criadaEmTs)` certo |

Não achei nenhuma outra regressão. A lápide, o cabeçalho, a Timeline e o modal estão como o ledger declara.

## Pendências A

| ID | Situação | Evidência |
|---|---|---|
| PAS-022 (resto) | Falta o X no `#psRegMenu`, no `.ps-obs-menu` e no `#pastaRespLista` | ver a conferência |
| PAS-023 (resto) | Faltam no arraste os menus sem tarja, o do Registro, o da observação, a lista do responsável, o drawer do Histórico e a Timeline | ver a conferência |
| PAS-034b (A, P4) | Continuam nativos: `confirm` de Restaurar (`_cabecalho:163`); `alert` "Número não confere" do Excluir (`_cabecalho:278`; o desenho usa toast `gmAviso`, dc:4337); `alert` de erro do Favoritos (`_cabecalho:691, 698`); `confirm` "Descartar alterações" do Editar dados (`show.html.twig:3817`). O Duplicar, o prompt do Excluir e a troca do número usam `confirm`/`prompt` **como o próprio desenho** (dc:4324, 4335, 4287) e ficam como estão. O prompt do desenho também mostra o cliente: "Excluir a pasta N (cliente)?" | linhas citadas |
| PAS-045b (A, P3, **migration**) | "Ver alteração" nas observações de Detalhes e do Financeiro: falta a coluna `conteudo_anterior` em `pasta_observacao_detalhes`/`_financeira` | `Montador:301-321` (sem `antes`) |

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-PAS-01 | A | P1 | P | não | Menu do registro recém-enviado diz "bloqueado" e excluir fica inacessível (regressão, ver acima) | dc:5958 (`regsLinha`), 5975 (`editDica`, minutos vivos) | `show.html.twig:3025`; `PastaController.php:1087` | O servidor devolve também `criadaEmEpoch` (int, `getTimestamp()`) e o JS usa esse valor em `data-janela-ate`. Alternativa sem servidor: `Math.floor(dt.getTime() / 1000)` com o `dt` da l.2977. Toca `show.html.twig` (compartilhado) | Funcional: o POST `pasta_enviar_mensagem` devolve `criadaEmEpoch` inteiro e próximo de `time()`. Estático: o template não contém `Number(data.criadaEmTs)`. Mutação: voltar ao `Number(...)` derruba o estático |
| C6-PAS-02 | A | P3 | P | não | Detalhes oferece escrita que o servidor recusa: o compositor, o "Responder" (botão e item do ⋮), o lápis e a lixeira aparecem para quem **só vê** a pasta, e o compositor e o lápis também na **lápide**. As rotas exigem EDIT (`PastaController:2186, 2246, 2286`) e o `PastaSomenteLeituraListener` recusa POST na lápide | Convenção PAS-014/PAS-025c ("item que o servidor recusa não aparece") | `_detalhes_obs:67-87` (compositor sem condição), `:928` e `:954` (`podeGerenciar` sem EDIT nem lápide), `:979-989`, `:1001-1005` (Responder só olha a lápide) | `podeEscreverObs = podeEditarPasta and not obsPastaLapide`. Sem ela, entra a nota de só leitura do Registro (`_dados_anotacoes:117-120`). `podeGerenciar` e o Responder passam a depender dela, e a `ps-vazio-nota` "Registre acima" some. O JS já tolera a falta do `#formDetalhesObservacao` (`_detalhes_obs:167, 193`, `if (formObs)`) | Funcional: usuário com VIEW sem EDIT não recebe `#formDetalhesObservacao`, `.btn-responder-obs-det` nem `.js-obs-responder`; a lápide idem; quem tem EDIT continua recebendo |
| C6-PAS-03 | A | P4 | P | não | Detalhes: lixeira e "· N min" na linha do cartão (PAS-037 só foi feito no Registro). Excluir já mora no ⋮ (PAS-041), então tirar a lixeira não apaga o caminho | dc:2323 (`o.editavel`, `o.editDica`), 5975 (`editDica: 'Editável por mais N minutos'`); dc:6467/6522 (Detalhes usa o mesmo `regsLinha`) | `_detalhes_obs:954-977` e o espelho JS em `:290-309` | O mesmo do `a86ffdd7`: a lixeira vira `hidden` (o ⋮ continua clicando nela), o `.ps-anotacao-janela` sai e o lápis ganha `title="Editável por mais N minuto(s)"` | Funcional: o cartão do autor na janela não tem `.ps-anotacao-janela` e o lápis tem `title^="Editável por mais"`; o `.btn-excluir-obs-det` existe e está `hidden` |
| C6-PAS-04 | A | P5 | P | não | Detalhes: dica do "editado" diz "Editado em dd/mm/aaaa HH:MM" | dc:2329 (`o.editadoDica`), 5975 | `_detalhes_obs:950-952` (+ espelho JS ao salvar) | Copiar o texto do PAS-033: "Publicado às HH:MM · última edição dd/mm/aaaa às HH:MM" | Funcional com observação editada: `title^="Publicado às "` |
| C6-PAS-05 | A | P5 | P | não | Detalhes: os minutos do ⋮ ("Editar · N min") são fixados na renderização. Passada a janela sem recarregar, o item continua habilitado e o servidor recusa. O Registro calcula na abertura (`data-janela-ate`) | dc:5958 (`regsLinha` recalcula a cada render) | `_detalhes_obs:1019-1028` e JS `:340-346` | Levar `data-janela-ate`/`data-do-autor` ao `article.detalhes-obs-item` e repintar Editar/Excluir ao abrir o menu, reaproveitando a lógica do Registro (`show.html.twig:2562-2607`). Fazer **depois** do C6-PAS-01, para não copiar o `NaN` | Estático: o artigo tem `data-janela-ate`. Funcional: o valor bate com `criadaEm + 15 min` |
| C6-PAS-06 | A | P4 | P | não | Timeline: a resposta em Detalhes ou no Financeiro sai "Registro em Detalhes/Financeiro"; o desenho diz "Resposta em …". No Registro de Dados já está certo | central:3370 (`(r.resp ? 'Resposta' : 'Registro') + ' em ' + ABA`) | `MontadorDaTimelineInteligente.php:313` (o de Dados está em `:281`) | `titulo: ($observacao->isResposta() ? 'Resposta' : 'Registro') . ' em ' . $aba` (as duas entidades têm `isResposta()`, l.163) | Unit/funcional do montador: resposta de Detalhes e do Financeiro dá "Resposta em …"; ajustar `PastaTimelineControllerTest:471` |
| C6-PAS-07 | A | P5 | P | não | Avatar do cartão recém-enviado (Registro e Detalhes) sai só com iniciais, mesmo quando o autor tem foto. O Twig mostra a foto | dc:1305/2325 (`fotoBg`) | `show.html.twig:3031`; `_detalhes_obs:288` | O autor do cartão novo é sempre o usuário logado: `data-minha-foto="{{ url da foto }}"` em `#timelineList`/`#detalhesObsLista`, e o JS põe `<img>` quando o atributo existe. Toca `show.html.twig` | Funcional: o atributo existe quando o usuário tem foto e falta quando não tem |
| C6-PAS-08 | A | P5 | P | não | Placeholder da busca da Timeline | central:3408 (`… "Jéssica", "telefone"…`) | `_timeline_inteligente.html.twig:57` (`"prazo"`) | Trocar "prazo" por "telefone". O sinônimo existe (`RegrasDaTimelineInteligente:63`) | Estático do placeholder |
| C6-PAS-09 | A | P5 | P | não | Comentários desatualizados, que já enganaram o ledger uma vez: `_cabecalho:506-508` diz que o "0" aparece em Metas e Documentos, mas `:549-550` o esconde e `mostraZero` (`:526`) nunca é lido; `_cabecalho:238-239` diz "Baixar PDF é o Salvar como PDF do navegador", mas hoje há Dompdf pela janela (EDT-001) | — (higiene; o inventário já pedia, observação 1) | linhas citadas | Corrigir os dois comentários e apagar `mostraZero` | — (só comentário) |
| C6-PAS-10 | C | — | P | não | "Enquanto você estava fora": a visita é gravada ao **abrir** o painel; o desenho grava ao **fechar** (`tlFechar`). O ledger registra o desvio (§3.1, linha M4), mas ele não entrou em §3.4. Efeito: o que acontece com o painel aberto aparece como "novo" na abertura seguinte. No desenho, isso fica marcado como visto ao fechar | central:3398 (`tlFechar` grava `tlVisto`) | `pasta-timeline.js:10-12, 87`; `Montador:202-205` | Ratificar com o Samuel ou voltar ao desenho (POST no `fecharPainel` + `sendBeacon` ao sair da página) | — |

Nenhuma divergência nova é P0. A C6-PAS-02 é só tela, porque o servidor recusa e não há vazamento. Não achei texto de usuário passando por
HTML sem escape nos trechos novos: a caixa de resposta usa `textContent` e `paragrafosDe` escapa.

## Propostas para as A pendentes e novas (arquivos e compartilhamento)

| ID | Arquivos | Toca compartilhado? |
|---|---|---|
| C6-PAS-01 | `show.html.twig` (JS do Registro, l.3025), `src/Controller/PastaController.php:1079-1093` | **sim**: `show.html.twig` |
| C6-PAS-02 | `templates/pasta/_detalhes_obs.html.twig` | não |
| C6-PAS-03/04/05 | `templates/pasta/_detalhes_obs.html.twig` (Twig e JS do mesmo arquivo) | não. Fazer num lote só, por causa do mesmo arquivo, e depois do C6-PAS-02 |
| C6-PAS-06 | `src/Pasta/Service/MontadorDaTimelineInteligente.php`, `tests/Pasta/Functional/PastaTimelineControllerTest.php` | não |
| C6-PAS-07 | `show.html.twig`, `_dados_anotacoes.html.twig`, `_detalhes_obs.html.twig` | **sim**: `show.html.twig` (juntar com o C6-PAS-01) |
| C6-PAS-08 | `_timeline_inteligente.html.twig` | não |
| C6-PAS-09 | `_cabecalho.html.twig` | não |
| PAS-022 (resto) | `pasta-show.js` (exportar `botaoFechar` como `window.PsPopovers.x(painel, fechar)`), JS do Registro em `show.html.twig`, `_detalhes_obs` (JS do menu) e `_resp_script` | **sim**: `pasta-show.js` e `show.html.twig`. O `_resp_script` é compartilhado com o Expediente, então lá o X só vale com `naPasta` |
| PAS-023 (resto) | `pasta-show.js` (`arrasteDasJanelas`: aceitar o 1º filho como alça nos `role=menu` sem tarja, no `#psHistorico` e no `.ps-tl-cab`) | **sim**: `pasta-show.js`. Cuidado: o menu do Registro e o da observação fecham ao rolar, então o arraste deles exige rever esse fechamento |
| PAS-034b | `_cabecalho.html.twig` (restaurar, alerta do excluir, favoritos) e `show.html.twig:3817`, via `confirmarPs`/`avisarPs` (`pasta-dialogos.js`) | **sim**: `show.html.twig` |
| PAS-045b | Entidades `PastaObservacaoDetalhes`/`Financeira` + UseCases de edição + Montador | não, mas **com migration** (uma por vez, M-lote) |

Ordem sugerida: **C6-PAS-01** (P1, um lote só com o C6-PAS-07, os dois em `show.html.twig`) → **C6-PAS-02** → C6-PAS-03/04/05 →
C6-PAS-06 → PAS-034b → PAS-022/023 (resto) → C6-PAS-08/09 → PAS-045b (migration).

## Matriz atualizada

Partimos da matriz do inventário (07/10). Somei ao OK cada A fechado, conferido acima, e aos denominadores as novas divergências pela
dimensão delas. Os itens parciais (PAS-022 V, PAS-023 I) continuam fora do OK.

| Dimensão | OK | Total | Como chegou |
|---|---|---|---|
| Visual | 66 | 76 | 53 + 13 A fechados (001, 002, 013, 015, 020, 021, 024, 030, 032, 033, 034, 037, 049). O total ganha 5 novas (C6-03, 04, 06, 07, 08) |
| Interações | 57 | 60 | 50 + 7 fechados (019, 020, 021, 030, 031, 034, 040). O PAS-025 sai do OK pela regressão C6-01. O total ganha 2 (C6-01, C6-05) |
| Funções | 35 | 54 | 24 + 11 fechados (001, 014, 015, 025, 038, 039, 041, 043, 045, 046, 047). O total ganha 3 (C6-01, C6-02, C6-10) |
| Responsivo | 10 | 10 | Sem mudança. Os trechos novos usam `max-width: calc(100vw - 24px)` (`_dados_anotacoes:70`) e `(hover:none)` na dica (`_cabecalho:664`) |
| Dark mode | 12 | 12 | Só leitura de CSS: os tokens novos (`--ps-resp-faixa-*`, `--ps-pop-x-hv-*`, `--ps-tl-fora-*`, `--ps-ok/warn-*`, `--ps-l7-aviso-*`) têm par escuro (2 definições cada em `pasta-show.css`) |
| **Total OK** | **180** | **212** | 84,9 % (no inventário: 149/202 = 73,8 %) |

A C6-PAS-09 é só higiene e não entrou na matriz. As C e D do inventário (PAS-003/004/006-012/016-018/026-029/035/036/042/048) e as
reclassificadas (PAS-005 → C, PAS-044 → D/C) continuam abertas.

## Resumo

- **Feitos conferidos:** 23 ✓ · 3 ◐ (PAS-022 e PAS-023 incompletos frente ao README "toda janelinha"; PAS-025 com regressão) · 0 ✗.
- **Regressões:** 1, a **C6-PAS-01** (P1, de `b1155da8`): no registro recém-enviado, o menu diz "bloqueado" e só dá para excluir depois de recarregar.
- **Pendências A:** 4. São o resto do PAS-022 e do PAS-023, o PAS-034b e o PAS-045b (com migration).
- **Novas:** 10. São 9 A (P1 ×1, P3 ×1, P4 ×2, P5 ×5) e 1 C. Nenhuma B, D ou E, e nenhuma pede migration.
