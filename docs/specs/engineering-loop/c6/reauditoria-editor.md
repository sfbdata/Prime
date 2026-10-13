# Reauditoria C6: Editor rico, atalhos e Impressão/Compartilhamento (EDT)

Auditoria somente leitura em master `4a98cc6b`. "dc" = `docs/design/claude-design-2026-10-05 (1)/02 - EXPEDIENTES 1.2.3.dc.html`;
"bje" = `bj-editor.js` do pacote. O visual (cor, raio, margem de página) foi conferido **lendo CSS e template**, não na tela.

## Fontes lidas
- Briefing do auditor C6; inventário `docs/specs/engineering-loop/inventario-editor.md` (inteiro).
- Ledger `docs/specs/engineering-loop-fidelidade-designer.md`: l.88-92 (equivalências), 151-155, 162-164, 180, 191, 193, 198, 206, 210 (feitos EDT), 250-262 e 330-345 (§3.4/§3.5).
- Fila `docs/specs/engineering-loop/fila-a-restante.md`: l.31, 48, 57-58, 99-104, 165, 182-183, 207.
- `git log --oneline 607f2c29..HEAD` filtrado por editor/impressão (≈45 commits; os EDT estão entre `11c0a460` e `ab78b61f`).
- Desenho: bje:355-372 (rodapé/Enviar), 404-420 (`mudou`), 614-690 (autoformatação, sugestões, chip, `onKey`), 693-762 (`ctxMenu`), 763-780 e 948-957 (colar);
  dc:195-214 (janela Imprimir), 2914-2932 (`procMenu`), 4556-4561 (`pdfBaixar`), 4579-4632 (`gmImprimir`, `imprimirResumo`, `impFinanceiro`, `impMontar`, `impVals`),
  5945-5958 (⋮ do comentário com `_extras`), 6448-6486 (`menuObs`/`extrasObs`, uso só em `obsDet`), 6509-6512 (atalhos E/H), 6615-6626 (`mCopiar`, `mImprimir`);
  `bj-visualizar.js:85-105` (`BJImprimir`, `@page margin 18mm 16mm`); `design_handoff_bluejus_intelligence/README.md:120-135`.
- Código: `app/public/js/editor-rico.js` (125-190, 550-578, 900-1412, 1447-1672, 1900-2326), `app/public/css/editor-rico.css` (grep `@media`, `data-bs-theme`, citação),
  `app/public/js/pasta-impressao.js` (inteiro), `app/public/css/pasta-impressao.css` (1-140 + grep), `app/templates/pasta/_impressao_modal.html.twig` (inteiro),
  `app/templates/pasta/resumo_impressao.html.twig` (inteiro), `app/src/Pasta/Controller/PastaResumoController.php` (inteiro),
  `app/src/Pasta/DTO/PastaResumoImpressaoOutput.php` (1-30, 105-125), `app/src/Djen/DTO/PublicacaoDjenListaItem.php` (grep), `PublicacaoDjenRepository::listarItensPorNumerosDoTenant` (grep),
  `app/templates/pasta/_cabecalho.html.twig:230-245`, `app/public/js/pasta-show.js:356-401`, `app/templates/pasta/_processos_vinculados.html.twig:215-262`,
  `app/public/js/pasta-processo.js:100-215`, `app/templates/pasta/_detalhes_obs.html.twig` (60-90, 150-230, 320-360, 600-625, 985-1052),
  `app/templates/pasta/_financeiro.html.twig` (160-176, 1210-1235), `app/templates/pasta/_financeiro_obs_script.html.twig:75-90`,
  `app/templates/pasta/_dados_anotacoes.html.twig:125-138`, `app/templates/pasta/show.html.twig:2460-2472, 2970, 4355-4370`, `app/public/js/nota-tecnica.js` (grep),
  `app/config/packages/html_sanitizer.yaml` (grep), lista de testes `app/tests/Pasta/Functional/*Impress*`.

## Conferência dos feitos
22 itens A do inventário estavam declarados feitos (ledger §3.1 + fila).

