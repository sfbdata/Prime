# Autorização — Estado Atual do Sistema

> Documento descritivo do modelo de autorização **como está implementado hoje**.
> Falhas são marcadas como falhas. Nada aqui é como "deveria ser".
> Cada afirmação técnica cita o arquivo de origem.
> **Leia este documento antes de mexer em qualquer coisa de permissão.**

---

## 1. Visão Geral

O sistema tem quatro camadas de autorização, todas implementadas via
`app/src/Service/PermissionChecker.php`:

| Camada | Mecanismo | Status |
|---|---|---|
| **Módulos** | `modules.{x}.view` no TenantRole | Ativo |
| **Admin** | `admin.{x}.{y}` no TenantRole | Ativo |
| **Recursos-tipo** | `resources.{x}.{y}` no TenantRole | Código morto — definido, nunca chamado diretamente |
| **Recursos-item** | Tabela `resource_access` | Ativo |

As três primeiras convergem no mesmo mecanismo de armazenamento: o
`TenantRole` de um usuário acumula `TenantRolePermission`s, cada uma
apontando para um `code` na tabela `permission`. A leitura é sempre
um scan linear em `PermissionChecker::hasPermissionFromUserTenant()`
(`app/src/Service/PermissionChecker.php`, linhas ~95–103).

A camada Recursos-item é a única com tabela própria (`resource_access`),
independente do TenantRole.

---

## 2. Bypasses do PermissionChecker

Dois caminhos curto-circuitam qualquer checagem de permissão.
Ambos estão declarados como os primeiros passos em **todos** os
métodos públicos de `PermissionChecker.php`:

### 2a. ROLE_SUPER_ADMIN (global)

```php
// PermissionChecker.php — isGlobalSuperAdmin(), chamado em todo método público
if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
    return true;
}
```

Qualquer usuário com a role Symfony `ROLE_SUPER_ADMIN` passa em
qualquer checagem, independente de tenant ou permissão. É uma role
atribuída diretamente no `User` (campo `roles`, JSONB), fora do
sistema de `TenantRole`.

**Onde é atribuída:** manualmente via comando CLI
`app/src/Command/CreateSuperAdminCommand.php` (~l. 46):

```php
$user->setRoles(['ROLE_SUPER_ADMIN']);
```

Não é atribuída automaticamente no bootstrap de tenant — só por
comando manual. Além do `PermissionChecker`, essa role é verificada
em outros pontos:

- `app/src/Security/UserAuthenticator.php` (l. 72): usuário sem tenant
  ativo e com `ROLE_SUPER_ADMIN` é redirecionado para `admin_platform_dashboard`
- `app/src/EventListener/TenantContextValidatorListener.php` (l. 50):
  sem tenant context ativo, permite acesso sem validar tenant
- `app/config/packages/security.yaml` (l. 37): rota `/tenant/new`
  requer `ROLE_SUPER_ADMIN`

### 2b. TenantRole.isSystem() (por tenant)

```php
// PermissionChecker.php — segundo passo em todos os métodos
if ($userTenant?->getTenantRole()?->isSystem() === true) {
    return true;
}
```

Se o `TenantRole` do usuário no tenant atual tem `isSystem = true`,
o usuário passa em qualquer checagem **dentro desse tenant**. É o
equivalente a "administrador total do escritório".

**Onde esse role é criado:** `app/src/Service/TenantBootstrapService.php`,
método `findOrCreateAdminRole()` (~l. 140), executado no bootstrap de
cada novo tenant:

```php
$adminRole = new TenantRole();
$adminRole->setName('Administrador do Escritório');
$adminRole->setDescription('Perfil padrão com acesso total ao escritório. Não pode ser excluído.');
$adminRole->setIsSystem(true);
$this->entityManager->persist($adminRole);
$this->attachAllPermissions($adminRole); // recebe TODAS as permissões do catálogo
```

