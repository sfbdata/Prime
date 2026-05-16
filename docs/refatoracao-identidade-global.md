# Refatoração de Identidade Global

## Objetivo

Separar `user` (identidade global, 1 conta por pessoa) de `user_tenant` (vínculo por escritório). Permite que um futuro usuário seja colaborador de múltiplos escritórios sem precisar de contas duplicadas.

---

## Estado atual — 14/05/2026

- **Última fase concluída:** 5c.3e (sub-lotes 5c.3e.1 a 5c.3e.7)
  (commits 0dac17a, 868cf76, 24cd0f8, 950fd74, 99e6b88, cae1b5c, 1ff80b2)
- **Próximo passo:** 5c.4 — cleanup (verificar resíduos pós-5c.3e e fechar a Sub-etapa 5c)
- **Roteiro completo até o fim:**
  1. 5c.4 — cleanup
  2. Sprint dedicada de fixtures (Sub-lotes A-E definidos em
     docs/etapas/5c.3e.7-fixtures-levantamento.md) — pré-requisito da Etapa 6
  3. Sprint de robustez do aceite de convite — bug de recontratação
     descoberto na 5c.3e.5 (UniqueConstraintViolationException)
  4. Etapa 6 — remover colunas de user: tenant_id, tenant_role_id,
     cargo_id, lotacao_id, codigo_funcionario, demitido_em, last_login

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

## Modelo de produto consolidado (08/05/2026)

Decisões finalizadas com Samuel + Dr. Farlei. Esta seção é fonte da verdade pra Etapa 5 em diante.

### Cadastro de conta
- Por convite apenas (já é assim hoje)
- Confirmação por email obrigatória (já existe)
- Login futuro: Google
- Recuperação de senha: auto por email
- Sem 2FA por enquanto

### Criação de escritório
- Só advogado com OAB pode criar
- Validação via API do CFOAB (decisão técnica de fornecedor pendente: Infosimples, Escavador, ou outro)
- HOJE: liberação manual (SuperAdmin aprova cada novo escritório)
- FUTURO: liberação via pagamento de plano
- Limite de escritórios: no grátis, 1 por advogado; quando virar pago, varia por plano

### Admins do escritório
- Pode ter múltiplos admins
- Permissão de convidar é granular (admin + quem o admin liberar)

### Convites
- Convite Plataforma (criar conta no sistema): expira em 24h, criado por SuperAdmin
- Convite Escritório (colaborar em escritório existente): expira em 1 semana, criado por admin do escritório, convidado aceita ou recusa

### Demissão
- Dados criados pelo demitido permanecem
- Pastas sob responsabilidade dele transferem pro admin do escritório

### Inadimplência (futuro)
- 30 dias de tolerância com avisos incisivos
- Após 30 dias: read-only em tudo (visualizar mas não criar/editar/excluir, ponto eletrônico também read-only)
- Dados apagados após 1 ano sem pagamento

### Cobrança (futuro)
- Modelo a definir (Samuel vai pesquisar mercado: Astrea, Themis, ADVBox, Projuris)
- Planos: básico, pro, enterprise (limites diferentes por plano)
- Período de teste grátis: 2 anos pros escritórios que entrarem agora

### LGPD
- Cada escritório é responsável pelos dados dos seus clientes
- Plataforma é processador, não controlador

### SuperAdmin
- Conta separada com ROLE_SUPER_ADMIN no campo roles JSON
- Atualmente: apenas Samuel (jusprime.samuel@gmail.com)
- Painel /admin/platform (stub criado na Etapa 4, implementação real pós-refatoração)
- Gerenciamento de SuperAdmins: por enquanto via SQL ou CLI; tela no painel quando for desenvolvido
- Boa prática: ter pelo menos 2 SuperAdmins assim que possível (promover Farlei quando apropriado)

### Limites por escritório
- Colaboradores: sem limite por enquanto
- Espaço de arquivo: sem limite por enquanto

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

### ⏳ Etapa 5 — Refatorar controllers e implementar tela de seleção (EM ANDAMENTO)

Levantamento original: 75+ arquivos, ~236 referências. Concentrações em:

- `src/Controller/TenantController.php` (35 refs)
- `src/Controller/PontoController.php` (23 refs)
- `src/Kanban/Controller/` (~20 refs)
- `src/Repository/AuditLogRepository.php` (linhas 78, 102, 155, 163, 169, 209, 215)
- `src/Repository/Ponto/FeriadoRepository.php` (linha 29)
- `src/Profile/DTO/PerfilOutput.php` (linhas 37, 38)

Implementar `TenantSelecaoController` real com lista de escritórios do user e POST para selecionar.

#### ✅ Sub-etapa 5a — Segurança imediata + entidade de convite (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Entity/Auth/Invitation.php` — entidade com 14 campos, índices por `(email)`, `(status, expires_at)`, `(tenant_id, status)`, UNIQUE em `token`; métodos de domínio `aceitar()`, `recusar()`, `revogar()`, `expirar()`
- `app/src/Repository/InvitationRepository.php`
- `app/migrations/Version20260509000000.php` — cria tabela `invitation`, adiciona `oab_numero`/`oab_uf` em `user` com CHECK constraints, converte 1 `invitation_token` legado para registro `Invitation` com `status=expired`

**Arquivos modificados:**
- `app/config/packages/security.yaml` — `/tenant/new`: `PUBLIC_ACCESS` → `ROLE_SUPER_ADMIN`
- `app/src/Controller/TenantController.php` — `#[IsGranted('ROLE_SUPER_ADMIN')]` no método `new()`
- `app/src/Entity/Auth/User.php` — campos `oabNumero`, `oabUf` + getters/setters

**Testes E2E:** `e2e/tests/etapa5a-tenant-new-acesso.spec.js` — 2/2 passando (anônimo → 302, SuperAdmin → 200), 1 skip documentado (user normal, pendente fixture)

#### ✅ Sub-etapa 5b — Fluxos de convite — Fase 5b.1 Domain layer (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Auth/DTO/` — 7 DTOs (`CriarConvitePlataformaInput`, `CriarConviteEscritorioInput`, `AceitarConvitePlataformaInput`, `AceitarConviteEscritorioSemContaInput`, `AceitarConviteEscritorioComContaInput`, `RecusarConviteEscritorioInput`, `RevogarConviteInput`)
- `app/src/Auth/UseCase/` — 7 UseCases (`RevogarConviteUseCase`, `RecusarConviteEscritorioUseCase`, `CriarConvitePlataformaUseCase`, `CriarConviteEscritorioUseCase`, `AceitarConviteEscritorioComContaUseCase`, `AceitarConviteEscritorioSemContaUseCase`, `AceitarConvitePlataformaUseCase`)
- `app/tests/Auth/Unit/` — 7 arquivos de teste (61 cenários, 155 assertions, 100% passando)

**Arquivos modificados:**
- `app/src/Repository/InvitationRepository.php` — `+encontrarPorToken`, `+encontrarPendentesPorEmail`, `final` removido
- `app/src/Repository/UserTenantRepository.php` — `+existeVinculoAtivo`, `final` removido

**Banco de teste:** migrations `Version20260508170000` e `Version20260509000000` aplicadas em `saas_test`. Suite total: 417 testes, 404 passando, 13 erros pré-existentes (Grupo A — UseCases Expediente de outra sprint).

#### ✅ Sub-etapa 5b — Fase 5b.2 Templates de email + ConviteMailer (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Auth/Service/ConviteMailer.php` — `final`, 2 métodos (`enviarConvitePlataforma`, `enviarConviteEscritorio`), captura `TransportExceptionInterface`
- `app/templates/email/convite_plataforma.html.twig` — HTML inline, fallback de nome, expiração com hora
- `app/templates/email/convite_escritorio.html.twig` — HTML inline, fallback de tenant/criador, expiração sem hora
- `app/tests/Auth/Unit/ConviteMailerTest.php` — 8 testes, 44 assertions, 100% passando

**Arquivos modificados:**
- `app/.env` — `MAILER_FROM="JusPrime <naoresponda@jusprime.com.br>"` adicionado no bloco mailer
- `app/.env.test` — `MAILER_DSN=null://null` adicionado (evita envio real em testes funcionais futuros)
- `app/config/services.yaml` — parâmetro `mailer_from` + binding `string $mailerFrom`

**Suite total:** 425 testes, 412 passando, 13 erros pré-existentes (Grupo A).

