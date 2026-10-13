# Reauditoria C7 (convergência): Editor rico, atalhos e Impressão/Compartilhamento (EDT)

Auditoria somente leitura em master `4ba51e14`. "dc" = `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html`,
"bje" = `bj-editor.js` e "bjv" = `bj-visualizar.js`, os dois do pacote. Cor, margem e posição foram conferidas lendo CSS e template, sem abrir a tela.

## Fontes lidas
- Base: `c6/reauditoria-editor.md`. Ledger `engineering-loop-fidelidade-designer.md` l.360-412 (Ciclo 6: S6-EDT, S6-IMP, S6-DET, S6-JS, S6-IA01).
- Commits da área em `3ff6d9b0..HEAD`: `3e4bc262`, `1050971f`, `2c2bec4a`, `3dc0e749`, `7d3aa289`, `a011e330`, `4c2a991c`, `242ac329`, `abda487d`, `720086bd`, `81ed4da1`, `27cdae74`, `85686de6`.
- Desenho: bje:693-724 (`ctxMenu` e sugestões); dc:195-214 (janela), 4579-4632 (`imprimirResumo`, `impMontar`, `impVals`), 4556-4561 (`pdfBaixar`), 5945-5958 e 6455-6486 (⋮ do comentário, `menuObs` e `extrasObs`); bjv:85-95 (`@page`).
- Código: `app/public/js/editor-rico.js` (975-995, 1140-1151, 1205-1262, 1330-1360); `app/public/css/editor-rico.css` (580-592, 790-798);
  `app/public/js/pasta-impressao.js` (35-73, 143-170, 315-430); `app/public/css/pasta-impressao.css` (1-50 e grep);
  `app/templates/pasta/resumo_impressao.html.twig` (inteiro); `app/templates/pasta/_impressao_modal.html.twig` (inteiro);
  `app/src/Pasta/Controller/PastaResumoController.php` (60-330); `app/src/Pasta/DTO/PastaResumoImpressaoOutput.php` (100-120);
  `app/templates/pasta/_detalhes_obs.html.twig` (285-385, 1095-1140); `app/templates/pasta/_processos_vinculados.html.twig` (155-175, 225-262);
  `app/public/js/pasta-processo.js` (130-185); `app/templates/pasta/_cabecalho.html.twig` (235-246).

## Conferência dos itens A do C6

| ID | Lote | Veredito | Evidência |
|---|---|---|---|
| C6-EDT-01 Texto da movimentação | S6-IMP | ✓ | `PastaResumoImpressaoOutput.php:113` (`'texto' => $pub->textoResumo`, que já chega como texto puro por `PublicacaoDjenListaItem::resumirTexto`); `resumo_impressao.html.twig:222-227` (mostra o texto e, sem ele, usa órgão · processo) |
| C6-EDT-02 Sem Prioridade/Abertura | S6-IMP | ✓ | `resumo_impressao.html.twig:131-145`: Cliente, Documento, Responsável, Situação, Processo, Ação e Órgão, como em dc:4586 |
| C6-EDT-03 Avisos do ⋮ do processo | S6-IMP | ✓ | `_processos_vinculados.html.twig:234, 238` ("Número do processo copiado", "Resumo do processo copiado") |
| C6-EDT-04 Imprimir antes do separador | S6-DET | ✓ | Twig `_detalhes_obs.html.twig:1118-1128` e template JS `:364-370`: Citar → Criar meta → **Imprimir** → separador → Editar/Excluir, como em dc:5958 e 6466 |
| C6-EDT-05 Sugestões de correção | S6-EDT | ✓ | `editor-rico.js:1235-1255` (`correcoesDoMenu`: a mesma regra de bje:720-723, ou seja, a correção do dicionário, a forma acentuada, ou até 3 palavras próximas a 1 ou 2 erros); `:1336-1351` (cabeçalho e itens antes de Recortar, separador, troca num passo só); `editor-rico.css:793-797` (verde `#186c47`, negrito; `--er-revisao-ok` no tema escuro `:590`). Rótulo por `textContent` (`:1145`) |
| C6-EDT-06 Baixar PDF do registro e do processo | S6-IMP | ✓ | `pasta-impressao.js:167` (o botão só some sem `pdf`), `:322-333`, `:404-409` (id do cartão `detalhes-obs-<id>`); `pasta-processo.js:174-180` (`data-processo-id`). No servidor, `PastaResumoController.php:206-212, 255-284`: a observação é procurada por `id+pasta+tenant` e o processo só entre os vínculos da pasta e do tenant (404); a folha HTML dessas seções dá 404 (`:99-101`); o guarda de dono e VIEW vem antes (`contexto()`) |
| C6-EDT-07 Margem 18 mm em toda página | S6-IMP | ✓ (só lido) | iframe: `pasta-impressao.js:41-43`; página própria: `resumo_impressao.html.twig` (`@page{margin:18mm 0}`, `@page :first{margin-top:0}`, folha com `padding:18mm 16mm 0` e faixa `-18mm -16mm`). Os lados vêm do padding, que se repete em cada página. Precisa do smoke com 2 ou mais páginas, que segue pendente no ledger |
| C6-EDT-08 Tokens escuros na janela | S6-IMP | ✓ | `pasta-impressao.css:13-29` (`--psi-*`) e `:31-47` (`[data-bs-theme="dark"] .psi-fundo`); a folha continua branca |
| C6-EDT-09 Comentários desatualizados | S6-EDT + S6-JS | ✓ | `editor-rico.js:982-990`; `_cabecalho.html.twig:240-243` (janela com prévia e PDF gerado no servidor) |

