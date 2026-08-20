# HANDOFF — Pastas aninhadas no gerenciador de arquivos

**Pausado em 2026-08-19.** Frente `pasta-subpastas-aninhadas`, worktree própria, **nada publicado**.

**Para retomar, leia nesta ordem:**

1. Este arquivo (onde parou e o que decidir)
2. `docs/specs/pasta-subpastas-aninhadas.md` — a spec, autoridade vinculante
3. `docs/superpowers/plans/2026-08-19-pastas-aninhadas.md` — o plano, 10 tarefas
4. `.superpowers/sdd/2026-08-19-pastas-aninhadas/progress.md` — o ledger, com **todos os rulings**

---

## 1. O que o dono pediu

> *"é possivel criar pasta dentro de pasta dentro de pasta nas pastas do expediente?"* → **não era**
> *"vamos abrir essa frente, preciso de mais niveis de pastas dentro de pastas, assim como o drive faz nativamente"*
> *"o pessoal aqui está precisando organizar os arquivos e precisa colocar pasta dentro de pasta no sistema"*

A última frase mudou o fatiamento: é demanda de uso corrente, não melhoria especulativa.

## 2. As decisões do dono (não reabrir sem ele)

| # | Decisão |
|---|---|
| D1 | A árvore espelha o Drive **nos dois sentidos** — mas isso é a Entrega 2, não a 1 |
| D2 | O passivo de 8.610 arquivos achatados é realinhado em **fatia própria**, com dry-run conferido antes |
| D3 | Apagar pasta com conteúdo **apaga a árvore**, com aviso que **conta** o que vai sumir |
| D4 | Modelo por **auto-referência**, teto de **10 níveis** |
| D5 | **Entrega 1 = só o sistema.** O Drive fica para a Entrega 2 |
| D6 | Mover pasta: **arrastar E menu**, os dois |

## 3. Estado: 6 de 11 tarefas fechadas (Task 0 + Tasks 1-5)

| Tarefa | Estado | Commits |
|---|---|---|
| 0 — abrir a frente | ✅ | `ac2b1ac9` |
| 1 — entidade `secao_pai_id` + migration | ✅ revisão limpa | `9e75453c`, `599298ac` |
| 2 — repository (ordem por irmãs, contagem recursiva) | ✅ revisão limpa | `b43adf3d`, `38004b3b` |
| 3 — criar pasta dentro de pasta | ✅ revisão limpa | `1cf7ce52`, `a11b25de` |
| 4 — mover pasta (guards de ciclo e teto) | ✅ revisão limpa | `6bf8c757`, `3457224f` |
| 5 — reordenar entre irmãs | ✅ revisão limpa | `b59cbc71`, `603a40ce` |
| 6 — controller | ⏳ brief pronto | — |
| 7 — template | ⏳ brief pronto | — |
| 8 — JavaScript | ⏳ brief pronto | — |
| 9 — regressão do sync | ⏳ brief pronto | — |
| 10 — fechamento + smoke | ⏳ brief pronto | — |

**Suíte:** **483/483** em `tests/Pasta`; 3888/3888 na suíte completa da frente (medido na Task 1).

**Worktree limpa**, sem alteração pendente. A próxima tarefa é a **6 (controller)**.

## 4. Como retomar

```bash
# a frente já existe — NÃO rode frente-abrir.sh de novo
cd /home/prime/projetos/jusprime
git -C .claude/worktrees/pasta-subpastas-aninhadas log --oneline -5

# testar (SEMPRE a partir da raiz do repo principal)
scripts/frente-testar.sh pasta-subpastas-aninhadas --filter ReordenarSecoesUseCaseTest
scripts/frente-testar.sh pasta-subpastas-aninhadas tests/Pasta
```

Os **briefs de todas as 10 tarefas** já estão gerados em
`.superpowers/sdd/2026-08-19-pastas-aninhadas/task-N-brief.md`. Para regerar depois de editar o plano:

```bash
bash ~/.claude/plugins/cache/claude-plugins-official/superpowers/6.3.0/skills/subagent-driven-development/scripts/task-brief \
  docs/superpowers/plans/2026-08-19-pastas-aninhadas.md <N>
```

### O ciclo que estava sendo usado

Por tarefa: dispatch `feature-implementer` (sonnet) com o brief → revisão `feature-review-agent` (sonnet) com o pacote de diff → fix round resumindo o **mesmo** implementador → re-review escopado (haiku) → fechar no ledger. Nenhum implementador em paralelo.

## 5. 🔴 O que NÃO pode ser esquecido ao retomar