#### ✅ Sub-etapa 5b — Fase 5b.3a Controllers de aceite de convite (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Auth/DTO/ConviteOutput.php` — DTO de saída para templates (não passa entidade Doctrine)
- `app/src/Auth/Controller/ConviteController.php` — 6 actions: `verConvite`, `aceitarPlataforma`, `aceitarSemConta`, `aceitarLogado`, `recusar`, `meusConvites`; rate limiter `convite_aceite` (20 req/min); CSRF manual
- `app/templates/auth/convite/ver.html.twig` — formulário de criação de conta (plataforma com OAB, escritório sem OAB)
- `app/templates/auth/convite/ver_logado.html.twig` — aceitar/recusar quando logado
- `app/templates/auth/convite/erro.html.twig` — estados: não encontrado, expirado, já utilizado
- `app/templates/auth/convite/nao_pertence.html.twig` — email do convite não bate com usuário logado
- `app/templates/auth/meus_convites.html.twig` — lista de convites pendentes
- `e2e/tests/etapa5b3a-aceite-convite.spec.js` — 13 testes E2E, todos passando

**Arquivos modificados:**
- `app/src/Controller/RegistrationController.php` — 302 redirect para `auth_aceite_convite` (aposentado)
- `app/src/Controller/InvitationController.php` — 302 redirect para `homepage` (aposentado; corrigido em 5b.3b → `auth_gerenciar_convites`)
- `app/config/packages/security.yaml` — `^/convite` e `^/invite$` → `PUBLIC_ACCESS`
- `app/config/packages/framework.yaml` — limiter `convite_aceite` adicionado (sliding_window, 20/min)
- `app/templates/base.html.twig` — bloco `{% block head_meta %}` + flash rendering no branch `{% else %}` (sem sidebar)

**Suite:** 13/13 testes E2E passando; 69 testes PHPUnit Auth passando; 13 erros pré-existentes (Grupo A) inalterados.

#### ✅ Sub-etapa 5b — Fase 5b.3b Criação de convites (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Auth/Controller/AdminConviteController.php` — `ROLE_SUPER_ADMIN`, 4 actions em `/admin/platform/convites`; type guard `platform` na revogação; rate limiter por user ID
- `app/src/Auth/Controller/GerenciarConvitesController.php` — `admin.users.invite`, 4 actions em `/escritorio/convites`; isolamento por tenant na revogação/reenvio; rate limiter por user ID
- `app/src/Auth/UseCase/ReenviarConviteUseCase.php` — limite de 3 reenvios; valida pending + não expirado + `podeReenviar()`
- `app/src/Auth/DTO/ReenviarConviteInput.php` — DTO de input
- `app/src/Auth/DTO/ConviteAdminOutput.php` — DTO de saída para views admin (não passa entidade Doctrine)
- `app/templates/admin/platform/convites.html.twig` — formulário + tabela; confirmação JS em revogar/reenviar
- `app/templates/auth/convites/gerenciar.html.twig` — idem + campo `tenant_role_id` no form
- `app/tests/Auth/Unit/ReenviarConviteUseCaseTest.php` — 7 cenários unit
- `app/tests/Auth/Functional/AdminConviteControllerTest.php` — 9 cenários funcionais
- `app/tests/Auth/Functional/GerenciarConvitesControllerTest.php` — 7 cenários funcionais
- `app/migrations/Version20260509145321.php` — `ALTER TABLE invitation ADD reenvio_count SMALLINT NOT NULL DEFAULT 0`

**Arquivos modificados:**
- `app/src/Entity/Auth/Invitation.php` — +`reenvioCount`, +`getReenvioCount()`, +`podeReenviar()`, +`incrementarReenvio()`
- `app/src/Repository/InvitationRepository.php` — +`listarDePlataforma()`, +`listarPorTenant()`
- `app/config/packages/framework.yaml` — limiter `convite_criar` adicionado (sliding_window, 10/min por user ID)
- `app/src/Controller/InvitationController.php` — redirect: `homepage` → `auth_gerenciar_convites`
- `app/config/packages/security.yaml` — `/invite`: `PUBLIC_ACCESS` → `ROLE_USER`

**Suite Auth:** 92/92 testes passando.

---

#### ✅ Sub-etapa 5b — COMPLETA (5b.1 + 5b.2 + 5b.3a + 5b.3b)

#### ⏳ Sub-etapa 5c — Refatorar referências legadas + TenantSelecaoController real (EM ANDAMENTO)

##### ✅ Fase 5c.1 — Extensão Twig + migração de templates (CONCLUÍDA)

**Arquivos criados:**
- `app/src/Twig/TenantContextExtension.php` — expõe `current_tenant(): ?Tenant` nos templates Twig

**Arquivos modificados:**
- `app/templates/_sidebar.html.twig` — 5 ocorrências de `app.user.tenant.id` → `current_tenant().id`
- `app/templates/tenant/index.html.twig` — 1 ocorrência: `app.user.tenant.id == tenant.id` → `current_tenant() and current_tenant().id == tenant.id`

##### ✅ Fase 5c.2 — TenantSelecaoController real (CONCLUÍDA)

**Arquivos modificados:**
- `app/src/Controller/Tenant/TenantSelecaoController.php` — stub → real: GET lista `UserTenant` ativos, auto-seleção quando 1 vínculo, redirect SuperAdmin sem tenant para `/admin/platform`, POST com validação CSRF + guard `tenantId <= 0` + try/catch + flash `'danger'`
- `app/templates/tenant/selecionar.html.twig` — placeholder → real: cards Bootstrap com nome do escritório + papel (role), estado vazio com mensagem ao usuário

**Suite:** 449 testes, 1039 assertions, 13 erros pré-existentes (Grupo A) inalterados. Smoke test manual validado em 4 cenários.

##### ✅ Fase 5c.3a — Refatorar módulo Kanban (CONCLUÍDA)

**Arquivos modificados (10 de 12 do levantamento):**
- `app/src/Kanban/UseCase/ListarBoardsUseCase.php` — `executar(User, Tenant)`: Tenant como parâmetro explícito
- `app/src/Kanban/UseCase/CriarBoardUseCase.php` — `executar(CriarBoardInput, User, Tenant)`
- `app/src/Kanban/UseCase/AtualizarBoardUseCase.php` — `executar(KanbanBoard, AtualizarBoardInput, User, Tenant)`
- `app/src/Kanban/UseCase/AtualizarCardUseCase.php` — `executar(KanbanCard, AtualizarCardInput, User, Tenant)`
- `app/src/Kanban/Controller/KanbanBoardController.php` — `assertAccess()` retorna `Tenant`; 5 actions refatoradas
- `app/src/Kanban/Controller/KanbanCardController.php` — 6 actions refatoradas
- `app/src/Kanban/Controller/KanbanComentarioController.php` — 3 actions refatoradas
- `app/src/Kanban/Controller/KanbanAnexoController.php` — 3 actions refatoradas
- `app/src/Kanban/Controller/KanbanMarcadorController.php` — 4 actions refatoradas
- `app/src/Kanban/Controller/KanbanChecklistController.php` — 5 actions refatoradas
- `app/tests/Kanban/Unit/CriarBoardUseCaseTest.php` — atualizado para nova assinatura

**Não modificados (2):** `ExcluirBoardUseCase` e `ExcluirComentarioUseCase` — usam `$board->getTenant()` via relação ORM da entidade `KanbanBoard`, não `User::getTenant()`.

**Padrão aplicado:** Controllers obtêm `$tenant = $this->assertAccess($user)` (retorno do método privado que já garante tenant não-nulo); UseCases recebem `Tenant` como parâmetro explícito em vez de extrair de `User::getTenant()`. Zero referências a `$user->getTenant()` no módulo.

**Suite:** 449 testes, 1039 assertions, 13 erros pré-existentes (Grupo A) inalterados.

##### ✅ Fase 5c.3c — Refatoração de ExpedienteController (CONCLUÍDA, bloco retroativo)

**Nota:** este bloco foi reconstruído retroativamente em 2026-05-13 durante verificação do levantamento. A fase foi executada mas não teve bloco de histórico estruturado registrado na época — permaneceu apenas como audit notes esparsas.

**Arquivos modificados:**
- `app/src/Expediente/Controller/ExpedienteController.php` — importou e injetou `TenantContext`; refatorou L39 (era `$usuario->getTenant()`) para uso de `TenantContext`; `ExpedienteController::acervoGeral()` ganhou `assertAccess()` para exigir autenticação/permissão (filtro real de `pastaRepository->findAll()` por tenant fica para sprint dedicada — ver "Pontos de auditoria")

**Verificação atual:** zero chamadas legadas (`->getTenant()`/`->setTenant()`/`->getTenantRole()`/`->setTenantRole()`) no arquivo.

**Pendência:** reconstruir lista completa de arquivos modificados a partir do `git log` do branch `refactor/etapa-5-identidade-global` filtrando por commits relacionados à fase 5c.3c. Comando sugerido: `git log --oneline refactor/etapa-5-identidade-global --grep="5c\.3c"`. Mesma pendência se aplica à Fase 5c.3b — bloco retroativo a consolidar em sprint dedicada.

