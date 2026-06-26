# Spec — C1: `DemitirFuncionarioUseCase` escreve cross-tenant

> Frente **C1** da segurança residual (`followups-seguranca-residual.md`). Risco **MÉDIO**
> (gestão User/Tenant + escrita cross-tenant). **Sem migration.** Esta spec é o alvo da revisão
> adversarial (`/review`).

## Problema

`App\Tenant\UseCase\DemitirFuncionarioUseCase` (rota `app_tenant_user_demitir` em
`TenantController::demitirFuncionario`) limpa/transfere as responsabilidades do funcionário
demitido em 4 operações que filtram **só por `user`**, sem escopo de tenant:

| Operação | Tipo | Tabela | Escapa do `TenantFilter`? |
|---|---|---|---|
| `UPDATE Pasta SET responsavel ... WHERE responsavel = :user` | bulk DQL | `pasta` | **Sim** (filtro só atua em SELECT) |
| `UPDATE Chamado SET responsavel ... WHERE responsavel = :user` | bulk DQL | `chamado` | **Sim** |
| `DELETE/INSERT ... WHERE user_id = :uid` | SQL nativo | `tarefa_responsaveis` | **Sim** (SQL cru) |
| `DELETE/INSERT ... WHERE user_id = :uid` | SQL nativo | `evento_participante` | **Sim** |

O `TenantFilter` é ligado pelo tenant da **sessão** e (a) só atua em SELECT — bulk DQL
UPDATE/DELETE e SQL nativo o ignoram; (b) fica **desligado** para super-admin sem tenant na
sessão e em CLI/console. Logo nenhuma dessas 4 operações está protegida pelo filtro.

**Consequência:** demitir um funcionário **multi-tenant** (vínculo ativo em ≥2 escritórios) de
**um** escritório apaga/transfere as responsabilidades dele em **todos** — corrupção e perda de
dados cross-tenant. O `$input->tenant` (tenant da URL, já validado no controller) é ignorado nas
4 operações.

## Fix

Escopar cada operação por `$input->tenant`. As 4 tabelas têm `tenant_id`
(`pasta`/`chamado`/`tarefa`/`evento`); as join tables referenciam `tarefa(id)`/`evento(id)`.

- **Pasta/Chamado (DQL):** `... AND p.tenant = :tenant` / `... AND c.tenant = :tenant`
  (parâmetro = a entidade `Tenant`).
- **`tarefa_responsaveis` (SQL nativo):**
  `... AND tarefa_id IN (SELECT id FROM tarefa WHERE tenant_id = :tid)` no `DELETE` **e** no
  `INSERT ... SELECT` (`:tid = $tenant->getId()`).
- **`evento_participante` (SQL nativo):** idem com
  `evento_id IN (SELECT id FROM evento WHERE tenant_id = :tid)`.
- O `NOT IN (...)` anti-duplicata do `INSERT` (transferência) permanece **sem** escopo de tenant
  de propósito: ele só evita colisão de PK quando o substituto já é responsável; como as linhas
  inseridas já estão restritas ao tenant pelo `IN`, manter o `NOT IN` global é mais seguro.

Sem mudança no controller (já valida CSRF, vínculo ativo, permissão e monta o input com o tenant
da URL). Sem migration.

## Testes (cross-tenant)

`tests/Tenant/Functional/DemitirFuncionarioIsolamentoTest.php` (KernelTestCase, chamando o UseCase
**direto** com o **TenantFilter desligado** = pior caso: super-admin/CLI). Funcionário com vínculo
ativo em A **e** B, responsável por `pasta` + `chamado` + `tarefa` (responsável) + `evento`
(participante) nos **dois** tenants:

1. **Remover (sem substituto):** demitir de A → responsabilidades de **A** zeradas
   (`pasta.responsavel=null`, `chamado.responsavel=null`, linhas removidas em
   `tarefa_responsaveis`/`evento_participante`); responsabilidades de **B intactas**.
2. **Transferir (com substituto):** demitir de A com substituto → responsabilidades de **A**
   passam ao substituto; responsabilidades de **B intactas** (funcionário continua responsável em B).

Cada teste faz `em->clear()` antes de re-`find()` (bulk DQL/SQL nativo não atualizam a identity
map).

**Mutation test confirmado (✓):** com o escopo de tenant neutralizado (operações voltando a
filtrar só por `user`/`user_id`), `testRemoverNaoCruzaTenant` foi a RED —
`pasta de B NÃO deveria ser tocada — Failed asserting that null is identical to 18176` —
provando que o teste pega o vazamento. Fix restaurado, suíte verde.

Um terceiro caso (`testTransferirComSubstitutoJaResponsavelNaoDuplica`) cobre o caminho do `NOT
IN` anti-duplicata: substituto já responsável pela mesma tarefa/evento de A → a transferência não
estoura a PK `(tarefa_id,user_id)`/`(evento_id,user_id)` e o substituto fica como responsável
único. Sem o `NOT IN`, esse INSERT colidiria.

Unit test existente (`DemitirFuncionarioUseCaseTest`) permanece válido — o número de operações não
muda (2 sem substituto / 4 com).

## Fora de escopo

- Frestinha super-admin (C6, bloqueado por decisão de produto).
- Demais frentes C2–C5.
