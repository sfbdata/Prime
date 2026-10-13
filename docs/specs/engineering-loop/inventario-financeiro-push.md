# Inventário — Pasta: aba Financeiro, aba Push e Notificações

Auditoria somente leitura em 07/10/2026, HEAD `607f2c29`. `dc` = `02 - EXPEDIENTES 1.2.3.dc.html`.

## Fontes lidas (arquivo:faixa de linhas)

**Designer**
- dc: L.1731-2010 (aba Financeiro: faixa, Relatório financeiro, Arquivos, Pagamentos, modal Adicionar pagamento), L.3414-3530 (`finVals`: estados, correção de valor, menu de Pagamentos, parcelamento Price, êxito e sucumbência), L.6540-6556 (⋮ do arquivo, `obsFin`), L.2409-2740 (aba Push: cabeçalho, análises IA, linha do tempo, notas técnicas, trilho com Monitoramento, Previsão, Avisar quando chegar, Avisar cliente, modal Enviar ao cliente), L.5355-5448 (`pushLinha`, `pushNotaVals`, `pushVals`: cores por polo, NOVO, Lida, Criar tarefa, menu de contexto, filtros e vazios), L.500-554 (modal Link externo do Push), L.750-770 (sino do topo), L.4429-4438 (sino da meta), L.2957/3548/4068/4098/4157/4185/4258 (`notificar` no protótipo).
- `bluejus-central.js` L.60-85 (TIPOS de notificação: grupo, nível, som, ícone e cor), L.384-385, 5140, 5380, 5630-5660, 5914, 6249-6268 (Central de notificações).
- `bj-link.js` (inteiro, 20 linhas) e `Push Compartilhado.dc.html` (textos e fluxo, por grep).
- `design_handoff_bluejus_intelligence/README.md` L.35-46 (Link público), L.125-127 (Push: botão direito), L.135-138 (Linha do tempo estilo PJe), L.172-179 (Previsão de andamento), L.213-219 (Inteligência financeira), L.249 (sininho).
- Capturas: `screenshots/sino.jpg`, `screenshots/01-push-previsao.jpg`.

**Decisões e mapas**
- `docs/specs/estado-geral-claude-designer-bluejus.md` §10.
- `docs/specs/trilha-b-designer.md` (B9, B11, B15, B23, B33, ledger P1-P19).
- `docs/specs/trilha-b-inventario/pasta.md` §6-7, `auditoria-pasta-2.md` §5 e §7, `pendencias-pos-documentos.md` itens 9-12, 20, N1-N3.

**Código**
- `app/templates/pasta/_financeiro.html.twig` (1-131 faixa, 132-260 Relatório, 261-416 Arquivos, 418-505 Pagamentos, 1255-1332 menus, botão direito e duplo clique), `_financeiro_pagamentos.html.twig` (inteiro), `_financeiro_pagamentos_script.html.twig` (60-260, 279-545), `show.html.twig:660-770` (modal `#modalNovoPagamento`).
- `app/templates/pasta/_push_processual.html.twig` (inteiro), `_push_teor.html.twig` (inteiro), `processo/_notas_tecnicas.html.twig`, `processo/_nota_tecnica_item.html.twig`, `inteligencia/_analises_push.html.twig`.
- `app/public/js/pasta-push.js`, `app/public/css/pasta-show.css` (1186, 1191, 1982-2010, 2183-2232, 2733-2900, 3684-3701, 4454-4610 e tokens de modo escuro 185/346).
- `app/src/Pasta/Controller/PastaPagamentoController.php`, `PastaPushProcessualController.php`, `src/Pasta/Entity/PastaPagamento.php`, `PastaObservacaoFinanceira.php`, `src/Pasta/DTO/PastaPagamentoLinhaOutput.php`, `PastaPushOutput.php`.
- `app/templates/base.html.twig:212-290, 501-545` (sino), `src/Entity/Notificacao.php` (tipos, `getIcone`), `src/Service/NotificacaoService.php`, `src/Djen/Service/NotificadorPublicacoesDjen.php`, `src/Tarefa/UseCase/AlertarResponsavelDaMetaUseCase.php`, `src/Inteligencia/MessageHandler/ProcessarAnaliseDeInteligenciaHandler.php:52`.
- `debug:router` (rotas `pasta_pagamento_*`, `pasta_financeiro_*`, `pasta_push_*`, `inteligencia_*`, `notificacao_*`).
- Testes existentes (nomes): `PastaPagamentoControllerTest`, `PastaPagamentoParcelamentoControllerTest`, `PastaFinanceiroMenusTelaTest`, `CorrigirValorDoPagamentoUseCaseTest`, `RegistrarParcelamentoDaPastaUseCaseTest`, `CalculadoraDeParcelamentoTest`, `PastaPushProcessualTest`, `PastaPushAcoesTelaTest`, `PastaPushTeorControllerTest`, `PastaPushLidaControllerTest`, `PastaPushGeramPrazoTelaTest`, `PastaNotasTecnicasTelaTest`.

