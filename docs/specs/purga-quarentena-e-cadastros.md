# Spec — Job de purga: `cadastro_pendente` expirado + escritórios em quarentena

> **Risco:** ALTO (destrutivo — hard delete irreversível; multi-tenant)
> **Data:** 2026-07-01 · **Status:** ✅ **IMPLEMENTADO E VALIDADO** (2 rodadas de revisão adversarial; suíte 976/976). **Aguardando commit + agendamento do cron em prod (passo humano).**
> **Origem:** dívida #2 da spec `self-service-escritorios.md` (RN09 / RS04)
> **Domínios tocados:** Auth (`CadastroPendente`), Tenant (`Tenant` + toda a cascata tenant-scoped), Command
> **Depende de:** soft delete (Fase 2b, `beb2f5b`) e cadastro público (Fase 3, `3d8cc95`) — ambos já no master

## ✅ Estado de entrega (2026-07-01)

Implementado seguindo o ciclo do projeto (UseCase+testes → resto → `/review` → correção → **re-review**).
Arquivos: `services.yaml` (parâmetro carência), `CadastroPendenteRepository` (contar/purgar),
`PurgarCadastrosPendentesUseCase`, `TenantRepository::encontrarPurgaveis`, `PurgaEscritorioResultado` (DTO),
`PurgarEscritorioUseCase` (hard delete Fases 0–6), `PurgarDadosExpiradosCommand`, e 4 testes (14 casos):
`PurgarCadastrosPendentesUseCaseTest`, `PurgarEscritorioUseCaseTest` (teste-ouro: purga completa +
isolamento cross-tenant + User/permission preservados + guards + drift + disco), `PurgarDadosExpiradosCommandTest`
(inclui isolamento de falha por tenant), `PurgaCoberturaSchemaTest` (guard-rail anti-drift, incl. tabelas-ponte).

**Revisão adversarial:** 1ª rodada = 5 achados (F1 ALTO isolamento de falha no loop; F2/F3 MÉDIO guard-rail/dry-run;
F4/F5 BAIXO). F1–F4 corrigidos e re-revisados (fechados, sem regressão). F5 (órfão de disco de `documento_processo`)
aceito como best-effort/cosmético (sem vazamento — nomes hex únicos). **Comando de execução usa `flock` (não
`LockableTrait`) para não adicionar `symfony/lock`.**

**Falta (passo humano):** commit atômico da frente + agendar o cron em prod (ver §Agendamento) + rodar `--dry-run`
primeiro em prod.

---

## 🧭 Handoff / estado (leia primeiro)

Esta spec desenha **um único job de manutenção** (command de console + agendamento por cron)
que faz duas faxinas independentes numa passada:

- **(A) `cadastro_pendente`** — apaga registros que guardam `senha_hash` + PII e não têm mais uso:
  os `pending` **expirados** (>24h sem confirmar) **e** os `confirmado` (cujo `senha_hash` virou
  redundante depois que o `User` foi criado). *(Decisão do dono: limpar os dois.)*
- **(B) escritórios em quarentena** — faz o **hard delete definitivo** dos `Tenant` soft-deletados
  (`isActive=false`) cujo `excluidoEm` já passou da **carência de 365 dias**. Deleção em cascata
  manual, escopada por `tenant_id`, isolada de outros escritórios.

**Nada foi implementado.** Este documento é o alvo da revisão (`feature-review-agent`) e a base do
plano de implementação. Isolamento multi-tenant é **inegociável**.

### Decisões do dono (2026-07-01, confirmadas)

| # | Decisão | Valor |
|---|---|---|
| 1 | Escopo da purga de `cadastro_pendente` | Apagar **expirados (pending) + confirmados** |
| 2 | `audit_log` do escritório purgado | **Reter tudo** (nunca apagar); registrar a purga como evento |
| 3 | `User` que fica sem nenhum vínculo após a purga | **Nunca apagar conta** (mantém órfão) |
| 4 | Carência da quarentena antes do hard delete | **365 dias**, configurável por env sem deploy |

### ⚠️ Premissa a validar (ponto 2 do dono)

O dono comentou que, ao excluir um escritório com usuários vinculados, "é só remover esses usuários
e fazer o soft-delete, que remove das vistas do cliente mas fica guardado no banco (para reativação
pelo suporte)". **Interpretação assumida:** "remover esses usuários" = *tirar o escritório da vista
deles* (que o soft-delete atual já faz via RS06, sem desvincular ninguém — os `user_tenant` ficam
intactos para a reativação restaurar o acesso de todos). **O soft-delete NÃO muda nesta spec.** Se o
dono quiser que o soft-delete **desative** os vínculos no ato da exclusão, é uma mudança separada na
Fase 2b — fora do escopo deste job. *Confirmar na revisão.*