##### ⏳ Fase 5c.3b+ — Refatorar demais módulos com referências PHP legadas (PENDENTE)

##### ✅ Fase 5c.3e — Refatoração final de refs legadas (CONCLUÍDA)

Sub-lotes 5c.3e.1 a 5c.3e.7, baseados no levantamento
docs/etapas/5c-levantamento-2026-05-13.md.

**Sub-lotes executados:**

- **5c.3e.1** (commit 0dac17a) — TenantUserProfileController via
  Padrão B-route. 1 ref eliminada (L41). Guarda 1 (target user)
  ausente do código original foi adicionada — efeito colateral de
  fechar A2.

- **5c.3e.2** (commit 868cf76) — PastaController:775. Verificação
  confirmou que a ref já havia sido eliminada no commit 0344eef
  (Lote 3, 11/05). Levantamento marcou erroneamente como pendente
  por não validar Apêndice 7 contra HEAD na geração. Doc do
  relatório fechou a entrada; zero código de produção alterado.

- **5c.3e.3** (commit 24cd0f8) — Ocultou bloco de lastLogin nos
  templates `base.html.twig` e `layout_peticionar.html.twig` via
  `{% if false %}`. 4 refs eliminadas. Decisão de produto sobre
  semântica (plataforma vs tenant ativo) adiada para sprint do
  trocador de escritório.

- **5c.3e.4** (commit 950fd74) — `PerfilOutput::fromEntity()` passa
  a receber `?UserTenant`. ProfileController injeta TenantContext
  e resolve via `getCurrentUserTenant()`. 2 refs eliminadas. 1 teste
  novo (UserTenant resolvido) + 1 teste novo (UserTenant null).

- **5c.3e.5** (commit 99e6b88) — UseCases de aceite de convite
  (ComConta e SemConta): removida duplicação `$user->setTenant`/
  `setTenantRole` que apenas sincronizava com UserTenant já criado
  independentemente. 6 refs eliminadas. 1 teste deletado
  (`testNaoSobrescreverTenantJaPreenchido` — testava guard removido).
  **Bug pré-existente descoberto:** recontratação de ex-funcionário
  (UserTenant inativo preservado para auditoria) → guard
  `existeVinculoAtivo` não detecta → UniqueConstraintViolationException
  não tratada → 500. Ver Pontos de auditoria.

- **5c.3e.6** (commit cae1b5c) — Removido `$user->setLastLogin()` em
  `UserAuthenticator`. 1 ref eliminada. Path auto-select (1 tenant)
  continua gravando `UserTenant.lastLoginAt` (Etapa 4). Paths
  multi-tenant e SUPER_ADMIN sem tenant deixam de gravar timestamp
  temporariamente. Sem consumidor ativo (templates ocultos via
  5c.3e.3). E2E auth/sessão/perfil validada: 0 testes novos falhando.

- **5c.3e.7** (commit 1ff80b2) — Levantamento de fixtures legadas.
  Não refatora código. Produz `docs/etapas/5c.3e.7-fixtures-levantamento.md`
  com inventário e proposta de 5 sub-lotes (A-E) para sprint
  dedicada, pré-requisito da Etapa 6.

**Verificações finais:**
- Refs legadas eliminadas no código de produção: 14 (de 22 PENDENTES
  do levantamento; restantes vão para Etapa 6 ou sprints dedicadas)
- Suite PHPUnit: 456 testes, 13 erros Grupo A inalterados, 0 falhas novas
- E2E: validada nos sub-lotes que tocam fluxos sensíveis (5c.3e.6)
- Doc mestre, relatório 5c-2026-05-13 e levantamento de fixtures
  consolidados

---

### ⏳ Etapa 6 — Limpeza (EM ANDAMENTO)

Sub-lotes:

| # | Escopo | Status |
|---|---|---|
| 6.A | DQL + services downstream | CONCLUÍDO (commit 3103542) |
| 6.B | editUserRole + Forms + Templates | CONCLUÍDO (pendente commit) |
| 6.C | Services + Tenant.php | pendente |
| 6.D | Entidades User.php + UserProfile.php + demitir() | pendente |
| 6.E | Migration DROP COLUMN (8 colunas) | pendente |

Colunas a dropar em 6.E: `user.tenant_id`, `user.tenant_role_id`, `user.cargo_id`,
`user.lotacao_id`, `user.codigo_funcionario`, `user.demitido_em`, `user.last_login`,
`user_profiles.data_admissao`.

---

## Histórico de operações em ambiente

### 12/05/2026 — Fase 5c.3d em andamento — Lote 4b sub-lotes 4b.1 a 4b.5 (`TenantController`)

Sub-lotes executados em ordem cronológica de commit:

- **4b.3** (`505cc8a`) — `pontoAdd`, `pontoEdit`, `pontoDelete`: 3 actions de ponto manual. Padrão B-route com guarda 1 (target user pertence ao tenant da URL).
- **4b.4** (`cf9aee9`) — 4 actions de justificativa: `aprovarJustificativa`, `rejeitarJustificativa`, `novaJustificativaAdmin`, `downloadAnexoJustificativa`. Padrão B-route com guarda 1 em todas.
- **4b.1** (`6aea0b3`) — `index`, `show`, `edit`, `listUsers`. Padrão B-route; `index` e `show` sem guarda 1 (sem target user na rota); `edit` e `listUsers` com guarda 1.
- **4b.2** (`c7c3fbd`) — `editUserRole` (apenas L458 — guarda 1 adicionada; 5 refs restantes em L468, L470, L525, L536×2 deferidas para sprint arquitetural pela complexidade do fluxo de role), `editUserName`, `demitirFuncionario`. Padrão B-route com guarda 1.
- **fix fixtures** (`71a8670`) — `DemitirFuncionarioControllerTest`: criação de `UserTenant` nas fixtures para desbloquear testes funcionais do demitir.
- **4b.5** (`690d1ce`) — `removeResourceAccess`: B-route com guardas 1 e 2 (target user + admin logado); CSRF validado antes da G1; mensagens 404 idênticas para não vazar existência cross-tenant. `manageSedes`: B-route só com guarda 2 (Tenant via ParamConverter, sem target user na rota). (`editSede` delegada a 4b.6a, `deleteSede` a 4b.6b.)
- **seed infra** (`492c422`) — Migrations de smoke test criadas: `Version20260512120000` (tenant B "Escritório Smoke B", role Admin, permissions 21+27, UserTenant para Emily user 3) e `Version20260512130000` (resource_access fake para Emily, `resource_type='pasta'`, `resource_id=999999`). Guard `skipIf(APP_ENV=prod)`. Reutilizáveis nos sub-lotes 4b.6a e 4b.6b.

**Smoke test do 4b.5:** cenários G1 e G2 cross-tenant validados com tenant B (id=30) + Emily como usuária dos dois tenants. OPcache do PHP-FPM cacheou versão anterior do `TenantController` — resolvido com `kill -USR2 1` no container `jusprime_php_dev`. Confirma ponto de auditoria OPcache pré-existente (vide seção de pontos de auditoria).

**Suite pós-4b.5:** 454 testes, 1070 assertions, 13 erros pré-existentes (Grupo A — A3 inalterado), 0 falhas novas.

### 13/05/2026 — Sub-lote 4b.6a (`editSede`) — retroativo

Commit: `07091d9`

- `editSede`: injetados `TenantRepository` e `UserTenantRepository`
- `editSede`: `$tenant` via `tenantRepository->find($tenantId)` + 404 se null
- `editSede`: `$isOwnTenant` via `existeVinculoAtivo($user, $tenant)` (era `$user->getTenant()->getId() === $tenantId`)
- `editSede`: `canAdminister` recebe `$tenant` da URL (era `$currentTenant` da sessão)
- Migration `Version20260512140000`: Sede Smoke B para tenant_id=30 — seed de smoke criada junto

Suite pós-4b.6a: 454 testes, 1070 assertions, 13 erros A3, 0 falhas novas.

### 13/05/2026 — Sub-lote 4b.6b (`deleteSede` + seed Sede Smoke A)

Commit: `bd17ec2`

- `deleteSede`: injetados `TenantRepository` e `UserTenantRepository`
- `deleteSede`: CSRF movido para antes das guardas (padrão 4b.5)
- `deleteSede`: `$tenant` via `tenantRepository->find($tenantId)` + 404 se null
- `deleteSede`: `$isOwnTenant` via `existeVinculoAtivo($user, $tenant)` (era `$user->getTenant()`)
- `deleteSede`: `canAdminister` recebe `$tenant` da URL; removida linha `$currentTenant = $this->tenantContext->getCurrentTenant()`
- `deleteSede`: posse da Sede como early return 404 (era `if ($sede && owns) { ... }`)
- Migration `Version20260513110809`: Sede Smoke A para tenant_id=1 (id=19)
- `DeleteSedeCrossTenantTest`: teste funcional cobre bloqueio cross-tenant (atacante sem vínculo em tenant B → 403, sede intacta)

