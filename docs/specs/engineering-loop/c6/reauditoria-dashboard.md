# Reauditoria C6 — Dashboard (`dashboard_index`)

Auditoria somente leitura em `master` 4a98cc6b. Prefixo DASH. Caminhos do app relativos a `app/`.
**dc** = `docs/design/claude-design-2026-10-05 (1)/01 - Dashboard 1.2.2.dc.html`; **RD** =
`design_handoff_dashboard/README.md`. O visual (cor, raio, posição) foi conferido lendo CSS e template, não na tela.
Nenhum teste foi rodado (proibido neste ciclo): onde cito teste, conferi só que ele existe e o que ele afirma.

## Fontes lidas

- Briefing do auditor C6; `inventario-dashboard.md` (inteiro); ledger `engineering-loop-fidelidade-designer.md`
  §3.1 (l.144-145, 182-184, 196, 216), §3.1 "novas divergências" (l.236-238), §3.2-§3.5 (l.287-352);
  `fila-a-restante.md` (l.37, 194-199, 225-228).
- RD: l.325-400 (números clicáveis, 4º card, testes), 500-600 (colaborador, histórico, janelas, ⋮,
  personalização), 700-820 (links "ver…", Modo avançado, básico × avançado, abertura, acesso).
- dc: l.524 ("ver metas"), 853-960 (cabeçalho do painel, interruptor, confirmação), 1405-1420 (cartão
  "Conhecer"), 1431-1474 (janela de histórico), script l.1718-1738 (HIST_CFG, EXTRAS), 1805-1860
  (`destinoCard`, `destino`), 3010-3011 (cards), 3040-3080 (cartão do colaborador), `montarHist`.
- Commits do loop na área: `ac76c8b1`, `78b555f6`, `14a9fa5b`, `f29386d5`, `7a36a92e`, `6340a902`, `e3cd352d`,
  `5f9082e1`, `61188192`, `7b9152c6`, `a0f07c60` (`git show --stat` de cada); `git log 607f2c29..HEAD` sobre
  `templates/dashboard`, `public/js/dashboard*`, `public/css/dashboard*`, `src/Dashboard`, `base.html.twig`,
  `filtro-tabela.*`, `PastaRepository`, `TarefaRepository`, `TarefaEquipeController`, `AgendaController`,
  `EventoRepository`, `agenda/lista.html.twig`; `git diff 607f2c29 HEAD -- public/css` (nenhum seletor `.db-`
  fora dos CSS do Dashboard).
- Código: `templates/dashboard/_resultado.html.twig` (l.1-240, 400-515), `_extras.html.twig` (inteiro),
  `_tendencia.html.twig` (inteiro), `_desempenho_cards.html.twig` (grep), `_inteligencia.html.twig` (l.1-140, 300-311),
  `index.html.twig` (grep); `src/Dashboard/Preferencia/ColunasExtrasDoDashboard.php` (inteiro),
  `UseCase/ObterDadosDashboardUseCase.php` (l.60-200), `UseCase/ObterHistoricoDoCardUseCase.php` (l.186-345 + grep),
  `Controller/HistoricoDoDashboardController.php`, `Repository/HistoricoDoDashboardRepository.php` (grep),
  `Repository/MetricasExtrasDoDashboardRepository.php` (l.1-214); `src/Tarefa/UseCase/ListarMetasDaEquipeUseCase.php`
  (l.1-140), `Tarefa/Controller/TarefaEquipeController.php` (l.25-67), `TarefaRepository.php` (l.410-460, 595-740);
  `Pasta/Repository/PastaRepository.php` (l.478-613, 660-715); `Controller/AgendaController.php` (l.80-131),
  `Repository/EventoRepository.php` (l.200-300), `templates/agenda/lista.html.twig` (l.30-66);
  `public/js/dashboard-janelas.js` (l.1-80 + grep), `dashboard-historico.js` (l.1-80, 150-190),
  `dashboard-preferencias.js` (grep), `public/css/dashboard-inteligencia.css` (l.265-360, 555-668 + grep),
  `dashboard-janelas.css` (l.240-262 + grep); lista de testes em `tests/Dashboard/`.

## Conferência dos feitos

