# Spec — Isolamento de tenant do domínio Tarefa (P2)

> Frente P2 da remediação multi-tenant sistêmica. Risco MÉDIO. Alvo da revisão adversarial.
> Contexto: `docs/specs/auditoria-multitenant.md` (veredito Tarefa: 🟠 vaza p/ user multi-tenant)
> e `docs/specs/isolamento-tenant-sistemico.md` (mecanismo `TenantAware` + `TenantFilter`).

## Decisão

Coluna `tenant` **direta** em `Tarefa` (não apenas transitiva via Pasta) — decisão do dono.
Defesa-em-profundidade: fecha as queries que vazam e o IDOR por id automaticamente pelo filtro,
independente do isolamento da Pasta.

## Achado que ampliou o escopo (vs. investigação inicial)

A investigação sugeriu que `TarefaMensagem` herdaria o tenant da `Tarefa` (sem coluna própria).
**Refutado:** há rotas que carregam a mensagem **por id direto** —
`tarefa_mensagem_editar` (`/tarefas/mensagem/{id}/editar`) e `tarefa_mensagem_arquivo_view`
(`/tarefas/mensagem/{id}/arquivo/visualizar`). O `TenantFilter` só adiciona `tenant_id = :tenant`
para entidades que implementam `TenantAware`; sem coluna própria, o `find(TarefaMensagem, id)`
**não** seria filtrado e o IDOR só falharia incidentalmente (proxy do pai estourando → 500).
Por isso `TarefaMensagem` **também** recebe coluna `tenant` própria (mesmo padrão das filhas do
Processo), fechando o IDOR direto com 404 limpo.

## Entidades

| Entidade | Arquivo | Mudança |
|---|---|---|
| `Tarefa` | `src/Entity/Tarefa/Tarefa.php` (legado) | `implements TenantAware` + coluna `tenant` NOT NULL + get/setTenant |
| `TarefaMensagem` | `src/Entity/Tarefa/TarefaMensagem.php` (legado) | `implements TenantAware` + coluna `tenant` NOT NULL + get/setTenant |

Edição cirúrgica (Opção A do legado) — não migra o domínio, só adiciona o isolamento.

## Migration `Version20260625212332`

Espelha Processo (`Version20260625192651`):
- `tarefa`: `ADD tenant_id nullable` → backfill `UPDATE ... FROM pasta` (pasta_id é NOT NULL e toda
  pasta tem tenant → 0 órfãos determinístico) → fallback de tenant único (simetria) → `SET NOT NULL`
  + FK `FK_31B4CBA9033212A` + índice `IDX_31B4CBA9033212A`.
- `tarefa_mensagem`: `ADD tenant_id nullable` → backfill `UPDATE ... FROM tarefa` (tarefa_id é NOT
  NULL → 0 órfãs) → `SET NOT NULL` + FK `FK_F0B050EB9033212A` + índice `IDX_F0B050EB9033212A`.
- Ordem importa: `tarefa_mensagem` só é preenchida depois de `tarefa.tenant_id` populado.
- `down()` reverte (drop FK/índice/coluna das duas).

Sem unique de chave de negócio em Tarefa → nada a tornar composto.

**Dados dev:** `tarefa`=0, `tarefa_mensagem`=0, `tenant`=1 → backfill trivial. **Prod:** conferir
`SELECT COUNT(*) FROM tenant` e órfãos pós-backfill natural antes de aplicar (se multi-tenant com
órfão, o `SET NOT NULL` aborta de propósito).

## Write-sites (setTenant)

| Local | Ação |
|---|---|
| `TarefaController::criarParaPasta` | `$tarefa->setTenant($tenant)` (tenant de `assertAccess`) |
| `TarefaController::mensagem` | `$mensagem->setTenant($tarefa->getTenant())` |

`AppFixtures::loadTarefas` é vazio (tarefas nascem via UI) — nada a fazer. Nenhum comando CLI cria Tarefa.

## Queries fechadas pelo filtro

- `TarefaRepository::findByResponsavel` — vazava todas as tarefas de um usuário multi-tenant em
  todos os escritórios; passa a filtrar pelo tenant ativo.
- `TarefaRepository::findByProcesso` — passa a não retornar tarefas de pasta de outro tenant ligada
  ao mesmo processo.
- `count*PorResponsavel`/`countMetas*` já filtravam via `pasta.tenant` (corretas) — ganham filtro
  redundante harmless.
- IDOR por id em `show/updateResponsaveis/atualizarPrazo/mensagem/editarMensagem/concluir/excluir`
  e `viewArquivoMensagem` → `find()` cross-tenant retorna null → 404.

## Testes

- `tests/Tarefa/Functional/TarefaIsolamentoRepositoryTest` (Kernel): `findByResponsavel` e
  `findByProcesso` isolam por tenant; `find()` IDOR de `Tarefa` **e** `TarefaMensagem` → null.
- `tests/Tarefa/Functional/TarefaIsolamentoControllerTest` (HTTP): `show` cross-tenant → 404 com
  controle positivo (dono gestor isSystem → 200); `editarMensagem` cross-tenant → 404 + prova de
  que a linha existe (filtro desligado).
- `TarefaFactory` deriva `tenant` da pasta via `afterInstantiate` quando não informado.
- Write-sites de teste (raw `new Tarefa()`/`new TarefaMensagem()` que persistem) atualizados com
  `setTenant`.

## Follow-ups (não bloqueiam)

- Manter `verificarAcessoTarefa` como defesa-em-profundidade (o filtro já fecha o IDOR; o guard
  valida vínculo do criador/responsável da pasta).
- Índices compostos (`tenant_id`, responsável/processo) — otimização futura, não necessária agora.
