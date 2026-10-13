# Reauditoria C6: Financeiro, Push processual e Notificações (FPN)

Auditoria somente leitura em 12/10/2026, HEAD `4a98cc6b`. `dc` = `02 - EXPEDIENTES 1.2.3.dc.html`.
Visual (cor, raio, posição) foi conferido lendo CSS e template, **não na tela**.

## Fontes lidas

**Desenho**
- dc L.1731-1935 (faixa do Financeiro, Relatório financeiro com resposta, Arquivos, Pagamentos, cabeçalho do modal).
- dc L.3414-3530 (`finVals`: estados, sufixo de juros, `confirm` de excluir, menu de Pagamentos, Imprimir extrato).
- dc L.2409-2560 (aba Push: cabeçalho, análise IA, linha do tempo, cartão, notas penduradas, compositor da nota).
- dc L.5363-5450 (`pushLinha`, `pushNotaVals`, `pushVals`: `COR`, `TT`, menu `ctx`, menu da nota, vazios, filtros).
- Inventário do ciclo 0: `docs/specs/engineering-loop/inventario-financeiro-push.md`.
- Ledger `docs/specs/engineering-loop-fidelidade-designer.md` §3.1 a §3.5 e `fila-a-restante.md`.

**Código**
- `app/templates/pasta/_push_processual.html.twig`, `_push_teor.html.twig`, `app/public/js/pasta-push.js`.
- `app/templates/pasta/_financeiro.html.twig`, `_financeiro_obs_script.html.twig`, `_financeiro_pagamentos.html.twig`, `_financeiro_pagamentos_script.html.twig`.
- `app/templates/processo/_notas_tecnicas.html.twig`, `_nota_tecnica_item.html.twig`, `app/public/js/nota-tecnica.js`, `app/public/css/nota-tecnica.css`.
- `app/public/css/pasta-show.css` (2780-2900, 4328-4346, tokens 193/356), `app/public/css/app.css:256-257`.
- `app/src/Entity/Notificacao.php:199-229`, `src/Repository/NotificacaoRepository.php`, `templates/base.html.twig:218-259`, `templates/notificacao/index.html.twig:90-155`, `templates/layout_peticionar.html.twig:785-803`.
- `src/Pasta/UseCase/EnviarObservacaoFinanceiraUseCase.php`, `src/Pasta/DTO/PastaPagamentoLinhaOutput.php`, `src/Pasta/Entity/PastaPagamento.php`, `src/Pasta/DTO/PastaPushOutput.php`, `src/Djen/DTO/PublicacaoDjenListaItem.php`, `src/Controller/PastaController.php:403-428`, `src/Processo/Repository/NotaTecnicaRepository.php:117-136`.
- `git log 607f2c29..HEAD` nos arquivos da área: 23 commits, de `a53d6cb6` a `be356e16`.

## Conferência dos feitos

