# Catálogo de Permissões — JusPrime (Primeira Onda)

> Documento gerado no Dia 1 da implementação multi-tenant.
> Este arquivo é a fonte de verdade para o catálogo de permissões.
> Qualquer alteração de escopo deve ser registrada aqui com justificativa.

---

## 1. Catálogo final de permissões

### 1.1 Permissões de módulo (`modules.*`)

| Código                      | Descrição                                      | Notas                        |
|-----------------------------|------------------------------------------------|------------------------------|
| `modules.pastas.view`       | Acesso ao módulo Pastas                        |                              |
| `modules.clientes.view`     | Acesso ao módulo Clientes                      |                              |
| `modules.processos.view`    | Acesso ao módulo Processos                     |                              |
| `modules.tarefas.view`      | Acesso ao módulo Tarefas (visão do usuário)    |                              |
| `modules.agenda.view`       | Acesso ao módulo Agenda                        |                              |
| `modules.servicedesk.view`  | Acesso ao módulo Service Desk (usuário)        |                              |
| `modules.precadastros.view` | Acesso ao módulo Pré-Cadastros                 | Antes: ROLE_COMERCIAL        |
| `modules.financeiro.view`   | Acesso ao módulo Financeiro                    | **Futuro** — pré-cadastrado  |
| `modules.bi.view`           | Acesso ao módulo BI                            | **Futuro** — pré-cadastrado  |

### 1.2 Permissões de recurso (`resources.*`)

Estas permissões controlam ações sobre recursos **individuais** (item-level).
Acesso ao módulo NÃO implica acesso automático a todos os itens.

| Código                      | Descrição                                      |
|-----------------------------|------------------------------------------------|
| `resources.pasta.view`      | Visualizar pasta específica                    |
| `resources.pasta.edit`      | Criar e editar pasta                           |
| `resources.pasta.delete`    | Excluir pasta                                  |
| `resources.cliente.view`    | Visualizar cliente específico                  |
| `resources.cliente.edit`    | Criar e editar cliente                         |
| `resources.cliente.delete`  | Excluir cliente                                |
| `resources.processo.view`   | Visualizar processo específico                 |
| `resources.processo.edit`   | Criar e editar processo                        |
| `resources.processo.delete` | Excluir processo                               |

> **Decisão:** `edit` abrange criação + edição para reduzir granularidade na primeira onda.
> Separar `create` de `edit` é escopo de onda futura.

### 1.3 Permissões administrativas (`admin.*`)

| Código                             | Descrição                                         | Antes mapeado em        |
|------------------------------------|---------------------------------------------------|-------------------------|
| `admin.roles.manage`               | Criar/editar/excluir perfis do tenant             | ROLE_ADMIN              |
| `admin.users.manage`               | Gerenciar funcionários (editar perfil, desativar) | ROLE_ADMIN              |
| `admin.users.invite`               | Convidar usuários para o tenant                   | ROLE_ADMIN (security.yaml) |
| `admin.access_requests.approve`    | Aprovar/negar solicitações de acesso por item     | ROLE_ADMIN              |
| `admin.tenant.settings.manage`     | Editar configurações do escritório                | ROLE_ADMIN              |
| `admin.tarefas.manage`             | Gestão completa de tarefas (visão admin)          | ROLE_ADMIN              |
| `admin.servicedesk.manage`         | Gestão de chamados Service Desk (TI)              | ROLE_ADMIN              |
| `admin.audit.view`                 | Acessar trilha de auditoria                       | ROLE_ADMIN              |

---

## 2. Escopo da primeira onda

### Módulos com controle por item (ResourceAccess)
- **Clientes** — view / edit / delete por cliente individual
- **Pastas** — view / edit / delete por pasta individual
- **Processos** — view / edit / delete por processo individual

### Módulos SEM controle por item (acesso ao módulo = acesso a todos os itens)
- Tarefas, Agenda, Service Desk, Pré-Cadastros

---

## 3. Perfis padrão (bootstrap de tenant)

| Perfil                    | isSystem | Permissões incluídas                                                                                                                  |
|---------------------------|----------|---------------------------------------------------------------------------------------------------------------------------------------|
| Administrador do Escritório | true   | Todos os `modules.*` + todos os `resources.*` + todos os `admin.*`                                                                   |
| Advogado                  | false    | `modules.pastas.view`, `modules.clientes.view`, `modules.processos.view`, `modules.tarefas.view`, `modules.agenda.view`, todos `resources.*` |
| Estagiário                | false    | `modules.pastas.view`, `modules.clientes.view`, `modules.processos.view`, `modules.tarefas.view`, `modules.agenda.view`               |