| ID | Declarado (ledger) | Veredito | Evidência |
|---|---|---|---|
| DASH-006 | "ver pastas"/"ver metas" com paridade card = lista (`6340a902`) | ✓ | Os 3 cards têm link com `bi-arrow-right` (`_resultado.html.twig:100, 155, 201`; dc l.524). Pastas criadas: `criado_por=equipe`/`equipe:<cargo>`/`<id>` (`_resultado.html.twig:91-98`), critério em `PastaRepository.php:676-711` (criador ativo do escritório, sem lápide). "ver metas": `escopo=card` → `TarefaRepository::findMetasAtivasDoCardPaginado` (`TarefaRepository.php:682-702`), mesmo critério de `countMetasAtivas` (`:418-431`). Teste `tests/Dashboard/Functional/DashboardCardsVerParidadeTest.php`. Diferenças conscientes: "ver metas" sem `cargo` (C6-DASH-01) e "ver pastas" de Urgentes com período (DASH-034, D). |
| DASH-008 | Links das colunas extras (`f29386d5`) | ◐ | Feito: Metas concluídas → `/tarefas/equipe?status=concluidas`, Em revisão → `status=em_revisao`, com pessoa e período (`_extras.html.twig:37-40`; `ListarMetasDaEquipeUseCase.php:46-53`; `TarefaRepository.php:651-661`); teste `DashboardExtrasLinkEquipeTelaTest.php`. **Falta** o que o inventário também pedia e o desenho mostra: Taxa de conclusão → `/tarefas/equipe?status=todas` e Tempo médio → `status=concluidas` (dc script l.1847; RD l.568 "números clicáveis"). O código as deixa como número simples por decisão própria (`_extras.html.twig:25-26`), sem consulta ao dono. Ver Pendências A. |
| DASH-009 | "Eventos na agenda" leva à lista da pessoa (`7b9152c6`, + SEC-016 `a0f07c60`) | ✓ | Link `agenda_lista?responsavel=<id>&data_de&data_ate`, só com módulo `agenda` (`_extras.html.twig:41-42`); `AgendaController.php:92-131` (pessoa do tenant, data estrita, vazio fora do escritório); `EventoRepository::findNaAgendaDaPessoa` (`:229-262`) com a régua de `contarEventosPorPessoa` + visibilidade de quem olha (`restringirAoVisivelPara`, `:271-284`); faixa "na agenda de … · só os eventos que você pode ver" (`agenda/lista.html.twig:60-66`). Paridade pode quebrar a favor do sigilo (C pendente, §3.4). |
| DASH-010 | Setas das colunas extras (`7a36a92e`) | ◐ | Só Eventos na agenda tem seta/pílula (`ColunasExtrasDoDashboard.php:112-114`; `_resultado.html.twig:429-447, 492-498`; `_desempenho_cards.html.twig:178-187, 241-248`). O desenho dá seta a todas as extras (RD l.568-571; dc l.1733-1737 `neutra`/`sobeRuim`). Em revisão e Pastas urgentes são estoque (dependem de foto: B, como DASH-018); concluídas/taxa/tempo foram retiradas no DASH-010b (critério de comparação: C). Nada A restante. |
| DASH-010b | Sem seta em concluídas/taxa/tempo médio (`61188192`) | ✓ | `ColunasExtrasDoDashboard.php:96-114` (motivo documentado; pendente do Samuel); UseCase só roda o período anterior das extras com tendência (`ObterDadosDashboardUseCase.php:139-152`). Retirada deliberada, não regressão. |
| DASH-017 | Histórico de 12 meses no botão direito (`5f9082e1`) | ✓ | `contextmenu` delegado nos 4 cards, links preservados (`dashboard-historico.js:156-183`); janela 330px, seletor de anos + Todos, barra atual na cor do card e melhor em dourado, valor só nessas duas, cascata 0,03s (`dashboard-historico.js:60-75`; `dashboard-janelas.css:243-258`; dc `montarHist`); Média/mês · Melhor · "vs ano anterior" e, em Todos, Média/ano · Melhor · Variação (`ObterHistoricoDoCardUseCase.php:190-290`). Endpoint GET com guarda `bi` e tenant (`HistoricoDoDashboardController.php:34-59`); série sem lápide (`HistoricoDoDashboardRepository.php:50-56`). Metas/Urgentes/Global mostram por que não têm série (DASH-018, B). Testes: `HistoricoDoDashboardControllerTest`, `ObterHistoricoDoCardUseCaseTest`, `DashboardHistoricoJsTest`. |
| DASH-020 | Janelas flutuantes (`e3cd352d`) | ✓ | Deslocamento 18px, limites 56/96px, arraste pela posição visível, frente ao clicar, Esc fecha a da frente, não fecha com clique fora (`dashboard-janelas.js:1-13, 33-38, 91, 129-135, 225, 255`); `role=dialog` não modal; tema escuro (`dashboard-janelas.css:44, 147, 176-179`); movimento reduzido e preferência "animações" (`:328-334`). Teste `DashboardJanelasJsTest`. |
| DASH-025 | Modo avançado lembrado no servidor (`ac76c8b1`) | ✓ | Chave `dashboard.ia_avancado` ligado/desligado, padrão desligado (`CatalogoDePreferenciasDoDashboard.php:40, 50-51, 82, 97`), fora do "Restaurar" da tabela (`:109-117`); interruptor `role=switch` na linha do título, no cabeçalho (`_inteligencia.html.twig:62-67`; dc l.872); 600px com `db-page--ia-avancado` (`dashboard-inteligencia.css:293`). Comentário obsoleto do inventário corrigido (`_inteligencia.html.twig:10-14`). |
| DASH-026 | Confirmação na 1ª ativação; cartão 6s (`78b555f6`) | ✓ | `alertdialog` com o texto do dc (`_inteligencia.html.twig:76-94`; dc l.939-959), só sem escolha gravada (`index.html.twig:31` `data-ia-avancado-escolhido`); cartão "Conhecer o Modo avançado" acima do rodapé, 6000/6600ms, a cada abertura com o modo desligado (`_inteligencia.html.twig:303-307`; `dashboard-preferencias.js:357-358, 404-408`; `dashboard-inteligencia.css:599-633`); tokens no escuro (`:150, 162-163`). Escopo "Poderá analisar" e subtítulo reduzidos: DASH-029 (C); 1ª vez × toda vez: D já registrado. |

