# Dashboard — Frente de Métricas

Painel de métricas operacionais por tenant: cards com totais globais e tabela de desempenho por advogado. Cruza dados de Pasta e Tarefa — os dois domínios que já têm `tenant` nativo. Chamado, Processo e Financeiro entrarão depois que resolverem `tenant_id` faltante (ver PENDENCIAS.md).

## Métricas implementadas

### Cards — todo o tenant

| Métrica | Definição |
|---|---|
| **Total de Metas Ativas** | Tarefas com `status != concluida` |
| **Demandas Urgentes** | Pastas com `prioridade = urgente` |
| **Meta Global Batida (%)** | `concluidas / total`, arredondado; retorna 0 quando `total = 0` |

### Tabela — por advogado (ordenada por Total Metas desc)

| Coluna | Definição |
|---|---|
| Total Metas | Tarefas atribuídas ao advogado |
| Metas Ativas | Tarefas ativas atribuídas |
| Metas Vencidas | Tarefas com prazo < hoje |
| Prazos Próximos | Tarefas com prazo entre hoje e hoje+7 |
| Total Demandas | Pastas com o advogado como responsável |
| Demandas Ativas | Pastas ativas do advogado |

**Relação responsável:** `Tarefa.responsaveis` é ManyToMany — tarefa com N responsáveis conta para os N advogados. A soma das linhas da tabela pode exceder o card global; é comportamento intencional.

## Arquitetura

```
GET /dashboard
  └─ DashboardController::index()         App\Dashboard\Controller
       └─ assertAccess('bi')              modules.bi.view via PermissionChecker
       └─ ObterDadosDashboardUseCase      App\Dashboard\UseCase
            ├─ PastaRepository            agregações filtradas por tenant
            ├─ TarefaRepository           agregações via JOIN em Pasta (tenant indireto)
            └─> DashboardOutput           App\Dashboard\DTO
                 └─ LinhaAdvogadoDashboardOutput[]
```

- **Repositórios:** métodos de agregação em `PastaRepository` e `TarefaRepository`; isolamento de tenant garantido em todos; testados com Foundry v2 + DAMA.
- **UseCase:** cruza as agregações por responsável, calcula meta global, monta o DTO.
- **Permissão:** reusa `modules.bi.view` (módulo BI estava reservado para este propósito).
- **Sidebar:** Dashboard é o primeiro item, acima de Expediente.
- **Rota:** `dashboard_index` — `GET /dashboard`.

## Estado das fatias

- [x] 1a — fundação de teste (Foundry v2, DAMA, factories base)
- [x] 1b-i — agregações de Pasta (`PastaRepository`)
- [x] 1b-ii — agregações de Tarefa (`TarefaRepository`)
- [x] 2 — UseCase + DTOs
- [x] 3 — controller + rota + permissão + stub de template
- [x] 4 — template completo (cards pastel + barra de progresso + tabela por advogado). SEM gráfico (cortado do escopo original).

## Próxima frente — tabela por colaborador

A tabela atual lista apenas advogados que aparecem como **responsáveis** em alguma pasta ou tarefa (lista derivada das agregações). Decisão: mudar para mostrar **todos os colaboradores do tenant**, com zeros para quem não tiver dados.

### Mudanças de backend necessárias

- **UseCase:** lista de linhas passa a vir dos `User` do tenant (via `UserTenantRepository` ou similar), não dos responsáveis encontrados nas agregações. Agregações de Tarefa e Pasta viram `LEFT JOIN` para que colaboradores sem tarefas apareçam com zeros.
- **DTO `LinhaAdvogadoDashboardOutput`:** ganha campos de `avatar` (URL ou iniciais) e `perfil` (a definir: cargo? `TenantRole.name`?).
- **Filtro por perfil:** interface ainda a especificar — qual campo define "perfil" de um colaborador no tenant.
- **Testes do UseCase:** os testes atuais assumem a lista derivada das agregações; precisarão ser reescritos para a nova lógica de LEFT JOIN.

**Status:** planejada, não iniciada.

## Decisões registradas

- **Permissão:** `modules.bi.view` reusada — não criada nova. BI estava reservado para métricas/dashboard.
- **Sidebar:** Dashboard como primeiro item (acima de Expediente).
- **Escopo v1:** só Pasta/Tarefa. Demais domínios dependem de `tenant_id` — ver PENDENCIAS.md. Gráfico (Chart.js) cortado do escopo original.
- **Tabela v1:** lista derivada de responsáveis (não de todos os colaboradores). Próxima frente expande para todos os usuários do tenant via LEFT JOIN.
- **Teste:** `#[ResetDatabase]` removido do Foundry (conflitava com DAMA) — ver PENDENCIAS.md.
- **Soma de linhas vs card:** intencional — ManyToMany de responsáveis em Tarefa.
