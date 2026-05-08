# Refatoração de Identidade Global

## Objetivo

Separar `user` (identidade global, 1 conta por pessoa) de `user_tenant` (vínculo por escritório). Permite que um futuro usuário seja colaborador de múltiplos escritórios sem precisar de contas duplicadas.

---

## Decisões de produto já tomadas

- Login é global (email único no sistema todo, sem `tenant_id` na constraint)
- Tenant ativo escolhido após login e guardado na sessão
- User com 1 tenant: auto-seleciona; com 2+: tela de seleção; com 0: bloqueado
- Trocador de escritório no menu (futuro)
- Demitidos preservados como vínculo inativo (`is_active=false`), nunca deletados — para auditoria
- "Tenant" mantido no código e banco; "Escritório" usado só na interface (refator de UI futuro)
- Se admin remove vínculo de user logado: redirecionar para seleção com flash message

---

## Plano de 6 etapas

### ✅ Etapa 1 — Criar tabela `user_tenant` (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Entity/Auth/UserTenant.php`
- `app/src/Repository/UserTenantRepository.php`
- `migrations/Version20260507153912.php`

**Estrutura:** PK `id`, FK `user_id` (CASCADE), FK `tenant_id` (CASCADE), FKs opcionais `tenant_role_id`, `cargo_id` (SET NULL), `lotacao_id` (SET NULL), campos `codigo_funcionario`, `data_admissao`, `demitido_em`, `last_login_at`, `is_active`, `created_at`, `updated_at`. UNIQUE composto `(user_id, tenant_id)`.

---

### ✅ Etapa 2 — Migrar dados (CONCLUÍDA)

**Arquivo:** `migrations/Version20260507160000.php`

6 vínculos criados a partir de `user.tenant_id`, `user.tenant_role_id`, `user.cargo_id`, `user.lotacao_id`, `user.codigo_funcionario`, `user_profiles.data_admissao` (LEFT JOIN), `user.demitido_em`, `user.last_login`. Dados antigos NÃO removidos — duplicados nos dois lugares durante a transição.

---

### ✅ Etapa 3 — Infraestrutura de tenant ativo (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Service/Tenant/TenantContext.php` — service com `getCurrentTenant`, `getCurrentUserTenant`, `setCurrentTenant`, `hasCurrentTenant`, `clearCurrentTenant`. Chave de sessão: `current_tenant_id`.
- `app/src/EventListener/TenantContextValidatorListener.php` — prioridade 7, valida vínculo a cada request, redireciona para `tenant_selecionar` se inválido.
- `app/src/Exception/Tenant/NoTenantSelectedException.php`
- `app/src/Controller/Tenant/TenantSelecaoController.php` — stub na rota `/escritorio/selecionar`
- `app/templates/tenant/selecionar.html.twig` — placeholder

---

### ✅ Etapa 4 — Refatorar pontos centrais (CONCLUÍDA)

**Decisões tomadas:**
- `PermissionChecker` passa a receber `Tenant` explicitamente como 2º parâmetro em todos os métodos públicos — internamente busca `UserTenant` pelo par `(user, tenant)` para ler o `TenantRole`
- Bypass de `ROLE_SUPER_ADMIN` mantido como estava (admin cross-tenant da plataforma) — **não** usar `ROLE_PLATFORM_ADMIN` (manter nome original)
- User com 0 tenants é estado válido; com 1 ativo: auto-seleciona no login; com 2+: redireciona para seleção

**Arquivos criados:**
- `app/src/Controller/Admin/PlatformDashboardController.php` — stub do painel SuperAdmin, rota `/admin/platform`
- `app/templates/admin/platform/dashboard.html.twig`
- Migration de dados: `ROLE_SUPER_ADMIN` atribuído ao user `jusprime.samuel@gmail.com`

**Arquivos modificados (resumo):**
- `app/src/Repository/UserTenantRepository.php` — adicionado `findActiveByUser(User): array`
- `app/src/Service/PermissionChecker.php` — nova assinatura pública + lógica via `UserTenant` (não mais `$user->getTenantRole()`)
- `app/src/Twig/PermissionExtension.php` — injeta `TenantContext`, passa `$tenant` ao checker
- `app/src/Controller/Trait/ResourceAccessTrait.php` — adicionado `Tenant $tenant` ao helper
- `app/src/Service/Audit/AuditLogSubscriber.php` — usa `TenantContext::getCurrentTenant()` em vez de `$user->getTenant()`
- `app/src/Security/UserAuthenticator.php` — auto-seleciona tenant único no login; redireciona SuperAdmin sem tenant para `/admin/platform`
- `app/src/EventListener/TenantContextValidatorListener.php` — corrigido: passa por SuperAdmin sem tenant; redireciona demais para `tenant_selecionar`
- 25 controllers + 2 UseCases + `NotificacaoService` — injetam `TenantContext` e passam `$tenant` ao checker