O role resultante recebe todas as permissões cadastradas via
`attachAllPermissions()`. Qualquer usuário vinculado a esse role
bypassa todas as verificações dentro do tenant.

---

## 3. Camada Módulos

### Como funciona

```php
// PermissionChecker.php — canAccessModule()
return $this->hasPermissionFromUserTenant(
    $userTenant,
    sprintf('modules.%s.view', $module)
);
```

`canAccessModule(User, ?Tenant, string $module)` constrói a string
`modules.{module}.view` e verifica se o TenantRole do usuário tem
essa permissão. Retorna `bool`.

### Onde é chamado

Em **listagens e ações de criação** de cada controller de módulo.
Nunca em ações sobre itens específicos (show, edit, delete) — ver
Falha F1.

Exemplos confirmados:
- `ClienteController::index()` e `::newPF()` — `canAccessModule('clientes')`
  (`app/src/Cliente/Controller/ClienteController.php`, linhas ~115 e ~145)
- `ExpedienteController::assertAccess()` — `canAccessModule('expediente')`
  (`app/src/Expediente/Controller/ExpedienteController.php`, l. 262)

### Twig — função de template

`can_access_module('chave')` em `app/templates/_sidebar.html.twig`.
Wrapper Twig do mesmo `canAccessModule`.

### Módulos cadastrados no catálogo

Fonte: `app/src/DataFixtures/PermissionFixture.php` e
`app/migrations/Version20260401130000.php`.

| Chave | Permissão | Guard na sidebar | Guard no controller | Situação |
|---|---|---|---|---|
| `expediente` | `modules.expediente.view` | **nenhum** — item aparece para todos no menu | ✅ `assertAccess()` bloqueia sem permissão (l. 262) | Ativo — ver nota abaixo |
| `clientes` | `modules.clientes.view` | `can_access_module('clientes')` | ✅ `index()`/`new()` | Ativo — label na sidebar é "Financeiro" (inconsistência) |
| `processos` | `modules.processos.view` | `can_access_module('processos')` | ✅ | Ativo |
| `tarefas` | `modules.tarefas.view` | `can_access_module('tarefas')` | ✅ | Ativo |
| `agenda` | `modules.agenda.view` | `can_access_module('agenda')` | ✅ | Ativo |
| `servicedesk` | `modules.servicedesk.view` | `can_access_module('servicedesk')` | ✅ | Ativo |
| `ponto` | `modules.ponto.view` | `can_access_module('ponto')` | ✅ | Ativo |
| `kanban` | `modules.kanban.view` | `can_access_module('kanban')` | ✅ | Ativo |
| `pastas` | `modules.pastas.view` | **nenhum** — sem item de menu próprio | não verificado | "Expediente" e "Demandas" são as entradas do domínio Pasta |
| `financeiro` | `modules.financeiro.view` | **nenhum** | não verificado | Marcado como "futuro" na fixture; sem tela |
| `bi` | `modules.bi.view` | **nenhum** | não verificado | Marcado como "futuro" na fixture; sem tela |

**Nota sobre Expediente:** o item aparece sem guard no menu Twig (sidebar),
então todos os usuários autenticados o veem. Porém o controller
`ExpedienteController::assertAccess()` (l. 262) ainda chama
`canAccessModule('expediente')` e lança `AccessDeniedException` se a
permissão não estiver no perfil. O acesso funciona na prática porque
a permissão `modules.expediente.view` foi concedida manualmente a
todos os perfis via painel como contorno do Bug B — o código não foi
alterado, o cadastro de dados foi. O guard está ativo.

**Nota sobre Demandas:** `DemandasController::index()`
(`app/src/Pasta/Controller/DemandasController.php`, 39 linhas no total)
não tem nenhum guard de permissão — só `$this->getUser()`. A rota
`demandas_index` é livre para qualquer usuário autenticado, sem
verificação de módulo. Não há guard na sidebar tampouco. Intencional
ou esquecimento — não está documentado.