**Contagem:** 9 itens declarados feitos → **7 ✓ · 2 ◐ · 0 ✗**.

## Regressões

Nenhuma encontrada.
- Os 12 commits do loop que tocam o Dashboard são só os da própria área (lista em Fontes). Dos commits de outras
  frentes, o único em arquivo compartilhado é `b216786b` (sino `bi-bell` em `base.html.twig`), sem efeito no Dashboard.
- `git diff 607f2c29 HEAD -- public/css` não altera seletor `.db-` fora de `dashboard*.css`.
- `61188192` retirou setas que `7a36a92e` tinha posto. Foi uma retirada deliberada e documentada (DASH-010b),
  não uma regressão.

## Pendências A

Dos 12 A do inventário: 006, 008, 009, 010, 017, 020, 025 e 026 constam como feitos; 011/012 → D e 027/028 → C
(fila, l.225-228). **Resta 1 A**, que é o resto do DASH-008:

| ID | Prior. | Esf. | Migration | O que falta | Proposta | Teste | Arquivo compartilhado? |
|---|---|---|---|---|---|---|---|
| DASH-008r | P4 | P | não | Taxa de conclusão e Tempo médio não são clicáveis (dc script l.1847: taxa → `/tarefas/equipe?status=todas`, tempo → `status=concluidas`, com pessoa e período; RD l.568) | Em `_extras.html.twig:35-46`, dois ramos novos: `taxa_conclusao` (valor não nulo) → `tarefa_equipe {status:'todas', responsavel}` + período; `tempo_medio` (não nulo) → `{status:'concluidas', responsavel}` + período. O destino já existe (`ListarMetasDaEquipeUseCase::STATUS`). Trocar o comentário de `:25-26`. `title` de destino: "Abrir: Metas de {quem}" / "Abrir: Metas concluídas de {quem}" | Functional em `DashboardExtrasLinkEquipeTelaTest`: o href da taxa e o total da lista = Total metas da linha (denominador); o href do tempo médio e o total da lista = Metas concluídas; "—" sem link; pessoa de outro escritório → lista vazia | não (só `templates/dashboard/_extras.html.twig`) |