> **Decisão:** Perfis pré-definidos são sugestões. O admin do tenant pode criar perfis livres e editar os não-isSystem.
> O perfil `Administrador do Escritório` (isSystem=true) não pode ser excluído.

---

## 4. Decisões registradas

| # | Decisão                                                                                     | Justificativa                                                              |
|---|----------------------------------------------------------------------------------------------|----------------------------------------------------------------------------|
| 1 | ROLE_SUPER_ADMIN permanece como papel global de plataforma (fora da gestão do tenant)        | Separa governança global de governança por tenant                          |
| 2 | ROLE_USER permanece apenas para autenticação básica                                          | Mínimo necessário pelo Symfony Security                                    |
| 3 | Um perfil por usuário na primeira versão                                                     | Reduz complexidade; múltiplos perfis é escopo futuro                       |
| 4 | `edit` inclui criação (sem `create` separado)                                                | Reduz granularidade inicial; separação é escopo futuro                     |
| 5 | Fallback temporário para ROLE_ADMIN durante migração (remover no Dia 15)                     | Evita quebrar autorização durante a transição                              |
| 6 | ResourceAccess somente para Clientes, Pastas e Processos na primeira onda                    | Itens de domínio mais críticos; outros módulos seguirão em ondas futuras   |
| 7 | Perfil `Administrador do Escritório` tem isSystem=true e não pode ser excluído               | Garante que o tenant sempre tenha um perfil admin operacional              |
| 8 | Permissão semântica é verificada no backend (fonte da verdade); frontend é apenas UX         | Segurança não pode depender exclusivamente do frontend                     |
| 9 | ROLE_COMERCIAL migra para `modules.precadastros.view` na primeira onda                       | Elimina role hardcoded que não cabe no modelo novo                         |
| 10| ROLE_FINANCEIRO, ROLE_OPERACIONAL, ROLE_CLOSER viram nomes de perfil livre por tenant        | Estes são títulos de cargo, não papéis técnicos — devem ser configuráveis  |

---

## 5. Fora de escopo (primeira onda)

- Múltiplos perfis por usuário
- ABAC avançado (regras por departamento, horário, etc.)
- Controle por item em Tarefas, Agenda, ServiceDesk e Pré-Cadastros
- Módulo Financeiro e BI (entidades pré-cadastradas, sem UI)
- Revogação automática de acesso por item (manual pelo admin)
- Auditoria analítica avançada de permissões

---

## 6. Mapeamento de rotas existentes → permissão semântica

| Rota / Controller                     | Permissão atual   | Permissão nova                        |
|---------------------------------------|-------------------|---------------------------------------|
| `^/invite`                            | ROLE_ADMIN        | `admin.users.invite`                  |
| `TenantController::users`             | ROLE_ADMIN        | `admin.users.manage`                  |
| `TenantController::show`              | ROLE_ADMIN        | `admin.tenant.settings.manage`        |
| `ClienteController::delete`           | ROLE_ADMIN        | `admin.users.manage` → `resources.cliente.delete` |
| `TarefaController::adminIndex`        | ROLE_ADMIN        | `admin.tarefas.manage`                |
| `TarefaController::create/edit/delete`| ROLE_ADMIN        | `admin.tarefas.manage`                |
| `AgendaController::edit/delete`       | ROLE_ADMIN ou dono | permissão do dono + `modules.agenda.view` |
| `ServiceDeskController::index`        | ROLE_ADMIN        | `admin.servicedesk.manage`            |
| `ServiceDeskController::atribuir`     | ROLE_ADMIN        | `admin.servicedesk.manage`            |
| `PreCadastroController`               | ROLE_COMERCIAL    | `modules.precadastros.view`           |
| `AuditLogController`                  | ROLE_ADMIN        | `admin.audit.view`                    |
| `_sidebar.html.twig` → Pré-Cadastros  | ROLE_COMERCIAL    | `modules.precadastros.view`           |
| `_sidebar.html.twig` → seção ADMIN    | ROLE_ADMIN        | qualquer `admin.*` do usuário         |

---

*Próximo passo: Dia 2 — Entidades e migrations (Permission, TenantRole, TenantRolePermission, ajuste no User).*