---

## 4. Camada Admin

### Como funciona

```php
// PermissionChecker.php — canAdminister()
return $this->hasPermissionFromUserTenant($userTenant, $permission);
// $permission já vem como string completa: 'admin.roles.manage'
```

`canAdminister(User, ?Tenant, string $permission)` passa o code
diretamente para `hasPermissionFromUserTenant`, sem construção de
string. A lógica dos 5 passos é **byte-a-byte idêntica** à de
`canAccessModule`. A única diferença está em como o permission code
é montado: módulos usam `sprintf('modules.%s.view', $module)`, admin
recebe o code completo.

Equivalências exatas:
```php
canAdminister($u, $t, 'admin.roles.manage')  == hasPermission($u, $t, 'admin.roles.manage')
canAccessModule($u, $t, 'clientes')          == hasPermission($u, $t, 'modules.clientes.view')
```

### Permissões admin e onde são checadas

| Permissão | Controller | Tipo de proteção | Observação |
|---|---|---|---|
| `admin.roles.manage` | `TenantRoleController::assertAccess()` | Listagem + CRUD de roles | Um guard cobre tudo |
| `admin.users.manage` | `TenantController::listUsers()` (l. 329) + `TenantUserProfileController` (l. 54) + ações em `TenantController` | Listagem + ações de gestão | Mistura "ver lista" e "executar ação" numa permissão só |
| `admin.users.invite` | `GerenciarConvitesController::assertAccess()` | Listagem + CRUD de convites | — |
| `admin.access_requests.approve` | `AccessRequestController` (l. 50) | Listagem + aprovação | — |
| `admin.tenant.settings.manage` | `TenantController` (l. 80, 174, 200) + `FeriadoController` (l. 39) | Cobre tenant index, show, edit e feriados | Uma permissão protege 4 telas e 2 itens de sidebar distintos |
| `admin.servicedesk.manage` | `ServiceDeskController` (l. 74, 209, 246, 286, 333) | Dashboard + 4 ações | — |
| `admin.ponto.manage` | `TenantController` (l. 1516, 1570, 1636) | Criar/editar/excluir sede — sem listagem protegida | — |
| `admin.audit.view` | `AuditLogController` (l. 38) | Apenas listagem | — |
| `admin.tarefas.manage` | **NENHUM** | — | **Permissão fantasma** — existe no catálogo e na sidebar mas nenhum controller a verifica |

### Seção admin da sidebar

`app/templates/_sidebar.html.twig` declara a seção admin com separador
visual e usa `can_administer('admin.*.*')` em vez de `can_access_module`.
A visibilidade da seção inteira é o OR de todas as permissões admin
(linhas ~65–75 do arquivo). Dois itens compartilham a mesma permissão
`admin.tenant.settings.manage` (Meu Escritório e Feriados e Folgas).

---

## 5. Camada Recursos

### 5a. Recursos-tipo (código morto)

```php
// PermissionChecker.php — canActOnResource()
return $this->hasPermissionFromUserTenant(
    $userTenant,
    sprintf('resources.%s.%s', $resourceType, $action)
);
```

O método `canActOnResource(User, ?Tenant, string $resourceType, string $action)`
existe em `PermissionChecker.php` mas **não é chamado por nenhum
controller ou serviço** do projeto. É código morto.

As permissões `resources.{tipo}.{ação}` no TenantRole existem no
banco (fixture e migration), aparecem no painel de perfis e podem ser
atribuídas — mas nunca são testadas diretamente. Funcionam apenas como
**fallback interno** de `canAccessResource` (item 5b abaixo).

### 5b. Recursos-item (ativo)

