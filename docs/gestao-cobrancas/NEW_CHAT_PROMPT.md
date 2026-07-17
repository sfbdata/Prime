# NEW_CHAT_PROMPT — Gestão de Cobranças (Ajuste 10, próximo passo: T9)

> Cole o bloco abaixo como primeira mensagem de um chat NOVO do Claude Code.
> NÃO confie em resumos — confirme TUDO no repositório e no Git antes de agir.

---

```
Continuo o Ajuste 10 (redesenho de UX do `cobranca_objeto_show`) do módulo
Gestão de Cobranças (App\Cobranca) do JusPrime — módulo JÁ EM PRODUÇÃO
(bluejus.com.br). As tarefas T1–T8 estão COMPLETAS, REVISADAS e commitadas
localmente (nada publicado). O passo natural agora é a T9. NÃO confie neste
resumo — confirme no Git e nos docs vivos antes de tocar em qualquer coisa.

## 1. Carregue a skill `workflow` antes de tocar em código.

## 2. Confirme o estado no Git (se divergir, PARE e reporte)
- Branch `redesenho-objeto-cobranca` (local, NÃO publicada).
- HEAD deve ser `52138d0` ou posterior; working tree LIMPO.
- docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'
  => 609/609. Global (`php bin/phpunit`) => 1973/1973.
- ⚠️ **UM PILOTO DE GIT POR VEZ.** Hoje (2026-07-17) dois chats mexeram no mesmo
  repo e um `git reset --hard` de outra sessão APAGOU trabalho não-commitado.
  Se houver sinal de outra sessão ativa, alinhe antes. Commite cedo.

## 3. Leia nesta ordem
- Memória (fora do repo): MEMORY.md → project_gestao_cobrancas.md (bloco do topo).
- docs/gestao-cobrancas/HANDOFF_AJUSTE10.md — estado, ambiente, gotchas.
- .superpowers/sdd/progress.md — o LEDGER (o que cada tarefa entregou; tarefa
  COMPLETE não se refaz). A entrada da T8 tem o desenho inteiro do B5.
- docs/gestao-cobrancas/PLANO_AJUSTE10_REDESENHO_OBJETO_SHOW.md — "Task 9".
- Spec: docs/specs/cobranca-ajuste10-redesenho-objeto-show.md.

## 4. O que é a T9 (formulários sob demanda, B6) — risco BAIXO
Objetivo: `MontadorModaisCaso` constrói ~13–16 FormViews em todo GET do objeto.
A T9 quer adiar a construção dos que o usuário não vai abrir. É **HIGIENE, não
UX — a página NÃO está lenta hoje.** O plano diz que é "a segunda a cair" se o
tempo apertar. Cadência: **meça ANTES (queries + tempo, via profiler do Symfony
no dev), adie o que der, meça DEPOIS. Sem medida antes, não há prova de melhora.**

## 5. ⚠️ VERIFIQUE A PREMISSA ANTES DE IMPLEMENTAR (pode estar obsoleta)
A T9 foi escrita ANTES da T4 (redesenho: 6 abas → 3). Depois da T4, os modais
são disparados por botões espalhados pela página e ficam TODOS no DOM — então
os FormViews podem ser TODOS necessários no render, e "adiar os das abas
fechadas" talvez não se aplique mais. **Investigue primeiro se existem de fato
FormViews adiáveis; se a premissa caiu, a T9 pode ser reduzida ou virar no-op —
reporte isso ao humano em vez de forçar.**

## 6. ⚠️ A T8 mexeu MUITO no `MontadorModaisCaso` — não quebre o B5
`deMutacao()` e `financeiros()` agora recebem `?array $erroModal` e passam cada
form por `reidratarSeErro()` (re-submete o payload no erro → o `form_row`
reidrata valor+erro). Qualquer lazy/closure na construção dos forms DEVE
preservar essa reidratação. Rode os testes de B5 (`--filter InvalidaReabreModal`
e os `ReabreModal`) depois de mexer.

## 7. Ambiente e gotchas (herdados)
- Tudo no container `jusprime_php_dev`; phpunit/cache pedem `-d memory_limit=512M`.
- Banco de teste = `saas_test` (schema:create, NÃO replay de migrations). Nunca
  `doctrine:schema:update --force` (quer dropar 3 índices funcionais).
- Smoke dev: http://localhost:8080 · farlei.rocha@gmail.com / Prime123! · objetos
  bons: 296, 297, 108. Gotcha: `#modalAlertaPonto` intercepta cliques → remover
  via browser_evaluate. Dado do dev é dump de PROD — não semeie/altere à toa.
  Cache de JS/CSS no dev agora revalida (nginx no-cache) — mas se um asset teimar
  em vir velho, confira o `encodedBodySize` do arquivo servido.
- Git: commit local OK (revisando status+diff). push/merge/rebase/deploy = humano.

## 8. Comece assim
Carregue a skill workflow, confirme o Git e a suíte, leia o HANDOFF + o ledger,
INVESTIGUE a premissa da T9 (§5) e ME REPORTE o que achou (medida antes + se há
forms adiáveis) ANTES de implementar. Só então proponha o plano.

## 9. Alternativa que talvez você prefira (me pergunte)
O Ajuste 10 já está substancialmente pronto (T1–T8). T9 e T10 são higiene/
opcional. Em vez de seguir com a T9, o humano pode preferir: (a) `/review` final
+ integrar/deployar o Ajuste 10 inteiro; ou (b) atacar o follow-up `acordoCriar`
(o 14º form da T8 — precisa conciliar a reidratação B5 com o reset-on-close do
modal de acordo, commits 351dcf8/906af4c, que hoje apagaria o reidratado). Se não
tiver certeza do que o humano quer, PERGUNTE antes de começar a T9.
```

---

## Contexto para quem cola (não faz parte do prompt)

**Commits do Ajuste 10 na branch `redesenho-objeto-cobranca` (nada publicado):**
T1–T7 + conserto de cache nginx (`941c8ce`) + spec fase2 recuperada (`7096c45`) +
T8 em 8 commits (`1fa7d22` → `52138d0`). Fonte de verdade viva:
`.superpowers/sdd/progress.md` (ledger) e `HANDOFF_AJUSTE10.md`.

**Follow-ups abertos:** `acordoCriar` (14º form da T8); re-smoke do B5 em prod ao
deployar; cache do nginx só muda no rebuild de prod (deploy do humano).
