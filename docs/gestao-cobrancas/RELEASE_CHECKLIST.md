# RELEASE CHECKLIST — Gestão de Cobranças (App\Cobranca)

> Checklist final para o **humano** revisar antes de **merge → deploy**. A implementação (Etapas 0–9) está
> concluída na branch `gestao-cobrancas`. Este documento **não executa** merge/push/deploy — é um guia de
> verificação. Última atualização: 2026-07-10.

## 0. Estado da entrega (validado contra Git + testes)

| Item | Valor | Como confirmar |
|---|---|---|
| Branch | `gestao-cobrancas` (local, **não pushada**) | `git branch --show-current` |
| HEAD | `b90f24f` (docs) ← `3cd426a` (impl. E9) ← `c80b4e5` (spec E9) | `git log --oneline -3` |
| Etapas | **0–9 concluídas** (implementação fechada) | `docs/gestao-cobrancas/EXECUTION_STATUS.md` |
| `tests/Cobranca` | **398/398** | `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'` |
| Suíte global | **1679/1679** | `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit'` |
| Working tree | limpo (untracked só `.claude/worktrees/` + `.xlsx` TOPLIFE gitignorados) | `git status --short` |
| Docs vivos | atualizados (EXECUTION_STATUS, SESSION_HANDOFF, NEW_CHAT_PROMPT) | — |
| Push / deploy | **não feitos** (proibidos p/ o agente) | — |

---

## 1. Estado das migrations

**Etapas 8 e 9 NÃO adicionaram nenhuma migration** (são camada HTTP/visual/leitura). Última migration do módulo
= `Version20260710160000` (Etapa 7). Migrations de Cobranças aplicadas em **dev/test**:

| Migration | Etapa | O que faz |
|---|---|---|
| `Version20260709123952` | E2 | tabelas `cobranca_caso`, `cobranca_obrigacao`, `cobranca_evento_historico` |
| `Version20260709142845` | E3 | `cobranca_pagamento` (+`cobranca_alocacao_pagamento`), `cobranca_liquidacao` |
| `Version20260709154458` | E4 | `cobranca_acordo` + ALTER `cobranca_obrigacao` (FKs acordo) |
| `Version20260709191327` | E5 | ALTER `cobranca_caso` (`pasta_judicial_id`) + `cobranca_proxima_acao` + `cobranca_revisao_pessoa_cobrada` |
| `Version20260709215805` | E6 | `cobranca_secao` + `cobranca_documento` |
| `Version20260710130000` | E7 | índices funcionais de dedup (dígitos CPF/CNPJ) |
| `Version20260710160000` | E7 | índice PARCIAL ÚNICO de idempotência da importação |

*(As migrations E0/E1 de fundação e cadastro entram na mesma faixa `Version20260701*`/`Version2026070312*` da branch — conferir `git log --stat app/migrations/` se precisar do inventário completo.)*

**Ação do humano:**
- [ ] Confirmar que **nenhuma migration de Cobranças insere permissões** (verificado: nenhuma faz `INSERT INTO permission`; as permissões vivem só no `PermissionFixture`, que **não roda em prod**). → ver §2.
- [ ] Em prod, o `entrypoint` roda `doctrine:migrations:migrate` sozinho no deploy (imagem baked). Nenhuma dessas migrations é destrutiva de dados existentes (todas criam tabelas novas do módulo).

---

## 2. Data-migration de permissões p/ PRODUÇÃO — **BLOQUEADOR Nº 1**

O módulo usa 4 códigos de permissão, hoje presentes **apenas** no `app/src/DataFixtures/PermissionFixture.php`
(dev/test). **Fixtures não rodam em produção** → sem uma data-migration, os papéis não-`isSystem` de prod não
conseguem receber acesso ao módulo, e o item de menu "Cobranças" fica invisível para usuários comuns.

Códigos que precisam existir no catálogo `permission` de prod:

| code | group | usado por |
|---|---|---|
| `modules.cobrancas.view` | `modules` | gate de módulo em TODAS as rotas (leitura inclusive) |
| `resources.cobranca.gerenciar` | `resources` | operar caso: obrigação, ação, revisão, acordo, objeto, pessoa, vínculo, abrir caso, encerrar, judicializar, documentos, importação |
| `resources.carteira.gerenciar` | `resources` | criar/configurar carteira |
| `resources.cobranca.movimentacao_financeira` | `resources` | pagamento, liquidação, correção (capacidade SEPARADA) |

**Padrão a seguir** (idêntico ao que o DJEN usou em `app/migrations/Version20260706195821.php`, linhas ~121-133):
insert idempotente no catálogo, sem auto-conceder a papéis (a concessão a papéis é feita depois pelo admin na UI).

```sql
-- up() — repetir para os 4 códigos
INSERT INTO permission (code, description, "group")
SELECT 'modules.cobrancas.view', 'Acesso ao módulo Gestão de Cobranças', 'modules'
WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = 'modules.cobrancas.view');
-- ... resources.cobranca.gerenciar / resources.carteira.gerenciar / resources.cobranca.movimentacao_financeira

-- down() — remove concessões e o catálogo (defensivo)
DELETE FROM tenant_role_permission WHERE permission_id IN (SELECT id FROM permission WHERE code IN (...));
DELETE FROM permission WHERE code IN ('modules.cobrancas.view', 'resources.cobranca.gerenciar', 'resources.carteira.gerenciar', 'resources.cobranca.movimentacao_financeira');
```

**Ação do humano (fora do escopo deste agente — NÃO foi criada):**
- [ ] Criar a data-migration idempotente com os 4 `INSERT ... WHERE NOT EXISTS` (espelhar `Version20260706195821`).
- [ ] Após o deploy, **conceder** as permissões aos papéis desejados via UI de papéis (`TenantRoleController`), por escritório. Super-admin (`isSystem` / `ROLE_SUPER_ADMIN`) já enxerga o módulo por bypass, mas operadores comuns dependem da concessão.

---

## 3. Ordem segura de merge (considerando DJEN e master)

**Fatos confirmados (read-only):**
- DJEN `b044c0c` está **na base da branch** `gestao-cobrancas` (é ancestral de `HEAD`) **E já está em `origin/master`**.
  → O merge de `gestao-cobrancas` **não duplica** o DJEN: o git vê `b044c0c` já no master e aplica só o delta de Cobranças.
- `origin/master` (ref local, possivelmente defasada) está em `8936379` — já contém DJEN **e** a integração do
  Sincronizador de Drive (`0e64dce`, `8936379`). A branch de Cobranças foi criada de um master **anterior** a isso.
- A branch carrega, "de carona", os commits **metas** `6ffb820` e **Datajud/CNJ** `b9de2b7` — eles vão junto no merge.

**Ordem recomendada (o humano executa manualmente no terminal externo):**
```bash
# Execute manualmente no terminal externo — o agente NÃO faz push/merge
cd /home/prime/projetos/jusprime
git fetch origin
git switch gestao-cobrancas
git merge origin/master        # traz DJEN(já) + sync-drive p/ dentro da branch; resolver conflitos prováveis
#   conflitos esperados em: composer.lock, config/services.yaml, config/packages/doctrine.yaml,
#   app/src/DataFixtures/PermissionFixture.php, ordenação de migrations — resolver semanticamente
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit'   # deve seguir verde
git push -u origin gestao-cobrancas
# abrir PR gestao-cobrancas -> master, revisar, então merge
```
- [ ] Garantir que o DJEN já está em master (está: `b044c0c` ∈ `origin/master`). **Não** re-mergear DJEN isolado.
- [ ] **Não** reconstruir a branch do zero (cherry-pick sobre master-sem-DJEN daria conflito garantido).
- [ ] Decidir conscientemente se metas `6ffb820` e Datajud `b9de2b7` devem ir juntos (vão, salvo separação explícita).