**Achado durante execução:** rota `/tenant/{tenantId}/sedes/{sedeId}/delete` (prefixo `/tenant` vem de `#[Route('/tenant')]` na classe `TenantController`) — URL inicialmente omitida no teste, corrigida ao detectar 404 de roteamento.

Suite pós-4b.6b: 455 testes, 1072 assertions, 13 erros A3, 0 falhas novas.

**Smoke manual:** pulado. Vetor cross-tenant crítico coberto pelo `DeleteSedeCrossTenantTest` (atacante sem vínculo em tenant B → 403, sede intacta). Cenários positivos (Emily admin em B deleta Sede em B; sfb.samuell admin em A deleta Sede em A) e anônimo não exercitados — risco baixo dado o padrão B-route já validado em `manageSedes` (4b.5) e `editSede` (4b.6a), com guardas análogas.

**Lote 4b completo.** Próximo: 5c.3e — demais arquivos com refs legadas (~10 arquivos).

---

### 11/05/2026 — Fase 5c.3d concluída (Lotes 1–4a) — Controllers legados em `src/Controller/`

- **Pré-Lote:** `findAtivoPorUserETenant(User, Tenant): ?UserTenant` adicionado em `UserTenantRepository` — usado no Padrão D para obter cargo, lotação e código do funcionário via `UserTenant`
- **Lote 1 (3 controllers simples):** `AuditLogController`, `FeriadoController`, `JornadaTenantController` — Padrão A eliminado; `assertAccess($user)` retorna `Tenant`
- **Lote 2 (3 controllers médios):** `TenantRoleController`, `AccessRequestController`, `JornadaColaboradorController` — Padrões A+B; `existeVinculoAtivo(User, Tenant)` substitui comparação de IDs de tenant
- **Lote 3:** `TarefaController` + residual de `PastaController` — Padrões A+B+D
- **Lote 4a — `PontoController`** (commit `608deae`): maior controller do lote (8 actions + 1 método privado `montarDadosFolha`). Mudanças:
  - `declare(strict_types=1)` adicionado; `User`, `Tenant`, `UserTenantRepository` importados
  - `PermissionChecker` e `UserTenantRepository` movidos para o construtor; removidos dos parâmetros de 8 actions
  - `assertAccess(User): Tenant` adicionado; usado em 5 actions HTML; 2 AJAX (`batida`, `alertaHorario`) mantêm retorno JSON e usam `$this->permissionChecker` diretamente
  - Padrão B: `exportarFolhaPdf` + `exportarFolhaXlsx` — `getTenant()->getId() === getTenant()->getId()` → `existeVinculoAtivo($targetUser, $tenant)`
  - Padrão D em `montarDadosFolha`: `getCargo()`, `getLotacao()`, `getCodigoFuncionario()` → `findAtivoPorUserETenant()` → `$userTenant?->getCargo()` etc.
  - 49 linhas de dead code removidas: blocos em `exportarFolhaPdf` (linhas 716–742) e `exportarFolhaXlsx` (linhas 837–858) que recomputavam variáveis já geradas por `montarDadosFolha`
- **Suite pós-Lote 4a:** 454 testes, 1070 assertions, 13 erros pré-existentes (Grupo A) inalterados
- **Pendente:** 5c.3d Lote 4b (`TenantController`, ~1369 linhas, ~27 refs, ~20 actions)

### 11/05/2026 — Fase 5c.3a concluída — Refatoração do módulo Kanban

- 4 UseCases refatorados: `ListarBoards`, `CriarBoard`, `AtualizarBoard`, `AtualizarCard` — `Tenant` adicionado como parâmetro explícito, `$user->getTenant()` removido
- 6 controllers refatorados: `assertAccess()` modificado para retornar `Tenant`; todas as actions obtêm `$tenant = $this->assertAccess($user)` e passam ao repositório / UseCase
- `CriarBoardUseCaseTest` atualizado para nova assinatura de `executar()`
- 2 UseCases não modificados (`ExcluirBoard`, `ExcluirComentario`): usam relação ORM `KanbanBoard::getTenant()`, sem dependência de `User::getTenant()`
- Zero referências a `$user->getTenant()` restantes no módulo Kanban
- 449 testes, 1039 assertions, 13 erros pré-existentes (Grupo A) inalterados

### 11/05/2026 — Fases 5c.1 e 5c.2 concluídas — Refatoração de templates Twig + TenantSelecaoController real

- Extensão Twig `TenantContextExtension` criada com função `current_tenant()`. Templates `_sidebar.html.twig` (5 linhas) e `tenant/index.html.twig` (1 linha) refatorados para usar `current_tenant().id` em vez de `app.user.tenant.id`.
- `TenantSelecaoController` real implementado: GET lista `UserTenant` ativos via `findActiveByUser()`, POST com CSRF + validação `tenantId <= 0` + try/catch no `setCurrentTenant()` + flash `'danger'`, auto-seleção quando user tem 1 tenant, redirect SuperAdmin sem tenant para `/admin/platform`.
- Template `tenant/selecionar.html.twig` real: cards Bootstrap com nome do escritório e papel (role), estado vazio com mensagem ao usuário.
- 449 testes, 1039 assertions, 13 erros pré-existentes (Grupo A) inalterados. Smoke test manual validado em 4 cenários.

### 09/05/2026 (continuação) — Fase 5b.3b concluída — Criação de convites

- `AdminConviteController` (4 actions em `/admin/platform/convites`) e `GerenciarConvitesController` (4 actions em `/escritorio/convites`). `ReenviarConviteUseCase` + campo `reenvio_count` (limite 3 por convite) na entidade `Invitation`. Rate limiter `convite_criar` (10/min por user). Reaproveitada permissão `admin.users.invite`. Migration `Version20260509145321`. 92 testes Auth passando. 7 erros corrigidos durante execução (3 problemas raiz: listener de tenant, seletor CSS `alert-erro` vs `alert-danger`, identity map Doctrine em associação bidirecional).

### 09/05/2026 (continuação) — Fase 5b.3a concluída

- `ConviteController` com 6 actions: `verConvite` (dispatcher de 8 estados), `aceitarPlataforma`, `aceitarSemConta`, `aceitarLogado`, `recusar`, `meusConvites`
- 5 templates Twig criados (`ver`, `ver_logado`, `erro`, `nao_pertence`, `meus_convites`); `<meta name="referrer" content="no-referrer">` nos templates com token na URL
- Redirects 302 dos controllers legados: `RegistrationController` → `auth_aceite_convite`, `InvitationController` → `homepage`
- Rate limiter `convite_aceite` (sliding_window, 20 req/min por IP) via `RateLimiterFactory`
- 13 testes E2E + 69 testes PHPUnit Auth, todos passando
- **2 bugs corrigidos durante execução:** (1) OPcache do PHP-FPM retinha container antigo — corrigido com `kill -USR2`; (2) flash messages não renderizavam no branch `{% else %}` (sem sidebar) do `base.html.twig` — corrigido adicionando loop `app.flashes` no branch público

### 09/05/2026 — Fase 5b.2 concluída (continuação)

- Service `App\Auth\Service\ConviteMailer` criado com 2 métodos (`enviarConvitePlataforma`, `enviarConviteEscritorio`); captura `TransportExceptionInterface` e relança como `\RuntimeException`
- Templates HTML inline criados em `templates/email/` (`convite_plataforma.html.twig`, `convite_escritorio.html.twig`) — fallbacks para nome, tenant e criador nulos
- `MAILER_FROM` parametrizado via `.env` (`"JusPrime <naoresponda@jusprime.com.br>"`) e injetado por binding em `services.yaml`; não hardcoded
- `MAILER_DSN=null://null` adicionado em `.env.test` — protege testes funcionais futuros de envio real pelo Gmail
- `ConviteMailerTest` com 8 testes, 44 assertions, 100% passando
- Suite total: 425 testes, 412 passando, 13 erros pré-existentes do Grupo A

### 09/05/2026 — Fase 5b.1 concluída (continuação)

- Domain layer de convites implementado: 7 DTOs + 7 UseCases + 7 arquivos de teste unitário (61 cenários, 155 assertions)
- 2 métodos novos em `InvitationRepository` (`encontrarPorToken`, `encontrarPendentesPorEmail`), 1 em `UserTenantRepository` (`existeVinculoAtivo`)
- `final` removido de `InvitationRepository` e `UserTenantRepository` (necessário para mock em testes unitários; padrão do projeto não usa `final` em repositórios)
- Migrations `Version20260508170000` e `Version20260509000000` aplicadas no banco de teste (`saas_test`) — 45 erros funcionais desbloqueados
- Suite total: 417 testes, 404 passando, 13 erros pré-existentes do Grupo A (`MoverPastaMarcadoresUseCase` e `RemoverMarcadorDaPastaUseCase` — UseCases Expediente de outra sprint)