```php
// PermissionChecker.php — canAccessResource()
// Passo 4 — item específico tem prioridade
$resourceAccess = $this->resourceAccessRepository
    ->findForUserAndResource($user, $resourceType, $resourceId);
if ($resourceAccess !== null && $resourceAccess->allows($action)) {
    return true;
}
// Passo 5 — fallback: permissão de tipo no TenantRole
return $this->hasPermissionFromUserTenant(
    $userTenant,
    sprintf('resources.%s.%s', $resourceType, $action)
);
```

`canAccessResource(User, ?Tenant, string $resourceType, int $resourceId, string $action)`
verifica primeiro se existe um `ResourceAccess` para aquele item
específico; se não, cai para a permissão de tipo no TenantRole.

**51 chamadas reais** no código, todas via
`ResourceAccessTrait::denyResourceAccessUnlessGranted()`
(`app/src/Controller/Trait/ResourceAccessTrait.php`), sempre em ações
sobre item específico (show, edit, delete, upload, download).

### Cobertura real por tipo de recurso

| Recurso | Catálogo | Código (`canAccessResource`) |
|---|---|---|
| `pasta` | ✅ view/edit/delete | ✅ 20+ chamadas em `PastaController`, `PastaSecaoController`, `PeticionarController` |
| `cliente` | ✅ view/edit/delete | ✅ 8+ chamadas em `ClienteController` |
| `processo` | ✅ view/edit/delete | ✅ show/edit/delete em `ProcessoController` (l. 168/139/219, via `AccessRequest::RESOURCE_PROCESSO`) |

Nenhum outro módulo (ponto, agenda, kanban, tarefas, servicedesk,
expediente) tem permissões `resources.*` no catálogo.

### Entidade ResourceAccess

**Arquivo:** `app/src/Entity/Permission/ResourceAccess.php`

Campos: `user`, `resourceType`, `resourceId`, `canView`, `canEdit`,
`canDelete`, `grantedAt`, `grantedBy`.

Constraint único em `(user_id, resource_type, resource_id)` — um
registro por usuário+item. Representa acesso a **um item específico**,
diferente de `resources.cliente.view` no TenantRole (que cobriria
todos os clientes de uma vez).

---

## 6. Fluxo de Solicitação de Acesso por Item

### Entidade AccessRequest

**Arquivo:** `app/src/Entity/Permission/AccessRequest.php`

Campos: `user`, `resourceType`, `resourceId`, `action`, `status`
(`pending`/`approved`/`denied`), `requestedAt`, `description`,
`decidedAt`, `decidedBy`.

Unicidade de pendentes via partial index no banco (`WHERE status = 'pending'`),
não via `UniqueConstraint` do Doctrine. Criado em
`app/migrations/Version20260331120000.php`.

### Fluxo completo

```
1. Usuário acessa /pasta/42 (GET)

2. PastaController::show() chama:
   denyResourceAccessUnlessGranted(permissionChecker, tenant,
     'pasta', 42, 'view', 'pasta_index', 'NUP da Pasta')
   (app/src/Controller/Trait/ResourceAccessTrait.php)

3. canAccessResource() retorna false

4. Trait redireciona para:
   /pasta/?requestAccess=1&resourceType=pasta&resourceId=42
          &action=view&resourceLabel=NUP+da+Pasta

5. Template pasta/index.html.twig inclui:
   {{ include('_partials/modal_access_request.html.twig') }}
   JS detecta query param requestAccess=1 e abre o modal.

6. Usuário preenche justificativa e submete.
   AJAX POST /access-requests/submit
   AccessRequestController::submit() valida, verifica duplicata
   pendente, cria AccessRequest(status='pending').

7. Admin vê em /access-requests — painel lista pendentes do tenant
   via AccessRequestRepository::findPendingByTenant().

8. Admin clica "Aprovar". Modal exibe checkboxes canView/canEdit/canDelete.
   POST /access-requests/{id}/approve
   AccessRequestController::approve() busca ou cria ResourceAccess,
   atualiza os três booleans, registra grantedBy + decidedAt,
   marca AccessRequest como 'approved'.

9. Na próxima tentativa, canAccessResource() encontra o ResourceAccess
   e allows('view') retorna true. Acesso concedido.
```

