# Reauditoria C7 — Pasta: Processo · Metas · Clientes (prefixo PMC-)

Auditoria de convergência, somente leitura. Master `76671b8a` (o briefing citava `d985225e`; entraram depois
`81ed4da1`, o C7-PAS-01, e `76671b8a`, só um comentário). 13/10/2026. Nada foi editado além deste arquivo.
Nenhuma suíte, container ou navegador foi usado. O visual foi conferido lendo CSS e template.

Fontes: relatório C6 da área. Ledger §"Ciclo 6" (lotes S6-CLI, S6-SHOW, S6-PUSH, S6-IMP). Commits
`git log 3ff6d9b0..HEAD` que tocam a área (12, listados abaixo). Desenho `02 - EXPEDIENTES 1.2.3.dc.html`:
dc:75 (janela da Nova meta), dc:3766-3772 e 3891 (`nmVals`, `nm.tecla`) e dc:5390-5407 (`pushNotaVals`).

## Conferência

| ID | Lote · commit | Veredito | Evidência |
|---|---|---|---|
| C6-PMC-01 | S6-SHOW `64927a09`, `cccddbb5` | ◐ | A janela não tem fundo nem trava de foco: `show.html.twig` traz `data-bs-backdrop="false" data-bs-focus="false"`, `pointer-events` só na `.modal-content`, a rolagem do body está liberada e o z-index é 1049. O foco vai para o título ao abrir (`pasta-metas.js:903-906`). **Falta:** o Esc foi ligado no `document` (`pasta-metas.js:907-911`). Por isso ele fecha a Nova meta mesmo quando o foco está em outro lugar da página. Ver **C7-PMC-01** |
| C6-PMC-02 | S6-SHOW `64927a09` | ✓ | `show.html.twig`: `Pasta {{ pasta.nup ?: ('#' ~ pasta.id) }}`. O `?:` cobre também o NUP vazio |
| C6-PMC-03 | S6-CLI `64367e07`, `06592edd` | ✓ | `_dados_trilho.html.twig`: `podeMexerNoCliente = podeEditarPasta and not pasta.estaExcluida` controla o "+" e todo o `.ps-vinc`, e o valor é repassado às linhas. `_cliente_linha.html.twig:22-24` tem o fallback para o XHR. A estrela vazia e a lixeira só aparecem com permissão. Quem não pode trocar não recebe o token nem a dica "Clique na estrela de outro". O `pasta-clientes-busca.js:27-28` já sai cedo sem `#psVincCliente`. O JS da troca de estrela (`show.html.twig:~1430`) só roda para quem edita |
| C6-PMC-04 | S6-IMP `7d3aa289` | ✓ | `_processos_vinculados.html.twig:234/238`: "Número do processo copiado" e "Resumo do processo copiado" (dc:2926-2927) |
| C6-PMC-05 | S6-PUSH `27cdae74`, `85686de6` | ✓ | `_nota_tecnica_item.html.twig`: sem `disabled`, com `aria-disabled="true"`, `.js-nota-bloqueado` e `data-aviso` (texto de dc:5405-5406, sem o D-MASTER). `nota-tecnica.js:~496-504`: fecha o menu e mostra o aviso neutro (`avisarPs`, como o `gmAviso`). O cinza continua vindo da classe (`nota-tecnica.css:188-189`) e não depende de `:disabled` |
| C6-PMC-06 | S6-CLI `26296bcf` | ✓ | `_metas.html.twig`: `maxlength` vem de `RenomearMetaUseCase::TAMANHO_MAXIMO` (255, `:24`). Não sobrou nenhum `maxlength="160"` em `templates/` nem em `public/js` |
| C6-FPN-01 (aba Processo) | S6-PUSH `27cdae74` | ✓ | As três listagens de `NotaTecnicaRepository` (`:64`, `:89`, `:123`) cortam no teto pelas mais novas e invertem a página (`emOrdemCronologica`). A nota nova entra com `beforeend` (`nota-tecnica.js:166`), logo acima do compositor, que fica embaixo (`_notas_tecnicas.html.twig`), como em dc:5395 (`A.push`). Consumidores: `NotaTecnicaExtension:59` (aba Processo e processo/show), `PastaController:423` e `PastaPushProcessualController:120`. Nenhum deles dependia de "mais nova primeiro" |
| Baixar PDF do processo | S6-IMP `7d3aa289`, `a011e330` | ✓ | `pasta-processo.js:174-180` passa `pdf: {secao:'processo', processo:<data-processo-id>}`. O menu continua dentro do `<article data-processo-id>` (`_processos_vinculados.html.twig:173`), então o `closest` acha o processo também pelo botão direito. O servidor confere o vínculo com a pasta e com o tenant (`PastaResumoController::processoDaPasta`, `:274-280`). A captura frágil saiu de `pasta-impressao.js` |

**Contagem:** 7 ✓ · 1 ◐ (C6-PMC-01) · 0 ✗.

## Regressões

Os 12 commits do C6 que tocam a área são `64367e07`, `26296bcf`, `06592edd`, `64927a09`, `cccddbb5`, `7d3aa289`,
`a011e330`, `27cdae74`, `85686de6`, `4c2a991c`, `242ac329` e `81ed4da1`. A conferência encontrou **1** regressão:

