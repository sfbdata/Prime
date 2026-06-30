# Runbook de Deploy em Produção — Remediação Multi-Tenant

> **Produção = bluejus.com.br.** Este é o checklist único a seguir ANTES e DURANTE o deploy da
> remediação multi-tenant. Consolida as notas de deploy de todas as frentes (specs individuais em
> `docs/specs/*-isolamento-tenant.md`). O ambiente dev (1 tenant) nunca disparou os casos perigosos —
> **prod pode ter >1 tenant**, então as travas de abort abaixo são reais aqui.

## ✅ DEPLOY EXECUTADO E VALIDADO — 2026-06-27

Toda a remediação (P0-P2.3, C1-C3, C4a/b/c, C5.1) foi pra prod via `scripts/deploy-prod-tls.sh`. Como foi:
- **Prod tem 1 tenant** → todos os backfills caíram no fallback de tenant único, **sem abort**, sem
  necessidade dos pré-checks de >1 tenant. Backup do banco feito antes (16M).
- As **8 migrations da remediação aplicaram** (até `Version20260626150000`). As 2 antigas de Ponto
  (`Version20260401000000`/`Version20260408180237`) **pularam sozinhas** (skip/abortIf — "tabelas já existem").
  Por isso, mesmo o script rodando `doctrine:migrations:migrate` (que a Regra de ouro #2 desaconselha), com
  1 tenant + os 2 skips foi seguro. `up-to-date` sempre mostra "2 available" = esses 2 skippers (cosmético).
- **C5.1 (nginx):** o script **NÃO recria o nginx**, então a mudança no `nginx.prod.conf` (bind-mount) só
  entrou com **`docker restart jusprime_nginx_prod`** (reload NÃO pega o inode novo). Provado live: `curl`
  anônimo num PDF de pasta foi de **200 → 404**.
- **🔴 Achado: `var/uploads` NÃO é volume persistido em prod** (compose só monta `uploads_prod` em
  `public/uploads`). Os commits `dcceb14`/`7f269e4` tinham apontado os configs de chamados/clientes/
  justificativas p/ `var/` mas sem mover arquivos nem criar volume → documentos existentes ficaram órfãos
  (404). **Conserto:** reverter os 3 configs p/ `public/` (`16fc10d`); a defesa é o bloqueio `/uploads/` do
  nginx (C5.1). **Todos os uploads ficam em `public/uploads/` (volume `uploads_prod`).** C5.2/3/4 cancelados.
- Pós-deploy: `schema:validate` OK, home 302. **Pendente:** smoke logado (cliente doc, kanban, ponto).

> O runbook abaixo fica como **referência** (foi seguido). Para um próximo deploy de migrations, a Regra de
> ouro segue válida; mas note que o `deploy-prod-tls.sh` roda `migrate` automático no entrypoint do php.

## ⚠️ Regra de ouro

1. **Backup do banco ANTES de qualquer migration.** Toda migration mexe em estrutura + dados.
2. **Aplicar cada migration ISOLADA, por versão, na ordem da tabela** — via
   `php bin/console doctrine:migrations:execute 'DoctrineMigrations\VersionXXX' --up --no-interaction`.
   **NUNCA rodar `doctrine:migrations:migrate` puro.** Motivo: o ledger tem 2 migrations antigas de
   Ponto fora de ordem/não aplicadas (`Version20260401000000`, `Version20260408180237`) — as tabelas
   de ponto já existem por outro caminho. `migrate` tentaria executá-las e quebraria/faria coisa
   inesperada. Inspecione o ledger de prod primeiro:
   `SELECT version FROM doctrine_migration_versions ORDER BY version;`
3. **Janela de deploy / manutenção:** os backfills fazem `UPDATE` em tabelas grandes (Cliente,
   Processo) antes do `NOT NULL`. Use a página de manutenção.
4. **Trava de segurança universal (todas as migrations de coluna):** o backfill só resolve órfãos
   automaticamente quando há **1 tenant**. Com **>1 tenant + qualquer linha órfã/ambígua**, o
   `SET NOT NULL` **ABORTA** (rollback total do Postgres, **nenhum dado alterado**). Isso é
   proposital — resolva os dados à mão e rode de novo. Não force.

## Pré-checks globais (rodar e anexar o resultado ao checklist)

```sql
SELECT COUNT(*) FROM tenant;   -- define se os backfills caem no fallback de tenant único
```
Se `COUNT = 1`: todos os backfills concluem (fallback de tenant único). Se `COUNT > 1`: rode os
pré-checks específicos de cada migration (coluna "Pré-check" abaixo) ANTES de aplicar.

## Ordem de aplicação (dependências importam)

| # | Versão | Frente | O que faz | Backfill (fonte) | Pré-check em prod (se >1 tenant) |
|---|--------|--------|-----------|------------------|----------------------------------|
| 1 | `Version20260625120342` | H1 ServiceDesk | tenant_id em `chamado` | solicitante do chamado | chamados com solicitante sem tenant resolvível |
| 2 | `Version20260625183049` | S2 Cliente | tenant_id em `cliente` + `cliente_documento` | `criado_por_id` → tenant ativo; doc herda do cliente | clientes com `criado_por_id` NULL/irresolvível (no dev eram 8 órfãos→tenant único) |
| 3 | `Version20260625192651` | S3 Processo | tenant_id em `processo` + 3 filhas; **unique `numero_processo` global → composto `(tenant_id, numero_processo)`** | autor; filhas herdam do pai | processos órfãos; **números de processo duplicados** que o unique composto vá rejeitar |
| 4 | `Version20260625200952` | S4 Agenda | tenant_id em `evento` + `legenda_cor` | evento via `criador`; legenda via fallback tenant único | eventos sem criador resolvível; legendas globais pré-existentes (decisão de negócio) |
| 5 | `Version20260625203433` | P1 Pasta | **NUP global → composto `(tenant_id, nup)`** | (sem coluna nova — Pasta já tinha tenant) | **NUPs duplicados** (ver SQL abaixo) |
| 6 | `Version20260625212332` | P2.1 Tarefa | tenant_id em `tarefa` + `tarefa_mensagem` | `pasta.tenant_id` (mensagem herda da tarefa) | **exige #2/#3/#5 já aplicadas** (lê `pasta.tenant_id`); tarefas sem pasta resolvível |
| 7 | `Version20260626103111` | P2.3 Ponto | tenant_id em `registro_ponto` + `justificativa_ponto` | `user_tenant` ativo **só se exatamente 1 vínculo** | **usuário com >1 vínculo ativo** (ambíguo → aborta) ou sem vínculo |

**Dependência crítica:** a #6 (Tarefa) lê `pasta.tenant_id` no backfill → as #2/#3/#5 (que populam pasta
e suas dependências) **precisam estar aplicadas antes**. As demais são independentes entre si, mas
mantenha a ordem da tabela por segurança.