---

## 📋 Problema & objetivo

Hoje **nada** limpa esses dados: `cadastro_pendente` retém `senha_hash`+e-mail+nome+OAB+IP
indefinidamente (a única limpeza é reativa, no reinício do mesmo e-mail), e o soft-delete de
escritório só marca `isActive=false` — a "purga definitiva por job" prometida em RN09 nunca foi
construída. Objetivo: **higiene de PII e cumprimento do ciclo soft-delete → quarentena → purga**,
sem jamais vazar/afetar dados de outro escritório.

---

## 🏗 Arquitetura

Um command orquestrador → dois UseCases independentes → repositórios. Segue o fluxo do projeto
(`Command → UseCase → Repository → flush/DBAL`), o padrão canônico multi-tenant de CLI
(iterar/escopar explicitamente, nunca confiar no `TenantFilter` — ver §Segurança) e a convenção
destrutiva já existente (`ClearDevDatabaseCommand`: `--force` + confirmação interativa + transação).

```
app:purgar-dados-expirados   (App\Command\PurgarDadosExpiradosCommand, LockableTrait)
  ├── --dry-run   → só relata o que apagaria, não apaga nada
  ├── --force     → pula a confirmação interativa (uso no cron, sem TTY)
  │
  ├─(A)→ PurgarCadastrosPendentesUseCase::executar(agora, dryRun): int
  │        └── CadastroPendenteRepository::purgar(agora) / contarPurgaveis(agora)
  │
  └─(B)→ TenantRepository::encontrarPurgaveis(limite = agora − 365d): Tenant[]
           foreach tenant:
             PurgarEscritorioUseCase::executar(tenant, dryRun): PurgaEscritorioResultado
             em->clear()   // higiene de memória entre iterações (long-running)
```

**Por que um command, não Scheduler/Messenger:** o projeto **não tem** `symfony/scheduler` nem
`symfony/messenger` instalados, nem worker/supervisor/cron dentro do container. O único agendamento
em prod é **crontab no host da VPS → `docker exec`** (é como o `backup.sh` roda). Adicionar Scheduler
exigiria novo pacote + serviço worker no `docker-compose.prod.yml` — atrito desproporcional para um
job diário. Ver §Agendamento.

---

## (A) Purga de `cadastro_pendente`

### Storytelling (obrigatório antes do UseCase)
- **Quem dispara:** o job (cron), sem usuário/tenant no contexto. Ação de sistema.
- **O quê:** apaga linhas de `cadastro_pendente` sem uso futuro.
- **Por quê:** cada linha guarda `senha_hash` + PII (e-mail, nome, OAB, IP); retenção indefinida é
  passivo de LGPD e de segurança. É tabela **global, não-TenantAware, não-auditável** (por design).
- **Critério (decisão 1):** `status = 'confirmado'` **OU** (`status = 'pending'` **E** `expires_at < :agora`).
  - `pending` **não** expirado = cadastro vivo legítimo → **preservar**.
  - `confirmado` = `User` já criado; o `senha_hash` aqui é cópia morta → **apagar**.
- **Erros/edge:** nenhum. Apagar é seguro: não há FK apontando para a tabela; não quebra confirmação
  (expirado já é rejeitado por `isExpired()`) nem reinício (que já apaga os anteriores do e-mail).
  `email` **não** é único → o critério é por `status`/`expires_at`, nunca por e-mail.

### Componentes
- `App\Auth\Repository\CadastroPendenteRepository`:
  - `contarPurgaveis(\DateTimeImmutable $agora): int` — `SELECT COUNT` do critério (para `--dry-run`).
  - `purgar(\DateTimeImmutable $agora): int` — `DELETE` em massa via DQL, retorna nº de linhas.
- `App\Auth\UseCase\PurgarCadastrosPendentesUseCase::executar(\DateTimeImmutable $agora, bool $dryRun): int`
  — em `dryRun` chama `contarPurgaveis`; senão `purgar`. Retorna a contagem.

---

## (B) Purga de escritórios em quarentena (hard delete)