## Matriz (contagem real)

Cada requisito é um elemento ou comportamento que o desenho mostra. "Total" inclui os que são B, C, D (dependência ou decisão).

| Dimensão | Conferidos OK (F) | Total de requisitos |
|---|---|---|
| Visual | 79 | 104 |
| Interações | 53 | 88 |
| Funções (persistência/backend) | 31 | 57 |
| Responsivo | 10 | 12 |
| Dark mode | 14 | 14 |
| **Total** | **187** | **275** |

Divergências: **42** linhas. Por classe: **A 17 · B 4 · C 17 · D 4 · E 0**. Nenhuma P0 encontrada. As rotas de escrita do Financeiro e do Push têm CSRF, checam a pasta e têm teste de controller.

## Divergências

| ID | Elemento | Fonte (arquivo:linha) | Dim | Divergência (desenho × código) | Classe | Prior. | Evidência no código | Esforço | Proposta (se A) |
|---|---|---|---|---|---|---|---|---|---|
| FPN-001 | Card "Valor da causa" da faixa | dc:1751-1760, 3424, 3497 | V/F | **Desenho:** "Valor da causa" = soma dos lançamentos contratados, só leitura, com a sublinha "total dos pagamentos · sincronizado". O "% da causa" do modal usa essa soma como base. **Código:** "Honorários contratuais", editável, grava `pasta.valor_causa`, com a sublinha "valor cadastrado na pasta". O modal ainda diz "Valor da causa R$ X" para o mesmo número. Falta decidir qual dos dois é a semântica do card e da base do % (o rótulo foi trocado pelo dono em 28/08, sobre o 1A). | C (nova: "valor da causa = soma dos pagamentos ou campo da pasta?"; a incoerência de rótulo modal × card entra junto) | P3 | `_financeiro.html.twig:91-119`; `show.html.twig:683` | P | — |
| FPN-002 | Tipos Êxito e Sucumbência, estado "Previsto", linha "+ R$ X previsto em êxito" | dc:1940-1950, 3423-3428, 3439, 3466-3468, 3501 | F/V | **Desenho:** lança % sem vencimento, condicionado ao resultado. Fica fora do "contratado" e aparece como selo azul "Previsto". A base do % é a soma dos contratados, não a causa do processo. **Código:** os dois botões estão `disabled` com "Ainda não disponível". `PastaPagamento` não tem tipo, % nem vencimento nulo. O P16b está planejado com migration, mas a regra é de dinheiro e a origem é ambígua: base do %, se o "previsto" entra em relatórios e o que acontece quando o resultado sai. | D | P2 | `show.html.twig:695-698`; `PastaPagamento.php:36-66` | M/G | — |
| FPN-003 | Selo "Assinado" por arquivo | dc:1845 (`a.assinado`) | V/F | **Desenho:** selo verde por arquivo, mas o mock é sempre `false` e não há regra de quando vira assinado. **Código:** não existe. | D (marcação manual, detecção de assinatura digital ou derivado do selo do Contrato?) | P3 | `_financeiro.html.twig:290-300` (linha do arquivo, sem selo) | M | — |
| FPN-004 | Placeholder do Relatório financeiro | dc:1787 | V | "Adicionar observação…" × "Registrar combinado, honorário ou movimento financeiro do caso…" | C (D-PASTA6, F19) | P4 | `_financeiro.html.twig:167` | P | — |
| FPN-005 | Responder a um registro financeiro | dc:1795-1823 (`n.responder`, `respOn`, "Respondendo a…"); README L.236 | F/V | **Desenho:** botão ↩ no cartão, caixa "Escreva a resposta…" inline e resposta recuada sob a raiz, como em Dados. **Código:** a observação financeira só tem editar e excluir. A entidade não tem `respostaA`. | A | P2 | `PastaObservacaoFinanceira.php:24-46`; `_financeiro.html.twig:201-224` | M | Coluna `resposta_a_id` (FK nullable para a própria tabela, `ON DELETE SET NULL`) em `PastaObservacaoFinanceira` (**migration sim**). `EnviarObservacaoFinanceiraUseCase` aceita `respostaA` (só raiz da MESMA pasta e do MESMO tenant; resposta a uma resposta liga à raiz). Notifica o autor da raiz com o mesmo `TIPO_PASTA_RESPOSTA_REGISTRO` do P5. Twig e JS espelham `_dados_anotacoes.html.twig:113-200`. Testes: unit do UseCase (raiz de outra pasta → recusa; resposta de resposta → raiz) e functional cross-tenant (404). |
| FPN-006 | Botão direito no registro financeiro | dc:1795 (`onContextMenu="{{ n.menu }}"`), README L.236 | F | **Desenho:** menu com Responder, Copiar, Encaminhar e Excluir. **Código:** sem `contextmenu` em `#financeiroObsLista`. Hoje o botão direito só funciona em arquivos e linhas de pagamento. | A (Encaminhar fica fora, ver FPN-007) | P3 | `_financeiro.html.twig:1289-1311` | P | Delegação no mesmo `contextmenu` de `_financeiro.html.twig:1289`, montando um `.ps-fin-menu` com: Copiar texto (texto puro do `.ql-editor`), Responder (depois do FPN-005), Editar e Excluir (só se os botões da janela de 15 min existirem no cartão: reusa `.btn-editar-obs` e `.btn-excluir-obs`). **Migration não.** Teste de tela em `PastaFinanceiroMenusTelaTest` (o cartão do autor expõe os gatilhos, o de outro autor não). |
| FPN-007 | "Encaminhar via Chat I.A" (registro e arquivo), presença e etiqueta do autor | dc:1803-1806, 6546 | F | Existe no desenho. No código fica fora, com comentário. | C (D-CONNECT) | P2 | `_financeiro.html.twig:309` | — | — |
| FPN-008 | Confirmação de excluir lançamento | dc:3456 (`'Excluir "' + desc + '" (' + brl(v) + ')?'`) | V | O código pergunta "Excluir este lançamento? Esta ação não pode ser desfeita." sem nome nem valor. | A | P4 | `_financeiro_pagamentos_script.html.twig:254` | P | Montar a frase com `linha.dataset.descricao` e o valor formatado da linha. **Migration não.** Sem teste de PHPUnit possível (é `confirm`); conferir no smoke. |
| FPN-009 | Sufixo "· juros N% a.m." nas parcelas Price | dc:3442 (`if (it.juros) quando += …`) | V/F | **Código:** o parcelamento com juros (P16a) não guarda a taxa, então a linha não mostra o sufixo. | A | P4 | `PastaPagamento.php:36-66`; `PastaPagamentoLinhaOutput.php:97-117` | P/M | Coluna `juros_mensal NUMERIC(6,4) NULL` em `pasta_pagamento` (**migration sim**). `RegistrarParcelamentoDaPastaUseCase` grava a taxa nas parcelas (nunca na entrada) e `PastaPagamentoLinhaOutput::quando()` concatena " · juros 1,5% a.m.". Testes: unit do Output (com e sem juros) e functional do parcelamento conferindo o texto. |
| FPN-010 | Imprimir extrato: pré-visualização com opções | dc:3515 (`gmImprimir`), README L.134 | F | **Desenho:** a mesma janela A4 do Imprimir (cabeçalho do escritório, tamanho da fonte). **Código:** `window.print()` com folha `@media print`. O B9 escolheu esse caminho por default técnico, sem decisão registrada. | A | P4 | `_financeiro.html.twig:1279`; `_financeiro_pagamentos.html.twig` (`#psExtratoFolha`) | M | Reusar a pré-visualização do Imprimir resumo (`PastaResumoController`, B14) com uma seção "só extrato" (`?secao=extrato`), sob a mesma permissão de VIEW da pasta. **Migration não.** Teste functional da rota (200, 404 cross-tenant, lista com todos os lançamentos). |
| FPN-011 | Financeiro oculto por permissão | dc:4202 (`__bjFinOculto`), README L.219 | F | **Desenho:** aba e análise ocultas para quem não vê o financeiro. **Código:** `podeVerFinanceiro()` é sempre true. | C (D-FIN; o cadeado por usuário entra no D-MASTER) | P3 | trilha-b-designer.md B14/D-FIN | — | — |
| FPN-012 | Título do cartão do Push | dc:2514, 5378 (`titulo: m.texto`, caixa alta) | V | **Desenho:** o teor curto em caixa alta. **Código:** `nomeOrgao` (pendência N3). | A | P3 | `_push_processual.html.twig:170` | P | Campo `textoResumo` (primeiros ~220 caracteres do texto sem HTML, cortados na palavra) no `PublicacaoDjenListaItem`. É aditivo e o módulo DJEN não o lê. O Twig usa `textoResumo ?: nomeOrgao ?: tipo`. **Migration não.** Teste: `PastaPushProcessualTest` confere o trecho e que `<script>` do teor sai escapado. |
| FPN-013 | Linha do documento e ações à vista no cartão (sem acordeão) | dc:2515-2531 | V/F | **Desenho:** o cartão mostra "ID - Tipo" com copiar e abrir no PJe, a pílula do tipo, a fonte, Criar tarefa, Encaminhar, Lida e a hora, tudo sem abrir nada. **Código:** o cartão é o botão do acordeão; documento e ações só aparecem depois de abrir o teor, e abrir já marca como lida. | A | P3 | `_push_processual.html.twig:160-185`; `_push_teor.html.twig:31-77` | M | O cartão vira `<article>` com um botão "ver teor" separado (o chevron). A linha do documento e as ações saem de `_push_teor` para o cartão, com `numeroComunicacao` e `link` vindos do item de lista: dois campos aditivos no DTO. "Lida" vira POST explícito (`pasta_push_lida`), sem depender do GET. **Migration não.** Testes: `PastaPushAcoesTelaTest` (ações presentes no cartão fechado, seletor de filho direto) e `PastaPushLidaControllerTest` sem mudança. |
| FPN-014 | Botão direito no cartão do Push | dc:5404 (`ctx`: Adicionar nota técnica, Criar tarefa, Encaminhar, Copiar texto); README L.125-127 | F | O código não tem `contextmenu` na aba Push. | A (Encaminhar → FPN-016; link externo → FPN-015) | P2 | `public/js/pasta-push.js` (sem `contextmenu`) | P/M | Em `pasta-push.js`: menu `.ps-fin-menu`-like no ponto do clique sobre `.ps-push-item`. **Adicionar nota técnica** abre o teor (se fechado) e aciona `.js-nota-nova` do bloco `#notas-publicacao-<id>`. **Criar tarefa** reusa `.js-push-criar-meta`. **Copiar texto** copia o trecho do teor. Ignora `a, button, textarea`, como o desenho. **Migration não.** Teste de tela: o `<li>` expõe `data-push-id` e os gatilhos que o JS procura. |
| FPN-015 | Link externo do Push (botão direito e menu da pasta) e Push Compartilhado | dc:500-554; `Push Compartilhado.dc.html`; `bj-link.js`; README L.35-46 | F | Nada no código (inventário abaixo, em Observações). | C (D-LINK) | P2 | — | G | — |
| FPN-016 | "Encaminhar" (cartão do Push, nota técnica, análise IA) | dc:2528, 5383, 5422 | F | Existe no desenho e não existe no sistema. | C (D-CONNECT) | P2 | `_push_teor.html.twig:16-17` | — | — |
| FPN-017 | Rótulo "Lida" + `bi-check2-all` | dc:5384 | V | O código mostra "Marcar como não lida" com `bi-envelope`. | C (D-PASTA6, U9) | P4 | `_push_teor.html.twig:72-74` | P | — |
| FPN-018 | Texto dos vazios do Push | dc:5426 (`vazioTxt`) | V | **Desenho:** "Vincule um processo na aba Processo para receber o push das movimentações." e "Nenhuma movimentação recebida ainda." **Código:** textos longos próprios. A forma já segue o desenho. | C (D-PASTA6, U4) | P4 | `_push_processual.html.twig:98-110` | P | — |
| FPN-019 | Cor do ponto por polo + dica | dc:5365, 5375 (`COR`: juízo azul, polo ativo verde, réu laranja, cartório vermelho, movimento sem documento cinza 8px); README L.137 | V | **Código:** ponto sempre `--ps-accent`, 16px, sem `title`. | A (simplificado por tipo; ver nota) | P4 | `pasta-show.css:2733-2747`; `_push_processual.html.twig:160` | P | No Twig, modificador `.ps-push-ponto--juizo` (Decisão, Despacho, Sentença) ou `--cartorio` (Intimação, Edital, Certidão), com `title` do desenho. Tokens com par escuro em `pasta-show.css`. Polo ativo e passivo não se deduzem do DJEN e ficam sem uso. **Migration não.** Teste de tela: a classe sai pelo tipo. *Nota:* se o dono quiser a inferência por texto do protótipo (regex de "réu/autor"), vira D. |
| FPN-020 | Notas técnicas visíveis sob o cartão sem abrir o teor | dc:2534-2551 (`m.temNotas` fora do teor) | V/F | **Desenho:** a nota aparece pendurada no cartão, sempre visível. **Código:** só aparece dentro do teor, depois de abrir. | A | P3 | `_push_teor.html.twig:92-100` | M | Na montagem da aba, buscar as notas por `publicacaoId` da página numa consulta só (tenant + processo da pasta) e renderizar `processo/_nota_tecnica_item.html.twig` sob `.ps-push-cartao`. O teor deixa de duplicá-las. **Migration não.** Teste: `PastaNotasTecnicasTelaTest` (nota aparece com o teor fechado; nota de outro tenant não). |
| FPN-021 | ⋮ e botão direito da nota técnica | dc:5409-5416 (Responder com outra nota, Copiar texto, Encaminhar, Editar, Excluir; "· bloqueado" fora da janela) | F | **Código:** só lápis e lixeira dentro da janela de 15 min. Não tem Copiar, Responder com outra nota nem item bloqueado com motivo. | A (Encaminhar → FPN-016) | P4 | `processo/_nota_tecnica_item.html.twig:40-63` | P | ⋮ em `_nota_tecnica_item` com Copiar texto, "Responder com outra nota" (aciona `.js-nota-nova` do mesmo bloco) e Editar/Excluir reaproveitando os botões atuais. Fora da janela, o item mostra o motivo do desenho ("Só o autor edita a nota, em até 15 minutos"). **Migration não.** Teste de tela em `PastaNotasTecnicasTelaTest`. |
| FPN-022 | @menção na nota técnica | dc:2546 (placeholder "…Use @ para mencionar alguém") | F/V | O P19 fez @menção só nos registros da pasta. A nota técnica não resolve menção, e o placeholder não fala em @. | A | P2 | `processo/_notas_tecnicas.html.twig:55`; `src/Pasta/Service/ResolucaoDeMencoesDoRegistro.php` | M | Reusar `ResolucaoDeMencoesDoRegistro` (só ids do tenant, idempotente) nos UseCases de criar e editar `NotaTecnica`, com a notificação `pasta_mencao_registro` apontando para a âncora da nota. Placeholder do desenho. **Migration não.** Testes: unit (menciona usuário de outro tenant → ignora; editar não renotifica) e functional. |
| FPN-023 | "Criar tarefa" direto + estado "Tarefa criada" | dc:5380-5382 | F | **Desenho:** cria a meta num clique ("Providência: intimação de dd/mm", responsável da pasta, **prazo = data da publicação**) e o botão vira "Tarefa criada". **Código:** abre o modal de meta pré-preenchido e não lembra o vínculo. Prazo igual à data da publicação (no passado) não tem lastro de regra. | D | P3 | `_push_teor.html.twig:58-66`; `pasta-push.js:222-260` | M | — |
| FPN-024 | Cartão "Monitoramento ativo/pausado" (interruptor por processo, processo · tribunal, "Última verificação") | dc:2566-2576, 5438-5445 | V/F | O código não tem nada disso. O monitoramento é por **OAB** (`OabMonitorada`), não por processo, e não existe hora da última verificação. Pausar um processo é regra nova. | C (nova: monitoramento por processo e pausa) | P3 | `src/Djen/Entity/OabMonitorada.php:26-56` | — | — |
| FPN-025 | "Buscar agora", "Atualizar com arquivos do processo", soltar arquivos (leitura de PDF, ZIP e DOCX) | dc:2624-2626; README L.89-115 | F | Nada no código. | B (D-PJE / OCR / D-IA) | P5 | — | G | — |
| FPN-026 | Previsão de andamento: Situação atual, próximo ato, quem age, prazo, confiança | dc:2584-2622; README L.172-179 | F | Nada no código. | B (D-IA) + C (D-PRAZOS) | P5 | — | G | — |
| FPN-027 | "Avisar quando chegar" (Responsável da pasta · Por e-mail · Análise da IA) | dc:2628-2634, 5447 | F | **Código:** a nova publicação gera UMA notificação agregada ("N novas publicações capturadas no DJEN") para todos com o módulo DJEN. Não há aviso por pasta, ao responsável, nem preferência. | C (nova: quem é avisado e por que canal) | P2 | `src/Djen/Service/NotificadorPublicacoesDjen.php:36-64` | — | — |
| FPN-028 | "Avisar cliente": e-mails (principal, pausar), envio automático (movimentações, audiências, resumo semanal), CC obrigatório da Controladoria, histórico, modal "Enviar ao cliente" com "Revisei e autorizo" | dc:2636-2738, 5014 | F | Nada no código (só o `mailto:` do arquivo financeiro). | C (D-CLI-CONTATOS; mais um mailer de saída, que é B) | P3 | — | G | — |
| FPN-029 | Análise IA: recolher/expandir com preferência salva | dc:2432, 2445-2446; README L.138 | F | O cartão da análise não recolhe. | A | P4 | `inteligencia/_analises_push.html.twig:37-140` | P | Botão `aria-expanded` no cabeçalho do cartão, prévia de uma linha quando fechado e preferência em `localStorage` por pasta, como conveniência do leitor. Leitura e escrita dentro de try/catch. **Migration não.** Teste de tela: o botão existe com `aria-controls`. |
| FPN-030 | Análise IA: Editar (resumo e pontos) com selo "editado" | dc:2436, 2464-2483 | F | Não existe rota de edição. | B (só faz sentido com D-IA ligado; exige UseCase, coluna `editada_em` e auditoria) | P5 | `debug:router` (só `inteligencia_analise_lida/excluir/interna`) | M | — |
| FPN-031 | Cabeçalho do Push quebra linha em <768px | dc:2412 (`flex-wrap:wrap`) | R | **Código:** `.ps-card-cab` não quebra. Com "ver no módulo", o botão de IA (o rótulo de indisponível é longo) e os três filtros, a faixa estoura na horizontal no celular. | A | P3 | `pasta-show.css:1191` (sem `flex-wrap`); `_push_processual.html.twig:21-86` | P | `.ps-push > .ps-card-cab--faixa { flex-wrap: wrap; row-gap: 8px }` e, abaixo de 768px, `.ps-push-filtros { flex-basis: 100% }`. **Migration não.** Teste: não dá em PHPUnit; conferir no smoke em 375px. |
| FPN-032 | Filtros com lista vazia | dc:2419-2421 (filtros sempre à vista) | V | **Código:** os filtros só aparecem com `push.total > 0`. | A | P5 | `_push_processual.html.twig:80` | P | Remover a condição ou manter de propósito. Custo baixíssimo, valor baixo: só se for revisitar o arquivo. **Migration não.** |
| FPN-033 | Ocultar a aba Push / cadeado (Master) | README L.127 ("O Master vê também as opções de ocultar") | F | Não existe. | C (D-MASTER) | P5 | — | — | — |
| FPN-034 | "Lida" é do escritório ou de cada usuário? | dc:5412 (`pushLidas` no estado de quem usa) | F | **Código:** `PublicacaoDjen.lida` é uma flag por tenant: um lê, some o NOVO para todos. O protótipo guarda por navegador, o que sugere por pessoa. Não dá para saber a intenção. | D | P3 | `PastaPushProcessualController.php:131-170` | — | — |
| FPN-035 | Sino do topo: ícone | dc:760 (`bi bi-bell`, contorno) | V | O código usa `bi-bell-fill`. O badge "9+" já bate. | A | P4 | `base.html.twig:218` | P | Trocar a classe para `bi-bell`. **Migration não.** Teste de tela opcional (`.navtop` contém `bi-bell` sem `-fill`). |
| FPN-036 | Ícone e cor das notificações de alerta de meta e de análise IA na lista | `bluejus-central.js:70` (`alerta`: `bi-bell-fill` `#e8590c`), L.71 | V | **Código:** `tarefa_alerta_verificar` e `ia_analise_concluida` não têm braço no `match` e caem no `bi-bell text-secondary` cinza. | A | P4 | `src/Entity/Notificacao.php:197-215` | P | Braços no `getIcone()`: `'tarefa_alerta_verificar' => 'bi-bell-fill text-orange'` (classe/token laranja com par escuro) e `'ia_analise_concluida' => 'bi-stars text-primary'` (ou o `bi-file-earmark-check` do desenho). Usar as constantes `AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO` e `ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO`. **Migration não.** Teste unit de `Notificacao::getIcone()`. |
| FPN-037 | Som e toque por tipo de notificação (Som, Volume, Toques, Não perturbe) | `bluejus-central.js:60-75` (`som: true/'toque'`), L.6255 | F | O sino do código é mudo. | C (D-CONNECT) | P5 | `base.html.twig:501-545` | — | — |
| FPN-038 | Central de notificações por grupo (Urgente, Atenção, Menções, Mensagens, Metas), resolvidas, "Limpar lidas" com confirmação | `bluejus-central.js:79, 5630-5660, 6249-6268` | V/F | **Código:** dropdown com abas "Minhas · Gestão", "marcar todas como lidas" e a página `/notificacoes`. É convenção do sistema; o modelo do desenho mora no Chat I.A. | C (D-CONNECT) | P5 | `base.html.twig:212-290` | — | — |
| FPN-039 | Notificação "Meta atrasada há N dias" (silenciosa) | dc:4258 | F | Não existe gatilho. O protótipo dispara ao abrir a pasta, o que não é regra. | C (nova: quando e com que frequência avisar o atraso) | P3 | `Notificacao.php:17-33` (sem tipo) | — | — |
| FPN-040 | Notificação "Relatório inicial incompleto" | dc:2957 | F | Não existe. | C (D-RELINI) | P4 | — | — | — |
| FPN-041 | Notificações de conformidade e cobrança do checklist (item sem anexo, checklist desativado, documento sem retorno) | dc:4068, 4098, 4157-4186 | F | Não existem. | C (D-DOC-S6) | P4 | — | — | — |
| FPN-042 | "Acompanhar alterações" da pasta e notificação "documento analisado" | dc:1113 (`mSeguir`); `bluejus-central.js` (tipo `documento_analisado`) | F | Não existem. | C (D-ACOMP) para seguir a pasta; B (D-IA) para documento analisado | P4 | `TarefaController.php:103` (acompanhar só meta) | — | — |

