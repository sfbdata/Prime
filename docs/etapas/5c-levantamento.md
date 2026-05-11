# Sub-etapa 5c — Levantamento de escopo (11/05/2026)

## Contexto

Branch `refactor/etapa-5-identidade-global`. Sub-etapas 5a e 5b concluídas.
Esta sub-etapa refatora todas as referências legadas `$user->getTenant()` etc. para
`TenantContext::getCurrentTenant()` e implementa o `TenantSelecaoController` real.

---

## A. Referências PHP legadas em `app/src/`

### Contagem por método

| Método              | Arquivos |
|---------------------|----------|
| `->getTenant()`     | 46       |
| `->getTenantRole()` | 5        |
| `->getCargo()`      | 2        |
| `->getLotacao()`    | 2        |
| `->getCodigoFuncionario()` | 2 |
| `->getDemitidoEm()` | 0        |
| **Total arquivos únicos** | **57** |

### Distribuição por módulo/diretório

| Módulo                | getTenant | getTenantRole | getCargo | getLotacao | getCodigoFuncionario | TOTAL |
|-----------------------|-----------|---------------|----------|------------|----------------------|-------|
| `src/Kanban/`         | 12        | 0             | 0        | 0          | 0                    | 12    |
| `src/Controller/`     | 10        | 0             | 1        | 1          | 2                    | **14** |
| `src/Pasta/`          | 8         | 0             | 0        | 0          | 0                    | 8     |
| `src/Auth/`           | 6         | 3             | 0        | 0          | 0                    | 9     |
| `src/Expediente/`     | 5         | 0             | 0        | 0          | 0                    | 5     |
| `src/Tenant/`         | 2         | 0             | 0        | 0          | 0                    | 2     |
| `src/Profile/`        | 0         | 0             | 1        | 1          | 0                    | 2     |
| `src/Service/`        | 1         | 1             | 0        | 0          | 0                    | 2     |
| `src/Security/`       | 1         | 0             | 0        | 0          | 0                    | 1     |
| `src/Entity/`         | 1         | 0             | 0        | 0          | 0                    | 1     |
| `src/Command/`        | 0         | 1             | 0        | 0          | 0                    | 1     |
| **TOTAL**             | **46**    | **5**         | **2**    | **2**      | **2**                | **57** |

### Lista completa de arquivos — `getTenant()` (46)

```
app/src/Pasta/UseCase/CriarPastaSecaoUseCase.php
app/src/Pasta/UseCase/RenomearPastaSecaoUseCase.php
app/src/Pasta/UseCase/ExcluirPastaSecaoUseCase.php
app/src/Pasta/UseCase/EnviarObservacaoDetalhesUseCase.php
app/src/Pasta/UseCase/MoverDocumentoParaSecaoUseCase.php
app/src/Pasta/UseCase/EnviarObservacaoFinanceiraUseCase.php
app/src/Pasta/UseCase/EnviarMensagemPastaUseCase.php
app/src/Pasta/UseCase/AdicionarChecklistItemUseCase.php
app/src/Expediente/UseCase/SincronizarMarcadoresDaPastaUseCase.php
app/src/Expediente/UseCase/EditarMarcadorUseCase.php
app/src/Expediente/UseCase/CriarMarcadorUseCase.php
app/src/Expediente/UseCase/ExcluirMarcadorUseCase.php
app/src/Expediente/Controller/ExpedienteController.php
app/src/Kanban/UseCase/ExcluirComentarioUseCase.php
app/src/Kanban/UseCase/ExcluirBoardUseCase.php
app/src/Kanban/UseCase/AtualizarCardUseCase.php
app/src/Kanban/UseCase/CriarBoardUseCase.php
app/src/Kanban/UseCase/AtualizarBoardUseCase.php
app/src/Kanban/UseCase/ListarBoardsUseCase.php
app/src/Kanban/Controller/KanbanMarcadorController.php
app/src/Kanban/Controller/KanbanCardController.php
app/src/Kanban/Controller/KanbanComentarioController.php
app/src/Kanban/Controller/KanbanAnexoController.php
app/src/Kanban/Controller/KanbanChecklistController.php
app/src/Kanban/Controller/KanbanBoardController.php
app/src/Auth/Controller/ConviteController.php
app/src/Auth/Controller/GerenciarConvitesController.php
app/src/Auth/UseCase/AceitarConviteEscritorioSemContaUseCase.php
app/src/Auth/UseCase/AceitarConviteEscritorioComContaUseCase.php
app/src/Auth/DTO/ConviteAdminOutput.php
app/src/Auth/DTO/ConviteOutput.php
app/src/Tenant/UseCase/DemitirFuncionarioUseCase.php
app/src/Tenant/Controller/TenantUserProfileController.php
app/src/Profile/DTO/PerfilOutput.php  (getCargo + getLotacao aqui)
app/src/Security/UserAuthenticator.php
app/src/Service/NotificacaoService.php
app/src/Entity/Tenant/Tenant.php
app/src/Controller/PastaController.php
app/src/Controller/AuditLogController.php
app/src/Controller/TenantController.php
app/src/Controller/JornadaColaboradorController.php
app/src/Controller/TenantRoleController.php
app/src/Controller/FeriadoController.php
app/src/Controller/AccessRequestController.php
app/src/Controller/JornadaTenantController.php
app/src/Controller/TarefaController.php
app/src/Controller/PontoController.php
```