### 5.1 A Task 6 carrega um defeito que a nossa feature introduz

`PastaSecaoController::excluir()` limpa do disco **só** `$secao->getDocumentos()` — os documentos diretos. Com a árvore e o cascade da Task 1, apagar uma pasta remove do **banco** os documentos de filhas e netas, mas deixa os **arquivos físicos delas órfãos no disco para sempre**.

Não existia antes porque não havia netas. **O plano da Task 6 já tem o `limparArquivosDaArvore()` recursivo** — não deixe cair. Nenhum teste falharia se caísse: o sintoma seria `uploads/` crescendo sem explicação.

### 5.2 A convenção de teste que a frente criou

Guards em cadeia exigem **teste de violação combinada**. Quando as duas violações produzem a **mesma classe** de exceção, é obrigatório `expectExceptionMessage` — senão o teste não distingue qual guard disparou. Vale para qualquer tarefa restante que acrescente guard.

### 5.3 Prova por reintrodução não é opcional

Cada guard só conta como provado se, removido, o teste correspondente ficar **vermelho pelo motivo certo** (a exceção que não veio — não um `TypeError` colateral). Isso já pegou dois testes que passavam por acidente.

### 5.4 O smoke é do dono

A Task 8 (JavaScript) **não tem teste automatizado** — o projeto não tem suíte de JS. A lista de 10 pontos de smoke está na Task 10 do plano. **Não abrir o navegador por conta própria.**

## 6. ⏳ Pendências que precisam do dono

| # | O que | Por quê |
|---|---|---|
| 1 | **Mock de `EntityManagerInterface`** | `app/tests/CLAUDE.md:72` proíbe explicitamente, mas **todo o domínio Pasta já faz isso**. Decidi seguir o padrão do arquivo em vez de refatorar (mudança arquitetural, fora do escopo). Se o dono discordar, vira frente própria. |
| 2 | **Migration já aplicada em `saas_ux`** | A coluna `secao_pai_id` foi aplicada no banco da tela para permitir o smoke. Se a frente for abandonada, sobra uma coluna órfã (removível com um `ALTER`). |
| 3 | **Colisão com a frente `expediente-ux`** | As duas tocam `app/templates/pasta/show.html.twig`. Quem integrar por último traz o master para dentro e roda a suíte **antes** do merge. |

## 7. Pendências menores registradas (não bloqueiam)

- `LIMITE_SEGURANCA = 100` na entidade não tem teste de ciclo real.
- `contarConteudoRecursivo()` recursa sem trava anti-ciclo (as outras travessias da mesma entidade têm).
- A chave de agrupamento `getPai()?->getId() ?? 'raiz'` colocaria um pai **não persistido** no grupo da raiz — não ocorre no caminho atual.
- Nome de constraint `FK_PASTA_SECAO_PAI` vira minúscula no Postgres (cosmético).

## 8. Números medidos em PROD (2026-08-19, via MCP)

Não re-medir sem motivo — estão na §2 da spec:

- 1.070 pastas · 623 seções · 189 pastas com seção
- 21.197 documentos: **12.587 na raiz** e 8.610 em seção
- **100% têm `drive_file_id`** — a árvore original ainda existe no Drive (é o que torna a Entrega 3 possível)
- **318 das 623 seções (51%)** têm prefixo numérico no nome — a hierarquia improvisada à mão
- Máximo de 62 seções numa pasta; média 3,3; p95 8,6
- Ritmo normal: **~200 arquivos/mês** (fora as duas cargas de acervo)

## 9. O que esta frente NÃO faz

Entrega 2 (Drive espelha a árvore) e Entrega 3 (realinhar o passivo) estão **desenhadas nas §8 e §9 da spec, não construídas**. Se você se pegar mexendo em `ReconciliadorDePasta.php` além do teste de regressão da Task 9, **parou de fazer a Entrega 1**.

O achado caro que a spec guarda: hoje as seções são casadas com o Drive **por nome**, e com árvore isso junta pastas homônimas de ramos diferentes — arquivo no lugar errado, sem erro. A correção (identidade por `drive_folder_id`, com adoção pelo nome na primeira passagem) está na §8.2.

## 10. Aviso operacional

Havia **5 sessões ativas** neste repositório durante o trabalho, e uma delas commitou 6 vezes no `master`. Isso produziu um susto de "commits sumiram" que era falso alarme — `git branch --contains` provou que estavam lá. **Ao retomar, confirme a base antes de commitar** e prefira `--contains` ao log linear.
