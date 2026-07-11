# DEPLOY RUNBOOK — Cobranças + separação das caronas (2026-07-11)

> Sequência **executável** para levar o módulo Gestão de Cobranças (e as duas melhorias "de carona") a
> produção, na ordem correta. Complementa o `RELEASE_CHECKLIST.md` (que tem o detalhe de cada verificação).
> **push / merge / deploy são do humano** — o agente só preparou os comandos. Blocos marcados
> `# Execute manualmente no terminal externo` NÃO são rodados pelo agente.

## Estado de partida (verificado no repo)

| Item | Valor |
|---|---|
| Branch de trabalho | `gestao-cobrancas` (local, **não pushada**), HEAD `8a8f063` |
| `origin/master` (ref local) | `ed05def` (2026-07-11 — fresca; já tem DJEN + Sincronizador de Drive) |
| Migration de permissões (bloqueador nº1) | ✅ criada e commitada na branch: `Version20260711120000` (`8a8f063`), testada up/idempotente/down |
| Perf N+1 (P0–P4) | ✅ concluída e commitada (`091315c`…`51b23c2`) |
| Caronas (fora do master) | `6ffb820` (metas / Tarefas) · `b9de2b7` (Datajud/CNJ — consulta processual, ≠ DJEN) |
| DJEN `b044c0c` | já em `origin/master` (base compartilhada, inofensivo — não duplica no merge) |
| Suíte | `tests/Cobranca` 409/409 · global 1690/1690 |

## Ordem recomendada
1. **Caronas primeiro** (isoladas, sem bloqueadores) → deploy rápido e independente.
2. **Cobranças depois** (tem a migration de permissões; precisa do merge-com-master + mitigação do timeout de importação).

As duas frentes são independentes; a ordem acima é só para as caronas não ficarem reféns dos bloqueadores de Cobranças.

---

## FASE 1 — Caronas (deploy isolado)

Cherry-pick das duas melhorias soltas sobre o `master` atual, numa branch própria. Elas tocam domínios
distintos (Tarefas / Processo) — não conflitam com sync-drive/DJEN.

```bash
# Execute manualmente no terminal externo
cd /home/prime/projetos/jusprime
git fetch origin
git switch -c fixes-metas-datajud origin/master
git cherry-pick 6ffb820      # Notificar responsáveis/criador nos eventos de meta (Tarefas)
git cherry-pick b9de2b7      # Tratar falhas da consulta CNJ com mensagens amigáveis (Datajud/Processo)

# valida antes de publicar
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit'   # deve ficar verde

git push -u origin fixes-metas-datajud
# abrir PR fixes-metas-datajud -> master, revisar e MERGE
```

Deploy das caronas (na VPS de produção, após o merge no master):

```bash
# Execute manualmente no terminal externo — na VPS (prod)
cd <repo em prod, onde está o .env.prod>       # ex.: /opt/jusprime
git checkout master && git pull                 # traz o merge das caronas
./scripts/deploy-prod-tls.sh                     # rebuild + recreate do php; SEM migration nova nas caronas
```
> As caronas **não têm migration** — é só código. Smoke: notificações de meta disparando; consulta CNJ
> falha com mensagem amigável em vez de erro cru.

---

## FASE 2 — Cobranças (merge → push → deploy)

### 2.1 — Trazer o master para dentro da branch e resolver conflitos

```bash
# Execute manualmente no terminal externo
cd /home/prime/projetos/jusprime
git fetch origin
git switch gestao-cobrancas
git merge origin/master
```
Conflitos **esperados** (resolver semanticamente — combinar, não escolher cegamente):
- `composer.lock` — regenerar/aceitar a união das dependências.
- `config/services.yaml`, `config/packages/doctrine.yaml` — combinar os binds/mapeamentos de ambos.
- `app/src/DataFixtures/PermissionFixture.php` — manter os 4 códigos de Cobranças **e** o que veio do master.
- Ordenação/lista de migrations — nenhuma renumeração; só garantir que todas coexistem.