## Itens F (agrupados, com evidência)

**Financeiro: faixa (8)**
- Contrato é selo-botão Pendente ⇄ Assinado com POST + CSRF (`_financeiro.html.twig:47-66`, script 521-548).
- Pró-bono idem (`:68-86`).
- Média por CPF com o fundo âmbar do desenho e o par escuro (`pasta-show.css:2227-2232`). A sublinha "nome do cliente" no lugar de "4 clientes vinculados" é **desvio decidido pelo dono em 28/08** (`_financeiro.html.twig:119-130`).
- Quatro cards iguais em `auto-fit minmax(200px)` (`pasta-show.css:2183`).
- Selo em linha (F1, `.ps-fin-corpo--selo`).

**Relatório financeiro (11)**
- Título e contador.
- Editor rico (Quill local).
- Pílula do dia e cartão com ponto, seta e megafone (`:183-234`).
- Editar e excluir só para o autor na janela de 15 min (D-15MIN fechada) com "· N min" (`:181-222`).
- Selo "editado" com data.
- CSRF por ação; rotas `pasta_financeiro_observacao_*`.

**Arquivos (17)**
- Contagem sempre visível, inclusive 0.
- "Adicionar" com upload e CSRF.
- Até 3 arquivos, com "ver os outros N no gerenciador".
- Ícone do tipo a 30px.
- Vazio "Nenhum arquivo financeiro anexado." (`:273`).
- ⋮ com os itens do desenho: Abrir (visualizador), Baixar, Copiar nome do arquivo, Enviar por e-mail ao cliente (`mailto:` com e-mail validado; sem e-mail leva a Dados), Ver em Documentos, Excluir (`:294-366`). "Renomear" é item extra, convenção do sistema.
- Botão direito e duplo clique na linha (`:1289-1318`); Esc fecha; rolagem fecha.
- Interruptor "Reduzir tamanho ao enviar" com a nota (`:380-394`).

