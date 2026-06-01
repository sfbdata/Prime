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
- [ ] 4 — template completo + gráfico (Chart.js) — **PENDENTE**

## Decisões registradas

- **Permissão:** `modules.bi.view` reusada — não criada nova. BI estava reservado para métricas/dashboard.
- **Sidebar:** Dashboard como primeiro item (acima de Expediente).
- **Escopo:** só Pasta/Tarefa. Demais domínios dependem de `tenant_id` — ver PENDENCIAS.md.
- **Teste:** `#[ResetDatabase]` removido do Foundry (conflitava com DAMA) — ver PENDENCIAS.md.
- **Soma de linhas vs card:** intencional — ManyToMany de responsáveis em Tarefa.