### Storytelling
- **Quem dispara:** o job (cron). Sem request → **`TenantFilter` desligado** → todo escopo é manual.
- **O quê:** apaga **de verdade** um `Tenant` e **tudo** que pertence a ele.
- **Por quê:** cumprir RN09 (quarentena → purga) e liberar o dado após 1 ano recuperável.
- **Elegibilidade (guarda de segurança, decisão 4):** `isActive = false` **E** `excluidoEm IS NOT NULL`
  **E** `excluidoEm <= agora − carência(365d)`. O UseCase **revalida** esse guard antes de qualquer
  DELETE — nunca purga escritório ativo ou dentro da carência, mesmo se chamado por engano.
- **Preservar (nunca apagar):** `User`, `user_profiles`, `permission` (catálogo global),
  `cadastro_pendente` (não é do tenant), `audit_log` (decisão 2), `jornada_colaborador` /
  `bloco_jornada_colaborador` (são **por-user**, sem `tenant_id`).
- **`User` órfão (decisão 3):** ao apagar o tenant, só o `user_tenant` daquele tenant some. Um `User`
  que fica com zero vínculos **permanece** intacto (reconvidável). Não há rotina que apague users.

### Verdade do schema (verificada no banco dev, 2026-07-01)
- **35 tabelas com coluna `tenant_id`** — 34 com FK para `tenant` + `audit_log` (coluna solta, **sem FK**).
- Dos 34 FKs → `tenant`: **32 são `NO ACTION`**, 1 `CASCADE` (`user_tenant`), 1 `SET NULL` (`invitation`).
- **Consequência crítica:** `DELETE FROM tenant` **falha** enquanto qualquer das 32 tabelas tiver linha
  daquele tenant. **O banco NÃO cascateia a partir do tenant.** A purga deleta manualmente, de baixo
  para cima, cada filha, escopando `WHERE tenant_id = :t`.
- **Não confiar no `cascade:remove` do ORM:** só cobre as associações diretas da entidade `Tenant`
  (roles/sedes/cargos/lotacoes/jornada), ignora as outras ~27 tabelas, e emite 1 DELETE por linha.
  Usar **DBAL DML em massa** por tabela.

### FKs `NO ACTION` intermediários (deletar a filha ANTES do pai)
Verificados via `pg_constraint`:
| Filha | Pai | Como escopar |
|---|---|---|
| `cliente_documento` | `cliente` | tem `tenant_id` → `WHERE tenant_id=:t` |
| `pasta_documento` | `pasta` | tem `tenant_id` |
| `movimentacao_processo` | `processo` | tem `tenant_id` |
| `parte_processo` | `processo` | tem `tenant_id` |
| `bloco_jornada` | `jornada_tenant` | **sem `tenant_id`** → subquery `WHERE jornada_tenant_id IN (SELECT id FROM jornada_tenant WHERE tenant_id=:t)` |
| `tenant_role_permission` | `tenant_role` / `permission` | **sem `tenant_id`** → subquery via `tenant_role` |
| `user_tenant` | `tenant_role` | tem `tenant_id` → deletar antes de `tenant_role` |

FKs `child → user` (chamado, cliente, pasta, processo, marcador, evento, etc.) **não bloqueiam** —
como **não** apagamos `user`, deletar a filha por `tenant_id` é sempre válido.

### Cascatas do banco (caem sozinhas ao deletar a raiz — confirmado)
- `pasta` → `pasta_checklist_item`, `pasta_cliente`, `pasta_marcador`, `pasta_mensagem`,
  `pasta_observacao_detalhes`, `pasta_observacao_financeira`, `pasta_processo`, `pasta_secao`, `tarefa`
- `cliente` → `cliente_pf`, `cliente_pj`, `pasta_cliente`
- `processo` → `documento_processo`, `pasta_processo`
- `tarefa` → `tarefa_mensagem`, `tarefa_responsaveis`, `notificacao` (com tarefa)
- `chamado` → `chamado_anexo`, `chamado_interacao`
- `evento` → `evento_participante`
- `marcador` → self + `pasta_marcador`
- `kanban_board` → **todo o subsistema kanban** (coluna, card, anexo, checklist, comentário, marcadores,
  participantes, responsáveis)

### Ordem segura de deleção (autoritativa)

Tudo em **uma transação DBAL por tenant** (`connection->beginTransaction … commit/rollBack`), cada
passo com `tenant_id = :t` (ou subquery escopada). Fases 1–3 podem reordenar internamente; a sequência
entre fases é obrigatória.