### 09/05/2026 — Sub-etapa 5a concluída

- Entidade `Invitation` criada em `app/src/Entity/Auth/` com 14 campos, índices e métodos de domínio
- `/tenant/new` fechada: `PUBLIC_ACCESS` → `ROLE_SUPER_ADMIN` no security.yaml + `#[IsGranted]` no controller
- Campos `oab_numero` / `oab_uf` adicionados em `user` com CHECK constraints PostgreSQL
- 1 token legado (`user.invitation_token`) convertido para registro `invitation` com `status=expired`, `created_by_id` preenchido (0 sem rastreabilidade)
- Migration `Version20260509000000` aplicada — correção de `SERIAL` → `IDENTITY` e operador `?` → `@>` para compatibilidade DBAL

### 08/05/2026 — Etapa 4 concluída

- `PermissionChecker` refatorado: nova assinatura pública com `?Tenant` explícito; bypass de `ROLE_SUPER_ADMIN` antes do null-check de tenant
- `AuditLogSubscriber` refatorado: usa `TenantContext::getCurrentTenant()` em vez de `$user->getTenant()`
- `UserAuthenticator` refatorado: auto-seleciona tenant único no login; redireciona SuperAdmin sem tenant para `/admin/platform`
- `TenantContextValidatorListener` corrigido: passa SUPER_ADMIN sem tenant; redireciona demais para `tenant_selecionar`; limpa sessão quando vínculo inativo detectado
- `ROLE_SUPER_ADMIN` atribuído ao user `jusprime.samuel@gmail.com` via migration
- `PlatformDashboardController` stub criado em `/admin/platform`
- **User de E2E criado:** `e2e@jusprime.local` com `ROLE_SUPER_ADMIN`, via `Version20260508170000.php` com guard `APP_ENV=prod` (no-op em produção — user nunca existe em prod)
- **Testes E2E:** 9/9 passando — 5 testes da Etapa 4 + auth.setup + 4 cenários do PermissionChecker

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

## Padrões de refatoração

### Padrão B-route — variante de B para actions com $tenantId na rota

Quando uma action recebe `int $tenantId` (ou similar) como parâmetro de
rota e precisa validar que o user logado tem vínculo ativo com aquele
tenant específico:

**Proibido:** usar `$this->tenantContext->getCurrentTenant()` como fonte
do Tenant na validação. `TenantContext` lê exclusivamente da sessão e
não é sincronizado com a URL. Usar a sessão quando a rota fornece o
tenant cria divergência possível (sessão=A, URL=B) e remove a defesa
contra manipulação de URL — vetor de IDOR.

**Correto:** buscar o `Tenant` via repositório a partir do `$tenantId`
da rota e usá-lo como fonte da verdade.

**Quando a action também recebe um `User $user` (target) na rota** —
caso típico de actions tipo `/{tenantId}/user/{id}/...` — é obrigatório
adicionar uma segunda guarda validando que o target user pertence ao
tenant da URL. O `ParamConverter` padrão do Symfony resolve `User` por
ID global, sem filtro de tenant — sem essa guarda, um admin do tenant
A pode operar sobre um user do tenant B via URL manipulada.

```php
public function minhaAction(
    int $tenantId,
    User $user,                              // target user (rota /{id})
    // ... outros params ...
    TenantRepository $tenantRepository,
    UserTenantRepository $userTenantRepository,
    PermissionChecker $permissionChecker,
): Response {
    $tenant = $tenantRepository->find($tenantId);
    if (!$tenant) {
        throw $this->createNotFoundException();
    }

    // Guarda 1: target user pertence ao tenant da URL.
    // Vale pra TODOS, incluindo SUPER_ADMIN — operação cross-tenant
    // sobre usuários individuais não tem caso de uso legítimo.
    // createNotFoundException (não AccessDenied): não vaza que o user
    // existe em outro tenant.
    if (!$userTenantRepository->existeVinculoAtivo($user, $tenant)) {
        throw $this->createNotFoundException();
    }

    $currentUser  = $this->getUser();
    $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);

    // Guarda 2: admin logado pertence ao tenant da URL.
    // SUPER_ADMIN bypass mantido: acesso cross-tenant intencional pra
    // dono da plataforma.
    $isOwnTenant = $userTenantRepository->existeVinculoAtivo($currentUser, $tenant);

    if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, '...'))) {
        throw $this->createAccessDeniedException('...');
    }

    // ... lógica da action ...
}
```

**Quando a action NÃO recebe target user pela rota** (só `$tenantId`),
aplica só a guarda 2 (admin logado). Não inventar target user a partir
de outras fontes.

**Quando usar B-route vs B puro:**
- Action **tem** `$tenantId` (ou `Tenant`) na rota → B-route
- Action **não tem** referência a tenant na rota, usa só sessão → B puro
  com `assertAccess()` ou `getCurrentTenant()` (sem risco de divergência
  porque não há outra fonte com a qual divergir)

**Quando usar B-route vs A:**
- Validando vínculo do user logado consigo mesmo (auto-acesso) → A
  (`assertAccess()`)
- Validando que o user logado tem vínculo com o tenant **da URL** → B-route
- Validando vínculo de outro user com um tenant → B-route (guarda 1)

**Sobre SUPER_ADMIN:** o bypass na guarda 2 é intencional (dono da
plataforma tem acesso cross-tenant por design). A guarda 1 (target user)
NÃO tem bypass — vale pra todos. Fluxos cross-tenant legítimos pro
SUPER_ADMIN devem usar endpoints dedicados, não actions de tenant
específico.