**Pagamentos (24)**
- Contagem.
- ⋮ com Adicionar pagamento, Editar ou corrigir valores, Ver todos os lançamentos, Copiar resumo e Imprimir extrato (`:443-466`, script 1255-1279).
- Botão "Concluir edição" enquanto o modo dura.
- Número recebido como porta do modo de edição.
- Barra de progresso.
- "Próximos vencimentos" + "N já pagos"/"nenhum pago" (F14).
- Três próximos não pagos, depois os pagos.
- Estados Pago, Pendente e Vencida com os textos "venceu … · há N dias", "vence … · hoje / em N dias" e "pago em" (`PastaPagamentoLinhaOutput.php:97-117`).
- Selo é o botão de quitar.
- Botão direito na linha: Corrigir valor, Marcar como recebido/Desfazer recebimento, Excluir lançamento (`_financeiro_pagamentos.html.twig`).
- Lixeira só no modo edição.
- Correção inline com Enter/Esc, `confirm` do desenho e 409 quando outra pessoa corrigiu no meio (`_financeiro_pagamentos_script.html.twig:68-200`).
- "corrigido · era R$ X" com histórico no `title` (vem do `audit_log`).
- Vazio "Nenhum lançamento. Use Adicionar pagamento." com total zerado (F5).
- Extrato com todos os lançamentos.
- Testes: `PastaPagamentoControllerTest`, `CorrigirValorDoPagamentoUseCaseTest`, `PastaFinanceiroMenusTelaTest`.