---

## 7. Modelo decidido: módulo e recurso são eixos PARALELOS

> **Decisão do dono (2026-06-30, achado M6 da auditoria pós-remediação).** Antes
> este documento registrava a *intenção* de um modelo **sequencial** (módulo como
> pré-requisito do recurso). Essa intenção foi **revista**: o modelo é, por
> decisão, **paralelo** — e o código já o implementa.

Módulo e recurso-item são **dois eixos independentes**, cada um com seu papel:

| Eixo | Papel | Onde é checado |
|---|---|---|
| **Módulo** (`canAccessModule`) | **Descoberta**: quem vê a listagem e cria itens | index (listagem) e new (criação) |
| **Recurso-item** (`canAccessResource`) | **Autorização do item**: quem abre/edita/exclui aquele item específico | show / edit / delete e demais ações por id |

| Ação | O que é checado |
|---|---|
| index (listagem) | apenas `canAccessModule` |
| new (criação) | apenas `canAccessModule` |
| show (item) | apenas `canAccessResource` |
| edit (item) | apenas `canAccessResource` |
| delete (item) | apenas `canAccessResource` |

**Não há dupla checagem, e isso é intencional.** Um `ResourceAccess` é concedido
**somente** por um admin (`admin.access_requests.approve`) e **somente** sobre um
recurso do próprio tenant (validado em `AccessRequestController::submit`/`approve`
via `recursoPertenceAoTenant`; `ResourceAccess` é `TenantAware`). Logo, **o grant
explícito JÁ é a autorização daquele item** — não faz sentido exigir também o
módulo, que governa apenas a descoberta/listagem. O caso de uso suportado é
"compartilhar um item isolado com quem não tem o módulo inteiro" (ex.: colaborador
que deve ver só uma pasta). Ver Falha F1 (rebaixada a comportamento aceito).

---

## 8. Falhas Conhecidas

### F1 — show/edit/delete não verificam o módulo (COMPORTAMENTO ACEITO, não é falha)

> **Status: aceito por design** (decisão do dono, 2026-06-30 — achado M6 da
> auditoria pós-remediação). Antes classificado "crítico"; **rebaixado** após a
> decisão do modelo paralelo (seção 7). Mantido aqui para registro técnico.

**Afeta: Cliente, Pasta e Processo (os recursos com `canAccessResource` ativo).**

`ClienteController::show()` (l. 204), `::edit()` (l. 229), `::delete()` (l. 272);
`PastaController::show()` (l. 185), `::editar()` (l. 824), `::delete()` (l. 952);
`ProcessoController::show()` (l. 168), `::edit()` (l. 139), `::delete()` (l. 219)
chamam apenas `canAccessResource`, sem chamar `canAccessModule`.

Efeito: um usuário com `ResourceAccess` para `pasta#42` mas sem
`modules.pastas.view` consegue acessar `/pasta/42` diretamente pela URL. Ele não
vê a listagem (que bloqueia), mas acessa o item.

**Por que isso é aceito e não um vazamento:** o `ResourceAccess` só existe porque
um **admin o concedeu** (`AccessRequestController::approve`), e **só** sobre um
recurso do próprio tenant (`recursoPertenceAoTenant`; `ResourceAccess` é
`TenantAware`). O acesso é exatamente o que o admin autorizou para aquele item — o
módulo governa só a descoberta/listagem, não a autorização do item concedido.
Cross-tenant continua fechado pelo `TenantFilter` (Cliente/Pasta/Processo são
TenantAware → `find()` por id de outro tenant → 404). Ver seção 7.

### F2 — canActOnResource é código morto