> **As caronas NÃO devem conflitar aqui:** seu conteúdo já está no master (via FASE 1) e também é ancestral
> da branch → git reconcilia como "mesma mudança dos dois lados". Se algum arquivo de carona acusar conflito,
> é conteúdo idêntico — manter qualquer um dos lados.

Após resolver:
```bash
# Execute manualmente no terminal externo
git add <arquivos resolvidos>
git commit                                        # conclui o merge
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit'   # deve seguir verde (>=1690)
git push -u origin gestao-cobrancas
# abrir PR gestao-cobrancas -> master, revisar e MERGE
```

### 2.2 — Antes do deploy: mitigar o timeout de importação (bloqueador operacional §4.5)

Importar arquivos grandes (~2.800 obrigações) num request HTTP único estoura o timeout do nginx (504).
**Antes de importar em prod**, fazer UMA das opções:
- (a) aumentar `proxy_read_timeout`/`fastcgi_read_timeout` no nginx de prod **e** `max_execution_time`/
  `memory_limit` do PHP para a rota de importação; **e/ou**
- (b) importar em lotes menores / de forma assíncrona (follow-up recomendado para volumes grandes).

> Não bloqueia o deploy do código — bloqueia **importar arquivos grandes** logo de cara. Arquivos pequenos
> passam normal.

### 2.3 — Deploy (na VPS, após o merge no master)

```bash
# Execute manualmente no terminal externo — na VPS (prod)
cd <repo em prod>                                # ex.: /opt/jusprime
# backup do banco antes (procedimento padrão)
./scripts/backup.sh                              # ou o procedimento vigente
git checkout master && git pull                  # traz Cobranças
./scripts/deploy-prod-tls.sh                     # rebuild; o entrypoint roda doctrine:migrations:migrate
                                                 # → aplica Version20260711120000 (permissões) automaticamente
```

### 2.4 — Pós-deploy (obrigatório)

```bash
# Execute manualmente no terminal externo — na VPS (prod)
# 1) confirmar que a migration de permissões entrou (4 linhas)
docker exec -w /var/www/app jusprime_php_prod php bin/console doctrine:migrations:status
docker exec jusprime_db_prod psql -U <POSTGRES_USER> -d <POSTGRES_DB> -c \
  "SELECT code FROM permission WHERE code IN ('modules.cobrancas.view','resources.cobranca.gerenciar','resources.carteira.gerenciar','resources.cobranca.movimentacao_financeira');"
```
- [ ] **Conceder as permissões aos papéis** de cada escritório via UI (tela de papéis / `TenantRoleController`).
      A migration só popula o CATÁLOGO — ninguém recebe acesso automaticamente (super-admin já vê por bypass).
- [ ] Smoke com usuário real: menu Cobranças aparece; painel/alertas/carteiras/casos carregam; criar
      carteira/objeto/caso; importar um relatório **pequeno**; upload de um documento.
- [ ] Isolamento: usuário de outro escritório não vê os dados criados.
- [ ] Monitorar logs `_prod` por 500 nas rotas `cobranca_*`.

---

## Pendências que ficam para DEPOIS do deploy (combinado)
- Atualizar o `RELEASE_CHECKLIST.md` (números 398→409 / 1679→1690; remover o follow-up "Central de Alertas N+1" já resolvido).
- Registrar no checklist o follow-up do **N+1 de autorização `user_tenant`** (`PermissionChecker`/`TenantContext`), pré-existente/transversal/MÉDIO risco — ver `PLANO_OTIMIZACAO_QUERIES.md` §1.1.

## O que o agente já fez (não precisa refazer)
- Migration de permissões criada, testada (up/idempotente/down no dev que espelha prod) e commitada — bloqueador nº1 fechado.
- Perf N+1 (P0–P4) concluída, revisada e commitada.
- Caronas identificadas e confirmadas fora do master.