### Lista completa — `getTenantRole()` (5)

```
app/src/Service/PermissionChecker.php
app/src/Auth/UseCase/AceitarConviteEscritorioSemContaUseCase.php
app/src/Auth/UseCase/AceitarConviteEscritorioComContaUseCase.php
app/src/Auth/DTO/ConviteAdminOutput.php
app/src/Command/MigrateLegatyRolesCommand.php
```

---

## B. Referências Twig legadas (`app.user.tenant*`)

Apenas **2 arquivos** afetados, todos usando `app.user.tenant.id`:

| Arquivo | Linhas | Uso |
|---------|--------|-----|
| `app/templates/_sidebar.html.twig` | 152, 162, 172, 203, 213 | Construção de URLs no menu lateral |
| `app/templates/tenant/index.html.twig` | 34 | Comparação `app.user.tenant.id == tenant.id` |

Propriedades `app.user.tenantRole`, `app.user.cargo`, `app.user.lotacao` **não aparecem** em templates.

---

## C. API do TenantContext (completa, sem precisar adicionar métodos)

Arquivo: `app/src/Service/Tenant/TenantContext.php`

```
getCurrentTenant(): ?Tenant              — busca Tenant via session key 'current_tenant_id'
getCurrentUserTenant(): ?UserTenant      — par (user, tenant) do contexto atual
setCurrentTenant(int $tenantId): void    — valida vínculo ativo, grava na sessão
hasCurrentTenant(): bool                 — verifica se há tenant na sessão
clearCurrentTenant(): void               — remove da sessão
```

**Conclusão:** API já completa. 5c não precisa adicionar métodos ao TenantContext.

---

## D. TenantSelecaoController — estado atual

Arquivo: `app/src/Controller/Tenant/TenantSelecaoController.php`

- Apenas 1 action `GET /escritorio/selecionar` via `__invoke()`
- Renderiza `tenant/selecionar.html.twig` → placeholder "Em breve: seleção de escritório."
- Não tem POST / lógica de seleção real
- **Precisa:** listar `UserTenant` ativos do user logado + POST que chama `TenantContext::setCurrentTenant()`

---

## E. Estado da auto-seleção (Etapa 4) — confirmado

**UserAuthenticator** (`app/src/Security/UserAuthenticator.php`):
- Se `count($tenants) === 1` → chama `setCurrentTenant()` e redireciona para `expediente_index`
- Se `count($tenants) === 0` + `ROLE_SUPER_ADMIN` → redireciona para `admin_platform_dashboard`
- Caso contrário → redireciona para `tenant_selecionar`

**TenantContextValidatorListener** (`app/src/EventListener/TenantContextValidatorListener.php`):
- Prioridade 7, todo request
- Se user autenticado sem tenant na sessão + não é `ROLE_SUPER_ADMIN` → redireciona para `tenant_selecionar`
- Se `getCurrentUserTenant()` retorna `null` ou `!isActive()` → limpa sessão + flash + redireciona para `tenant_selecionar`
- Rotas ignoradas: `app_login`, `app_logout`, `tenant_selecionar`, `admin_platform_dashboard`

---

## Observações para o planejamento de fases

1. **getTenant() em UseCases** (Pasta, Expediente, Kanban): padrão é receber `Tenant` como parâmetro do controller — o controller passa `$tenant = $tenantContext->getCurrentTenant()`. Não é `$user->getTenant()` no UseCase chamando diretamente. Precisará injetar `TenantContext` no controller e passar via DTO/parâmetro.

2. **Kanban tem 12 arquivos** (5 controllers + 7 UseCases) — módulo mais afetado em refs absolutas.

3. **src/Controller/ legado tem 14 arquivos** — maior concentração num único diretório. Esses controllers ainda NÃO foram migrados para `src/<Domínio>/Controller/`.

4. **Auth/UseCase (AceitarConvite*)** usa `getTenant()` e `getTenantRole()` para popular campos do UserTenant que está sendo criado no momento do aceite — tratamento especial (o tenant ainda não está na sessão quando o convite é aceito).

5. **Entity/Tenant/Tenant.php** tem `getTenant()` — provavelmente método auto-referencial ou de compatibilidade; precisa verificar antes de remover.

6. **Migration E2E** (`Version20260508170000`) faz `UPDATE user SET tenant_id=1` para compensar o template legado do sidebar — pode ser removida após migrar os templates.