**Origem:** descoberta de risco IDOR antes do sub-lote 4b.3 da Fase 5c.3d,
e descoberta da ausência de guarda no target user durante a expansão
do mesmo sub-lote.

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
- Decidir fornecedor de validação de OAB (Infosimples, Escavador, scraping próprio, ou validação manual com upload de carteira)
- Pesquisar modelos de cobrança no mercado jurídico (Astrea, Themis, ADVBox, Projuris) antes de definir cobrança final
- Promover segundo SuperAdmin assim que possível — risco de single point of failure se Samuel perder acesso
- Refatorar fixtures pra criarem UserTenant (advogado1, etc. estão sem vínculo após Etapa 4)
- 6 testes E2E em `perfil.spec.js` falhando (cropper de foto / modal `#modalFotoEditar`) desde a troca do user de testes — investigar antes de fechar Etapa 5
- Padronizar política de `final` em repositórios — alguns têm (`InvitationRepository`, `UserTenantRepository` tinham até a 5b.1), outros não; definir regra única e aplicar consistentemente
- Implementar UseCases pendentes com testes já escritos: `MoverPastaMarcadoresUseCase` e `RemoverMarcadorDaPastaUseCase` (13 testes em erro no Grupo A)
- Garantir que migrations sejam aplicadas no banco de teste (`saas_test`) sempre que houver mudança de schema — investigar se há automação (hook de CI, script pré-teste) ou se depende de execução manual
- Padrão de WebTestCase: documentar regra de sempre popular ambos os lados de associações bidirecionais após `persist` (lição da 5b.3b — falta do `$role->getTenantRolePermissions()->add($trp)` causou 3 falsos-negativos de permissão)
- Outros tenants além do tenant 1 não têm role com permissão `admin.users.invite` — quando outros escritórios entrarem em produção, garantir que o role inicial criado já tenha essa permissão (sem ela, `/escritorio/convites` fica inacessível para todos no tenant)
- Templates de email recebem entidade `Invitation` diretamente — viola padrão `templates/CLAUDE.md` (que exige DTOs). Decisão: aceitável pra emails internos, mas avaliar criar `InvitationEmailDTO` se complexidade crescer
- `ConviteMailer` chama rota `auth_aceite_convite` que será criada na Fase 5b.3a — não chamar `ConviteMailer` até a rota existir, ou vai quebrar em runtime
- OPcache do PHP-FPM [CONFIRMADO em 12/05/2026]: durante smoke test do 4b.5, o PHP-FPM serviu versão antiga do TenantController ignorando mudanças recentes. Solução validada em container jusprime_php_dev: `docker exec jusprime_php_dev bash -c 'kill -USR2 1'` (PID 1 = master do PHP-FPM). Fallback se PID 1 não for o master: `kill -USR2 $(pgrep -f 'php-fpm: master')`. Criar alias ou script de reload para uso após deploys e mudanças em controllers com rotas novas
- Padronizar chave de flash messages no projeto: `'erro'` gera `alert-erro` (classe Bootstrap inválida — sem estilização de cor). Mapear para `'danger'` ou ajustar `base.html.twig` para mapear `'erro' → 'danger'`. Afeta principalmente os controllers das fases 5b.3a e 5b.3b
- Validação visual da regra `current_tenant().id == tenant.id` em `/tenant` impossível com apenas 1 tenant no banco de dev. Quando segundo tenant entrar (5c.3+), revalidar que botão "Editar" só aparece no card do tenant atualmente selecionado
- `AtualizarBoardUseCase` (`app/src/Kanban/UseCase/AtualizarBoardUseCase.php`) não possui `KanbanBoardRepository` injetado e não chama flush explícito — depende do unit-of-work do Doctrine para persistir as mudanças no board. Comportamento atual e funcional, mas inconsistente com outros UseCases que chamam `$repository->salvar()` explicitamente. Revisar numa sprint futura.
- `TenantController.php` tem ~1369 linhas com 20 actions cobrindo: criação/edição de tenant, gestão de usuários, perfis, ponto manual, justificativas, sedes, demissão, resource access. Candidato a quebra em controllers menores agrupados por responsabilidade. Não tratar na refatoração de identidade — sprint dedicada após a Etapa 6. Mesma lógica aplicada ao `PastaController` (~1700 linhas).
- `PastaController.php` tem ~1700 linhas — controller monolítico, candidato a quebra em módulos menores (`PastaShowController`, `PastaChecklistController`, `PastaObservacaoController`, etc.). Não tratar na refatoração de identidade; tratar em sprint dedicada.
- `TenantContextValidatorListener` não tem testes funcionais/integração cobrindo o cenário "user sem tenant tenta acessar rota protegida". Durante a Fase 5c.3b, 6 testes unitários `testUsuarioSemTenantLancaLogicException` dos UseCases de Pasta foram removidos (tornaram-se inaplicáveis com Tenant explícito) sem substituto direto. A barreira existe no código (listener redireciona para `tenant_selecionar`), mas não está coberta por teste automatizado. Criar testes funcionais para o listener em sprint futura.
- Criar helper compartilhado `logarComTenant()` em WebTestCase base do projeto. A 5c.3b precisou implementar o helper em `PastaSecaoControllerTest`, e esse padrão vai se repetir em todo teste funcional das fases 5c.3c+. N+1 ocorrência: `DeleteSedeCrossTenantTest` (4b.6b) inlinou a lógica de login + sessão de tenant. Agora são pelo menos 3 testes com padrão duplicado aguardando extração. Não adiar além do início de 5c.3e.
- `DeleteSedeCrossTenantTest` usa `User::setTenant($tenantVinculo)` (método legado em `User`) para associar o atacante a um tenant via propriedade direta. Antes da Etapa 6, junto da refatoração geral de fixtures, migrar para criação exclusiva via `UserTenant` (sem `setTenant` no `User`). Inventariar todos os testes funcionais que usam `setTenant` antes de remover o campo na Etapa 6.
- Levantamentos por grep com filtro de receiver (padrão usado em `5c-levantamento-2026-05-13.md`) não capturam refs legadas acessadas via propriedade de DTO ou VO (ex: `$input->usuarioAtual->getTenant()`). Próximos levantamentos devem incluir passada manual em DTOs com propriedade tipada `User`. Estado em 2026-05-13: 4 DTOs em `app/src/Auth/DTO/` têm propriedade pública `User` — `AceitarConviteEscritorioComContaInput` (`$usuarioAtual`), `RecusarConviteEscritorioInput` (`$usuarioAtual`), `CriarConvitePlataformaInput` (`$criadoPor`), `CriarConviteEscritorioInput` (`$criadoPor`). Apenas `AceitarConviteEscritorioComContaUseCase` chama métodos legados via DTO (4 refs em L58, L59, L61, L62 — entram no escopo da Fase 5c.3e).
- `ExpedienteController::acervoGeral()` usa `pastaRepository->findAll()` sem filtro de tenant — risco de vazamento de dados cross-tenant. Decisão: 5c.3c adiciona apenas `assertAccess()` pra exigir autenticação/permissão. Filtro real precisa ser definido em sprint dedicada — entender primeiro se "acervo geral" é conceito intencional ou bug de copy-paste.
- `PermissionChecker` faz N+1 queries no sidebar: cada chamada de `can_administer()` / `can_access_module()` no `_sidebar.html.twig` dispara query em `user_tenant` + iteração sobre TRPs com lazy-loading de cada `Permission` individual. Smoke test em `/expediente` mostrou 55 queries totais, sendo maioria re-fetchs do mesmo `user_tenant` e `permissions`. Otimização requer cache de permissions no scope da request (provavelmente via `PermissionChecker` mantendo um array carregado uma vez). Não tratar na refatoração de identidade; sprint dedicada de performance.
- Deprecation Symfony 7.3 do autowiring `RateLimiterFactory $conviteCriarLimiter` / `$conviteAceiteLimiter` — trocar tipo dos parâmetros para `RateLimiterFactoryInterface` em `AdminConviteController`, `ConviteController` e `GerenciarConvitesController`. Trivial mas vai quebrar em Symfony 8.0. Tratar antes de fechar a Etapa 5 ou em sprint separada.

- `TenantController::removeResourceAccess` L1294: `throw createNotFoundException('Acesso não encontrado.')` diverge da mensagem das demais 404s da mesma action ('Usuário não encontrado.'). G1+G2 protegem antes, mas quando ambos passam, a mensagem diferente vaza a existência do `resource_access` sem vazar existência cross-tenant diretamente. Refatorar para mensagem genérica ('Registro não encontrado.' ou similar) quando tocar essa action novamente.
- Role "Colaborador (a)" no tenant 1 (dados de produção restaurados) não tem permission `modules.expediente.view`. Users com esse role caem em 403 ao acessar `/expediente` (rota default pós-login). Não é bug da refatoração — gap de seed de produção. Avaliar: (a) adicionar a permission ao role via migration de correção, ou (b) mudar rota default pós-login para algo que todos os roles tenham acesso.
- Migrations de seed para smoke test criadas e ficam no repo: `Version20260512120000` (tenant B + role + permissions + UserTenant para Emily), `Version20260512130000` (resource_access fake para Emily), `Version20260512140000` (Sede Smoke B, tenant_id=30) e `Version20260513110809` (Sede Smoke A, tenant_id=1, id=19). Todas têm guard `skipIf(APP_ENV=prod)` — no-op em produção. Após o encerramento da Fase 5c.3d, avaliar se devem permanecer no repo ou ser removidas via `down()`.

- **CSRF em controllers de tenant — clarificação de padrão (5c.3e.1):**
  endpoint POST raw sem Form Component exige CSRF manual ANTES das
  guardas (Lote 4b.5, commit 690d1ce). Endpoint POST com Form Symfony
  tem CSRF implícito via `$form->isValid()`; guardas podem rodar
  antes do form porque (a) não mutam estado, (b) form é fronteira
  canônica de validação, (c) Guarda 1 (B-route) rejeita requisição
  forjada com tenant divergente antes do form sequer ser criado.

- **Apêndice 7 de levantamentos deve ser validado contra HEAD (5c.3e.2):**
  falso positivo descoberto durante o sub-lote. PastaController:775
  estava listado como pendente mas já havia sido fechado em commit
  anterior. Próximos levantamentos: rodar grep amplo do método
  específico, não copiar entradas manuais de versões antigas do doc.

- **Restaurar bloco de lastLogin nos templates pós-trocador (5c.3e.3):**
  `app/templates/base.html.twig` e `app/templates/layout_peticionar.html.twig`
  têm bloco do `<span class="navtop-last-access">` envolto em
  `{% if false %}`. Restaurar após decisão de produto sobre semântica
  (plataforma vs tenant ativo) com Samuel + Farlei.

- **Restaurar gravação de lastLoginAt em paths não-auto-select (5c.3e.6):**
  `UserAuthenticator` parou de gravar timestamp nos paths multi-tenant
  e SUPER_ADMIN sem tenant. Path auto-select (1 tenant) preservado.
  Implementar gravação em `TenantSelecaoController` (e no fluxo
  SUPER_ADMIN, se houver caso de uso) junto com o trocador de
  escritório.

- **Bug pré-existente: recontratação de ex-funcionário (5c.3e.5):**
  `existeVinculoAtivo` em `AceitarConviteEscritorioComContaUseCase`
  filtra `isActive=true`. UserTenant inativo (ex-funcionário demitido,
  vínculo preservado para auditoria por decisão de produto) para o
  mesmo par (user, tenant) não é detectado pela guard. Tentativa de
  recontratação dispara `UniqueConstraintViolationException` não
  tratada → 500. Bug pré-existente desde Fase 5b.1, não introduzido
  pela refatoração. Tratamento: (a) substituir `existeVinculoAtivo`
  por método que checa qualquer vínculo (ativo ou inativo) e reativa
  se inativo; ou (b) try/catch da exception. **Sprint de robustez
  antes da Etapa 6.**