- **C7-PMC-01** (de `cccddbb5`). O Esc da Nova meta passou a valer na página inteira. Antes do C6, o Bootstrap só
  ouvia o Esc na própria janela. Os outros Esc da tela ficam em elementos ou no `document`, e quase nenhum para a
  propagação. Um Esc dado em outro ponto fecha esses elementos **e também** a Nova meta:
  - fechar o relatório (drawer) da meta: `pasta-metas.js:505-510`, no `document`, sem `stopPropagation`. Esse é
    justamente o cenário que o "Recolher (continue vendo a lista)" promete;
  - cancelar o renomear da meta (`:329-333`);
  - fechar os popovers ⋮ da lista (`:636-638`);
  - cancelar a resposta no Registro (`show.html.twig:2488`);
  - cancelar o compositor de nota técnica.

  A guarda `.modal.show:not(#modalCriarTarefa)` só protege outros `.modal` do Bootstrap. O drawer
  (`#psMetaRelatorio`) e os popovers não são `.modal`, então ficam de fora. O desenho prende o Esc na janela:
  `onKeyDown="{{ nm.tecla }}"` está no próprio `role="dialog"` (dc:75, dc:3891). O texto digitado não se perde,
  porque o Bootstrap não limpa o form. Mas a janela fecha, e o `hidden.bs.modal` desfaz a posição e o recolhido
  (`pasta-metas.js:964-969`).

Outros pontos sensíveis foram conferidos e **não** regrediram:
- A confirmação (`confirmarPs`) com a Nova meta aberta foi corrigida por `81ed4da1` (C7-PAS-01). A confirmação não
  é mais hospedada num modal com `data-bs-focus="false"` e o fundo voltou a ter `pointer-events:auto`. O Esc da
  confirmação para a propagação (`pasta-dialogos.js:~414-418`).
- O z-index 1049 fica abaixo do drawer da meta (1055/1056) e das outras janelas, como pedia o lote.
- O calendário e a lista de responsáveis da Nova meta param a propagação do Esc (`pasta-dialogos.js:182-186`,
  `:321-325`). O Esc ali continua fechando só o popover.

## Pendências A

Nenhuma, além da parte que falta do C6-PMC-01 (C7-PMC-01). PMC-052 e PMC-058i continuam C (D-CLI-AUDIT) e
C6-PMC-07 continua C (decisão do Samuel).

## Novas

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho | Código | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C7-PMC-01 | A (regressão de `cccddbb5`) | P4 | P | não | Esc da Nova meta | dc:75 `role="dialog" onKeyDown="{{ nm.tecla }}"`: o Esc vale só com o foco **na janela**; dc:3891 | `pasta-metas.js:907-911`: `document.addEventListener('keydown', …)` fecha a Nova meta com qualquer Esc da página (drawer da meta, renomear, popovers ⋮, resposta do Registro, compositor de nota) | Ligar o handler em `modal` (`#modalCriarTarefa`) em vez de `document`, ou simplesmente apagá-lo. Com o foco levado ao título (`:905`), o próprio Bootstrap (`keyboard` padrão = true; o listener `keydown.dismiss` fica no `.modal`) já fecha quando o Esc nasce dentro da janela. Os popovers internos já param a propagação | Ajustar `PastaMetasListaTelaTest.php:494`, que hoje exige o texto do handler no `document`. Estático: o listener de Esc está no `modal` e o JS não tem `document.addEventListener('keydown'` nesse bloco. Smoke: Nova meta recolhida, abrir o drawer de uma meta, apertar Esc. Só o drawer fecha |

Nenhuma outra divergência A nova foi encontrada nos pontos tocados pelo C6.

## Matriz

Muda pelos A do C6 que foram confirmados. C6-PMC-02 entra em Visual/Metas. C6-PMC-03 entra em Interações/Clientes.
C6-PMC-04 entra em Interações/Processo. C6-PMC-06 entra em Funções/Metas. C6-PMC-05 já estava dentro do PMC-017.
O PMC-038/059 continua fora do "OK" em Interações por causa do C7-PMC-01.

| Dimensão | Conferidos OK | Total de requisitos |
|---|---|---|
| Visual | 65 | 79 (Processo 17/22 · Metas 29/33 · Clientes 19/24) |
| Interações | 66 | 85 (Processo 23/33 · Metas 23/28 · Clientes 20/24) |
| Funções (persistência/backend) | 27 | 42 (Processo 9/14 · Metas 11/15 · Clientes 7/13) |
| Responsivo (<768px) | 8 | 8 |
| Dark mode | 8 | 8 |
| **Total** | **174** | **222 (78,4%)**. No C6 eram 170/222 (76,6%) |

## Resumo

- Itens do C6 da área: 7 ✓, 1 ◐ (C6-PMC-01, porque o Esc está no escopo errado) e 0 ✗.
- Regressões: 1 (C7-PMC-01, A, P4, esforço P, sem migration). Mexe em `pasta-metas.js` e num teste de tela.
- Pendências A: 0 além dessa. Novas fora da regressão: 0.
- Matriz: 174/222 (78,4%).
