# Ajustes pós-taxa (Cobrança) — Índice de execução

> **7 ajustes** pedidos pelo dono, **todos com spec escrita** nesta branch isolada. **ZERO código de feature ainda.**
> Aguardando o **chat 2** (implementação da taxa por-obrigação em `cobranca-encargos-cascata`) terminar para
> executar. Nada de push/merge/deploy — é do humano.

## Onde está tudo

- **Branch isolada:** `cobranca-ajustes-pos-taxa` (worktree `.claude/worktrees/cobranca-ajustes-pos-taxa`,
  ramificada de `origin/master`). Commits aqui **não movem** `cobranca-encargos-cascata` (chat 2) nem
  `fix-folha-pre-admissao` (chat 3).
- Specs em `docs/specs/cobranca-*.md` (uma por ponto/cluster).

## Os 7 pontos (+#8 decidido)

| # | Spec | Risco | Base de implementação | Depende de |
|---|---|---|---|---|
| **1** Pessoa: qualificação + listas (endereço/tel/email) | `cobranca-pessoa-qualificacao-listas.md` | MÉDIO | independente (master/atual) | — |
| **2** Contato: opções de resultado + "Relato do Atendimento" | `cobranca-contato-resultado-relato.md` | BAIXO | independente | — |
| **4/5/6** Documentos: acordo + carteira + grampo no objeto | `cobranca-documentos-acordo-carteira-grampo.md` | MÉDIO | independente | reusa `EnviarDocumentoUseCase` |
| **7** Importar linhas de acordo como Acordo | `cobranca-importar-linhas-acordo.md` | MÉDIO/ALTO | **base de encargos** | importador mudou lá |
| **3** Esconder botões pagamento/liquidação | `cobranca-esconder-botoes-pagamento-liquidacao.md` | BAIXO | **base de encargos** | ⚠️ colide c/ Twig do chat 2 |
| **8** Honorários no acordo | *(sem spec — decidido)* | — | — | **acordo NÃO cobra honorários** (parcela nasce com honorários 0) |

## Ordem sugerida de execução

1. **Independentes primeiro** (sem esperar ninguém): **#1**, **#2**, **#4/5/6**. Podem sair em paralelo entre si
   (arquivos disjuntos) se houver worktrees separadas — senão, sequencial.
2. **Depois que o chat 2 (taxa) estabilizar/mesclar:** **#7** e **#3**, rebaseados sobre a base de encargos
   (ambos dependem de código que a taxa toca — importador e `objeto/show.html.twig`).

## Regras de execução (por ponto)

- Ciclo: **TDD** (teste primeiro) → implementar → **testes direcionados no container** → **/review**
  (`feature-review-agent`, contra a spec; ALTO/MÉDIO obrigatório) → corrigir → **commit local**.
- Container: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit ...'`.
- **Banco de teste `saas_test` é COMPARTILHADO** entre os chats (taxa, Ponto, estes ajustes). **Nunca** rodar a
  suíte completa ao mesmo tempo que outro chat — combinar antes (o `saas_test` é recriado e um derruba o outro).
- `saas_test` = schema:create → aplicar as colunas/tabelas novas por ALTER cirúrgico ou schema:create; não a cadeia.
- **Multi-tenant** em tudo: filtro por tenant + guard IDOR + teste cross-tenant (specs #1, #4/5/6, #7).
- Git: **commit local OK**; **push/merge/deploy = humano**. Um piloto de git por branch.

## Como retomar (chat novo, quando o chat 2 terminar)

1. Ler este índice + as specs dos pontos a executar.
2. Confirmar a base por ponto (independentes: master/atual; #7/#3: base de encargos já estabilizada).
3. Para cada ponto: `writing-plans` (gera o passo-a-passo TDD) → `subagent-driven-development`/`executing-plans`.
4. Trabalhar em **worktree isolada** (não no checkout que outro chat usa) e **serializar a suíte** com os demais.
5. Parar em "pronto pra o humano publicar". Migrações novas (Pessoa/listas; documentos; `numero_externo` do
   acordo) são aplicadas pelo humano no deploy.