`PermissionChecker::canActOnResource()` existe e constrói
`resources.{tipo}.{ação}`, mas nenhum controller ou serviço o chama.
As permissões de tipo no TenantRole só funcionam como fallback interno
de `canAccessResource`. O painel de perfis permite atribuí-las a roles,
mas atribuir `resources.pasta.view` a um role não garante acesso a
nenhuma pasta específica — é apenas o fallback genérico.

### F3 — resources.processo.* fantasma → CORRIGIDO (Processo agora é wired)

> **Desatualizado.** Quando este doc foi escrito, não havia chamada de
> `canAccessResource(..., 'processo', ...)`. **Hoje há:** `ProcessoController`
> show/edit/delete (l. 168/139/219) usam `canAccessResource` com
> `AccessRequest::RESOURCE_PROCESSO` (`= 'processo'`). Logo o item-level
> (`ResourceAccess` de processo) funciona, e a permissão de tipo
> `resources.processo.*` no TenantRole passa a ter efeito como **fallback** de
> `canAccessResource` (passo 5). Processo deixou de ser fantasma; equipara-se a
> Cliente/Pasta.

### F4 — admin.tarefas.manage é permissão fantasma

`admin.tarefas.manage` está no catálogo (`PermissionFixture.php`) e
na condição de visibilidade da seção admin da sidebar
(`_sidebar.html.twig`, linhas ~65–75). Nenhum controller verifica
essa permissão. Atribuí-la não libera nenhuma funcionalidade.

### F5 — admin e módulo são tecnicamente a mesma coisa

`canAdminister` e `canAccessModule` têm lógica interna idêntica.
A diferença está apenas no como o permission code é construído
(sprintf com prefixo vs string direta). Não há separação de mecanismo,
entidade ou tabela. A distinção existe só como convenção de nomenclatura
e prefixo de string.

### F6 — Divergência fixture vs migration

`PermissionFixture.php` inclui `modules.expediente.view` e
`modules.kanban.view`, mas `Version20260401130000.php` não os inclui.
A migration inclui `modules.precadastros.view`, que não está na fixture.
Em produção (migration) e em desenvolvimento (fixtures), o catálogo
é diferente.

### F7 — Label "Financeiro" com chave `clientes`

Na sidebar, o item "Financeiro" usa `can_access_module('clientes')` e
aponta para `cliente_index`. O label exibido é "Financeiro" mas a
chave de permissão e a rota são do módulo `clientes`.

---

## 9. Onde Vive Cada Coisa

| Camada | Método PHP | Tabela(s) | Arquivo principal |
|---|---|---|---|
| Módulos | `canAccessModule()` | `permission` (`modules.*`), `tenant_role_permission`, `tenant_role` | `app/src/Service/PermissionChecker.php` |
| Admin | `canAdminister()` | `permission` (`admin.*`), `tenant_role_permission`, `tenant_role` | `app/src/Service/PermissionChecker.php` |
| Recursos-tipo | `canActOnResource()` *(morto)* | `permission` (`resources.*`), `tenant_role_permission` | `app/src/Service/PermissionChecker.php` |
| Recursos-item | `canAccessResource()` | `resource_access` (+ fallback `resources.*`) | `app/src/Service/PermissionChecker.php`, `app/src/Entity/Permission/ResourceAccess.php` |
| Solicitação de acesso | `AccessRequestController` | `access_request` | `app/src/Controller/AccessRequestController.php`, `app/src/Entity/Permission/AccessRequest.php` |
| Catálogo de permissões | — | `permission` | `app/src/DataFixtures/PermissionFixture.php`, `app/migrations/Version20260401130000.php` |
| Bypass global | `isGlobalSuperAdmin()` | `user` (coluna `roles`, JSONB) | `app/src/Command/CreateSuperAdminCommand.php`, `app/src/Service/PermissionChecker.php` |
| Bypass por tenant | `TenantRole::isSystem()` | `tenant_role` (coluna `is_system`) | `app/src/Service/TenantBootstrapService.php`, `app/src/Entity/Auth/TenantRole.php` |