| Item (ledger) | Veredito | Evidência |
|---|---|---|
| FPN-012 Título = trecho do teor | ✓ | `_push_processual.html.twig:205` (`textoResumo ?: nomeOrgao ?: tipo`). O órgão vai para o `title` em `:210`. Corte de 220 caracteres na palavra em `PublicacaoDjenListaItem.php:19,52`. Caixa alta em `pasta-show.css` (`.ps-push-assunto-txt`). |
| FPN-014 Botão direito no cartão | ✓ | Menu em `_push_processual.html.twig:256-282`, lógica em `pasta-push.js:440-540`. A ordem é a do desenho (nota · ─ · tarefa · lida · documento · ─ · copiar, dc:5385/5394), sem Encaminhar (C). Veja C6-FPN-03 e -04 para os efeitos colaterais. |
| FPN-014b Marcar lida / Abrir documento no menu | ✓ | `_push_processual.html.twig:269-277`; `pasta-push.js:486-519`. O rótulo "Marcar como não lida" no lugar do "Já lida" do desenho segue para ratificar (ledger §3.1). |
| FPN-014c / FPN-040(ledger) Escrita escondida de quem só vê e na lápide | ✓ | `_push_processual.html.twig:26-28, 259, 269`; `_push_teor.html.twig:41-42, 72-84, 111`. |
| FPN-006 Botão direito no registro financeiro | ✓ | `_financeiro.html.twig:233-252` (menu), `:1136-1180` (`prepararMenuObs`). |
| FPN-006b Editar/Excluir · bloqueado | ✓ | `_financeiro.html.twig:1143-1170` (cadeado, `disabled`, motivo no `title`). |
| FPN-005 (M1) Responder a registro financeiro | ✓ | Entidade e migration (`07ba1031`). O UseCase recusa raiz de outra pasta ou de outro escritório (`EnviarObservacaoFinanceiraUseCase.php:60-68`). Respostas recuadas sob a raiz (`_financeiro.html.twig:193-219`). Caixa "Respondendo a…" e "Escreva a resposta…" em `_financeiro_obs_script.html.twig:256-345`, como em dc:1815-1820. |
| FPN-009 (M7) "· juros N% a.m." | ✓ | `PastaPagamentoLinhaOutput.php:97-116`; `PastaPagamento.php:77`. Corresponde a dc:3442. |
| FPN-008 (M7) `confirm` com nome e valor | ✓ | `_financeiro_pagamentos_script.html.twig:253-261`; `data-descricao`/`data-valor` em `_financeiro_pagamentos.html.twig:71-72`. Texto de dc:3456. |
| FPN-010 (=EDT-004) Imprimir extrato na janela do resumo | ✓ | `_financeiro.html.twig:1215-1221` → `pasta-impressao.js:407` (`secao: 'extrato'`). Folha do servidor em `PastaResumoController.php:212`, só para quem vê o financeiro. O modal está incluído em `show.html.twig:471`. |
| FPN-019 Cor do ponto por quem praticou o ato | ✓ | `_push_processual.html.twig:187-195`. Tokens com par escuro em `pasta-show.css:193-194/356-357` e `:2840-2843`. 16px. |
| FPN-031 Cabeçalho quebra linha no celular | ✓ (só no CSS) | `pasta-show.css:4340-4345`. A largura de 375px só se prova no smoke. |
| FPN-032 Filtros com a lista vazia | ✓ | `_push_processual.html.twig:92-96` (fora de qualquer `if`). |
| FPN-020 Notas penduradas com o teor fechado | ✓ | `_push_processual.html.twig:232-243`. Uma consulta por tenant e processos da pasta em `PastaController.php:420-427`. Recuo e "L" do desenho em `nota-tecnica.css:68`. Veja C6-FPN-01 (ordem). |
| FPN-020b Nota nova no processo da publicação | ✓ | `_push_processual.html.twig:233-240` (`processoIdDaPublicacao`; sem processo, sem compositor). |
| FPN-020c Editar uma cópia atualiza as outras | ✓ | `nota-tecnica.js:286-300` (edição) e `:337-340` (exclusão). |
| FPN-021 (=PMC-017) ⋮ da nota técnica | ✓ | `_nota_tecnica_item.html.twig:67-117`. Itens e "· bloqueado" com o motivo de dc:5398-5406. Encaminhar fica de fora (C). |
| FPN-022 (=PMC-019) @menção na nota técnica | ✓ | Placeholder de dc:2554 em `_notas_tecnicas.html.twig:80`. A lista do "@" vem de `:46-48`. Notifica com o tipo `TIPO_NOTA_TECNICA_MENCAO` (`ResolucaoDeMencoesDoRegistro.php:187`). |
| FPN-029 Recolher análise IA (virou F pelo IA-010) | ✓ | `pasta-ia-push.js:18-19, 302-371`: chevron, prévia e preferência gravada no servidor. |
| FPN-035 Sino de contorno | ✓ | `base.html.twig:218`; `layout_peticionar.html.twig:785`. |
| FPN-036 Ícone e cor do alerta e da análise IA | ◐ | `Notificacao.php:224-226` e `app.css:256-257` estão certos no **dropdown** do sino. Na **página `/notificacoes`** o ícone sai sem o laranja: a regra só casa `.notif-icon.text-orange`, e lá o `<i>` não tem `.notif-icon` (`notificacao/index.html.twig:155`). Registrado como C6-FPN-02. |

Placar: **20 itens conferidos, 19 ✓, 1 ◐, 0 ✗**.

