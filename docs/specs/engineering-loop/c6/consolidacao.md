# Ciclo 6 — consolidação da reauditoria de convergência (12/10/2026)

Base: master `4a98cc6b` (`cceb7e48` = último commit do loop; `4a98cc6b` é de outra frente, e-mail institucional).
Suíte completa antes de começar: **9007/9007 verdes, 50.022 asserções** (06:39).
Oito auditores Opus somente leitura; relatórios nesta pasta (`reauditoria-<área>.md`).

## 1. Resultado por área

| Área | Feitos conferidos | ✓ | ◐ | ✗/regrediu | A pendentes do inventário | Novas A | Novas C/D | Matriz C0 → C6 |
|---|---|---|---|---|---|---|---|---|
| Dashboard | 9 | 7 | 2 (DASH-008, 010) | 0 | 1 (DASH-008r) | 0 | 1 C | 188/242 → 199/245 |
| Pasta/Dados/Registro/Detalhes/Timeline | 26 | 23 | 3 (PAS-022, 023, 025) | **1 regressão** (C6-PAS-01) | 4 (PAS-022r, 023r, 034b, 045b) | 8 (+01) | 1 C | 149/202 → 180/212 |
| Processo/Metas/Clientes | 27 | 26 | 1 (PMC-038/059) | 0 | 0 | 6 | 1 C | 145/217 → 170/222 |
| Financeiro/Push/Notificações | 20 | 19 | 1 (FPN-036) | 0 | 0 | 4 | 0 | 187/275 → 214/284 |
| Documentos/Visualizador | 28 | 27 | 1 (DOC-108) | 0 | 0 | 3 | 1 D | 226/262 → 247/266 |
| Editor/Impressão | 22 | 20 | 2 (EDT-003, 011) | 0 | 0 | 9 | 1 C | 63/97 → 83/105 |
| IA | 15 | 14 | 1 (IA-008) | 0 | 0 | 4 | 0 | 70/113 → 85/119 |
| Segurança | 15 | 15 | 0 | 0 | 0 | 3 | 0 | 371/392 → 537/551 |
| **Total** | **162** | **151** | **11** | **1** | **5** | **37 (+1 regressão)** | **5** | |

Nenhum item declarado feito estava ausente; os parciais viraram itens novos (sufixo `r` ou C6-*).

## 2. Itens novos não-A (registrar, não bloqueiam)

- **C6-DASH-01 (C):** "ver metas" sem o `cargo` do painel (paridade com o card × desenho).
- **C6-PAS-10 (C):** visita da Timeline gravada ao abrir (desenho: ao fechar) — levar ao §3.4.
- **C6-PMC-07 (C):** limite do título da Nova meta (120 no desenho × 255), junto do PMC-039 (600 × 5000).
- **C6-DOC-04 (D):** tarja na impressão de PDF (vendorizar pdf.js × carimbo no servidor).
- **C6-EDT-10 (C):** registrar download do PDF na auditoria (leitura na auditoria é regra nova).
- **C6-SEC-03 (parte C):** criar meta exige VER (feito como A) ou EDITAR (paridade com renomear/reabrir) — Samuel.
- **DASH-010 resto:** B (foto diária) e C (critério de comparação), já registrados.
- Observações de segurança anteriores ao loop (frente própria, fora do escopo): resto do `TarefaController`
  sem permissão por pasta (concluir meta de pasta que não vê); `processo_edit` sem comparar tenant;
  `pasta_resumo_pdf` (Dompdf) sem limitador; "Peticionar na pasta" visível a quem só lê (decisão).

## 3. Lotes (escopo de escrita exclusivo por lote)

Onda 1 — paralela, sem arquivo em comum. **Nenhum lote da onda 1 toca** `pasta-show.css`, `pasta-show.js`,
`base.html.twig`, `_cabecalho.html.twig` (reservados à onda 2).

