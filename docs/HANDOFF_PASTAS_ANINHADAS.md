# HANDOFF — Pastas aninhadas no gerenciador de arquivos

**ENTREGA 1 COMPLETA em 2026-08-19 — aguardando o smoke do dono.** Frente `pasta-subpastas-aninhadas`,
worktree própria, **nada publicado**. 18 commits, **3927/3927 verde**, worktree limpa.

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

## 3. Estado: TODAS as 11 tarefas fechadas + onda final de correção

| Tarefa | Estado | Commits |
|---|---|---|
| 0 — abrir a frente | ✅ | `ac2b1ac9` |
| 1 — entidade `secao_pai_id` + migration | ✅ revisão limpa | `9e75453c`, `599298ac` |
| 2 — repository (ordem por irmãs, contagem recursiva) | ✅ revisão limpa | `b43adf3d`, `38004b3b` |
| 3 — criar pasta dentro de pasta | ✅ revisão limpa | `1cf7ce52`, `a11b25de` |
| 4 — mover pasta (guards de ciclo e teto) | ✅ revisão limpa | `6bf8c757`, `3457224f` |
| 5 — reordenar entre irmãs | ✅ revisão limpa | `b59cbc71`, `603a40ce` |
| 6 — controller (+ arquivos órfãos) | ✅ revisão limpa | `7e4704e9`, `11229b2f` |
| 7 — template | ✅ revisão limpa | `df3be991` |
| 8 — JavaScript | ✅ revisão limpa (1 Crítico corrigido) | `ca7ed050`, `341b6892` |
| 9 — regressão do sync | ✅ verde de primeira | `891a81d8` |
| 10 — fechamento | ✅ suíte, lints e ritual feitos |
| **Onda final** (5 achados da revisão da branch) | ✅ todos ADDRESSED | `95ac9eb1` |

**Suíte:** **483/483** em `tests/Pasta`; 3888/3888 na suíte completa da frente (medido na Task 1).

**Worktree limpa.** O que falta é **do humano**: o smoke na tela (§11), o merge e o deploy.

⚠️ A frente está **21 commits atrás do `origin/master`**. Antes de integrar:
`git -C .claude/worktrees/pasta-subpastas-aninhadas merge origin/master`, resolver o que conflitar, e
**rodar a suíte de novo** — é o passo que todo mundo pula. A migration da frente
(`Version20260819175112`) é **posterior** à última do master (`Version20260819160000`) e as duas tocam
tabelas **diferentes** (`pasta_secao` × `permission`): sem colisão, conferido.

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


---

## 11. 🔬 A LISTA DE SMOKE — é isto que precisa dos seus olhos

A suíte não enxerga tela. **13 pontos**, do mais importante para o menos:

1. 🔴 **Arrastar uma pasta pela alcinha e soltar EM CIMA de outra** → ela entra na outra. Dê **F5** e
   confira que continua lá **e** que a ordem das irmãs ficou coerente. Repita 3× no mesmo par.
   *(É o item nº 1 porque a spec descrevia esse gesto errado, e a correção da corrida entre dois
   pedidos concorrentes não tem como ser testada automaticamente.)*
2. 🔴 **Arrastar um arquivo do computador** para cima de um cartão de pasta, **depois** de já ter
   arrastado alguma pasta na mesma visita → o arquivo sobe e **nenhuma pasta muda de lugar**.
   *(Era um defeito real: a pasta se movia sozinha, em silêncio.)*
3. **Apagar uma pasta que tem subpastas** → o aviso precisa **contar**: "contém 3 subpastas e 127
   arquivos". Confira o singular também (1 subpasta, 1 arquivo) e uma pasta vazia.
4. Criar pasta na raiz → aparece na raiz. Entrar nela e criar outra → aparece **dentro**.
5. Descer 3 níveis → o caminho no topo mostra os 3, e cada degrau volta para o nível certo.
6. Arrastar **pela alcinha** soltando **fora** de outro cartão → reordena, não move.
7. Menu ⋮ → **"Mover para..."** → o destino mostra o caminho completo. Escolher a raiz devolve a
   pasta ao topo.
8. Tentar mover uma pasta para dentro da própria filha → recusa com mensagem.
9. Buscar um arquivo 3 níveis abaixo → aparece, **e diz em que pasta está**.
10. **Apagar uma pasta com filhas e continuar navegando sem F5** → os cartões das filhas somem, e a
    busca não lista mais os arquivos delas.
11. **Aba Peticionar** → os dois campos de seção mostram o caminho (`Financeiro › 2026 › Notas`), não
    só o nome solto.
12. **Tela de documentos de um caso de Cobrança** → continua funcionando com um nível só.
13. Recarregar a página dentro de uma subpasta → volta para ela. *(Na primeira visita depois do
    deploy pode cair na raiz uma vez — é esperado, o formato guardado mudou.)*

## 12. ⚠️ Um aviso que vale mais que os outros

**Não use o botão "Importar" do Drive até a Entrega 2.**

A revisão final mediu no banco de dev: **27 grupos / 54 seções com nome repetido dentro da mesma
pasta**. O importador casa seções **pelo nome**, sem olhar hierarquia — então uma pasta do Drive pode
casar com uma seção aninhada e invisível, e o arquivo aterrissa no lugar errado **sem dar erro**.

Isso não é novo, mas a Entrega 1 é que torna nome repetido a norma. O cron roda só no sentido
`enviar` (que está provado por teste e continua achatando, como antes), então o dia a dia não é
afetado. O Importar é ação pontual — e é ela que deve esperar.