**Modal Adicionar pagamento (17)**
- Cabeçalho com ícone, título, subtítulo e X.
- Quatro tipos com nota (Contratuais e Custas ativos).
- Base Valor fixo / % da causa.
- Campos Valor total, %, Entrada, Parcelas (teto 60), 1º vencimento, Juros sem/com e taxa.
- Prévia com resumo, "+N", erro e vazio.
- Botão "Gerar N lançamentos" / "Adicionar" (`_financeiro_pagamentos_script.html.twig:455-463`).
- Cancelar.
- Price no servidor (`CalculadoraDeParcelamentoTest`).
- "Entrada já recebida hoje" removida: **D-FIN-ENTRADA FECHADA** (o servidor responde 422).
- Campo "Descrição (opcional)" a mais: aprovado no P16a.

**Push (34)**
- Faixa "Movimentações recebidas" com contagem sempre visível (U8).
- "Resumir com IA" / "Gerar nova análise" / "Analisando…" com motivo real quando indisponível (`_push_processual.html.twig:36-74`; o funcionamento depende de D-IA).
- Filtros Todas · Novas · Geram prazo, com "Nenhuma movimentação neste filtro." (`:79-84, 188`). "Geram prazo" sem distinção de caixa é desvio técnico registrado no P4.
- Pílula do dia "5 out 2026" (N1).
- Ponto alinhado ao cartão (U1).
- Cartão: raio 3, padding 12/14/9, sombra, `inset 3px` azul quando novo (U2, `pasta-show.css:2778`).
- Megafone espelhado, texto em caixa alta, selo NOVO 10px/800 (`:2780-2791`).
- Pílula do tipo com cor por tipo e par escuro (U3, `:3684-3701`, tokens 185/346).
- Canto da hora vazio: **D-PUSH-HORA, ratificar §10.2**.
- Documento "ID - Tipo" com link `noopener`, Copiar ID com aviso e Abrir no PJe no teor (`_push_teor.html.twig:31-56`).
- Criar tarefa (modal pré-preenchido).
- Marcar lida/não lida por POST com CSRF e checagem de que a publicação é do processo da pasta (`PastaPushProcessualController.php:131-170`, `PastaPushLidaControllerTest`).
- Notas técnicas por movimentação: editor, 15 min, tenant (B11).
- Cartão de análise IA: cadeado "interna do escritório", lido, criar tarefa da providência, excluir com confirmação, "Quem deve agir" (`_analises_push.html.twig`).
- Vazios em linha simples (forma de U4).
- Teto "Exibindo as N mais recentes".