## Pré-checks específicos (SQL para rodar em prod se houver >1 tenant)

```sql
-- #5 Pasta: NUPs duplicados (o unique era global; o composto rejeita duplicado dentro do mesmo tenant)
SELECT nup, COUNT(*) FROM pasta GROUP BY nup HAVING COUNT(*) > 1;   -- esperado: 0 linhas

-- #3 Processo: números de processo que colidiriam (deve ser 0 ou já segregados por tenant após backfill)
SELECT numero_processo, COUNT(*) FROM processo GROUP BY numero_processo HAVING COUNT(*) > 1;

-- #7 Ponto: usuários com >1 vínculo ativo (tornam o backfill de ponto AMBÍGUO → migration aborta)
SELECT user_id, COUNT(*) FROM user_tenant WHERE is_active = true GROUP BY user_id HAVING COUNT(*) > 1;
-- registros/justificativas órfãos (usuário sem vínculo ativo):
SELECT COUNT(*) FROM registro_ponto r WHERE NOT EXISTS (SELECT 1 FROM user_tenant ut WHERE ut.user_id=r.user_id AND ut.is_active);
SELECT COUNT(*) FROM justificativa_ponto j WHERE NOT EXISTS (SELECT 1 FROM user_tenant ut WHERE ut.user_id=j.user_id AND ut.is_active);
```
Qualquer um desses retornando linhas em prod multi-tenant → **resolver os dados manualmente antes** de
aplicar a migration correspondente (a migration aborta de propósito).

## Migrations pós-remediação (auditoria — PRÓXIMO deploy, ainda NÃO aplicadas em prod)

Lote da correção dos achados da auditoria. Mesma regra de ouro: **backup do banco antes**, aplicar cada
uma ISOLADA via `migrations:execute '<Version>' --up --no-interaction`, na ordem abaixo. Prod = 1 tenant
→ todos os backfills caem no fallback de tenant único e rodam liso.