---

## 4. Testes manuais recomendados (smoke no navegador)

**Pré-requisito:** dev **não tem dados de Cobrança** (módulo novo, ausente do dump de prod). É preciso **semear um
grafo realista** primeiro (carteira → objeto → pessoa → vínculo → caso → obrigações → 1-2 pagamentos/1 liquidação →
1 acordo → 1 próxima ação atrasada → 1 revisão pendente → 1 caso judicializado → 1 caso encerrado). Sem isso as
telas mostram só empty states. Rotas do módulo (todas sob `/cobrancas`, gate `modules.cobrancas.view`):

### 4.5 Fluxo de importação visual (Etapa 8C-A)
- [ ] `GET /cobrancas/carteiras/{id}/importar` → tela de upload (exige `resources.cobranca.gerenciar`).
- [ ] Upload de `.xlsx` TOPLIFE → **prever** (`/importar/prever`): mostra preview (dry-run, **não persiste**);
      **linha só-encargos aparece REJEITADA com motivo** (decisão E7 — não deve criar obrigação principal-zero).
- [ ] **Confirmar** (`/importar/confirmar`): persiste; reimportar o MESMO arquivo é **idempotente** (não duplica).
- [ ] Arquivo temporário isolado por tenant (`import-tmp/<tenantId>/...`) some após confirmar.

> **✅ SMOKE REAL EXECUTADO NO DEV (2026-07-10):** os dois relatórios reais foram importados no tenant 1
> (super-admin `farlei.rocha@gmail.com`): carteiras **TOPLIFE I** (credor id 5) e **TOPLIFE II** (id 6).
> Resultado: **TOPLIFE I → 2826 obrigações / 81 casos importados, 100 rejeitadas** (linhas só-encargos, decisão
> E7 comprovada) · **TOPLIFE II → 444 / 0 rejeitadas**. Total no tenant: **2 carteiras, 194 casos, 194 objetos,
> 3270 obrigações, 207 pessoas.** Painel, Casos, Central de Alertas e o detalhe do Caso renderizaram com os
> dados reais (honorários projetados = 10% do saldo, alertas "Obrigação exigível vencida" agregados por
> carteira, tabela de obrigações com encargos/valor atual). **A prévia (dry-run) foi validada pela UI real** no
> navegador; a **persistência do TOPLIFE I foi concluída via CLI** por causa do achado de timeout abaixo.

> **⚠️ ACHADO — importação grande estoura o timeout de request (BLOQUEADOR OPERACIONAL p/ prod):** importar
> ~2800 obrigações num **único request HTTP síncrono** excede o `proxy_read_timeout` do nginx do dev (~60s →
> **504 Gateway Timeout**; o worker do FrankenPHP fica bloqueado e derruba requests paralelos). No dev foi
> preciso subir `memory_limit`/`max_execution_time` do PHP **e** rodar a confirmação por fora do request web.
> **Antes do deploy, para relatórios grandes:** (a) aumentar `proxy_read_timeout`/`fastcgi_read_timeout` no
> nginx de prod e `max_execution_time`/`memory_limit` do PHP para a rota de importação; e/ou (b) tratar a
> importação de forma **assíncrona/em lote** (fila) — recomendado como follow-up para volumes desse porte. Os
> arquivos TOPLIFE reais têm ~3963 e ~472 linhas; o maior é o que estoura.

### 4.6 Upload / drag-drop / file-manager de documentos (Etapa 8C-B)
- [ ] `GET /cobrancas/casos/{id}` → aba **Documentos** renderiza o file-manager (`pasta-arquivos.js` religado por `data-*`).
- [ ] Criar seção (`/casos/{id}/secoes`), renomear, excluir.
- [ ] **Drag/drop e upload com barra de progresso** (`/casos/{id}/documentos`) — validar mimetype/extensão/tamanho.
- [ ] Mover documento entre seções (`/documentos/{docId}/mover`), reordenar (`/casos/{id}/documentos/reordenar`), excluir, download (`/documentos/{docId}/download`).
- [ ] Leitor SEM `gerenciar`: vê lista read-only + download, **sem** o file-manager de escrita.
- [ ] Documento permanece no Caso ao judicializar (INV-25 — não migra para Pasta).

