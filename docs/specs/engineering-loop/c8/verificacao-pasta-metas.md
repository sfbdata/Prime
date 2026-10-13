# C8 — Verificação: Pasta e Metas

Auditor somente leitura, no HEAD `3fdc0541`. Commits conferidos: `81ed4da1` (C7-PAS-01/02/03), `4ba51e14` (C7-PMC-01) e `517900bc` (C7-EDT-01).
Desenho: `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html` (dc).

## Conferência dos itens do C7

| ID | Situação | Evidência |
|---|---|---|
| C7-PAS-01 | ✓ | `pasta-dialogos.js:352` usa `'.modal.show:not([data-bs-focus="false"])'`. A Nova meta (`show.html.twig:4123`, `data-bs-focus="false"`) deixou de hospedar a janela, e o `#psConfirmar` fica no body com `z-index:2147483500`, acima da Nova meta (1049). O reforço `pointer-events: auto` está no `.ps-cf-fundo` (`show.html.twig:4341`). Um modal de verdade (Editar dados) continua hospedando. Ressalva de teste: a folha (`PastaJanelasRestantesFolhaTest`) prova o seletor, mas não o `pointer-events: auto` que o relatório também pedia |
| C7-PAS-02 | ✓ | `_cabecalho.html.twig:285`: `avisarPs('Número não confere. A pasta não foi excluída.')` sai sem tipo. O `avisarPs` (`pasta-dialogos.js:470-473`) usa por padrão o `bi-check-circle-fill` neutro, como o `gmAviso` (dc:4338) |
| C7-PAS-03 | ✓ | `_cabecalho.html.twig:284-285`: `data-cliente` recebe `cliente.nomeExibicao`, ou `pasta.nomeCliente` quando a pasta não tem cliente, que é o mesmo texto do título `h1` (`:328-331`). O prompt fica `'Excluir a pasta ' + n + (c ? ' (' + c + ')' : '') + '?'`, como em dc:4337. O valor é escapado pelo Twig e lido via `dataset`, então não há injeção. Ressalva de teste: o funcional cobre só o caminho do fallback (`getNomeCliente`). O ramo com cliente cadastrado (`nomeExibicao`) não tem teste |
| C7-PMC-01 | ✓ | `pasta-metas.js:899-907`: o ouvinte de Esc no `document` saiu. O Esc agora fica com o Bootstrap 5.3.3 (`base.html.twig:467`), que ouve o keydown no próprio `.modal`, e o `#modalCriarTarefa` não tem `data-bs-keyboard="false"`. Isso equivale ao `nm.tecla` no `role="dialog"` do desenho. O foco continua indo para o título ao abrir (`:904`). O calendário e a lista de responsáveis dentro da janela continuam parando a propagação (`pasta-dialogos.js:182-185, 321-324`). O teste (`PastaMetasListaTelaTest:494-495`) garante que o ouvinte no documento não volta |
| C7-EDT-01 | ✓ | `resumo_impressao.html.twig:234-243`: a seção `data-secao="financeiro"` ficou com Valor da causa, Recebido e Lançamentos, como dc:4592. O tipo de contrato continua no bloco da opção (`rp-fin-linha`, `:275`). O teste está em `PastaResumoImpressaoControllerTest` |

**Contagem:** 5 ✓, 0 ◐, 0 ✗.

## Regressões

Nenhuma.
- **PAS-01:** com Editar dados e Nova meta abertos ao mesmo tempo, o seletor pega o modal de verdade. Com só a Nova meta aberta, a janela fica no body, recebe o clique e mantém a trava de Tab/Esc própria (`pasta-dialogos.js:413-430`).
- **PMC-01:** o Esc dado dentro da Nova meta fecha a janela, como antes. O Esc dado na lista atrás fecha só o que é da lista (drawer `pasta-metas.js:507`, renomear `:330`, popovers `:637`).
- **EDT-01:** o `PastaResumoImpressaoOutput:202` continua montando `financeiro.contrato`, mas o template não usa mais esse campo. É código morto e não afeta o comportamento.

## Novas A

Nenhuma. Notei uma divergência que não vem desses commits e por isso não abri item: no dc:4592 o "Recebido" aparece sempre, e o código só o mostra com `lancamentos > 0` (`resumo_impressao.html.twig:239`). Fica para o orquestrador decidir se classifica.

## Resumo

- Itens do C7: 5 ✓, 0 ◐, 0 ✗. Regressões: 0. Novas A: 0.
- Lacunas de teste, nenhuma bloqueante: o `pointer-events: auto` (PAS-01) e o ramo `nomeExibicao` (PAS-03) não têm teste.