Observação de rastreabilidade: o ledger reaproveitou o ID **FPN-040** (§3.1, linha 171: "teor sem escrita para quem só vê") para um item novo. No inventário, FPN-040 é "Notificação Relatório inicial incompleto" (C, D-RELINI). Proposta: renomear a linha do ledger para **FPN-040L**, como foi feito com PAS-047L e PMC-058L.

## Regressões

Nenhuma encontrada. Conferi os 23 commits do loop nos arquivos da área. Os dois que tiram coisa da tela agem como o desenho:
- `fdbb56b0` tira o Imprimir dos menus do Registro e do Financeiro (dc:6454-6466).
- `be356e16` troca o texto do `confirm` pelo do desenho.

Os textos e estados que o inventário dava como F continuam no lugar:
- placeholder do Relatório, vazios do Push e "Exibindo as N mais recentes" (`_push_processual.html.twig:107-119, 285-292`);
- extrato com fallback de `window.print`;
- CSRF nas rotas de lida e de nota.

## Pendências A (do inventário)

Nenhuma A do inventário segue pendente. Dos 17 A:
- 15 foram feitos e confirmados acima, contando o FPN-029, coberto pelo IA-010.
- O FPN-013 foi reclassificado como C (fila-a-restante, junto com o SEC-011).
- O FPN-036 está parcial; o que falta virou o C6-FPN-02.

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-FPN-01 | A | P4 | P | não | Ordem das notas técnicas penduradas (Push) e da lista de notas | dc:5391 (`L` em ordem de gravação), dc:5397 (`A.push`: nota nova vai para o FIM), compositor depois da lista (dc:2552) | `NotaTecnicaRepository.php:136` (`criadaEm DESC`); `nota-tecnica.js:147` (`insertAdjacentHTML('afterbegin')`): a mais nova fica em cima | Ordenar `ASC` em `listarPorPublicacoesDosProcessos` (e na consulta do teor, `:71/:95`, para o bloco ser o mesmo) e trocar `afterbegin` por `beforeend` no `nota-tecnica.js`. "Responder com outra nota" passa a ler como conversa, de cima para baixo. **Toca arquivo compartilhado com a aba Processo** (`_notas_tecnicas`/`nota-tecnica.js`): combinar com o dono da área PMC antes. | Functional em `PastaNotasTecnicasTelaTest`: duas notas da mesma publicação; a mais antiga aparece primeiro no HTML. Unit do JS (`NotaTecnicaJsTest`): confere `beforeend`. |
| C6-FPN-02 | A | P4 | P | não | Laranja do "alerta de meta" na página `/notificacoes` (resto do FPN-036) | `bluejus-central.js:70` (`alerta`: `bi-bell-fill` `#e8590c`) | `app.css:256-257` (só `.notif-icon.text-orange`); `notificacao/index.html.twig:155` (`<span class="notif-ico"><i class="bi {{ notif.icone }}">`, sem `.notif-icon`) | Generalizar a regra para `.text-orange` (com o par `html[data-bs-theme="dark"] .text-orange`) em `app.css`, ou acrescentar `.notif-page .notif-ico .text-orange` no `<style>` da página. A primeira opção cobre os três lugares (dropdown, Peticionar, página). **CSS global** (`app.css`). | Functional: `GET /notificacoes` com uma notificação `tarefa_alerta_verificar` contém `bi-bell-fill text-orange`. Teste unit que lê `app.css` e confere que `.text-orange` existe fora do escopo `.notif-icon`, como no `PastaPushCabecalhoQuebraTest`. Só o smoke confirma a cor. |
| C6-FPN-03 | A | P5 | P/M | não | "Copiar texto" do menu do cartão do Push copia só o trecho | dc:5394 (`navigator.clipboard.writeText(String(titulo))`, sendo `titulo = m.texto`: o teor inteiro, sem abrir o cartão) | `pasta-push.js:478-483`: sem o teor carregado, copia `.ps-push-assunto-txt` (no máximo 220 caracteres, `PublicacaoDjenListaItem.php:19`) e avisa "Texto copiado" como se fosse o todo | Rota GET sem efeito colateral `pasta_push_texto` (VIEW da pasta e publicação do processo da pasta, a mesma checagem do `pasta_push_lida`), que devolve o texto puro do teor. O menu copia o que ela devolver, sem abrir o cartão e sem marcar como lida. Alternativa mínima sem rota: avisar "Trecho copiado. Abra a movimentação para copiar o teor inteiro". **Não toca arquivo compartilhado.** | Functional: 200 com o texto puro para a publicação da pasta; 404 para publicação de outra pasta e de outro tenant; a publicação continua `lida = false` depois da chamada. |
| C6-FPN-04 | A | P4 | M | não | "Adicionar nota técnica" pelo botão direito marca a publicação como lida | dc:5392 (`abrirNova`: abre o compositor sob o cartão, `m.notaEditando`, sem tocar em `pushLidas`) | `pasta-push.js:528-537`: sem nota pendurada, `comTeor()` clica o cartão, o GET `pasta_push_teor` marca como lida e o compositor abre dentro do teor | Para o cartão que aceita nota (`aceitaNotaTecnica` e `pushPodeEscrever`), renderizar o bloco `_notas_tecnicas` pendurado também com a lista vazia, escondido (`hidden`) enquanto não houver nota nem compositor aberto. O menu tira o `hidden`, clica `.js-nota-nova` e não abre o teor. Ao cancelar sem nota, o bloco volta a se esconder. **Toca `_notas_tecnicas.html.twig`, compartilhado com a aba Processo** (só um parâmetro opcional novo). | Tela: o `<li>` que aceita nota traz `#notas-publicacao-<id>-…` com `hidden` e `.js-nota-nova`; o que não aceita não traz. Functional: nenhuma escrita em `lida` sem GET do teor (já coberto pelo `PastaPushLidaControllerTest`). |