---

### ⏳ Etapa 5 — Refatorar controllers e implementar tela de seleção (PENDENTE)

Levantamento original: 75+ arquivos, ~236 referências. Concentrações em:

- `src/Controller/TenantController.php` (35 refs)
- `src/Controller/PontoController.php` (23 refs)
- `src/Kanban/Controller/` (~20 refs)
- `src/Repository/AuditLogRepository.php` (linhas 78, 102, 155, 163, 169, 209, 215)
- `src/Repository/Ponto/FeriadoRepository.php` (linha 29)
- `src/Profile/DTO/PerfilOutput.php` (linhas 37, 38)

Implementar `TenantSelecaoController` real com lista de escritórios do user e POST para selecionar.

---

### ⏳ Etapa 6 — Limpeza (PENDENTE)

- Remover colunas de `user`: `tenant_id`, `tenant_role_id`, `cargo_id`, `lotacao_id`, `codigo_funcionario`, `demitido_em`, `last_login`
- Remover `user_profiles.data_admissao`
- Trocar UNIQUE de `user.email` por UNIQUE global puro (já é, mas confirmar que está como índice de coluna e que faz sentido depois da remoção do `tenant_id`)

---

## Histórico de operações em ambiente

### 07/05/2026 — Carga de dados reais em DEV

- Backup manual de produção feito em `/var/backups/jusprime/jusprime_20260507_191757.tar.gz` (22 MB)
- Backup baixado para `~/jusprime-backups/` na máquina local
- Banco DEV (`saas`) recriado e restaurado com dump de produção (renomeando owner `jusprime` → `symfony`, filtrando `\restrict`/`\unrestrict` do psql 16)
- Migrations reaplicadas: 2 antigas auto-puladas (`preUp skipIf`), 2 da refatoração aplicadas (`Version20260507153912` e `Version20260507160000`)
- Conteúdo restaurado: 10 users, 6 tenants (1 ativo + outros), 682 registros de ponto, 7 jornadas
- `user_tenant` populada com 10 vínculos (9 ativos, 1 demitido)
- Schema: 2 diferenças cosméticas detectadas (comment vazio em `user.demitido_em`, default em `jornada_colaborador.alerta_habilitado`) — ignoradas

**Notas operacionais**

- DEV pode ser destruído a qualquer momento (sem dados próprios importantes)
- Backup só fica em `/var/backups/jusprime/` na própria VPS — sem storage externo (**PENDENTE:** configurar backup off-site)
- Senha do banco de produção em texto plano em `.env.prod` (não commitada — `.gitignore` confirmado) — **PENDENTE:** migrar para vault

---

## Pontos de auditoria identificados mas NÃO tratados

Tratar após a refatoração de identidade global:

- CPF em texto plano em `user_profiles` e `pre_cadastro` (risco LGPD)
- `pre_cadastro` sem `tenant_id` (potencial vazamento entre escritórios)
- 2 sistemas de permissão coexistindo: `user.roles` (JSON) vs `tenant_role_permission`
- `audit_log` sem índice em `tenant_id`
- `resource_access` polimórfico sem validação de mesmo tenant entre user e resource
- `invitation_token` possivelmente em texto plano (verificar)
- Falta soft delete em quase todas as tabelas
- Falta `updated_at` em várias tabelas (`user`, `tenant`, `permission`, `pre_cadastro`)
- `tenant.cnpj` sem UNIQUE
- Sem 2FA, sem lockout de tentativas de login
- `audit_log.changes` JSON pode estar gravando dados pessoais sem sanitização
- Sequences duplicadas detectadas no dump de produção: `audit_log_id_seq` + `audit_log_id_seq1`, `chamado_id_seq` + `chamado_id_seq1`, `chamado_anexo_id_seq` + `chamado_anexo_id_seq1` — investigar origem
- `registro_ponto` sem campo de auditoria (`created_at`) e sem hash de integridade — risco para conformidade CLT
- `registro_ponto` sem `tenant_id` direto — vínculo apenas via `user_id`, vulnerável quando user perder `tenant_id` (Etapa 6)
- User `farlei.rocha@gmail.comm` (com 2 m's) — possível conta duplicada por erro de digitação, demitida em 29/04
- 2 migrations não aplicadas em produção mas presentes no código (`Version20260401000000` e `Version20260408180237`) — limpar
- Revisar bypass total de `ROLE_SUPER_ADMIN` no `PermissionChecker` — implementar modelo de impersonate com motivo registrado + audit log para acessos cross-tenant (LGPD/sigilo profissional)
- Performance: `PermissionChecker` faz query de `UserTenant` a cada chamada — cachear por request quando virar gargalo