| Lote | Itens | Arquivos exclusivos (principais) |
|---|---|---|
| **S6-SEC** | C6-SEC-03 (P0: posse + VER), C6-SEC-01 (prólogo de tenant nas 6 rotas de observação), C6-SEC-02 (aviso do financeiro só a quem vê o financeiro) | `src/Controller/TarefaController.php` (só `criarParaPasta`), `src/Controller/PastaController.php` (só as rotas de observação de Detalhes/Financeiro), serviço de menções/respostas das observações, testes |
| **S6-SHOW** | C6-PAS-01 (regressão P1), C6-PAS-07 (foto no cartão recém-enviado do Registro), C6-PMC-01 (Nova meta não modal), C6-PMC-02 (NUP nulo no cabeçalho da Nova meta) | `templates/pasta/show.html.twig` |
| **S6-DET** | C6-PAS-02, 03, 04, 05, C6-EDT-04, C6-PAS-07 (Detalhes) | `templates/pasta/_detalhes_obs.html.twig` |
| **S6-CLI** | C6-PMC-03 (escrita do cartão Clientes escondida), C6-PMC-06 (maxlength 255 no renomear meta) | `_dados_trilho.html.twig`, `_cliente_linha.html.twig`, `_metas.html.twig` (só o maxlength) |
| **S6-TL** | C6-PAS-06 ("Resposta em…"), C6-PAS-08 (placeholder "telefone") | `src/Pasta/Service/MontadorDaTimelineInteligente.php`, `_timeline_inteligente.html.twig` |
| **S6-DOC** | C6-DOC-01, 02, 03 | `_documentos_sugeridos.html.twig`, `public/js/visualizador-documento.js` |
| **S6-PUSH** | C6-FPN-01 (notas em ordem cronológica), C6-FPN-03 (Copiar texto completo), C6-FPN-04 (Adicionar nota sem marcar lida), C6-IA-03 (Criar tarefa escondida de quem só vê), C6-IA-04 ("Marcar como lido"), C6-PMC-05 (bloqueado clicável com aviso) | `pasta-push.js`, `_push_processual*.html.twig`, `_push_teor*`, `_notas_tecnicas*.html.twig`, `_nota_tecnica_item.html.twig`, `nota-tecnica.js`, `NotaTecnicaRepository.php`, menus da IA, controller do Push |
| **S6-IMP** | C6-EDT-01, 02, 06, 07, 08, C6-EDT-03 (= C6-PMC-04, toasts do ⋮ do processo) | `resumo_impressao.html.twig`, `PastaResumoController.php`, DTO do resumo, CSS/JS da janela Imprimir, `_processos_vinculados.html.twig` (só os 2 toasts) |
| **S6-EDT** | C6-EDT-05 (Sugestões de correção no botão direito) | `public/js/editor-rico.js` |
| **S6-DASH** | DASH-008r | `templates/dashboard/_extras.html.twig` |
| **S6-MISC** | C6-FPN-02 (`.text-orange` global), C6-IA-01 (download/impressão no registro de auditoria da IA), C6-IA-02 (keyframes e fundo da IA) | `public/css/app.css` (só `.text-orange`), `ExportarAnaliseEmTextoUseCase.php`, `pasta-ia-agentes.css` |

Onda 2 — depois da onda 1 integrada:

| Lote | Itens | Arquivos |
|---|---|---|
| **S6-JS** | PAS-022r (X nos menus restantes), PAS-023r (arraste nos menus restantes), PAS-034b (`confirm`/`alert` restantes), C6-PAS-09 + C6-EDT-09 (comentários desatualizados) | `pasta-show.js`, `pasta-show.css`, `_cabecalho.html.twig`, `pasta-dialogos.js`, arquivos dos `confirm` |

Onda 3 — migration:

| Lote | Itens |
|---|---|
| **S6-M1** | PAS-045b ("Ver alteração" em Detalhes e Financeiro: `conteudo_anterior` nas duas observações) |

## 4. Execução

(registro por lote: objetivo, estado inicial, commits, testes, revisão, resultado — ver §4 do ledger)
