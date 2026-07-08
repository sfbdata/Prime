# AUTONOMOUS_EXECUTION_PROTOCOL — Gestão de Cobranças

> Modo de trabalho autônomo **testado e aprovado** no piloto de fan-out (2026-07-08, commits `454bbf2`/`f6362f0`). Vale para esta feature e é consistente com `.claude/skills/workflow` e com o hook `.claude/hooks/block-git-writes.py` (commit `228c294`).

---

## Pipeline padrão

```
contratos estáveis → checkpoint (commit) → worktrees → implementação paralela
→ commit por implementador → revisão read-only → cherry-pick individual
→ teste direcionado → estabilização → próxima integração → suíte final
```

Regra de ouro: **o paralelismo está na ESCRITA; a integração é SERIAL** (uma por vez, testando entre cada uma).

---

## Papel do orquestrador (sessão principal)

**Pode:**
- criar commits locais (`git add` explícito + `git commit`);
- criar worktrees (`git worktree add`) e removê-las (`git worktree remove`);
- revisar (via `feature-review-agent`, read-only);
- integrar **um commit por vez**;
- `git cherry-pick <um hash>` — **apenas o hash**, sem `2>&1`/flags/range;
- `git cherry-pick --continue` / `git cherry-pick --abort`;
- rodar testes no container `jusprime_php_dev`;
- corrigir e estabilizar antes de seguir.

**Não pode:**
- `push`, `pull`, `deploy`, alterar produção;
- Git destrutivo: `merge`, `rebase`, `reset`, `revert`, `branch -D`, `checkout` de reescrita, `stash`, `tag -d`;
- integrar vários commits numa única operação (cherry-pick de range/múltiplos/`--no-commit`/`--stdin`/`--skip`);
- `commit --no-verify`/`-n`; override de `core.hooksPath`.

> O hook `block-git-writes.py` aplica exatamente esses limites. Se um comando for bloqueado, **não improvisar** — reformular na forma permitida (ex.: cherry-pick só com o hash) ou entregar o comando ao humano.

---

## Papel do `feature-implementer` (subagente em worktree)

**Pode:**
- trabalhar **somente na própria worktree** e no **próprio escopo** (arquivos designados);
- ler contratos congelados (entidades/enums/repos/migration/factories) — sem alterar;
- `git add` explícito dos arquivos que criou;
- criar **exatamente 1 commit** do próprio trabalho;
- reportar hash + arquivos + premissas.

**Não pode:**
- `cherry-pick`, `merge`, `rebase`, `reset`, `push`;
- integrar trabalho alheio;
- alterar contrato compartilhado (se precisar, **para e devolve ao orquestrador**);
- rodar testes que dependem do container (a worktree **não** é montada nele) — apenas escreve os testes; quem executa é o orquestrador após integrar.

---

## Integração (fluxo obrigatório, por commit)

1. **Revisar** o commit (`feature-review-agent`, read-only, via `git show <hash>` — o object store é compartilhado entre worktrees).
2. **Cherry-pick individual** do hash aprovado.
3. **Teste direcionado** da tarefa no container (`php bin/phpunit --filter <NomeDoTeste>`).
4. **Estabilizar** (corrigir se necessário) — só avançar com verde.
5. **Somente então** integrar o próximo commit. Nunca integrar o 2º antes de testar o 1º.

Ao fim da onda: **suíte do domínio** (`php bin/phpunit tests/Cobranca`) + verificações transversais (cross-tenant, `tenant-safety-review`).

---

## Conflitos

**Mecânico simples** (textual, sem ambiguidade de intenção):
- pode ser resolvido autonomamente;
- revisar `git status` e o **diff staged**;
- garantir que só a resolução necessária está staged;
- `git cherry-pick --continue`;
- testar.

**Semântico / arquitetural / de contrato:**
- **interromper** o fan-out;
- **não** escolher silenciosamente;
- `git cherry-pick --abort` se necessário;
- documentar o problema (em `EXECUTION_STATUS.md`);
- corrigir o contrato no **orquestrador** (contrato congelado → só o orquestrador muda);
- replanejar os dependentes antes de retomar.

---

## Paralelização (não maximizar agentes)

O número de agentes é **consequência das dependências reais**, não meta:
- **1 agente** quando o trabalho é acoplado (núcleo, migration única, serviço central);
- **2 agentes** quando há duas frentes de arquivos independentes;
- **3–4 agentes** somente com isolamento real de escopo e contratos já committados.

Pré-requisito de fan-out: os **contratos compartilhados** (entidades/enums/interfaces/migration/factories) precisam estar **committados** antes de delegar (worktrees ramificam do HEAD committado — uncommitted não chega aos implementadores).

---

## Operações proibidas (sempre)
- `push`, `deploy`, qualquer alteração de **produção**;
- comandos **destrutivos** de banco (drop/truncate/`fixtures:load` que purga o dataset real do dev);
- Git **destrutivo** (merge/rebase/reset/revert/branch -D/…);
- **expandir o MVP** além do escopo da SPEC (§24);
- criar antecipadamente o **futuro domínio Financeiro** (SPEC §19);
- reinterpretar as **regras invariáveis** (SPEC §23).

---

## Lições do piloto (aplicar sempre)
- `git cherry-pick` recebe **só o hash** — `2>&1`/flags fazem o hook bloquear.
- Implementadores **não** rodam `phpunit` (worktree fora do container); validação centralizada no orquestrador pós-integração.
- Migration é **exclusiva do orquestrador**; nenhum cluster a edita. Índices/colunas novos → migration do orquestrador.
- Guard **same-tenant** nos UseCases que cruzam entidades é obrigatório e deve ter teste que o exercite de fato (não confiar em defaults de factory).
- No dev, aplicar migration com `doctrine:migrations:execute --up <Versão>` (evita as migrations-fantasma do dump de prod); no test idem.