**Notificações (6 tipos com lastro)**
- `meta_nova` ≈ `tarefa_criada`; `meta_concluida` ≈ `tarefa_concluida`; `meta_atualizada` ≈ `tarefa_em_revisao/pendente`.
- `alerta` = `tarefa_alerta_verificar` (P1, com "Alertado: X às HH:MM").
- `mencao` = `pasta_mencao_registro` (P19).
- `direcionada` (resposta) = `pasta_resposta_registro` (P5).
- Badge "9+" e polling de 30 s (`base.html.twig:219-221, 501-516`).
- Publicação nova do DJEN notifica quem tem o módulo.

**Responsivo (10)**
- Grade de 2 colunas vira 1 abaixo de 980px (`pasta-show.css:1186`).
- Faixa `auto-fit`.
- Rótulos de "Adicionar pagamento" e "Adicionar nota técnica" não somem abaixo de 768px (T3, `:2001-2005`).
- Modal com `max-width` 568 e respiro.
- Menus `position: fixed` fecham em resize e scroll.
- Ações do Push com `flex-wrap`.
- Corpo do cartão com `flex-wrap`.
- `.ps-push-meta` com ellipsis.
- `hover: none` mostra as ações.
- Faixa de filtros horizontal.

**Dark mode (14)**
- Tokens com par escuro para: média âmbar, selos do Financeiro, `.ps-push` (doc, ação, hover), pílulas de tipo, NOVO, ponto, `.ps-np-*` do modal (`[data-bs-theme="dark"] .ps-body`), extrato, ajuste "corrigido", vazio, menus `.ps-fin-menu`, pílula do dia, filtros e cartão da IA (`pasta-ia.css`).
- Nenhuma cor crua sem par encontrada nas seções auditadas.

