# Validação de OAB — Plano do Passo 2 (caminhos manuais: admin + perfil)

> **Execução (JusPrime):** inline pelo orquestrador (subagentes read-only). Ciclo TDD por tarefa.
> Spec: `docs/specs/validacao-oab.md` (ver **Revisão MANUAL-FIRST** e §Faseamento). Passo 1 (modelo + #4)
> COMMITADO (`f96ae9e`). Container: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit ...'`.

**Contexto:** com o backend de verificação **dormente** (sem chave SOAP), a única forma de uma OAB virar
`confirmada` é (a) backfill de dono existente, ou (b) **aprovação manual do super-admin**. O Passo 2 cria os
**dois caminhos manuais** — a **tela de revisão do super-admin** e a **seção de OAB no perfil do usuário** —
sem ligar o gate (isso é o Passo 3). **Nada bloqueia nem libera novo comportamento** ainda.

## Decisões de UX/escopo (brainstorming 2026-07-01, confirmadas com o dono)
- **Fila do admin:** lista **paginada** com **filtro por status** (default: pendentes = `nao_verificada` +
  `divergente`; + divergentes / não-verificadas / confirmadas / todas) e **busca** (nome, e-mail, nº OAB).
- **Ações do admin:** `marcar confirmada` (override) · `reverter/revogar` (**admin escolhe o destino**:
  `nao_verificada` ou `divergente`) · `re-verificar` (dormente → segue `nao_verificada`). **Auditadas** (só as
  do admin).
- **Área do admin:** página **fora de escritório** (platform/super-admin, sem tenant). O dono pediu um
  **layout/nav próprio de plataforma** (não só um card solto), pensado para crescer (escritórios, análise).
- **Perfil — botão "verificar":** **mostrar + mensagem clara**. Roda `verificar`; como fica `nao_verificada`,
  exibe *"Verificação automática indisponível no momento — sua OAB será revisada por um administrador."*
- **Perfil — editar OAB:** **reset quando muda** — se número/UF realmente mudar, o status é recalculado
  (dormente → `nao_verificada`); se limpar a OAB, tudo volta a `null`.
- **Auditoria:** `User` **é** `Auditavel` → o `AuditLogSubscriber` já grava automaticamente toda mudança de
  `oabStatus` (com ator/rota/changeset) no flush. Logo **não há auditoria manual**: confirmar/reverter geram o
  log automático atribuído ao super-admin logado. *(Correção pós-review: a premissa inicial "User não é
  Auditavel" estava errada; o `RegistradorAuditoria` manual foi removido para não gerar log duplicado.)*

## Global Constraints
- **Não-breaking / sem gate:** o Passo 2 não impede ninguém de criar escritório (isso é o Passo 3). `verificar`
  segue dormente.
- **Isolamento:** perfil age **só no próprio** `getUser()`. Admin é **global por design** (super-admin opera
  sobre identidade `User`, sem tenant) — documentar a query cross-tenant como exceção consciente.
- **`strict_types`, `final` (exceto entities), type hints 100%, enums backed string, `===`.**
- Templates novos: espelhar o **vizinho real** (`convites.html.twig` usa pt-BR hardcoded, sem `|trans`) —
  consistência com o código ao lado.

## Interfaces (fixas)
```php
// UseCase base compartilhado (admin re-verificar + perfil verificar):
final class VerificarOabUseCase {
    public function executar(User $user): ResultadoVerificacaoOab; // sem OAB → NaoVerificada; aplica status/nomeOficial/verificadaEm no User + flush
}
// Perfil:
final class AtualizarOabPerfilUseCase {
    public function executar(User $user, OabPerfilInput $input): void; // validarFormato; grava nº/UF; se mudou → verificar; se limpou → null
}
final class OabPerfilInput { public ?string $oabNumero = null; public ?string $oabUf = null; } // data_class do OabPerfilType
// Admin (auditoria é automática via AuditLogSubscriber — sem dependência de audit):
final class ConfirmarOabManualmenteUseCase {
    public function executar(User $alvo): void; // → Confirmada + verificadaEm=now + flush
}
final class ReverterOabUseCase {
    public function executar(User $alvo, StatusOab $destino): void; // destino ∈ {NaoVerificada, Divergente}
}
// Repository (legado, cross-tenant — exceção consciente); statuses vazio = todos com OAB:
// UserRepository::contarParaRevisaoOab(list<StatusOab> $statuses, ?string $busca): int
// UserRepository::buscarParaRevisaoOab(list<StatusOab> $statuses, ?string $busca, int $offset, int $limite): list<User>
// Output:
final class OabReviewOutput { /* id, nome, email, oabNumero, oabUf, oabStatus, oabNomeOficial, oabVerificadaEm */ }
```

---

### Tarefa 1 — `VerificarOabUseCase` (base compartilhada) + teste
**Criar:** `app/src/Auth/UseCase/VerificarOabUseCase.php`, `app/tests/Auth/Unit/VerificarOabUseCaseTest.php`.
- [ ] **Teste primeiro** (com `OabWebServiceClientFake` já existente injetado no `ValidadorOab`, ou stub do
  `ValidadorOab`): user com OAB + fake dormente → `NaoVerificada` gravado (status/verificadaEm setados);
  user com OAB + fake que confirma → `Confirmada` + `oabNomeOficial`; user **sem** OAB → `NaoVerificada` sem
  quebrar (ou early-return documentado). Assere `flush`.
- [ ] Implementar: `validarFormato`; `verificar($num,$uf,$user->getFullName())`; aplica no `User`
  (`setOabStatus/setOabNomeOficial/setOabVerificadaEm(new \DateTimeImmutable())`); salva via `UserRepository`.
- [ ] `php bin/phpunit tests/Auth/Unit/VerificarOabUseCaseTest.php` verde.

### Tarefa 2 — Perfil: seção de OAB (informar/editar + verificar)
**Criar:** `app/src/Profile/DTO/OabPerfilInput.php`, `app/src/Profile/Form/OabPerfilType.php`,
`app/src/Profile/UseCase/AtualizarOabPerfilUseCase.php`,
`app/tests/Profile/Unit/AtualizarOabPerfilUseCaseTest.php`,
`app/tests/Profile/Functional/ProfileOabTest.php`.
**Modificar:** `ProfileController` (+2 actions), `PerfilOutput` (+campos OAB), `profile/index.html.twig`
(card + modal), `app/config/packages/framework.yaml` (limiter `oab_verificar`).
- [ ] **Testes primeiro** (functional): informar OAB nova → status `nao_verificada` gravado; **editar** para
  número diferente → status recalculado (dormente `nao_verificada`); **limpar** OAB → tudo `null`; botão
  **verificar** → flash "indisponível…", status segue `nao_verificada`; **rate-limit** estoura após N.
  Unit do `AtualizarOabPerfilUseCase`: detecção de mudança (mesmo valor não reseta; valor novo reseta; vazio
  → null).
- [ ] `PerfilOutput::fromEntity` passa a expor `oabNumero/oabUf/oabStatus/oabNomeOficial/oabVerificadaEm` (do `User`).
- [ ] `AtualizarOabPerfilUseCase`: `validarFormato`; se vazio → zera tudo; senão grava nº/UF e, **se mudou**,
  chama `VerificarOabUseCase` (ou aplica `verificar` inline). Flush.
- [ ] `ProfileController::salvarOab` (POST `/perfil/oab`, form+CSRF → UseCase → flash → redirect) e
  `verificarOab` (POST `/perfil/oab/verificar`, **rate-limit `oab_verificar`** → `VerificarOabUseCase` →
  flash da msg dormente → redirect). Injetar `RateLimiterFactory` (padrão do `CadastroController`).
- [ ] Limiter `oab_verificar` (ex.: `sliding_window`, 5/`1 hour`) em `framework.yaml`.
- [ ] Card OAB no `profile/index.html.twig`: badge de status; nº/UF ou "não informada"; se `divergente`,
  nome oficial × informado; botões "Informar/editar OAB" (modal com `form_row`) e "Verificar" (form CSRF).
- [ ] `php bin/phpunit tests/Profile` verde.

### Tarefa 3 — Auditoria (automática, sem código)
**Descartada a auditoria manual** (correção pós-review). `User` **é** `Auditavel`, então o
`AuditLogSubscriber` já registra automaticamente a mudança de `oabStatus` no flush (ação `update`, com
ator/rota/changeset). Confirmar/reverter geram esse log atribuído ao super-admin — **sem** `RegistradorAuditoria`
(que geraria log duplicado). Cobertura: assert no `OabReviewTest` de que existe `AuditLog` para o `User`-alvo
com `actorUserId` = super-admin após confirmar.

### Tarefa 4 — Admin: UseCases + repositório + Output + controller
**Criar:** `app/src/Auth/UseCase/ConfirmarOabManualmenteUseCase.php`,
`app/src/Auth/UseCase/ReverterOabUseCase.php`, `app/src/Auth/DTO/OabReviewOutput.php`,
`app/src/Auth/Controller/OabReviewController.php`,
`app/tests/Auth/Unit/ConfirmarOabManualmenteUseCaseTest.php`,
`app/tests/Auth/Unit/ReverterOabUseCaseTest.php`, `app/tests/Auth/Functional/OabReviewTest.php`.
**Modificar:** `app/src/Repository/UserRepository.php` (2 métodos de revisão, cross-tenant comentado).
- [ ] **Testes primeiro** (unit): `Confirmar` → `Confirmada` + `verificadaEm` + flush. `Reverter` com
  `destino=NaoVerificada`/`Divergente` → grava destino (verificadaEm null/now); `destino` fora do conjunto
  permitido → `\InvalidArgumentException`.
- [ ] **Functional** (`OabReviewTest`): super-admin lista (filtro default = pendentes; busca cross-tenant);
  confirmar muda status + gera **auditoria automática** atribuída ao admin; reverter (admin escolhe destino);
  re-verificar → dormente; **não super-admin → 403**; **CSRF inválido → 403**.
- [ ] `UserRepository::contarParaRevisaoOab` / `buscarParaRevisaoOab` — filtro por `StatusOab`, base
  `oab_status IS NOT NULL`, busca por nome (`getFullName`/join `UserProfile` — resolver na impl), e-mail,
  nº OAB. **Cross-tenant por design (super-admin) — comentar a ausência do filtro de tenant.**
- [ ] `OabReviewOutput::fromUser`.
- [ ] `OabReviewController` (`#[IsGranted('ROLE_SUPER_ADMIN')]`, `#[Route('/admin/platform/oab')]`):
  `index` (GET, paginado + filtro + busca), `confirmar`/`reverter`/`reverificar` (POST `/{id}/...`, CSRF →
  UseCase → flash → redirect preservando filtro). `{id}` int (`User.id`).
- [ ] `php bin/phpunit tests/Auth` verde.

### Tarefa 5 — Layout de plataforma + templates + hub
**Modificar:** `app/templates/base.html.twig` (envolver o `{% include '_sidebar.html.twig' %}` em
`{% block sidebar %}…{% endblock %}` — aditivo, preserva comportamento).
**Criar:** `app/templates/admin/platform/_base_platform.html.twig` (estende base; override `block sidebar`),
`app/templates/admin/platform/_sidebar_platform.html.twig` (nav: Dashboard · Convites · Revisão de OAB),
`app/templates/admin/platform/oab.html.twig` (card + tabela + badges + forms CSRF por linha).
**Reescrever:** `app/templates/admin/platform/dashboard.html.twig` (stub → hub com cards/links).
**Migrar (baixo risco):** `convites.html.twig` para estender `_base_platform` (só troca `extends` + envolve
conteúdo) — **smoke manual** depois.
- [ ] Envolver o include do sidebar em `block sidebar` no base (conferir que telas de tenant seguem idênticas).
- [ ] `_base_platform` + `_sidebar_platform` (links por `path(...)`, item ativo via `currentRoute starts with`).
- [ ] `dashboard.html.twig` vira hub; `oab.html.twig` no padrão do `convites.html.twig`.
- [ ] Migrar `convites.html.twig` + **smoke**: /admin/platform, /admin/platform/convites, /admin/platform/oab
  (nav lateral aparece, item ativo correto, telas de tenant intactas).

### Tarefa 6 — Suíte verde + revisão + commit
- [ ] `php bin/phpunit` (completa) → verde.
- [ ] Smoke manual (Playwright/login dev): fila do admin (filtro/busca/ações), perfil (informar/editar/verificar),
  nav de plataforma.
- [ ] `/review` (feature-review-agent) contra este plano + a spec — foco: **nada bloqueia** (sem gate), audit
  gravado só nas ações do admin, isolamento (perfil só no próprio user; admin global comentado), rate-limit,
  CSRF, reset-quando-muda correto.
- [ ] Corrigir → **re-review (ALTO)** → commit atômico (só os arquivos desta frente; **nunca `git add -A`** —
  o working tree é compartilhado; há `app/tests/Ponto/Unit/FolhaPontoBuilderTest.php` alheio).

---
## Cobertura da spec (Passo 2)
- Tela de revisão super-admin (listar/filtrar/buscar; confirmar/reverter/re-verificar; audit) → T3/T4/T5.
- Seção de OAB no perfil (ver status + informar/editar + verificar dormente + rate-limit) → T2.
- Base compartilhada de verificação → T1. Layout de plataforma extensível → T5.
- **Não-breaking / sem gate** (gate = Passo 3) → constraint global, conferido no T6.