| ID | Veredito | Evidência |
|---|---|---|
| EDT-001 Imprimir resumo: janela com prévia e opções | ✓ | `_impressao_modal.html.twig:16-63` (título, 4 opções, 9–16 pt, nota "Papel A4…", Imprimir/Baixar PDF/Copiar texto); `pasta-impressao.js:27-29, 380-381` (passo 0,5), `:249-293` (iframe oculto `sandbox` sem script); interceptação do link `:350-361`; folha do servidor com guardas `PastaResumoController.php:84-100, 203-216` |
| EDT-002 Baixar PDF | ✓ (auditoria fora, ver C6-EDT-10) | `PastaResumoController.php:102-152` (Dompdf, `isRemoteEnabled=false`, rodapé "BlueJus · página k de n", nome `BlueJus <título> dd.mm.aaaa.pdf` `:158-165`) |
| EDT-003 Imprimir no ⋮ da observação | ◐ | Existe, com título "Registro do expediente" e autor · data · tipo (`pasta-impressao.js:385-397`; `_detalhes_obs.html.twig:1041-1048`). Falta: posição do item (C6-EDT-04) e Baixar PDF (C6-EDT-06). Retirada do Registro/Financeiro (EDT-003r) confere com o desenho: `menuObs`/`extrasObs` só em `obsDet` (dc:6486) |
| EDT-004 Imprimir extrato pela mesma janela | ✓ | `_financeiro.html.twig:1215-1220`; folha `resumo_impressao.html.twig:235-244` (tarja, faixa, rodapé comuns) |
| EDT-005 Imprimir resumo do processo | ✓ | `_processos_vinculados.html.twig:249-256`; `pasta-processo.js:152-175` (tabela rótulo/valor por DOM, título "Processo N", como dc:2930). PDF: C6-EDT-06 |
| EDT-006 Compartilhar sem Web Share | ✓ | `pasta-processo.js:138-145` (copia e avisa "Resumo copiado para compartilhar"); item sem `hidden` `_processos_vinculados.html.twig:243-248` |
| EDT-007 Copiar link: ícone e 900 ms | ✓ | `pasta-show.js:369-378` |
| EDT-008 "Impresso em" com segundos | ✓ | `resumo_impressao.html.twig:99` (`d/m/Y, H:i:s`) |
| EDT-009 Citar no novo registro | ✓ | `editor-rico.js:1928-1993` (`inserirCitacao`, conversor do Quill, chip "Citação inserida no editor"); `_detalhes_obs.html.twig:612-619` |
| EDT-010 @menção nos demais editores | ✓ | `_detalhes_obs.html.twig:71`, `_financeiro.html.twig:165`, `_financeiro_obs_script.html.twig:473`, `nota-tecnica.js:44`; servidor em `ab78b61f` (UseCases de observação + `ResolucaoDeMencoesDoRegistro`) |
| EDT-011 Menu do botão direito | ◐ | Mini barra, Recortar/Copiar/Colar/Colar somente texto, Parágrafo, Sinônimos, qualificação, Localizar, Revisar, Selecionar tudo e Shift+botão direito: `editor-rico.js:1257-1393`. Falta o bloco **"Sugestões de correção"** da palavra sob o cursor (bje:719-724), deixado de fora "de propósito" sem decisão registrada (`editor-rico.js:982`) → C6-EDT-05 |
| EDT-012 Sinônimos | ✓ | `editor-rico.js:178, 1216-1228, 1319-1330` |
| EDT-014 Inserir qualificação do cliente | ✓ | `editor-rico.js:1236-1255, 1332-1339`; fonte só para quem vê o cliente `show.html.twig:4361-4370` |
| EDT-015 Sugestão de palavras | ✓ | `editor-rico.js:1524-1665` (3 letras, 4 opções, Tab/↑↓/Enter após navegar/Esc, cede à @menção) |
| EDT-016 "> " e "1)" com chip e Desfazer | ✓ | `editor-rico.js:910-962, 2108-2114` |
| EDT-017 Colar com opções | ✓ | `editor-rico.js:1400-1508` (rótulo "Colar", 3 opções, 7 s) |
| EDT-018 Citação no seletor de estilo | ✓ | `editor-rico.js:138-147, 392-416`; `editor-rico.css:421-422, 458-459` |
| EDT-019 Ctrl+Y em todo sistema | ✓ | `editor-rico.js:2092-2098` |
| EDT-020 Tab = 4 espaços não separáveis | ✓ | `editor-rico.js:555-572, 2102-2105` |
| EDT-021 Enviar apagado com editor vazio | ✓ | `editor-rico.js:2006-2017`; ligado em `_dados_anotacoes.html.twig:130`, `_detalhes_obs.html.twig:78`, `_financeiro_obs_script.html.twig:81`, `_notas_tecnicas.html.twig:77`, `nota-tecnica.js:199` |
| EDT-022 Rótulo do Enviar só em texto | ✓ | `show.html.twig:2970`, `_detalhes_obs.html.twig:227`, `_financeiro_obs_script.html.twig:118` (`textContent = 'Enviar'`) |
| EDT-023 Barra 34×32 sem hover | ✓ | `editor-rico.css:362-366` |

**Contagem:** 20 ✓ · 2 ◐ (EDT-003, EDT-011) · 0 ✗.