| # | Versão | Frente | O que faz | Backfill (fonte) | Pré-check em prod (se >1 tenant) |
|---|--------|--------|-----------|------------------|----------------------------------|
| 8 | `Version20260629120000` | M4 Notificacao | tenant_id em `notificacao` | `tarefa.tenant_id`; resto via fallback tenant único | notificações sem tarefa em multi-tenant (cobertas só pelo fallback) |
| 9 | `Version20260629130000` | M2 AccessRequest | tenant_id em `access_request` | `user_tenant` ativo **só se exatamente 1 vínculo** | solicitante com >1 vínculo ativo (ambíguo → aborta) ou sem vínculo |
| 10 | `Version20260630120000` | B5-f4 ResourceAccess | tenant_id em `resource_access` | tenant do recurso (`cliente`/`pasta`/`processo`.tenant_id); resto via fallback | RA cujo recurso (resource_id) não existe mais em multi-tenant (órfão não-derivável → aborta) |

```sql
-- #9 AccessRequest: solicitantes com >1 vínculo ativo (backfill ambíguo → aborta)
SELECT user_id, COUNT(*) FROM user_tenant WHERE is_active = true GROUP BY user_id HAVING COUNT(*) > 1;
-- #10 ResourceAccess: grants cujo recurso não existe (só problemático em multi-tenant; em 1 tenant o fallback cobre)
SELECT ra.id, ra.resource_type, ra.resource_id FROM resource_access ra
WHERE (ra.resource_type='cliente'  AND NOT EXISTS (SELECT 1 FROM cliente  c  WHERE c.id =ra.resource_id))
   OR (ra.resource_type='pasta'    AND NOT EXISTS (SELECT 1 FROM pasta    p  WHERE p.id =ra.resource_id))
   OR (ra.resource_type='processo' AND NOT EXISTS (SELECT 1 FROM processo pr WHERE pr.id=ra.resource_id));
```
Dev (1 tenant): M4 0 notif / M2 0 solicitações / B5-f4 0 RAs → todas triviais, já aplicadas no dev.

## Passos NÃO-migration

- **H2 — anexos do ServiceDesk fora do public:** mover os arquivos de
  `app/public/uploads/chamados/` → `app/var/uploads/chamados/` (em dev/prod provável ~vazio). A rota
  de download passou a ser controlada (auth + tenant + posse); o caminho do disco mudou junto.
  Conferir permissões de escrita do novo diretório. Spec: `docs/specs/servicedesk-anexo-download-seguro.md`.
- **Follow-up app-wide de uploads (NÃO bloqueia este deploy):** `pastas`, `justificativas`, `clientes`,
  `perfil` ainda guardam em `public/uploads/*` — mover para fora do public é frente própria futura.

- **🟠 M5 — isolar por tenant as imagens do editor de peças (mover ARQUIVOS, sem migration).**
  Spec: `docs/specs/peca-imagem-isolamento-tenant.md`. O `PecaImagemController` passou a servir a
  imagem só da subpasta `public/uploads/pastas/<tenantId>/` (tenant da sessão); o upload novo já
  grava lá. **As imagens já existentes estão planas em `public/uploads/pastas/` e precisam ser
  MOVIDAS para a subpasta do tenant dono, senão o próprio dono perde a imagem nas peças antigas.**
  Prod = 1 tenant `T` → todas as órfãs do editor são de `T`. Critério **DB-backed** (sem parsear
  HTML): mover só os arquivos de imagem que **não** são `PastaDocumento.caminho_arquivo` (isso exclui
  os HTMLs de peça e as imagens de DOCUMENTO — RG/procuração — que ficam planas e seguem servidas pela
  rota de entidade `pasta_documento_*`). **Ordem copy → deploy → cleanup (zero janela de imagem
  quebrada).** Rodar DENTRO do container php de prod (volume `uploads_prod` montado em
  `public/uploads/pastas`); **fazer backup antes** (regra de ouro). Conferir `SELECT count(*) FROM tenant = 1`.

  ```bash
  set -euo pipefail
  DIR=public/uploads/pastas
  TENANT=$(psql -U symfony -d saas -tAc "SELECT id FROM tenant LIMIT 1")   # prod = 1 tenant
  # trava de segurança: aborta se o psql não está no PATH/retornou vazio (senão criaria pastas/ e cp falharia)
  [ -n "${TENANT:-}" ] || { echo "ERRO: TENANT vazio (psql ausente? >1 tenant?) — abortar"; exit 1; }
  IMG='( -iname *.jpg -o -iname *.jpeg -o -iname *.png -o -iname *.gif -o -iname *.webp )'
  mkdir -p "$DIR/$TENANT"

  # conjunto de exclusão: TODOS os caminho_arquivo de documento (HTML de peça, PDF, imagem de doc)
  psql -U symfony -d saas -tAc "SELECT caminho_arquivo FROM pasta_documento" | sort -u > /tmp/docs.txt

  # (1) COPY: cada imagem PLANA que NÃO é caminho_arquivo de doc = imagem órfã do editor → copia p/ <T>/
  eval find "$DIR" -maxdepth 1 -type f $IMG -printf '%f\\n' \
    | grep -vxF -f /tmp/docs.txt \
    | while IFS= read -r f; do cp -n "$DIR/$f" "$DIR/$TENANT/$f"; done

  # (2) DEPLOY do código (git push + script). Smoke: abrir peça antiga com imagem → renderiza (logado).

  # (3) CLEANUP (só após o smoke OK): remove os planos órfãos já copiados (inalcançáveis pelo
  #     controller novo + bloqueio nginx de /uploads/). NÃO toca os doc files (excluídos pelo grep).
  eval find "$DIR" -maxdepth 1 -type f $IMG -printf '%f\\n' \
    | grep -vxF -f /tmp/docs.txt \
    | while IFS= read -r f; do rm -f "$DIR/$f"; done
  ```
  Em deploys novos (multi-tenant do zero) **não há legado** — toda imagem já nasce em `<tenantId>/`.

