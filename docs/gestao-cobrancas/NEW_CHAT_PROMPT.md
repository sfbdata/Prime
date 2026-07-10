# NEW_CHAT_PROMPT — Gestão de Cobranças

> Cole o bloco abaixo como primeira mensagem de um chat NOVO do Claude Code (sem contexto anterior) para retomar a feature com segurança.

---

```
Você vai continuar a feature "Gestão de Cobranças" do projeto JusPrime. NÃO confie em nenhum resumo; confirme tudo no repositório.

1) Leia integralmente, NESTA ordem (todos em docs/gestao-cobrancas/, exceto os CLAUDE.md):
   1. FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md  (a SPEC — fonte de verdade das regras; §23 invariáveis)
   2. PLAN.md                                  (10 etapas de implementação)
   3. PARALLELIZATION_MAP.md                   (ondas, paralelização, piloto de fan-out)
   4. EXECUTION_STATUS.md                       (panorama VIVO: onde parou, checklist 0–9, histórico, próxima ação exata)
   5. SESSION_HANDOFF.md                        (memória da última sessão: git, testes, próxima ação, ordem de retomada)
   6. AUTONOMOUS_EXECUTION_PROTOCOL.md          (o modo de fan-out autônomo aprovado — papéis, integração, proibições)
   7. Os CLAUDE.md aplicáveis: raiz /CLAUDE.md, app/src/CLAUDE.md, app/src/Entity|Repository|Controller|Shared/CLAUDE.md conforme a camada, app/tests/CLAUDE.md, docs/AUTORIZACAO.md.

2) Carregue a skill "workflow" antes de tocar em código.

3) Verifique o ESTADO REAL do repositório (não assuma):
   - git branch --show-current   (esperado: gestao-cobrancas)
   - git status --short          (limpo, salvo untracked .claude/worktrees/ e os .xlsx TOPLIFE gitignorados)
   - git log --oneline -8        (topo esperado: docs da Onda 8A `5950015` / feature da Onda 8A `3e20b3e`, OU POSTERIOR)
   - docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/console doctrine:migrations:status'  (migrations E1–E7 aplicadas em dev; Etapa 8 NÃO tem migration)
   - git worktree list
   - docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'   (esperado: 234/234)

4) COMPARE a documentação com o repositório. Se divergirem (commits a mais/menos, testes falhando, working tree suja, branch diferente), PARE e reporte a divergência antes de agir — corrija o entendimento a partir do Git, não dos docs.

5) A branch já está resolvida: trabalhe em gestao-cobrancas (master ficou só com DJEN; a feature vive só nesta branch). Não faça switch/rebase/move de commits por conta própria.

6) A Etapa 8 (Telas/UX) está DIVIDIDA em ondas. **Onda 8A (leitura) e Onda 8B fatias 8B-0/A/B/C (mutações caso-level: obrigações/encerrar, ação/tentativa/revisão, acordo) estão CONCLUÍDAS, testadas e revisadas** (HEAD `30e4cf4`, `tests/Cobranca` 288/288, GLOBAL 1569/1569). **Retome pela Onda 8B-D (financeiro: pagamento/corrigir/liquidação), depois 8B-E (cadastro+seleção)** — a "Próxima ação exata" do SESSION_HANDOFF.md tem o "padrão estabelecido" a replicar (NÃO reinventar) e os desafios específicos. NÃO refaça o que está pronto. A Onda 8C (importação visual + file-manager) e a Etapa 9 continuam pendentes — NÃO antecipe. Siga o AUTONOMOUS_EXECUTION_PROTOCOL.md; continue autonomamente sem pedir aprovação a cada passo — pare apenas para: (a) commit obrigatório antes de fan-out, (b) decisão de negócio bloqueante, (c) inconsistência grave SPEC/PLAN/código, (d) conclusão de onda/etapa, (e) ~90% de contexto (entrar em modo handoff).

7) Regras duras: sem push/deploy/produção; sem Git destrutivo (merge/rebase/reset/branch -D); cherry-pick só com um hash; sem expandir o MVP; sem criar o futuro domínio Financeiro. Git de escrita é local e limitado pelo hook block-git-writes.py. **Toda mutação da 8B: gate módulo `cobrancas` + capacidade via hasPermission (`resources.cobranca.gerenciar`/`carteira.gerenciar`/`cobranca.movimentacao_financeira`) + CSRF + findOneByIdDoTenant (IDOR) + controller fino.** Mantenha intacta a decisão da Etapa 7 (linha só-encargos é rejeitada, sem Obrigação principal-zero).

8) Ao se aproximar do limite de contexto, reescreva SESSION_HANDOFF.md e atualize EXECUTION_STATUS.md, deixe a working tree limpa e os commits locais criados, e encerre de forma controlada.
```

---

**Observações para quem cola o prompt:**
- O prompt não faz `push` nem deploy — só trabalho local e commits locais.
- Estado atual (2026-07-10): Etapa 8 Onda 8A + Onda 8B fatias 8B-0/A/B/C concluídas (HEAD `30e4cf4`); o próximo chat deve retomar por **8B-D (financeiro)**. Se quiser forçar outra frente, adicione uma linha ao final do bloco.
- Mantenha este arquivo e os demais versionados; eles são a ponte entre chats.