## Regressões
Nenhuma encontrada. Os atalhos E/H do ⋮ (`pasta-show.js:400-401`), Copiar número/resumo, Ctrl+Enter (`editor-rico.js:2246-2256`, agora respeitando o Enviar apagado) e o dark mode do editor (`editor-rico.css:59, 182, 585, 689, 703, 810, 885`) seguem de pé. A retirada do Imprimir do Registro e do Financeiro (`fdbb56b0`) **aproxima** do desenho, não é regressão.

Dois comentários ficaram mentindo depois do loop (C6-EDT-09): `_cabecalho.html.twig:237-238` ("Folha A4 em aba nova… Baixar PDF é o Salvar como PDF") e `editor-rico.js:982-984` (diz que a qualificação, EDT-014, fica fora do menu).

## Pendências A
Nenhum item A do inventário ficou sem implementar. O que sobrou dos dois ◐ virou item novo: **C6-EDT-04** (resto do EDT-003) e **C6-EDT-05** (resto do EDT-011).

Os itens C do inventário (EDT-013, 024–034) continuam C, sem mudança no código: o sanitizador ainda não aceita `a`/`img` (`html_sanitizer.yaml:12`), e não há Ctrl+K em `public/js`.

## Novas divergências
| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-EDT-01 | A | P4 | P | não | Resumo impresso: "Últimas movimentações" sem o texto | dc:4588 (`data · tipo · texto` da movimentação) | `resumo_impressao.html.twig:207-219` mostra data, tipo e **órgão · processo**; `PastaResumoImpressaoOutput.php:110-116` não leva texto, embora `PublicacaoDjenListaItem::$textoResumo` já venha da consulta (`PublicacaoDjenListaItem.php:30, 52`) | Pôr `'texto' => $pub->textoResumo` no DTO e mostrar na 3ª coluna (com o órgão como fallback quando o texto vier nulo). Não toca arquivo compartilhado | Functional em `PastaResumoImpressaoControllerTest`: publicação com texto conhecido → o trecho aparece na folha (fragmento e página) |
| C6-EDT-02 | A | P5 | P | não | Resumo impresso: linhas "Prioridade" e "Abertura" em "Dados da pasta" | dc:4586 (Cliente, Documento, Responsável, Situação, Processo, Ação, Órgão) | `resumo_impressao.html.twig:131-132` | Tirar as duas linhas. As seções Clientes (N) e Processos (N) (`:146-175`) **ficam**: o desenho só modela 1 cliente e 1 processo, então nesse ponto ele é omisso | Functional: a folha não contém "Prioridade" nem "Abertura" |
| C6-EDT-03 | A | P5 | P | não | Avisos do ⋮ do processo | dc:2926-2927 ("Número do processo copiado", "Resumo do processo copiado") | `_processos_vinculados.html.twig:234, 238` ("Número copiado", "Resumo copiado") | Trocar os dois `data-aviso`. Sobrepõe a área PMC: alinhar com esse auditor | Template test com os dois textos |
| C6-EDT-04 | A | P5 | P | não | Posição do "Imprimir" no ⋮ da observação (resto do EDT-003) | dc:5958 + 6466: os `_extras` (Citar, Criar meta, **Imprimir**) entram antes do separador e de Editar/Excluir | `_detalhes_obs.html.twig:1041-1048` e `:348-352` (versão JS): Imprimir é o **último**, depois de Excluir. O comentário `:992` diz "como no desenho" | Mover o botão para logo depois de "Criar meta…", nos dois lugares (Twig e template JS) | Arranjo em `PastaDetalhesObsMenuTelaTest`: o `.js-obs-imprimir` vem antes do `.ps-processo-menu-sep` |
| C6-EDT-05 | A | P3 | P | não | Menu do botão direito: "Sugestões de correção" (resto do EDT-011) | bje:719-724 (até 3 sugestões, ícone `bi-spellcheck` verde, troca a palavra) | Ausente; `editor-rico.js:982` diz que ficou de fora "de propósito", sem decisão no ledger. `correcaoDe`/`sugestaoDe`/`palavraProxima` já existem (`:225-265`), e é tudo dicionário local, sem terceiros | Em `abrirMenuContexto`, antes de Recortar: cabeçalho "Sugestões de correção" + itens que chamam `trocarTrechos` (como os sinônimos), quando `palavraDoMenu` tiver correção. Só `editor-rico.js`/`.css` | `EditorRicoMenuContextoTest` (leitura do JS: rótulo e ordem antes de "Recortar"); smoke do dono |
| C6-EDT-06 | A | P3 | M | não | "Baixar PDF" do registro (observação) e do resumo do processo | dc:211 + 4630 (`baixar: () => this.pdfBaixar(m)` vale para toda folha da janela) | `pasta-impressao.js:158` esconde o botão na `moldura`; `PastaResumoController.php:107` recusa moldura no PDF | O servidor monta o corpo pelo id, sem receber HTML do cliente: `secao=observacao&obs=<id>` (observação de Detalhes da MESMA pasta, `SanitizadorTextoRico::paraExibicao`, autor/data/tipo) e `secao=processo&processo=<id>` (processo vinculado à pasta, as mesmas linhas do `data-resumo`). Toca `PastaImpressaoOpcoes`, o controller, o template e `pasta-impressao.js` | Functional: PDF 200 das duas seções; obs/processo de outra pasta → 404; de outro tenant → 404; sem VIEW → 403 |
| C6-EDT-07 | A | P3 | P | não | Margem das páginas seguintes na impressão (iframe e página própria) | `bj-visualizar.js:93` (`@page{size:A4;margin:18mm 16mm 18mm}` em toda página) | `pasta-impressao.js:35` e `resumo_impressao.html.twig:308`: `@page{margin:0}` com só o `padding` da folha. Da 2ª página em diante o texto encosta na borda de cima e de baixo. Conferido lendo CSS, não impresso | No CSS de impressão: `@page{size:A4;margin:18mm 16mm}` e `.rp-folha{padding:0}`, com a faixa azul `margin:-18mm -16mm 30px` (como já faz o PDF, `:287-295`) | Nenhum PHPUnit enxerga. Leitura do JS/template (constante com `margin:18mm`) + smoke do dono com resumo de 2 páginas |
| C6-EDT-08 | A | P5 | P | não | Janela Imprimir no tema escuro | dc omisso (convenção do sistema: tokens `[data-bs-theme="dark"]`) | `pasta-impressao.css`: 0 `var(--`, 0 `data-bs-theme`, cores fixas (ex.: `:101` `#fafcfd`, `:123` `#243845`). Fica clara no tema escuro, mas legível | Tokens no `:root`/`[data-bs-theme="dark"]` para a lateral, os rótulos e os botões. A folha continua branca, porque é papel | Leitura do CSS (bloco dark presente) |
| C6-EDT-09 | A | P5 | P | não | Comentários desatualizados | — | `_cabecalho.html.twig:237-238`; `editor-rico.js:982-984` | Corrigir o texto: janela com prévia e PDF do servidor; a qualificação está no menu. `_cabecalho` é compartilhado | — |
| C6-EDT-10 | C | P4 | P | a decidir | Registrar download/impressão na auditoria | dc:4561 (`auditar('impressao', 'download PDF · <título>')`) | Nenhum registro em `PastaResumoController::pdf` | Decisão do Samuel: a auditoria deve guardar evento de **leitura** (baixar PDF/imprimir)? Hoje ela só guarda mudança. É regra nova | — |

