# NEW_CHAT_PROMPT — Encargos, rodada pós-go-live (Ajustes 2 e 3)

> Cole o bloco abaixo como PRIMEIRA mensagem de um chat NOVO. Continua a rodada de ajustes da feature de
> encargos (F1→F6 já completa e pushada). O Ajuste 1 (recálculo automático) já foi feito.

---

```
Continue, de forma AUTÔNOMA, a RODADA PÓS-GO-LIVE da feature "Encargos separados e configuráveis em cascata"
do módulo App\Cobranca do JusPrime (PHP 8.2/Symfony 7.4, módulo EM PRODUÇÃO). Risco ALTO (dinheiro): rigor
máximo. Trabalhe delegando a subagentes para manter ESTE contexto leve. NUNCA faça push/merge/deploy (é do humano).

1) CARREGUE a skill `workflow` antes de tocar em código.

2) LEIA nesta ordem (fonte de verdade, não resumos):
   - Memória: MEMORY.md -> project_cobranca_encargos.md (carrega sozinha; tem o estado e as decisões).
   - docs/gestao-cobrancas/HANDOFF_ENCARGOS.md — seção "RODADA PÓS-GO-LIVE" tem os ajustes e a ordem.
   - .superpowers/sdd/progress-encargos.md — ledger vivo (gitignored); seção "RODADA PÓS-GO-LIVE".
   - docs/specs/cobranca-encargos-configuraveis-cascata.md — a spec (§7 honorários, §11 UI).
   Para o código, use subagente read-only (Explore) sob demanda; não leia tudo aqui.

3) CONFIRME o git ANTES de escrever (há sessões concorrentes; se divergir, PARE e reporte):
   git log --oneline -5 · git branch -vv · git status. Esperado: branch `cobranca-encargos-cascata`,
   HEAD `1c9206f`, 3 commits à frente de origin, árvore limpa. Baseline:
   docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'
   (esperado 772/772). Continue NESTA branch (é a mesma feature).

4) FAÇA NA ORDEM:

   PASSO 0 — SMOKE do Ajuste 1 (já implementado, commit f33b538). Prove no navegador que funciona antes de seguir:
   dev http://localhost:8080 · farlei.rocha@gmail.com / Prime123! (dado é de TESTE; gotcha: #modalAlertaPonto
   intercepta cliques, remover via browser_evaluate). Teste: criar uma obrigação com vencimento ANTIGO na
   carteira TOPLIFE I (id 1, objeto 117) → os juros/multa/honorários aparecem NA HORA (não zero); depois editar
   o vencimento para mais atrás → os valores recalculam na hora. Se falhar, é bug do Ajuste 1 — investigue.

   AJUSTE 2 — Honorários editáveis no CASO e na OBRIGAÇÃO, com o campo junto dos encargos nos forms.
   Hoje só se edita honorário na carteira (snapshot no caso). Estender a cascata para honorários (a spec §11
   previa "config por obrigação"). É DINHEIRO: investigar (Explore) → spec curta em docs/specs/ → implementar
   (feature-implementer em worktree) → revisar (feature-review-agent) → integrar → testar → SMOKE.
   Default recomendado (adotar e registrar; confirmar com o humano se ele quiser mudar): honorário editável
   por obrigação fica FORA do valorExigivel/saldo (INV-E2, consistente com a regra atual do módulo). Mapear
   "quase todos os forms" que o dono citou.

   AJUSTE 3 — Redesign do card da obrigação (a linha em colunas ficou apertada: descrição espremida).
   PERGUNTE ao dono o estilo antes (3 opções: card compacto / detalhado / expansível, com previews).
   ⚠️ Um objeto tem 150+ obrigações → densidade importa (candidato: card EXPANSÍVEL, compacto por padrão).
   Só Twig/CSS/JS; preserve os ganchos de teste (ObjetoShowContratoJsTest) e o B5 (reidratação). SMOKE em
   3 larguras, sem rolagem horizontal.

5) DECISÕES JÁ TOMADAS (não reabrir):
   - Correção monetária = MANUAL por obrigação (o gestor pesquisa e digita; congela; rastro). NÃO construir
     busca de índice IGP-M/IPCA — o sistema é de REGISTRO. Manter "Correção (%)" da carteira em 0.
   - Obrigação AUTOMÁTICA (encargosCongeladosEm NULL) recalcula na criação/edição/cron; digitar encargo à mão
     TRAVA (congela) e o motor completa os honorários. Isso já está no commit f33b538.

6) MODO: workflow do projeto. Subagentes read-only para investigar/revisar; feature-implementer em worktree
   isolada (contratos congelados e COMMITADOS antes do fan-out); orquestrador integra por cherry-pick individual,
   roda testes direcionados, estabiliza. Verificação de dinheiro = subagente independente. SMOKE visual é seu
   (subagentes não têm navegador). Commit local OK; push/merge/deploy = HUMANO. Ao fim de cada ajuste: smoke +
   suíte direcionada + global + /review sem bloqueante + commit + atualizar ledger/memória/HANDOFF.

7) GOTCHAS: tudo no container jusprime_php_dev (phpunit/cache com -d memory_limit=512M; nunca php fora); banco de
   teste saas_test = schema:create — recriá-lo quebra ImportarRelatorioCarteiraTest::testIndiceUnicoBloqueia...
   até rodar à mão CREATE UNIQUE INDEX uniq_cobranca_obrigacao_ref_externa ON cobranca_obrigacao (caso_id,
   referencia_externa) WHERE referencia_externa IS NOT NULL; migrations: colisão de número = falha silenciosa;
   8 worktrees de agentes ainda listadas em `git worktree list` (o harness limpa ao encerrar — não forçar remoção).

Comece: carregue o workflow, leia a memória/handoff/ledger/spec, confirme o git e a baseline, faça o SMOKE do
Ajuste 1, e então siga para o Ajuste 2. Pare só no que exige decisão humana (o estilo do card no Ajuste 3;
push/merge/deploy) ou em bloqueante real.
```
