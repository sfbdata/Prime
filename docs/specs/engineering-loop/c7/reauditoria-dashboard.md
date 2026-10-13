# Reauditoria C7 — Dashboard (`dashboard_index`)

Auditoria de convergência, somente leitura, em 13/10/2026, no checkout principal, com o master em `517900bc`.
O prefixo é DASH. Base: `c6/reauditoria-dashboard.md`. A suíte não foi rodada, porque o briefing proíbe. O visual
foi conferido lendo template e CSS, não na tela. Os caminhos do app são relativos a `app/`. **dc** =
`docs/design/claude-design-2026-10-05 (1)/01 - Dashboard 1.2.2.dc.html`.

Commits do C6 nos arquivos da área (`git log 3ff6d9b0..HEAD` sobre `templates/dashboard`, `src/Dashboard`,
`public/{js,css}/dashboard*`, `tests/Dashboard`, `src/Tarefa`, `templates/tarefa*`): só `39e32d63` e `4b463629`
(S6-DASH). Commits de outras frentes que tocaram arquivos compartilhados (`base.html.twig`, `public/css`,
`PastaRepository`, `EventoRepository`, `AgendaController`, `templates/agenda`): `242ac329`, `4c2a991c`, `7d3aa289`,
`bbb0deee`, `4baade61` e `3e4bc262`.

## Conferência

| ID | Lote · commits | Veredito | Evidência |
|---|---|---|---|
| DASH-008r (Taxa de conclusão e Tempo médio clicáveis) | S6-DASH · `39e32d63`, `4b463629` | ✓ | Os ramos estão em `templates/dashboard/_extras.html.twig:48-51`. A taxa leva a `tarefa_equipe {status:'todas', responsavel}` + período, e o tempo médio leva a `{status:'concluidas', responsavel}` + período. É o `mapa` do dc script l.1847, com `periodo()` l.1850. A condição `valor > 0` reproduz o `zero ? sem href` do `celulaLink` (dc l.1958, 1968): 0% e 0d viram `db-num--zero` (`:56-57`) e "—" (null) nunca é link (`:41-42`). O `title`/`aria-label` "Abrir: Taxa de conclusão de X" / "Abrir: Tempo médio de X" segue `'Abrir: ' + EXTRAS[col].rotulo + ' de ' + quem` (dc l.1851, 1968). O macro é o mesmo da tabela (`_resultado.html.twig:437, 445`) e dos cards do celular (`_desempenho_cards.html.twig:185`), e o comentário do celular foi atualizado (`:12`). O destino existe e aceita os dois status (`ListarMetasDaEquipeUseCase.php:46-53`; `TarefaRepository.php:651-663`). O teste é `tests/Dashboard/Functional/DashboardExtrasLinkEquipeTelaTest.php`: a paridade do denominador com e sem período e com meta fora da janela, o 0% sem link (`:339-345`), outro escritório (`:368`) e sem `bi` (`:390`). O comentário de cabeçalho (`_extras.html.twig:25-33`) registra a ressalva do legado sem `dataConclusao`. |

Total: **1 ✓**, 0 ◐, 0 ✗. Somando o C6, os 9 feitos da área ficam com 8 ✓ e 1 ◐. O ◐ restante é o DASH-010 (setas
das extras), cujo resto é B/C, e não A.

## Regressões

Nenhuma.
- `4b463629` só mudou um comentário em `_desempenho_cards.html.twig`. Os outros links do card do celular
  (`:105-170`) ficaram como estavam.
- `39e32d63` e `4b463629` acrescentam ramos ao macro `valor` sem mexer nos ramos que já existiam
  (`pastas_urgentes`, `metas_concluidas`, `metas_revisao`, `eventos_agenda`), e a ordem do `elseif` não engole
  nenhum deles.
- A linha de Total continua sem link (`_resultado.html.twig:497-509`), como manda o DASH-007 (C).
- Os commits de outras frentes não tocam o Dashboard. `242ac329`, `4c2a991c`, `7d3aa289` e `bbb0deee` só mexem em
  `pasta-*`, e `3e4bc262` só em `editor-rico.*`. O único em CSS global é `4baade61`, que generaliza `.text-orange`
  em `app.css:258-259`, e nenhum template de `dashboard/` usa essa classe.
- `/tarefas/equipe` (`src/Tarefa`, `templates/tarefa*`) não recebeu commits no C6.

## Pendências A

Nenhuma. A única A que o C6 deixou aberta (DASH-008r) foi feita. Continuam fora da classe A, sem mudança:
- **B:** DASH-005 (cron da foto) e DASH-018.
- **C:** C6-DASH-01 (cargo no "ver metas") e o critério de comparação do DASH-010b.
- **Decisão do §3.4:** a paridade da Agenda (DASH-009).

**Documentação ainda pendente:** a observação 1 do C6 não foi aplicada. O ledger §3.4, l.338-340 ("Agenda
(DASH-009)"), ainda diz que o gestor vê na lista da pessoa eventos "Para Todos" de colegas dos quais não participa.
Depois do SEC-016 (`a0f07c60`, `EventoRepository::restringirAoVisivelPara`), isso não acontece mais. A decisão
aberta é a inversa: o número pode ser maior que a lista (`_extras.html.twig:19-24`). A correção cabe ao
orquestrador.

## Novas

Nenhuma divergência nova da classe A. Conferi o resto do contrato do `celulaLink` contra o código: rótulo do
bloco do celular (`ROT_NUM[k] || EXTRAS[k].rotulo`, dc l.2996 = `extrasCatalogo[k].rotulo`), título do link e
zero sem foco. Encontrei duas diferenças, as duas já classificadas: o link do Total (DASH-007, C) e o toast no
clique, que é coisa do protótipo.

| ID | Classe | Prior. | Esf. | Migration | Elemento | Desenho | Código | Proposta | Teste |
|---|---|---|---|---|---|---|---|---|---|
| — | | | | | | | | | |

## Matriz

DASH-008 é requisito de Funções (inventário l.53, coluna F). No C6 ele estava ◐ e não somava. Agora soma.

| Dimensão | C6 OK / total | Ganhos C7 | **C7 OK / total** |
|---|---|---|---|
| Visual | 101/108 | 0 | **101/108** |
| Interações | 47/68 | 0 | **47/68** |
| Funções | 24/40 | +1 (DASH-008) | **25/40** |
| Responsivo | 13/15 | 0 | **13/15** |
| Dark mode | 14/14 | 0 | **14/14** |
| **Total** | **199/245** | | **200/245** (82%) |

Divergências abertas na área: A 0 · B 2 (005, 018) · C 18 · D 5 · E 2. Não mudaram desde o C6, a não ser pelo
DASH-008r.