**Fase 0 — coletar caminhos de arquivo em disco** (SELECT, antes de qualquer DELETE):
`cliente_documento`, `documento_processo`, `pasta_documento` (col. de caminho, têm `tenant_id`);
`chamado_anexo`, `kanban_anexo` (via subquery do pai), `justificativa_ponto`, `tarefa_mensagem`
(anexo). Mais o diretório `public/uploads/pastas/<tenantId>/` (PecaImagem, único keyed por tenant).

**Fase 1 — bloqueadores intermediários:**
1. `bloco_jornada` (subquery via `jornada_tenant`)
2. `tenant_role_permission` (subquery via `tenant_role`)
3. `cliente_documento` `WHERE tenant_id`
4. `pasta_documento` `WHERE tenant_id`
5. `movimentacao_processo` `WHERE tenant_id`
6. `parte_processo` `WHERE tenant_id`
7. `user_tenant` `WHERE tenant_id` *(antes de `tenant_role`/`cargo`/`lotacao`)*

**Fase 2 — raízes de subsistema (cascata derruba os filhos):**
8. `tarefa` `WHERE tenant_id` → auto `tarefa_mensagem`, `tarefa_responsaveis`, `notificacao` (com tarefa)
9. `notificacao` `WHERE tenant_id` *(pega as sem tarefa)*
10. `pasta` `WHERE tenant_id` → auto filhos de pasta
11. `processo` `WHERE tenant_id` → auto `documento_processo`
12. `cliente` `WHERE tenant_id` → auto `cliente_pf/pj`
13. `chamado` `WHERE tenant_id` → auto anexos/interações
14. `evento` `WHERE tenant_id` → auto participantes
15. `kanban_board` `WHERE tenant_id` → auto subsistema kanban
16. `marcador` `WHERE tenant_id`
17. `legenda_cor` `WHERE tenant_id`
18. `jornada_tenant` `WHERE tenant_id` *(blocos já removidos)*
19. `justificativa_ponto` `WHERE tenant_id`
20. `registro_ponto` `WHERE tenant_id`
21. `feriado` `WHERE tenant_id`

**Fase 3 — estruturais / permissões do tenant:**
22. `aceite_termo` `WHERE tenant_id`
23. `access_request` `WHERE tenant_id`
24. `resource_access` `WHERE tenant_id`
25. `invitation` `WHERE tenant_id` *(senão vira convite órfão com `tenant_id` NULL)*
26. `tenant_role` `WHERE tenant_id` *(perm+vínculos já removidos)*
27. `cargo` `WHERE tenant_id`
28. `lotacao` `WHERE tenant_id`
29. `sede` `WHERE tenant_id`

**Fase 3.5 — verificação de integridade (guard anti-drift, dentro da transação):**
Antes do passo 30, iterar **todas** as tabelas com `tenant_id` (de `information_schema`, exceto a
allowlist `audit_log`) e `SELECT COUNT` do tenant. Se **qualquer** ainda tiver linha → **abortar**
(`throw`), fazendo rollback, com mensagem nomeando a tabela. Isso transforma um FK-violation críptico
numa falha clara e **protege contra tabelas `tenant_id` novas** adicionadas no futuro sem atualizar a
lista de deleção (falha segura: o tenant não é apagado até o código ser corrigido).

**Fase 4 — o tenant:** `DELETE FROM tenant WHERE id = :t`.

**Fase 5 — disco (após o `commit`, fora da transação, best-effort):** `unlink` dos caminhos coletados
na Fase 0 + `rm -rf public/uploads/pastas/<tenantId>/`. Falhas são **logadas, não fatais** (arquivo
órfão é cosmético; unlink não é transacional — nunca apagar disco antes do commit do banco).