Os dois ◐ do C6 fecham junto: EDT-003 (posição + PDF) e EDT-011 (sugestões).
**Contagem:** 9 ✓ · 0 ◐ · 0 ✗.

## Regressões
Nenhuma. Os commits vizinhos que tocam arquivos da área foram conferidos:
- `2c2bec4a` e `3dc0e749` escondem Citar, Editar e Excluir de quem não escreve. O Imprimir continua para todos, porque ler basta.
- `abda487d` mudou o cartão novo de Detalhes. O cartão mantém `id="detalhes-obs-<id>"`, então o PDF continua funcionando para o registro recém-enviado.
- `4c2a991c` e `81ed4da1` tocaram `_cabecalho` só em outros itens. O link "Imprimir resumo" (`:245`) segue interceptado por `pasta-impressao.js:356-366`.
- `720086bd` restringe a @menção do Financeiro. O EDT-010 continua ligado.

## Pendências A
Nenhuma da área. Sobre o **C6-EDT-10**, que segue **C** à espera da decisão do Samuel: o motivo dado no C6 foi "a auditoria só guarda mudança", e isso deixou de valer. O S6-IA01 (`ca97a7e7`) passou a gravar no `audit_log` o download e a impressão da análise da IA (`ia_baixar`/`ia_imprime`). Vale decidir os dois casos juntos e pelo mesmo critério, já que agora há precedente de registrar leitura.

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C7-EDT-01 | A | P5 | P | não | Resumo impresso: linha "Contrato" na seção Financeiro | dc:4590: a seção tem só Valor da causa, Recebido ("X de Y") e Lançamentos. O tipo de contrato aparece só no bloco `impFinanceiro` (dc:4600-4602), que o código já reproduz em `rp-fin-linha` | `resumo_impressao.html.twig:235` (`<tr>Contrato</tr>`), presente desde `3f68af0b` e nunca classificada (nem no inventário nem no C6). É o mesmo caso de "Prioridade/Abertura" (C6-EDT-02) | Tirar a linha. O "Contrato" continua no bloco Financeiro da opção | Functional em `PastaResumoImpressaoControllerTest`: a seção `data-secao="financeiro"` do resumo não contém "Contrato" (o bloco `data-rp-parte="financeiro"` pode conter) |

Sem achado de tenant ou segurança. As rotas novas do PDF conferem o dono antes da seção, a observação e o processo pelo escritório e pela pasta, e mantêm `isRemoteEnabled=false`.

## Matriz
Fecham 8 A do C6 nesta área: 01, 02, 04 e 07 em Visual; 03 em Interações; 06 em Funções; 08 em Dark; e o 05, que completa o EDT-011. Os ◐ EDT-003 e EDT-011 passam a contar como OK. O C7-EDT-01 soma um requisito em Visual.

| Dimensão | Conferidos OK | Total de requisitos |
|---|---|---|
| Visual | 31 | 32 |
| Interações | 41 | 44 |
| Funções (persistência/backend) | 11 | 21 |
| Responsivo | 4 | 4 |
| Dark mode | 5 | 5 |
| **Total** | **92** | **106** |

Ciclo 0: 63/97 (65%). C6: 83/105 (79%). C7: **92/106 (87%)**. Faltam 12 C do inventário (D-EDITOR, D-BARRA, LGPD, rascunho), o C6-EDT-10 (C) e o C7-EDT-01 (A).

## Resumo
- Itens A do C6: **9 ✓**, 0 ◐, 0 ✗. **0 regressões**, **0 pendências A**.
- Novas: **A 1** (C7-EDT-01, P5) · B/C/D/E 0. Nenhuma pede migration.
- Smoke do dono ainda pendente (do ledger): resumo com 2 ou mais páginas (margem do C6-EDT-07) e a janela no tema escuro.