## Pós-deploy (validar)

```bash
php bin/console doctrine:schema:validate --no-interaction   # mapping + database em sync
php bin/console doctrine:migrations:list                    # confirmar as 7 versões aplicadas
```
Smoke manual recomendado por escritório: listar clientes/processos/agenda/pastas/tarefas/ponto e
confirmar que cada escritório vê só o próprio (e que nada sumiu indevidamente).

## Riscos e rollback

- **`down()` do Processo (#3) após colisão:** o `down()` recria o unique GLOBAL de `numero_processo`;
  se em prod existirem dois processos com o mesmo número em tenants diferentes (legítimo pós-isolamento),
  o `down()` **aborta**. Não é defeito — os dados violam o unique global. Exige limpeza manual antes de
  reverter. Evite reverter o #3 sem necessidade real.
- **Abort no meio da cadeia:** se a #N abortar, as #1..#N-1 já aplicadas permanecem (cada uma é
  transacional e isolada). Resolva os dados da #N e continue a partir dela. O sistema fica consistente
  (entidades já isoladas continuam isoladas; as não-migradas seguem como antes).
- **M7 — backfill de `legenda_cor` é single-tenant POR DESIGN (freio consciente, já deployado).**
  `Version20260625200952` (Agenda, S4) backfilla `legenda_cor.tenant_id` **só** pelo fallback de tenant
  único (`(SELECT COUNT(*) FROM tenant) = 1`), pois a legenda não tem âncora (nem autor, nem FK p/ evento).
  É o **mesmo padrão dos outros 8 backfills** da remediação. Cenários: deploy do zero = tabela vazia →
  `SET NOT NULL` em 0 linhas = OK; prod (1 tenant) = fallback resolveu. **Só abortaria** num banco
  multi-tenant pré-existente com legendas globais (não ocorre aqui) — aí a decisão de negócio é **duplicar
  o catálogo por tenant manualmente** antes do `SET NOT NULL`, não adivinhar dono por cor.
  **O 2º tenant em prod é SEGURO (verificado):** legendas são criadas **sob demanda** com `setTenant`
  (`AgendaController::salvarLegendas`), o `TenantBootstrapService` **não faz seed** de legenda (logo nenhum
  INSERT sem tenant no bootstrap), e a agenda renderiza com **zero legendas**. Nada a fazer no deploy.

## Pendências de segurança conhecidas (NÃO bloqueiam o deploy, mas registrar)

- **🔴 Frestinha super-admin (Ponto):** `ROLE_SUPER_ADMIN` sem tenant na sessão roda com o
  `TenantFilter` desligado → IDOR residual nas rotas admin de ponto/justificativa (só p/ a conta de
  plataforma; admin comum não alcança). Fechamento adiado para a definição dos poderes do super-admin.
  Detalhe: `docs/specs/ponto-isolamento-tenant.md` (follow-up "frestinha super-admin").
- Demais follow-ups sistêmicos (SQL nativo/bulk do `DemitirFuncionarioUseCase`, unique global de
  `cpf/cnpj` do Cliente, CSRF em endpoints JSON) listados no fim de `docs/specs/PROGRESSO-PENDENCIAS.md`.