- **Migration Version20260508170000.php:60 — RESOLVIDO (Sub-lote E, 15/05/2026):**
  `UPDATE user SET tenant_id=1` removido da migration. Sidebar já usava
  `current_tenant().id` desde a Fase 5c.1. Migration nunca havia rodado
  em produção, então foi editada diretamente.

- **Detecção de dead code por grep pode produzir falsos positivos massivos (5c.4):**
  levantamento automatizado via grep (Fase 5c.4) reportou 12 métodos
  privados como órfãos em Kanban + Auth controllers; verificação manual
  com grep direto confirmou 3–6 callers por método em todos os casos. O
  grep não cobria variações de chamada como `$this->method(`,
  encadeamento e callbacks. Próximos cleanups de dead code: dupla
  verificação manual obrigatória de TODA detecção "método sem caller"
  antes de remover qualquer código. Cleanup correlato (5c.4): seção
  "Pré-requisitos bloqueantes da Etapa 6" continha 3 entradas resolvidas
  há semanas (sidebar, tenant/index, fixtures advogado) ainda listadas
  como ativas. Cada consolidação de fase deve revalidar entradas dessa
  seção contra HEAD antes de fechar — padrão já estabelecido em 5c.3e.2
  para Apêndice 7 deve estender-se a todas as listas de pendências do
  doc mestre.

- **AppFixtures.php em estado degradado (descoberto na Sprint de Fixtures Sub-lote A, 15/05/2026):** 851 linhas, 13 entidades, 100% hardcoded, sem Faker/factory. 6 bugs identificados: L261/L262 (corrigidos no Sub-lote A — new DateTime → new DateTimeImmutable pra desbloquear smoke), L612 e L629 (mesmo padrão de DateTime, não corrigidos pois não bloqueiam o smoke), L599-602 (Collection::clear() pós-flush() — Doctrine state inconsistency). Zero referência externa relevante: scripts reset_db.sh e reset_symfony.sh carregam fixtures sem nomear, sem CI/CD nem composer scripts. Decisão pendente: (a) corrigir bugs remanescentes em sub-lote isolado, (b) reescrever do zero em sprint pós-refatoração (factories por domínio, escolha de Faker), (c) deletar e usar dump de produção como única fonte de seed para DEV. Discussão deferida pra calendário pós-refatoração de identidade global.

- **Audit metodológica — namespace de entidade não verificado antes de prescrever `use` (detectado no Sub-lote B1, 15/05/2026):** o plano do Sub-lote A criou `JusPrimeWebTestCase` importando `App\Entity\Auth\Tenant` (namespace inexistente; Tenant real em `App\Entity\Tenant\Tenant`). O bug ficou silencioso porque nenhum teste do Sub-lote A chamava `logarComTenant` ainda; explodiu apenas ao prescrever o Diff 2 do B1 (DemandasControllerTest). **Regra para próximos planos:** antes de prescrever qualquer `use` de entidade em classe base/utility de testes, verificar o namespace real com `rg -n 'namespace App\\Entity' app/src` e confirmar o caminho do arquivo via `find app/src -name '<Entidade>.php'`.

- **Audit metodológica — levantamento de fixtures unit não cruzou estado do UseCase correspondente (detectado no Sub-lote D, 15/05/2026):** `5c.3e.7-fixtures-levantamento.md` listou `DemitirFuncionarioUseCaseTest.php` como candidato a migrar `User::setTenant()` por UserTenant transient. Não verificou se o próprio `DemitirFuncionarioUseCase` havia sido refatorado para Tenant explícito. Resultado: `setTenant` no teste é load-bearing (UseCase usa `$user->getTenant()` em L38, L42-44, L51), tornando a remoção direta impossível sem refatorar o UseCase. Sub-lote D pulou o arquivo. **Regra para próximos levantamentos:** cruzar candidatos de teste unit com o código de produção testado — verificar se o UseCase/service correspondente ainda usa padrões legados (`getTenant()`, `getTenantRole()`, etc.) antes de classificar o `setTenant` no teste como dead code.

- **`MigrateLegatyRolesCommand` deletado — RESOLVIDO (15/05/2026):** Investigação pré-Etapa 6 identificou o comando como bloqueante: `execute()` lê `user.tenant_id` via `findBy(['tenant' => $tenant])` (L127) e lê/escreve `user.tenant_role_id` via `getTenantRole()`/`setTenantRole()` (L215, L219, L242) — ambas colunas a serem dropadas na Etapa 6. Decisão: **deletar** (não refatorar). Justificativa: (1) migração já concluída em produção — todos os 13 users com tenant_id têm tenant_role_id; (2) 2 users remanescentes sem role também não têm tenant_id, portanto fora do escopo do comando; (3) após Etapa 6, o conceito de "migrar role legada para TenantRole em `User`" torna-se inaplicável pois a coluna deixa de existir; (4) `UserTenant` não tem herança legada a migrar (criado corretamente na Etapa 2). Sem testes, sem callers externos (CI/CD, Makefile, controllers). Arquivo deletado, cache limpo, suite validada: 458 testes, 13 erros Grupo A inalterados, 0 novas falhas. Typo no nome da classe (`Legaty` em vez de `Legacy`) e no filename — morreu junto.

- **AccessRequestRepository.findPendingByTenant não filtra UserTenant.isActive (6.A):**
  Comportamento atual preservado — access requests pendentes de demitidos continuam
  aparecendo na listagem do admin. Validar com produto se é comportamento intencional
  (auditoria) ou bug (demitidos não deveriam ter requests pendentes visíveis).

- **GerarCodigoFuncionarioTest tem cobertura cega à DQL (6.A):** mock usa
  `Doctrine\ORM\Query` concreta com `willReturn` genérico em `createQuery` — não
  asserta a string DQL. A pivotação da query (User → UserTenant) em 6.A passou sem
  validação automática da DQL correta. Reescrever mock seguindo padrão atual
  (interface + InMemory ou KernelTestCase) em sprint futura.

- **Feature "pausar funcionário no tenant" removida em 6.B:** o toggle "Conta ativa"
  no form `EditUserTenantRoleType` tinha semântica incorreta — mapeava para
  `User.isActive` (desativava o login global) em vez de pausar o vínculo por tenant.
  Removido sem substituto: campo deletado do form, bloco de processamento removido
  do controller, render removido do template. Reimplementar como feature dedicada
  no futuro com: motivo (férias/licença/afastamento), data prevista de retorno,
  fluxo de aprovação se necessário. Arquivos alterados: `EditUserTenantRoleType.php`,
  `TenantController.editUserRole`, `templates/tenant/edit_user_role.html.twig`.

- **Bug persist($creator) removido por acidente em 6.C:** ao remover
  `setTenantRole` de `User` no `TenantBootstrapService`, a linha
  `$this->entityManager->persist($creator)` que estava no mesmo bloco
  `if` foi removida junto. Bug passou pela suite de 458 testes — o service
  não tem teste de integração do fluxo de bootstrap. Padrão recorrente:
  fluxos críticos (forms, services de bootstrap) escapam de cobertura.
  Considerar sprint dedicada a integration tests de `TenantBootstrapService`,
  `AceitarConviteEscritorioComContaUseCase` e demais services de boot/aceite.

- **TenantBootstrapService: find-or-create silencioso de UserTenant (6.C):**
  Se chamado com `$creator` que já tem vínculo ativo em outro role, o bootstrap
  sobrescreve o role sem aviso. Aceito como débito técnico para acelerar a Etapa 6.
  Conserto futuro: `bootstrap()` recebe `UserTenant` pronto na assinatura; callers
  (`TenantController::new` e `AppFixtures`) fazem o find-or-create antes.

- **Bug DateTimeImmutable em EditUserTenantRoleType (6.B smoke):** campo `dataAdmissao`
  do form Symfony retornava `DateTime` mutável, mas `UserTenant::setDataAdmissao` tipa
  como `?\DateTimeImmutable`. Bug passou pela suite de 458 testes porque não existe
  teste de submit no form. Padrão recorrente — ver também audit note do
  `GerarCodigoFuncionarioTest` (6.A). Considerar sprint dedicada a criar form-level
  integration tests para forms críticos (`EditUserTenantRoleType`, `EventoType`, etc)
  em momento futuro. Fix: `'input' => 'datetime_immutable'` adicionado ao campo
  `dataAdmissao` em `EditUserTenantRoleType.php`.