Divergências vistas e **não** registradas, porque já estão em algum inventário ou no ledger:
- cabeçalho "Notas técnicas · N" no bloco pendurado (ledger §3.4, C);
- "Já lida" × "Marcar como não lida" (ledger §3.1, ratificar);
- Encaminhar (FPN-007/016, C);
- trilho da aba Push (FPN-024 a 028);
- êxito e sucumbência (FPN-002, D);
- valor da causa (FPN-001, C);
- placeholder do Relatório financeiro (FPN-004, C);
- vazios do Push (FPN-018, C);
- hora do cartão (D-PUSH-HORA).

## Matriz atualizada

O denominador agora inclui os requisitos que nasceram no ledger e já foram atendidos (FPN-014b, 014c/040L, 006b, 020b, 020c) e as quatro divergências novas.

| Dimensão | Conferidos OK (F) | Total de requisitos | Ciclo 0 |
|---|---|---|---|
| Visual | 88 | 106 | 79/104 |
| Interações | 63 | 92 | 53/88 |
| Funções (persistência/backend) | 38 | 60 | 31/57 |
| Responsivo | 11 | 12 | 10/12 |
| Dark mode | 14 | 14 | 14/14 |
| **Total** | **214** | **284** | 187/275 |

O que falta para fechar a matriz está quase todo em B, C ou D. São cerca de 66 requisitos: o trilho do Push, Encaminhar, link externo, êxito e sucumbência, valor da causa, central de notificações e avisos. Só 4 são A, os C6-FPN-01 a 04.

## Resumo

- **Feitos:** 20 conferidos: 19 ✓, 1 ◐ (FPN-036: falta o laranja na página `/notificacoes`), 0 ✗.
- **Regressões:** 0.
- **A pendentes do inventário:** 0. O FPN-013 virou C; o resto do FPN-036 virou C6-FPN-02.
- **Novas:** 4, todas A: C6-FPN-01 P4, C6-FPN-02 P4, C6-FPN-03 P5, C6-FPN-04 P4. Nenhuma B, C, D ou E nova. Nenhuma P0.
- **Arquivos compartilhados:**
  - C6-FPN-01 e C6-FPN-04 tocam `processo/_notas_tecnicas.html.twig` e `nota-tecnica.js`, usados pela aba Processo;
  - C6-FPN-02 toca `app.css` (CSS global).
- **Rastreabilidade:** o FPN-040 do ledger colide com o FPN-040 do inventário; proposta: renomear para FPN-040L.