## Observações (riscos, conflitos Designer × sistema, dúvidas para o Farlei)

1. **GET com efeito colateral.** Abrir o teor (`pasta_push_teor`, GET) marca a publicação como lida, contrato herdado do módulo. Com o FPN-013 (ações no cartão) dá para separar ler de abrir. Até lá, um pré-carregamento ou um crawler interno marcaria como lido.
2. **"Lida" por escritório × por pessoa (FPN-034).** Se for por pessoa, precisa de tabela `publicacao_leitura(usuario, publicacao)` e muda o selo da aba e o "Novas". É regra de produto. Não mexer sem o Farlei.
3. **Valor da causa (FPN-001) e base do % (FPN-002) são a mesma pergunta.** No desenho, o "valor da causa" é a soma dos honorários contratados, e o êxito e a sucumbência incidem sobre ela. No processo judicial, "valor da causa" é outra coisa (e já existe na aba Processo, B16). O P16b não deve começar antes dessa resposta.
4. **Push Compartilhado (D-LINK): requisitos inventariados, nada implementado.**
   - Modal "Link externo do Push" com aviso do que o terceiro vê (só o Push, sem documentos, financeiro, metas e registros).
   - Link legível `bluejus-<cliente|processo-NNNNNNN>-<código de 8>`, com a opção "Mostrar o nome do cliente".
   - Código de acesso de 6 dígitos enviado por outro canal e conferido por hash (nunca o código).
   - Validade de 30 dias, com "Pedir renovação" que pergunta ao advogado.
   - "Ver como terceiro" e "Criar outro".
   - Lista "Links desta pasta" com quem criou, validade e contagem de acessos.
   - Análise IA só entra se não tiver cadeado.
   - Bloqueios: aba oculta, sem processo, pasta cancelada ou arquivada, sem movimentações.
   - Página pública com tela de código, "Conteúdo protegido" (bloqueio de cópia, impressão e captura, que só se deve prometer como dissuasão) e rodapé de aviso.
   - Servidor (README L.40-45): `POST /api/push-links` com snapshot no servidor, `GET /p/{curto}`, endpoint de estado (revogação, renovação, acessos), limite de 5 tentativas por hora, auditoria.
   - O `#d=` autocontido do protótipo **não** deve ir para produção (revogação só valeria no navegador de quem criou).
5. **O trilho da aba Push (FPN-024 a 028) é metade da área visual do desenho** e não existe no código. Nenhuma parte é A: o monitoramento é por OAB, a leitura do processo depende de PJe/OCR/IA e os avisos são regra nova. Até a decisão, a aba fica em coluna única, o que é uma divergência visual grande e conhecida.
6. **Notificações.** O desenho concentra tudo no Chat I.A (Central). O sino do topo, no desenho, é só ícone + badge e já bate (exceto o glifo, FPN-035). As notificações por evento de pasta que não têm lastro (meta atrasada, relatório inicial, checklist, acompanhar pasta) dependem de decisões já listadas.
7. **Testes.** Toda proposta A acima tem teste de PHPUnit, exceto FPN-008 e FPN-031. Esses dois são de aparência e `confirm`, invisíveis para a suíte, e precisam do smoke do dono.
