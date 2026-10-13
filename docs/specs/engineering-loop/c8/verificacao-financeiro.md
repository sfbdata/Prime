# Verificação C8: Financeiro

Auditoria somente leitura em 13/10/2026, no HEAD `3fdc0541`. Commits conferidos: `0fc196da` e `5b356986` (S7-FIN).
Relatório de base: `c7/reauditoria-financeiro-push.md`. Conferi lendo template, JS e CSS. **Não olhei na tela.**

## Conferência

| Item | Veredito | Evidência |
|---|---|---|
| C7-FPN-01: escrita do Financeiro escondida de quem só vê e na lápide | ✓ | `_financeiro.html.twig:33-35` (`podeEscreverFin = podeEditarPasta and not pasta.estaExcluida`; `show.html.twig:245` passa `podeEditarPasta` ao include `only`). Ficam de fora: os selos de Contrato e Pró-bono, que viram `<span>` sem gesto (`:66-118`); o lápis, o campo e o `data-url`/CSRF dos honorários (`:130-163`); o compositor, que na lápide dá lugar à nota `ps-compositor--leitura` (`:206-234`); o convite "Anote acima" (`:243`); Responder, Editar e Excluir com o separador no ⋮ (`:295-311`); o Responder e a lápis/lixeira do cartão (macro `podeEscrever`, `:1550,1606`); Adicionar, Renomear e Excluir arquivo e o rodapé de envio (`:332,412,442,471`); Concluir edição, Adicionar e Editar valores no ⋮ de Pagamentos (`:517,538`); o "+ Adicionar pagamento" (`:577`); e, no partial, o número clicável, o lápis de valor, o selo de quitar, a lixeira, o menu da linha, o `aria-controls` e o CSRF (`_financeiro_pagamentos.html.twig:36-154`). O JS tolera a falta de cada id: `if (btnSituacao)`, `if (btnProBono)`, `if (vcBloco && vcTexto && vcInput)`, `if (reduzirBtn)`, `if (uploadInput)`, `if (formObs)`, e `prepararMenuObs` retorna sem Editar/Excluir (`:1265-1267`). Testes: `PastaFinanceiroEscritaTelaTest` cobre leitor, editor, lápide e outro escritório. |
| C6-FPN-05: minutos do ⋮ recalculados | ✓ | `data-janela-ate` vem no cartão Twig (`_financeiro.html.twig:1565`) e no cartão JS (`_financeiro_obs_script.html.twig:172-176`, a partir de `criadaEmEpoch`, em `PastaController.php:2111`). `minutosRestantesObs` (`:1246-1251`) segue a regra de Detalhes, e `prepararMenuObs` (`:1268-1283`) chama a função a cada abertura. O item que nasceu bloqueado não reabre, e passada a janela o menu mostra "· bloqueado" com `OBS_MOTIVO_PRAZO` (o cartão JS tem `data-obs-do-autor="1"`). Atualizar o "· N min" do cartão era opcional e não foi feito. |
| C7-FPN-02: bloqueado clicável com aviso (Financeiro) | ✓ | `pintarItemObs` (`:1230-1243`) usa `disabled = false`, `aria-disabled` e `data-aviso`. O clique (`:1417-1423`) fecha o menu e chama `avisarPs(motivo)`, como o `gmAviso` de dc:5958/5904. O CSS mantém a cara de bloqueado via `[aria-disabled="true"]` (`pasta-show.css:2796-2801`, `5b356986`). |
| C7-FPN-02: o mesmo em Detalhes | ✓ | `_detalhes_obs.html.twig`: os itens do servidor (`:1172-1182`) têm `aria-disabled` e `data-aviso`, sem `disabled`. `pintarItemObs` (`:618-632`) e `pintarJanelaObs` repintam ao abrir. O handler da lista (`:796-797`) e `acionarDoCartao` (`:755`) chamam `avisarBloqueadoObs` (`:745`). O `<style>` local passou para `[aria-disabled="true"]` (`:64-66`). O menu fica dentro de `obsLista` (não é portal), então o clique chega ao handler. |
| Menção: a edição do Registro diz a URL explicitamente | ✓ | `show.html.twig:2781` define `textareaEd._mencoesUrl` antes de `PastaMencoes.ligar`. `pasta-mencoes.js:197` lê a URL de forma preguiçosa (`textarea._mencoesUrl \|\| urlPadrao()`). O ponto latente do C7 fica fechado. |

Placar: **5 conferidos: 5 ✓, 0 ◐, 0 ✗.**

## Regressões e leitura escondida por engano

Nenhuma. O que conferi:
- **Partial de Pagamentos re-renderizado** (`PastaPagamentoController.php:318`, sem `podeEscreverFin`): o partial usa `podeEscreverFin ?? true` (`:36`), e o `??` do Twig tolera variável indefinida. Todos os chamadores são POST que exigem `edit` (`:61,111,172,207,248`), e a lápide é recusada pelo listener. Por isso a escrita volta só para quem escreve.
- **Leitura que continuou à vista**:
  - Arquivos: Abrir, Baixar, Ver em Documentos e o `mailto:` do Twig.
  - Copiar nome: sem o Renomear, o nome sai do `title` do `.ps-fin-menu-titulo` (`:1427-1429`, o título traz `title="{{ doc.titulo }}"`).
  - ⋮ de Pagamentos: Ver todos, Copiar resumo e Imprimir extrato, com o ⋮ montado quando há lançamento (`:525`). A folha `#psExtratoFolha` continua no partial.
  - Valores: "corrigido · era …" e o `title` com o histórico. O `#financeiro-valor-causa` com `data-valor`, que o modal e a média leem, e o `#financeiro-media-cpf` (`:179`).
  - Notas: Copiar texto do ⋮ do Relatório.
- **Clique direito numa linha de Pagamentos sem `aria-controls`**: cai no seletor `.ps-pag-linha[aria-controls]` (`:1493`), não acha gatilho e fica com o menu do navegador. Não há erro.
- **Detalhes e Registro**: só mudaram o bloqueado (acima) e a URL da menção. A aba Processo e a nota técnica não foram tocadas.

## Novas A

Nenhuma.

Observação, que não é A: o "aviso neutro" (`avisarPs` sem `tipo`) sai com o ícone `bi-check-circle-fill`. É o mesmo padrão já aceito no C6-PMC-05 e no `gmAviso` do desenho, que é um só para tudo. Não reabro.

## Resumo

5 conferidos, todos ✓. 0 regressões. 0 A novos.