**Pré-requisitos bloqueantes da Etapa 6 (identificados na Etapa 4):**

Todos os pré-requisitos resolvidos — Etapa 6 desbloqueada.

- `Version20260508170000.php:60` — RESOLVIDO em Sub-lote E (15/05/2026)
- `DemitirFuncionarioUseCase.php` — RESOLVIDO em Sprint de Fixtures (15/05/2026):
  Recebe `Tenant` explícito via `DemitirFuncionarioInput.tenant`. Validações
  L38–40 e L51–53 removidas (redundantes com guardas do controller). Guard de
  substituto adicionada no controller via `existeVinculoAtivo`. `DemitirFuncionarioUseCaseTest`
  migrado: 2 testes removidos (validações movidas), `setTenant` eliminado.
- `MigrateLegatyRolesCommand.php` — RESOLVIDO (deletado) em 15/05/2026:
  Bloqueante descoberto na investigação pré-Etapa 6 (dependia de `user.tenant_id`
  e `user.tenant_role_id`). Migração já concluída em produção. Arquivo deletado.
  Ver audit note detalhada em "Pontos de auditoria".

---

## Achados paralelos pra triagem posterior

Lista única de observações feitas durante a refatoração de identidade
global que não se relacionam diretamente com o escopo (separação
user/user_tenant), mas foram registradas pra triagem futura. Nenhum
desses itens bloqueia a refatoração em andamento.

### A1 — Uso de `in_array('ROLE_SUPER_ADMIN', ...)` em PontoController

**Origem:** auditoria do Lote 4a (Fase 5c.3d), 2026-05-11.

**Local:**
- `app/src/Controller/PontoController.php` — `exportarFolhaPdf` (~L670)
- `app/src/Controller/PontoController.php` — `exportarFolhaXlsx` (~L753)

**Trecho:**
```php
$isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
```

**Status:** SUSPEITA, não confirmada. O `CLAUDE.md` aparentemente proíbe
checagem direta de role via `in_array`, recomendando uso do
`PermissionChecker`. Porém, o `CLAUDE.md` está marcado para revisão geral
após a Etapa 6 e pode conter regras desatualizadas. A semântica do
bypass (SUPER_ADMIN = dono da plataforma, acesso total cross-tenant)
é intencional — o que está em discussão é a forma de implementar a
checagem, não o comportamento.

**Ação:** revisitar junto da revisão do `CLAUDE.md` pós-Etapa 6.

### A2 — Ausência de guarda do target user em actions com User $user na rota

**Origem:** descoberta durante a expansão do sub-lote 4b.3 (Fase 5c.3d),
2026-05-11.

**Local:** `app/src/Controller/TenantController.php` — múltiplas actions.

Actions com guarda 1 aplicada (target user na rota):
- ✅ 4b.3: pontoAdd, pontoEdit, pontoDelete
- ✅ 4b.4: aprovarJustificativa, rejeitarJustificativa, novaJustificativaAdmin, downloadAnexoJustificativa
- ✅ 4b.1: edit, listUsers
- ✅ 4b.2: editUserName, demitirFuncionario, editUserRole L458
- ✅ 4b.5: removeResourceAccess
- ⏳ editUserRole — deferida (sprint arquitetural)

**Critério de deferral (editUserRole):** todas as refs legadas (acessos a `$user->getTenant()`, `$user->getTenantRole()`, campos migrados para `UserTenant` como `codigoFuncionario`, `cargo`, `lotacao`, etc.) dentro do método `editUserRole` (`app/src/Controller/TenantController.php`, L425–L583) ficam deferidas para sprint arquitetural pela complexidade do fluxo de role. Inventário atual (verificado em 2026-05-13):

- L468: `$user->getTenant()?->getId()`
- L470: `$user->getTenant()`
- L475: `$user->getCodigoFuncionario()`
- L476: `$user->setCodigoFuncionario(...)`
- L525: `$user->getTenant()?->getJornadaTenant()`
- L536 (×2): `$user->getTenant()` (na mesma linha, 2 chamadas)

Total: 7 refs no método. Levantamentos futuros devem aceitar como ESPERADO qualquer match dentro do range L425–L583 do arquivo `TenantController.php`, independente da linha exata listada acima — adições/remoções ao método antes da sprint arquitetural não devem virar falsa regressão.

Origem da correção: levantamento `5c-levantamento-2026-05-13.md` detectou L475 e L476 como refs reais não capturadas pela lista anterior do A2.

Actions sem target user (só guarda 2):
- ✅ 4b.1: index, show
- ✅ 4b.5: manageSedes
- ✅ 4b.6a: editSede (sem target user na rota — só guarda 2; commit `07091d9`)
- ✅ 4b.6b: deleteSede (sem target user na rota — só guarda 2; commit `bd17ec2`)

**Problema:** o `ParamConverter` padrão resolve `User $user` por ID
global, sem filtro de tenant. Não há `Voter` no projeto. O
`TenantContextValidatorListener` valida apenas o `$currentUser` logado,
não o target. Resultado: admin do tenant A pode operar sobre user do
tenant B via URL manipulada (`/B/user/{id_de_user_de_B}/...`).

**Status:** parcialmente corrigido. Sub-lotes 4b.3, 4b.4, 4b.1, 4b.2, 4b.5, 4b.6a e 4b.6b
concluídos (Lote 4b completo). Restam 7 refs de `editUserRole` deferidas para sprint arquitetural (inventário atualizado em 2026-05-13).

**Ação:** Lote 4b completo. As refs de `editUserRole`
deferidas dependem de decisão arquitetural separada.
Registrado aqui pra rastreabilidade — a refatoração de identidade
fechou um buraco de autorização pré-existente como efeito colateral.

### A3 — 13 testes com erro "Class not found" em UseCases de Expediente

**Origem:** descoberto na execução da suite após commit do sub-lote 4b.3
(Fase 5c.3d), 2026-05-11.

**Local:**
- `tests/Expediente/Unit/MoverPastaMarcadoresUseCaseTest.php` (8 erros)
- `tests/Expediente/Unit/RemoverMarcadorDaPastaUseCaseTest.php` (5 erros)

**Problema:** os testes referenciam classes que não existem no código:
- `App\Expediente\UseCase\MoverPastaMarcadoresUseCase`
- `App\Expediente\UseCase\RemoverMarcadorDaPastaUseCase`

Provavelmente os UseCases foram movidos, renomeados ou deletados em
alguma fase anterior, mas os testes não foram atualizados. Pré-existente
ao Lote 4b — confirmado por execução isolada do arquivo de teste no
estado pré-4b.3 (mesma falha).

**Status:** não-bloqueador. 441 dos 454 testes passam. Os 13 erros são
sempre os mesmos e independem das mudanças do Lote 4b.

**Ação:** triagem em momento dedicado (não durante a refatoração de
identidade). Restaurar as classes ou deletar os testes — decisão de
produto.

---

## Como retomar em chat novo do Claude Code

Este chat foi encerrado após a Etapa 4 para iniciar a Etapa 5 com contexto limpo.

No próximo chat, primeira mensagem deve ser:

> Estou retomando a refatoração descrita em `docs/refatoracao-identidade-global.md`.
> Leia o documento e me confirme em qual etapa vamos continuar.
> Próxima etapa: 5 (refatorar controllers e implementar tela de seleção).
> **Não execute nada ainda — só confirme que entendeu o contexto.**

### Status atual ao iniciar chat novo
- Etapas 1, 2, 3, 4 concluídas; Etapa 5 quase fechada (Sub-etapa 5c,
  Fases 5c.1, 5c.2, 5c.3a, 5c.3b, 5c.3c, 5c.3d, 5c.3e completas;
  falta 5c.4 cleanup).
- Banco de dev com dados reais de produção (restaurados em 07/05/2026).
- User dedicado de E2E (`e2e@jusprime.local`) funcional.
- 456 testes PHPUnit, 13 erros pré-existentes (Grupo A) inalterados.
- Branch refactor/etapa-5-identidade-global, HEAD em cae1b5c.
- Documento mestre, relatório 5c-2026-05-13 e levantamento de
  fixtures atualizados e completos.

Próximas tarefas em ordem:
1. 5c.4 cleanup (verificar resíduos pós-5c.3e)
2. Sprint dedicada de fixtures (5c.3e.7 sub-lotes A-E)
3. Sprint de robustez do aceite de convite
4. Etapa 6 (remover colunas de user)

### Pré-requisitos identificados que precisam ser tratados ANTES da Etapa 6
Ver seção "Pontos de auditoria identificados mas NÃO tratados" no início do documento.

### Modo de trabalho preferido
- Plan mode por padrão (revisar antes de executar)
- Edit automatically apenas para alterações pequenas, isoladas, ou atualização de docs
- Ask before edits raramente