**Fase 6 — auditoria (decisão 2):** registrar **um** evento em `audit_log` ("escritório #X purgado
em definitivo"). Como o hard delete é via DBAL (não passa pelo `AuditLogSubscriber` do ORM), é um
insert manual. `audit_log.tenant_id = X` sobrevive (sem FK) como registro forense.

### Componentes
- `App\Repository\TenantRepository::encontrarPurgaveis(\DateTimeImmutable $limite): Tenant[]`
  — `WHERE isActive = false AND excluidoEm IS NOT NULL AND excluidoEm <= :limite`.
- `App\Tenant\UseCase\PurgarEscritorioUseCase` — recebe `EntityManagerInterface`, `ArquivoStorageInterface`,
  o(s) diretório(s) de upload e o `int $carenciaPurgaDias`. `executar(Tenant $tenant, bool $dryRun):
  PurgaEscritorioResultado`. Revalida o guard; em `dryRun` conta linhas por tabela sem apagar; senão
  executa Fases 0–6. Retorna um DTO simples (`tenantId`, `nome`, `linhasPorTabela[]`, `arquivosRemovidos`).

---

## ⚙️ Configuração (carência) — espelha RS05

`app/config/services.yaml` (mesmo padrão do `tenant_max_por_usuario`):
```yaml
parameters:
    tenant_carencia_purga_dias: '%env(default:default_tenant_carencia_purga_dias:int:TENANT_CARENCIA_PURGA_DIAS)%'
    default_tenant_carencia_purga_dias: 365
services:
    _defaults:
        bind:
            int $carenciaPurgaDias: '%tenant_carencia_purga_dias%'
```
Trocar a carência (ex.: 180) = setar `TENANT_CARENCIA_PURGA_DIAS` no `.env.prod`, sem deploy de código.

---

## ⏰ Agendamento (deploy — passo manual do humano)

Caminho de menor atrito, no padrão do `backup.sh` (crontab **no host** da VPS → `docker exec`):
```cron
# Purga diária às 03:00 (após o backup das 02:00)
0 3 * * * docker exec jusprime_php_prod php bin/console app:purgar-dados-expirados --force >> /var/log/jusprime-purga.log 2>&1
```
- **Ordem importa:** rodar **depois** do backup das 02:00 → o dump da noite ainda contém o tenant, então
  há janela de recuperação mesmo após a purga.
- **`--force`** é necessário (cron não tem TTY para a confirmação interativa).
- **TZ:** o cron usa o TZ do **host**; o container fixa `America/Sao_Paulo`. A carência é em dias
  (tolerante a fuso), então não é crítico, mas documentar.
- **Lock:** um lockfile via `flock` (`sys_get_temp_dir()/jusprime-purga.lock`) evita sobreposição.
  **Decisão de implementação:** não usamos `LockableTrait` porque exigiria `composer require
  symfony/lock` (dependência ausente) — o lockfile dá a mesma garantia sem novo pacote, coerente com
  a stack minimalista do projeto (sem Scheduler/Messenger).
- Documentar a linha no `DEPLOY.md` (proposta ao humano, não auto-commit em doc de decisão).

---

## 🔒 Segurança / multi-tenancy (risco ALTO)

1. **`TenantFilter` OFF no CLI** (`doctrine.yaml` `enabled:false`, ligado só por request). Um `DELETE
   FROM pasta` sem `WHERE` apagaria pastas de **todos** os escritórios. **Garantia de isolamento:**
   `WHERE tenant_id = :t` explícito em **cada** DELETE/subquery. **Decisão de implementação:** o UseCase
   opera 100% via DBAL DML (não faz nenhum SELECT de entidade pelo ORM), então o `TenantFilter` (que só
   afeta SELECTs de entidades TenantAware) seria inerte aqui — por isso não é ligado; o escopo explícito
   é a única e suficiente barreira. A verificação da Fase 3.5 é a rede final antes do `DELETE FROM tenant`.
2. **Lição B1 (Datajud):** no CLI, todo lookup precisa de `tenant` explícito — a purga é uma aplicação
   direta dessa lição, agora em DELETE.
3. **`KanbanBoard` e `aceite_termo`** têm `tenant_id` mas **não** implementam `TenantAware` — fáceis de
   esquecer numa varredura pela interface. Estão explicitamente na ordem de deleção **e** na verificação
   da Fase 3.5 (que lê `information_schema`, não a interface).
4. **Guard de elegibilidade** revalidado no UseCase: jamais purgar tenant ativo/dentro da carência.
5. **Ordem disco depois do banco:** rollback do DB nunca deixa arquivos apagados sem registro.

---

## 🧪 Testes (seguindo `app/tests/CLAUDE.md`)

**Unit — `PurgarCadastrosPendentesUseCase`** (Foundry factory de `CadastroPendente`):
- apaga `pending` expirado; **preserva** `pending` vivo; apaga `confirmado`; retorna contagem certa;
  `--dry-run` conta e não apaga.

**Functional — `PurgarEscritorioUseCase` (o teste mais importante):**
- **Purga completa:** popular um tenant A com **1 linha em cada tabela tenant-scoped** (todas as 32 +
  filhos por cascata + kanban + jornada + anexos) usando factories; purgar; assertar **0 linhas** de A
  em cada tabela e `tenant` A inexistente.
- **Isolamento cross-tenant (inegociável):** popular tenant B com os mesmos dados; purgar **só** A;
  assertar que **todas** as linhas de B seguem intactas e B ativo. Reaproveitar o padrão do
  `DatajudIsolamentoTest`.
- **User compartilhado:** um `User` dono de A **e** vinculado a B; purgar A; assertar que o `User`
  sobrevive, seu vínculo com B intacto, só o vínculo com A sumiu (decisão 3).
- **Guard:** purgar tenant ativo → `throw`, nada apagado; tenant soft-deletado **dentro** da carência
  → não elegível.
- **Guard anti-drift (Fase 3.5):** popular uma tabela tenant-scoped e "esquecer" de deletá-la (simular
  drift) → a verificação aborta nomeando a tabela, com rollback (tenant preservado).
- **Disco:** anexos gravados + `uploads/pastas/<id>/` são removidos; arquivo já ausente não quebra.

**Functional — command `app:purgar-dados-expirados`** (`CommandTester`, padrão `KernelTestCase`):
- `--dry-run` relata contagens e **não** altera nada; execução real faz as duas faxinas; `--force`
  pula confirmação; sumário (SymfonyStyle) lista o que foi purgado.

**Guard-rail de schema:** um teste que lê `information_schema` (todas as tabelas com `tenant_id`) e
assere que cada uma está **coberta** (na ordem de deleção **ou** numa allowlist documentada de cascata/
retenção). Novo `tenant_id` sem tratamento → teste vermelho.

---

## ⚠️ Casos de borda

| Cenário | Tratamento |
|---|---|
| Nenhum tenant elegível / nenhum cadastro purgável | No-op silencioso; sumário "0 purgados". |
| Tenant reativado pelo suporte durante a carência | Vira `isActive=true` → deixa de ser elegível. Sem corrida (job é diário). |
| Execução sobreposta (job anterior ainda rodando) | `LockableTrait` aborta a segunda com aviso. |
| Falha no meio de um tenant | Transação por tenant faz rollback total daquele tenant; os demais seguem; job termina com status de erro para o log. |
| Tabela `tenant_id` nova não tratada | Fase 3.5 aborta aquele tenant nomeando a tabela (falha segura). |
| Arquivo em disco já removido | `ArquivoStorageService::excluir` checa existência; best-effort. |
| Rodar sem `--force` num TTY | Confirmação interativa (default não). Sem TTY e sem `--force` → recusa com instrução. |

---

## 📌 Fora de escopo (defaults aprovados)

- Export/backup por-tenant antes da purga (recuperação vem do dump noturno; job roda após o backup).
- Instalar Scheduler/Messenger (mantém cron-no-host).
- Purgar/anonimizar `audit_log` (decisão 2: reter).
- Apagar `User` órfão (decisão 3: manter).
- Mudar o soft-delete para desativar vínculos (premissa a validar; mudança separada se pedida).
- Job de purga de `jornada_colaborador` órfã (per-user, não per-tenant).

---

## 🗺 Plano de implementação (fases)

Seguindo o ciclo do projeto (UseCase+testes → resto → `/review` → correção → re-review em ALTO):

1. **Config:** parâmetro `tenant_carencia_purga_dias` (default 365) + bind.
2. **(A) Cadastro:** `CadastroPendenteRepository::contarPurgaveis/purgar` → `PurgarCadastrosPendentesUseCase`
   → testes unit.
3. **(B) Tenant:** `TenantRepository::encontrarPurgaveis` → `PurgaEscritorioResultado` (DTO) →
   `PurgarEscritorioUseCase` (Fases 0–6, DBAL, guard, Fase 3.5) → testes functional (purga, isolamento,
   user compartilhado, guard, drift, disco).
4. **Command:** `PurgarDadosExpiradosCommand` (`--dry-run`/`--force`/`LockableTrait`) orquestrando A+B
   → teste functional via `CommandTester`.
5. **Guard-rail de schema** (teste `information_schema`).
6. **`/review`** (feature-review-agent contra esta spec) → correção → **re-review** (é ALTO).
7. **Docs:** propor linha de cron no `DEPLOY.md` + nota na `self-service-escritorios.md` (dívida #2 → feita).
8. **Deploy (humano):** rodar em `--dry-run` primeiro em prod; conferir sumário; agendar no crontab.