Arquivos compartilhados: C6-EDT-09 toca `_cabecalho.html.twig`. Nenhum item toca `base.html.twig`, `pasta-show.css`, `show.html.twig` nem `pasta-show.js`. C6-EDT-04 toca `_detalhes_obs.html.twig`, da área Registro/Detalhes.

## Matriz
O denominador cresce com C6-EDT-01, 02, 04 e 07 (Visual), 03 (Interações), 06 e 10 (Funções) e 08 (Dark). C6-EDT-05 é o resto do EDT-011 e por isso não soma. C6-EDT-09 é documentação e fica fora.

| Dimensão | Conferidos OK | Total de requisitos |
|---|---|---|
| Visual | 27 | 31 |
| Interações | 38 | 44 |
| Funções (persistência/backend) | 10 | 21 |
| Responsivo | 4 | 4 |
| Dark mode | 4 | 5 |
| **Total** | **83** | **105** |

Ciclo 0: 63/97 (65%). Agora: 83/105 (79%). Os ◐ EDT-003 e EDT-011 não contam como OK. O que falta: 12 C do inventário (D-EDITOR, D-BARRA, LGPD, rascunho) + 8 novos A + 1 novo C.

## Resumo
- Feitos: **20 confirmados**, **2 parciais** (EDT-003: posição do item e PDF; EDT-011: "Sugestões de correção"), **0 regredidos**.
- Pendências A do inventário: **0** (o resto dos parciais virou C6-EDT-04 e 05).
- Novas: **A 9** (C6-EDT-01 a 09) · **C 1** (C6-EDT-10) · B/D/E 0. Nenhuma pede migration. Nenhuma é P0. P3: 05, 06, 07.
- Sem achado de tenant/segurança: as rotas da folha e do PDF conferem dono (404) e VIEW (403), o PDF roda sem recurso remoto, e o iframe roda sem script e com CSP fechada.