### 4.7 Painel e Central de Alertas (Etapa 9)
- [ ] `GET /cobrancas/painel` (`cobranca_dashboard`): visão financeira (saldo aberto/vencido, recuperado no período,
      honorários projetados/realizados), operacional (pagamentos a verificar, ações atrasadas, parcelas vencidas,
      revisões, judicializadas — cards linkam para alertas/casos), resultado (taxa de recuperação, total recuperado,
      em aberto, objetos inadimplentes vs casos ativos). Filtro por **carteira** e **período**.
- [ ] `GET /cobrancas/alertas` (`cobranca_alertas`): chips por tipo + grupos por carteira com badges de alerta; links
      para o caso. Filtro por carteira.
- [ ] Números batem com o grafo semeado; **valores formatados em reais** (`|centavos`); tema claro/escuro OK; empty
      states quando não há casos/alertas.

---

## 5. Permissões por perfil (verificar em prod após §2)

| Perfil | Vê menu Cobranças / painel / alertas / listas | Opera caso (obrigação, ação, acordo, docs, importação) | Cria/config carteira | Movimentação financeira |
|---|---|---|---|---|
| **Super-admin** (`isSystem` / `ROLE_SUPER_ADMIN`) | ✅ (bypass) | ✅ | ✅ | ✅ |
| Operador com `modules.cobrancas.view` só | ✅ (leitura) | ❌ | ❌ | ❌ |
| + `resources.cobranca.gerenciar` | ✅ | ✅ | ❌ | ❌ |
| + `resources.carteira.gerenciar` | ✅ | (conforme acima) | ✅ | ❌ |
| + `resources.cobranca.movimentacao_financeira` | ✅ | (conforme acima) | (conforme acima) | ✅ |

- [ ] Confirmar que capacidades são **independentes**: financeiro sem `gerenciar` e vice-versa (provado por `CapacidadeSeparacaoControllerTest`).
- [ ] Judicializar exige **também** `can_access_module('pastas')` no controller (além de `gerenciar`).
- [ ] Leitura (index/show/painel/alertas) exige só o módulo — sem capacidade (espelha 8A GET).

---

## 6. Isolamento tenant / IDOR (revisado — SEM bloqueante)

Coberto por testes DB-real + revisão adversarial + tenant-safety scan em TODAS as etapas. Pontos de garantia:
- [ ] Toda listagem/agregação filtra `WHERE tenant` explícito (`doTenant`, `findByFilters`, `daCarteira`, `opcoes*`).
- [ ] Toda rota resolve entidade por `findOneByIdDoTenant($id, $tenant)` → **404** antes de qualquer efeito (anti-IDOR); nenhum `find()`/`findOneBy(['id'])` cru.
- [ ] Selects escopados = `Repository::opcoesDoTenant`/`opcoesFacetaDoTenant` + `ChoiceType` (nunca `EntityType`); defesa dupla (ChoiceType rejeita id fora do escopo + UseCase revalida por tenant).
- [ ] Dashboard/alertas (E9): agregados nunca somam/mostram casos de outro escritório (provado por `testTenantScoping` DB + não-vazamento HTTP `testAlertasNaoVazaOutroTenant`/`testPainelNaoVazaValoresDeOutroTenant`).
- [ ] Uploads de documentos isolados fisicamente por tenant no disco: `cobrancas/<tenantId>/<hash>`.
- [ ] CSRF: mutações via Symfony Form (automático) ou token nomeado (file-manager AJAX). ⚠️ CSRF **stateless** (`stateless_token_ids:[submit]`) valida same-origin — não usar referer externo em teste.

