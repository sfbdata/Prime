# Spec — Escopo por-URL nas rotas admin de Ponto (MÉDIO + C6)

> Fecha o **MÉDIO** (folha do escritório errado) e o **C6** (frestinha super-admin) achados na
> auditoria do isolamento do Ponto. Decisão do dono: super-admin agindo em `/tenant/{X}/...` é
> **escopado ao tenant X** (pela URL). Risco MÉDIO (toca o `TenantController` compartilhado).
> Sem migration. Contexto: `docs/specs/followups-seguranca-residual.md` (C6),
> `docs/specs/ponto-isolamento-tenant.md` (follow-up frestinha).

## Causa-raiz (uma só para os dois achados)

O `TenantFilter` é ligado pelo tenant da **sessão** (`TenantFilterListener` → `getCurrentTenant`),
não pelo `{tenantId}` da **URL**. As rotas admin de ponto/justificativa operam sobre o escritório
da URL, mas confiam no filtro de sessão. Consequências:

- **MÉDIO — folha do escritório errado:** um admin com vínculo ativo em A **e** B, sessão=A, abre
  `/tenant/{B}/user/{alvo}/edit-role`. O guard (`existeVinculoAtivo($user, $tenantB)`) passa, mas as
  queries DQL da folha (`findByUserAndCompetencia`, `findByUserAndCompetenciaIndexed`,
  `findByTenantUser`) são filtradas pela **sessão A** → a tela do escritório B mostra as batidas de A.
- **C6 — frestinha super-admin:** `ROLE_SUPER_ADMIN` passa sem tenant na sessão (`TenantContextValidatorListener`),
  então o filtro fica **desligado** → `find($registroId)`/`find($justificativaId)` por id retornam a
  entidade de **qualquer** tenant (IDOR), e a folha mostraria batidas de **todos** os escritórios.

Admin comum sempre tem tenant na sessão (forçado), então o filtro está ON para ele — mas pelo tenant
da sessão, que pode divergir da URL (o caso MÉDIO).

## Decisão de implementação

**Re-apontar o `TenantFilter` para o tenant da URL** logo após o guard de acesso, em cada rota admin
de ponto. Um helper único:

```php
private function escoparFiltroNoTenant(EntityManagerInterface $em, Tenant $tenant): void
{
    $em->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
}
```

Por que re-apontar o filtro (em vez de guard por-id + param de tenant nas queries):
- **Uniforme:** uma chamada por rota escopa de uma vez a folha (DQL), os `find()` por id e os `findBy`.
- **Menos invasivo:** não altera as assinaturas dos repos (`findByUserAndCompetencia` é usado também
  pelo self-service em `PontoController`, que deve continuar usando o tenant da sessão = o próprio).
- **Consistente com o mecanismo da remediação:** é o `TenantFilter` que fecha o IDOR por id.
- Para super-admin (filtro off) o helper **liga** o filtro no tenant da URL → fecha folha e find-by-id.
- Idempotente: `enable()` numa flag já ligada devolve o filtro existente; `setParameter` re-aponta.

O guard de acesso (`isSuperAdmin || (isOwnTenant && canAdminister)`) **continua antes** — só quem pode
administrar o tenant da URL chega ao re-aponte (super-admin pode qualquer tenant da URL, por decisão).
A checagem `$registro->getUser()?->getId() === $user->getId()` permanece como defesa adicional.

## Rotas tocadas (todas em `src/Controller/TenantController.php`)

| Rota | Ponto de inserção | Fecha |
|---|---|---|
| `editUserRole` (folha) | após validar `findAtivoPorUserETenant` | MÉDIO (folha) + super-admin folha |
| `pontoEdit` | antes do `find($registroId)` | C6 find-by-id |
| `pontoDelete` | antes do `find($registroId)` | C6 find-by-id |
| `aprovarJustificativa` | antes do `find($justificativaId)` | C6 find-by-id |
| `rejeitarJustificativa` | antes do `find($justificativaId)` | C6 find-by-id |
| `aprovarTodosJustificativas` | antes do `findBy([...])` | C6 bulk findBy |
| `reverterJustificativa` | antes do `find($justificativaId)` | C6 find-by-id |

`pontoAdd` já grava o tenant da URL no novo `RegistroPonto` (write-site correto) — sem mudança.
`findCompetenciasComRegistroPorUsuario` (SQL nativo) já recebe `$tenant` — sem mudança.

**Avaliadas e DISPENSAM o re-aponte (não têm o bug):** `PontoController::exportar{Pdf,Xlsx}` (folha de
terceiro via `?userId=`). Não há `{tenantId}` na URL — o tenant vem da **sessão** via `assertAccess`
(que lança se não houver tenant na sessão). Logo o C6-puro (super-admin sem tenant) é barrado por
`assertAccess`, e o guard (`existeVinculoAtivo($alvo, $tenant)`) e o filtro usam a **mesma** fonte
(sessão) — sem a divergência URL-vs-sessão. **`JornadaColaboradorController`** (get/save/delete jornada): o guard `$tenant?->getId() === $tenantId`
já barrava admin comum com sessão≠URL, MAS a checagem de vínculo do alvo era **pulada para super-admin**
→ super-admin podia ler/mutar a jornada de qualquer alvo. **Corrigido nesta frente** (decisão do dono):
resolve o tenant da URL (`tenantRepository->find($tenantId)`) e exige `existeVinculoAtivo($alvo, $urlTenant)`
**incondicionalmente** (vale p/ super-admin) → 404 se o alvo não pertence ao tenant da URL. `JornadaColaborador`
não é TenantAware (não há filtro a re-apontar); o escopo é por guard explícito.

## Testes

`tests/Tenant/Functional/PontoAdminEscopoTenantControllerTest`:
- **MÉDIO:** admin multi-tenant (vínculo em A e B), sessão=A, abre `/tenant/{B}/user/{alvo}/edit-role`
  com batidas do alvo em A e B → a folha lista só as de B (não vaza as de A na tela de B).
- **C6 (super-admin find-by-id):** super-admin sem tenant na sessão, `pontoEdit`/`aprovarJustificativa`
  de registro/justificativa de tenant X via URL de tenant Y → 404 (filtro re-apontado fecha o IDOR).
- Controle positivo: super-admin/admin agindo no tenant correto da URL → ação ok.

## Não-objetivos
- Não redefine poderes amplos de super-admin fora destas rotas (segue como decisão de produto à parte).
- Não move o `TenantController` nem refatora as rotas — fix cirúrgico de segurança.
