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

##### ⏳ Fase 5c.3b+ — Refatorar demais módulos com referências PHP legadas (PENDENTE)

---

### ⏳ Etapa 6 — Limpeza (PENDENTE)

- Remover colunas de `user`: `tenant_id`, `tenant_role_id`, `cargo_id`, `lotacao_id`, `codigo_funcionario`, `demitido_em`, `last_login`
- Remover `user_profiles.data_admissao`
- Trocar UNIQUE de `user.email` por UNIQUE global puro (já é, mas confirmar que está como índice de coluna e que faz sentido depois da remoção do `tenant_id`)

---

## Histórico de operações em ambiente

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
- OPcache do PHP-FPM: criar procedimento documentado de reload após mudanças em rotas (`kill -USR2` no processo ou outro mecanismo) — evita 404 em rotas que existem no `debug:router` mas não são encontradas via HTTP
- Padronizar chave de flash messages no projeto: `'erro'` gera `alert-erro` (classe Bootstrap inválida — sem estilização de cor). Mapear para `'danger'` ou ajustar `base.html.twig` para mapear `'erro' → 'danger'`. Afeta principalmente os controllers das fases 5b.3a e 5b.3b
- Validação visual da regra `current_tenant().id == tenant.id` em `/tenant` impossível com apenas 1 tenant no banco de dev. Quando segundo tenant entrar (5c.3+), revalidar que botão "Editar" só aparece no card do tenant atualmente selecionado
- `AtualizarBoardUseCase` (`app/src/Kanban/UseCase/AtualizarBoardUseCase.php`) não possui `KanbanBoardRepository` injetado e não chama flush explícito — depende do unit-of-work do Doctrine para persistir as mudanças no board. Comportamento atual e funcional, mas inconsistente com outros UseCases que chamam `$repository->salvar()` explicitamente. Revisar numa sprint futura.
- `PastaController.php` tem ~1700 linhas — controller monolítico, candidato a quebra em módulos menores (`PastaShowController`, `PastaChecklistController`, `PastaObservacaoController`, etc.). Não tratar na refatoração de identidade; tratar em sprint dedicada.
- `TenantContextValidatorListener` não tem testes funcionais/integração cobrindo o cenário "user sem tenant tenta acessar rota protegida". Durante a Fase 5c.3b, 6 testes unitários `testUsuarioSemTenantLancaLogicException` dos UseCases de Pasta foram removidos (tornaram-se inaplicáveis com Tenant explícito) sem substituto direto. A barreira existe no código (listener redireciona para `tenant_selecionar`), mas não está coberta por teste automatizado. Criar testes funcionais para o listener em sprint futura.
- Criar helper compartilhado `logarComTenant()` em WebTestCase base do projeto. A 5c.3b precisou implementar o helper em `PastaSecaoControllerTest`, e esse padrão vai se repetir em todo teste funcional das fases 5c.3c+. Não duplicar.
- Validações de posse de entidade que comparam `$user->getTenant()` com o tenant corrente (ex: `PastaController.php` linha 775 verifica se `$responsavel->getTenant()` é igual ao tenant do operador) vão quebrar na Etapa 6 quando `user.tenant_id` for removido. Precisam migrar para checagem via `UserTenant` antes da Etapa 6. Inventariar todas as ocorrências antes.
- `ExpedienteController::acervoGeral()` usa `pastaRepository->findAll()` sem filtro de tenant — risco de vazamento de dados cross-tenant. Decisão: 5c.3c adiciona apenas `assertAccess()` pra exigir autenticação/permissão. Filtro real precisa ser definido em sprint dedicada — entender primeiro se "acervo geral" é conceito intencional ou bug de copy-paste.

**Pré-requisitos bloqueantes da Etapa 6 (identificados na Etapa 4):**

- `app/templates/_sidebar.html.twig` linhas 152, 162, 172, 203, 213: usam `app.user.tenant.id` para construir URLs de navegação — migrar para `TenantContext` exposto via Twig global antes de remover `user.tenant_id`
- `app/templates/tenant/index.html.twig:34`: usa `app.user.tenant.id == tenant.id` — mesmo padrão legado, migrar junto
- `app/migrations/Version20260508170000.php`: faz `UPDATE user SET tenant_id=1` no E2E user para compensar o template legado — remover quando os templates forem refatorados
- `app/src/Expediente/Controller/ExpedienteController.php:39`: usa `$usuario->getTenant()` — refatorar para `TenantContext`
- Fixtures de testes E2E (`advogado1@escritorio.com.br` e demais users carregados do dump): não têm `user_tenant` após a Etapa 4 — adicionar registros de UserTenant nas fixtures antes de rodar a suite completa

---

## Como retomar em chat novo do Claude Code

Este chat foi encerrado após a Etapa 4 para iniciar a Etapa 5 com contexto limpo.

No próximo chat, primeira mensagem deve ser:

> Estou retomando a refatoração descrita em `docs/refatoracao-identidade-global.md`.
> Leia o documento e me confirme em qual etapa vamos continuar.
> Próxima etapa: 5 (refatorar controllers e implementar tela de seleção).
> **Não execute nada ainda — só confirme que entendeu o contexto.**

### Status atual ao iniciar chat novo
- Etapas 1, 2, 3, 4 concluídas
- Banco de dev local com dados reais de produção (restaurados em 07/05/2026)
- User dedicado de E2E (`e2e@jusprime.local`) funcional
- 9/9 testes E2E passando
- Documento mestre `docs/refatoracao-identidade-global.md` atualizado e completo

### Pré-requisitos identificados que precisam ser tratados ANTES da Etapa 6
Ver seção "Pontos de auditoria identificados mas NÃO tratados" no início do documento.

### Modo de trabalho preferido
- Plan mode por padrão (revisar antes de executar)
- Edit automatically apenas para alterações pequenas, isoladas, ou atualização de docs
- Ask before edits raramente