---

## 7. Riscos conhecidos

| Risco | Severidade | Mitigação |
|---|---|---|
| **Permissões ausentes em prod** (§2) | ALTA (funcional) | Data-migration idempotente + concessão via UI. Sem isso o módulo fica inacessível a operadores comuns. |
| Merge conflita com sync-drive/DJEN já em master (§3) | MÉDIA | `git merge origin/master` na branch primeiro, resolver conflitos semânticos, rodar suíte, só então PR. |
| ~~Dev sem dados → smoke JS não exercitado~~ | RESOLVIDO | ✅ Smoke real feito no dev com os relatórios TOPLIFE reais (§4.5): Painel, Casos, Alertas e detalhe do Caso validados no navegador. |
| **Importação grande estoura timeout de request HTTP (504)** (§4.5) | **ALTA (operacional)** | Aumentar `proxy_read_timeout` (nginx) + `max_execution_time`/`memory_limit` (PHP) na rota de importação em prod, e/ou importação assíncrona em lote. ~2800 obrigações num request só não passam no timeout padrão. |
| Perf O(casos) do dashboard sob volume alto | BAIXA | Aceito p/ MVP; filtros carteira/período limitam; follow-up de agregação materializada. |
| Temporário órfão de importação (preview 2× sem confirmar) | BAIXA (disco) | Follow-up: comando de limpeza por idade. |
| Deploy prod é imagem **baked** (não bind-mount) | Operacional | Rebuild obrigatório via `deploy-prod-tls.sh`; `git pull` na VPS NÃO aplica nada. |

---

## 8. Comandos seguros de validação (antes do deploy — read-only, não alteram prod)

```bash
# Estado do repo
git status --short && git log --oneline -5

# Testes (no container dev) — devem ficar verdes
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'   # 398/398
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit'                   # 1679/1679

# Rotas do módulo registram sem erro
docker exec jusprime_php_dev bash -c 'cd app && php bin/console debug:router | grep cobranca_'

# Sanidade de container/config (dev)
docker exec jusprime_php_dev bash -c 'cd app && php bin/console lint:container'
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:schema:validate'   # mapping vs schema

# Migrations pendentes no dev (informativo)
docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:migrations:status'
```

**⚠️ Falsos-positivos ESPERADOS nessas validações (pré-existentes, benignos, NÃO são de Cobrança — não alarmar):**
- `doctrine:schema:validate` acusa **"schema not in sync"** por 3 índices por EXPRESSÃO do E7
  (`idx_cobranca_pessoa_tenant_cpf_digitos`, `..._cnpj_digitos`, `uniq_cobranca_obrigacao_ref_externa`). O ORM não
  modela índices funcionais/parciais → ele os reporta como "sobrando" e sugere `DROP`. **Não executar o DROP** —
  os índices são intencionais (criados via SQL nas migrations `Version20260710130000`/`160000`, com aviso de drift
  embutido). É ruído cosmético do validador, não um problema de schema.
- `doctrine:migrations:status` mostra **~2 "not migrated"** no dev (`Version20260401000000` do Ponto e
  `Version20260408180237`) — são **migrations-fantasma do dev** (o banco de dev veio de dump/`schema:create`, não do
  replay completo). **Dev-only**, sem relação com Cobrança; em prod as migrations são aplicadas em ordem sobre a
  imagem baked, sem esse artefato.
- `lint:container` deve dar **OK** (validado).

---

## 9. Checklists por fase

### 9.1 Pré-merge
- [ ] Suíte global verde (1679/1679) e `tests/Cobranca` (398/398) no HEAD a mergear.
- [ ] Working tree limpo; docs vivos coerentes com o HEAD.
- [ ] `git merge origin/master` na branch resolvido (DJEN + sync-drive incorporados), suíte segue verde.
- [ ] Decisão sobre caronas (metas `6ffb820`, Datajud `b9de2b7`) tomada.
- [ ] Data-migration de permissões (§2) criada e incluída no merge (ou combinada como passo de deploy).