Nota: a lista do Tempo médio inclui concluídas do legado sem `dataConclusao`, que a média exclui
(`MetricasExtrasDoDashboardRepository.php:62-74`). É o destino que o desenho pede; o `title` do cabeçalho já explica a ressalva.
Se o dono preferir paridade estrita, isso vira C.

## Novas divergências

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho (arq:linha) | Código (arq:linha) | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| C6-DASH-01 | C | P5 | P | não | "ver metas" do card Metas ativas sem o `cargo` do painel | dc script l.1814-1825 (`destinoCard` põe `cargo` em todos os links); RD l.743 ("+ `responsavel`/`cargo`") | `_resultado.html.twig:149-155` e `ListarMetasDaEquipeUseCase.php:80-84` descartam o cargo | Ratificar com o Samuel. O próprio desenho conta o card sem cargo (dc l.3010) e mesmo assim manda o cargo no link, então o código escolheu a paridade. Seguir o desenho faria a lista contar menos que o card | — |

Nada mais novo foi encontrado nesta área. Todas as outras diferenças entre o desenho e o código que conferi já
estão registradas: DASH-001..005, 007, 011-016, 018, 019, 021-024, 027-034, SEC-010 e SEC-016/§3.4.

### Observações (documentação, sem divergência de desenho)

1. **Ledger §3.4, "Agenda (DASH-009)" (l.338), está desatualizado.** Ele diz que o gestor vê na lista da pessoa
   eventos "Para Todos" de colegas dos quais não participa. Depois de `a0f07c60` (SEC-016), a lista aplica
   `restringirAoVisivelPara` (`EventoRepository.php:248, 271-284`), então isso já não acontece. A decisão que
   continua aberta é a inversa: o número do Dashboard pode ser maior que a lista (`_extras.html.twig:20-24`).
   O ledger precisa ser corrigido pelo orquestrador.
2. **O histórico não abre pelo teclado.** O botão direito só existe com mouse, e os cards não recebem foco.
   O desenho não fala de teclado, então não registrei como divergência.
3. **DASH-005 (cron da foto, B) continua sendo o maior impacto visível em produção.** Ele também deixa vazias as
   janelas de histórico de 3 dos 4 cards (DASH-018).

## Matriz

Denominador do C0 (242) + 3 requisitos novos: C6-DASH-01 (Funções) e o tema escuro das janelas e da
confirmação/cartão (Dark). Conto como OK só os itens ✓ (os ◐ não somam).

| Dimensão | C0 OK / total | Ganhos C6 | **C6 OK / total** |
|---|---|---|---|
| Visual | 96/108 | +5 (006, 017, 020, 025, 026) | **101/108** |
| Interações | 47/68 | 0 | **47/68** |
| Funções (persistência/backend) | 20/39 | +4 (006, 009, 017, 025); +1 no denominador (C6-DASH-01) | **24/40** |
| Responsivo | 13/15 | 0 | **13/15** |
| Dark mode | 12/12 | +2 (janelas/histórico; confirmação/cartão), denominador +2 | **14/14** |
| **Total** | **188/242** | | **199/245** (81%) |

Divergências abertas na área: A 1 (DASH-008r) · B 2 (005, 018) · C 18 (15 do C0 + 027/028 reclassificados + C6-DASH-01;
o critério de comparação do DASH-010b e a paridade da agenda do DASH-009 são decisões pendentes no ledger, fora dessa conta) · D 5 (002, 021, 034, 011, 012) · E 2 (031, 032).

## Resumo

- Feitos: **7 ✓ · 2 ◐ (DASH-008, DASH-010) · 0 ✗**. Nenhuma regressão.
- O que falta no DASH-010 não é A (estoque → B; critério de comparação → C).
- A pendente: **1**, DASH-008r (links de Taxa de conclusão e Tempo médio). P4, esforço P, sem migration,
  toca só `_extras.html.twig`.
- Novas: **1**, C6-DASH-01 (C, P5: cargo no "ver metas").
- Documentação: o ledger §3.4 l.338 (DASH-009) ficou desatualizado depois do SEC-016.
- Matriz: **199/245**.
