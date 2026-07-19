# NEW_CHAT_PROMPT — Encargos configuráveis em cascata (implementação autônoma)

> Cole o bloco abaixo como PRIMEIRA mensagem de um chat NOVO, com contexto limpo.
> Ele instrui a implementação autônoma, fase por fase, da spec já aprovada — usando o workflow do projeto e
> subagentes, mantendo o contexto do chat principal enxuto. NÃO confie em resumos: confirme no Git e na spec.

---

```
Implemente, de forma TOTALMENTE AUTÔNOMA e fase por fase, a feature "Encargos separados e configuráveis em
cascata" do módulo App\Cobranca do JusPrime (SaaS jurídico, PHP 8.2/Symfony 7.4, módulo JÁ EM PRODUÇÃO). A spec
está APROVADA. Trabalhe tomando as melhores decisões, delegando a subagentes para manter ESTE contexto leve, e
NUNCA faça push/merge/deploy (isso é do humano). Risco ALTO (dinheiro): rigor máximo.

## 1. Carregue as skills antes de tocar em código
- `workflow` (comportamento de orquestrador, delegação, ciclo, git do projeto) — SEMPRE primeiro.
- Use o ciclo do projeto: investigar → (spec já existe) → implementar → revisar (/review) → corrigir → conferir.

## 2. Leia, nesta ordem (fonte de verdade — não resumos)
- docs/specs/cobranca-encargos-configuraveis-cascata.md   ← A SPEC (o quê/porquê, fórmulas, invariantes, fases).
- docs/gestao-cobrancas/HANDOFF_ENCARGOS.md                ← estado, decisões, gotchas, fases resumidas.
- Memória: MEMORY.md → project_cobranca_encargos.md.
- Para o código: docs/gestao-cobrancas/HANDOFF_ENCARGOS.md aponta os arquivos; use um subagente read-only
  (Explore/feature-review-agent) para mapear detalhes sob demanda em vez de ler tudo neste contexto.

## 3. Confirme o estado antes de escrever (se divergir, PARE e reporte)
- git status limpo; anote o HEAD e a branch atual.
- Crie uma branch de feature (ex.: `cobranca-encargos-cascata`) a partir do estado atual do master local.
- Baseline: docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'
  (anote o número) e global. Um piloto de git por vez; commite cedo.

## 4. Modo de trabalho AUTÔNOMO com contexto enxuto (obrigatório)
- Mantenha um LEDGER vivo em `.superpowers/sdd/progress-encargos.md` (o que cada fase entregou; tarefa COMPLETE
  não se refaz). Guarde estado NELE, não na sua cabeça — assim o contexto do chat fica leve.
- Para CADA fase: delegue a IMPLEMENTAÇÃO a `feature-implementer` em worktree isolada (escopo exclusivo,
  contratos congelados e COMMITADOS antes do fan-out), e a REVISÃO a `feature-review-agent` (read-only). Você
  (orquestrador) integra por `git cherry-pick` individual, roda os testes direcionados, estabiliza, segue.
- Investigações/mapeamentos → subagentes read-only que devolvem digest (não despeje arquivos no seu contexto).
- Onde a spec deixa uma decisão "a confirmar", adote o DEFAULT recomendado da spec (§13) e siga; registre no
  ledger para o humano ver. Não trave esperando resposta.
- Faça o SMOKE visual você mesmo (subagentes não têm navegador). Dev: http://localhost:8080 ·
  farlei.rocha@gmail.com / Prime123! (dado é de TESTE). Gotcha: #modalAlertaPonto intercepta cliques.

## 5. As fases (spec §14) — implemente NA ORDEM, uma estável antes da próxima
F1 Motor `CalculadoraEncargos` (puro, testes round-trip + prova contra as linhas reais do Apêndice A da spec) +
   colunas na obrigação (juros/multa/correcao/honorarios + congelamento) + `ResolvedorConfigEncargos` + migração.
   valorExigivel = soma dos 3. PROVA obrigatória: suíte de saldo INTACTA (INV-E1).
F2 Config de taxas na carteira (DTO/Form/UseCase) + cascata (snapshot no AbrirCaso, override no Objeto) +
   importador lê Correção (col K) + split + congela o importado.
F3 Cron `app:cobranca:atualizar-encargos` (só `encargosCongeladosEm IS NULL`; `$hoje` injetável; tenant-safe).
F4 UI: linha da obrigação com colunas do PDF (Original·Juros·Multa·Correção·Honorários·Total) + %↔R$ no
   criar/editar + override no objeto + honorários base composta/carência. Preserve B5 (reidratação) e reset-on-close.
F5 Editar obrigação PAGA + reconciliar valor recebido (estende EditarObrigacao/CorrigirPagamento) com histórico.
F6 Verificação independente FINAL: subagente sem viés reprova a fórmula contra um extrato NOVO (half-down do
   juros; corte exato da carência) → checklist de go-live → PARA e entrega ao humano (deploy é dele).

## 6. Invariantes que não se afrouxam (spec §12)
- INV-E1 valorExigivel = valorOriginal + juros + multa + correcao; a soma TEM de igualar o antigo
  encargosReconhecidos na migração (teste de consistência batch==per-caso).
- INV-E3 CalculadoraSaldo/Dashboard/FIFO/Acordo NÃO recebem $hoje (crescimento é via cron, não via exigível).
- INV-E4 obrigação congelada nunca é tocada pelo cron.
- INV-E5 fórmula provada por subagente independente contra dados reais ANTES do go-live.
- Multi-tenant: toda query filtra tenant; lookup por rota = findOneByIdDoTenant→404; teste cross-tenant.

## 7. Ambiente / gotchas (herdados)
- Tudo no container jusprime_php_dev; phpunit/cache com -d memory_limit=512M. Nunca php fora do container.
- Banco de teste saas_test = schema:create (não replay). Nunca doctrine:schema:update --force.
- Migrations: colisão de número = falha SILENCIOSA → confira o último Version antes de criar.
- Planilhas reais (PII, gitignored) em docs/gestao-cobrancas/*.xlsx|*.pdf; ler .xlsx via Python stdlib
  (zipfile+xml). Backfill por REIMPORT é a via limpa (dado é de teste).
- Git: commit local OK (revisando status+diff, sem git add cego). push/merge/rebase/reset/deploy = HUMANO.
  block-git-writes.py bloqueia escrita remota. feature-implementer tem trava própria (não integra).

## 8. Entrega ao humano ao final de CADA fase e no fim
- Mostre o smoke, rode a suíte direcionada + global, /review sem bloqueante, commit local, atualize ledger +
  memória + HANDOFF_ENCARGOS.md. NÃO publique. Ao terminar tudo (ou ao fim de cada fase relevante), deixe um
  resumo do que foi feito, o que falta e o comando de deploy montado em bloco "# Execute manualmente" para o humano.

Comece carregando o workflow, lendo a spec e o HANDOFF, confirmando o Git e a baseline, criando a branch e o
ledger, e então implemente a F1. Trabalhe autônomo até onde for seguro; pare só no que exige decisão humana
irreversível (push/merge/deploy) ou em bloqueante real.
```

---

## Notas para quem cola (fora do prompt)
- A verificação da fórmula JÁ foi feita nesta preparação (100% ao centavo) e está no Apêndice A da spec — a F6 é a
  **revalidação contra extrato NOVO** (por causa do arredondamento half-down e do corte da carência).
- 3 correções importantes à premissa do brainstorm estão na spec §2 e no HANDOFF — o implementador deve
  SURFAÇÁ-LAS ao humano, não escondê-las.
- Se o humano quiser mudar um default "a confirmar" (spec §13) antes de começar, é o momento.