### 9.2 Pré-deploy
- [ ] Branch mergeada no master (após PR aprovado).
- [ ] Backup do banco de prod feito (procedimento padrão `backup.sh`).
- [ ] Data-migration de permissões presente na cadeia de migrations que o deploy vai aplicar.
- [ ] `.env.prod` na VPS conferido (não usar a cópia dev — está stale).
- [ ] Nenhuma migration destrutiva de dados existentes (as de Cobranças só criam tabelas do módulo).

### 9.3 Deploy
- [ ] Rodar `./scripts/deploy-prod-tls.sh` na VPS (rebuild + recreate; entrypoint roda warmup + migrations).
- [ ] Prod é imagem baked (containers sufixo `_prod`); `-w /var/www/app` no `docker exec` para `bin/console`.

### 9.4 Pós-deploy
- [ ] `doctrine:migrations:status` em prod: todas aplicadas, inclusive a de permissões.
- [ ] Verificar catálogo: `SELECT code FROM permission WHERE code LIKE '%cobranca%' OR code = 'modules.cobrancas.view';` → 4 linhas.
- [ ] Conceder permissões aos papéis por escritório via UI.
- [ ] Smoke em prod com um usuário real: menu Cobranças aparece; painel/alertas/carteiras/casos carregam; criar uma carteira/objeto/caso de teste; importar um relatório pequeno; upload de um documento.
- [ ] Conferir isolamento: um usuário de outro escritório não vê os dados criados.
- [ ] Monitorar logs (nginx/php `_prod`) por erros 500 nas rotas `cobranca_*`.

---

## 10. Rollback / observações

- **Sem migration destrutiva:** as migrations de Cobranças criam tabelas novas do módulo; o rollback de código
  (reverter o merge) não perde dados de outros módulos. A data-migration de permissões tem `down()` que remove as
  concessões e o catálogo — reversível.
- **Rollback de deploy:** re-deploy da imagem anterior via `deploy-prod-tls.sh` a partir do commit anterior do master
  (procedimento padrão da VPS). As tabelas `cobranca_*` podem permanecer vazias sem afetar os demais módulos.
- **Feature isolada:** o módulo é aditivo (novo domínio `App\Cobranca`, rotas sob `/cobrancas`, menu gated). Não
  altera comportamento dos módulos existentes; o principal ponto de contato é o `PermissionFixture` (catálogo) e a
  FK unidirecional `cobranca_caso.pasta_judicial_id → pasta` (SET NULL; `PastaController` intocado).
- **Não** limpar as worktrees `.claude/worktrees/` automaticamente (limpeza é decisão do humano — follow-up #6 do EXECUTION_STATUS).

---

## 11. Pendências NÃO bloqueantes (follow-ups)

- Perf O(casos) do dashboard (~6–8 queries/caso) — agregação materializada se escalar.
- Coletor de temporários órfãos de importação (preview 2× sem confirmar) — comando de limpeza por idade.
- FIFO de alocação de pagamento (#8, adiado) — hoje alocação manual.
- Endurecer testes de evento de histórico (asserir `tipo`+`dados`) — #1/#10/#11 do EXECUTION_STATUS.
- Índice único "máx. 1 próxima ação pendente" no banco (#13) — hoje garantido por check no UseCase.
- Cobertura positiva granular de `gerenciar` no cadastro (separação/negação já provadas).
- Limpeza das branches/worktrees de agente `worktree-agent-*` (#6) — decisão do humano.

---

## 12. Fora do escopo (reafirmado)
Não foi feito **push**, **merge**, **deploy** nem alteração em produção. Nenhuma migration nova foi criada (a de
permissões do §2 é responsabilidade do humano). Não iniciar o futuro domínio Financeiro (§19/§24 da SPEC).
