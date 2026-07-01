# Validação real de OAB — Plano de implementação (Fase 1: backend core)

> **Execução (JusPrime):** inline pelo orquestrador (subagentes são read-only). Ciclo por tarefa:
> escreve teste → roda (falha) → implementa → roda (passa) → confere → commit ao fim da fase.
> Spec: `docs/specs/validacao-oab.md`. Comandos no container:
> `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit ...'`.

**Goal:** Verificar a OAB de verdade contra o CNA (SOAP oficial, fail-open) e passar a exigir
`oabStatus == confirmada` para criar escritório — sem bloquear cadastro. Fecha a dívida #4 (validação
única em `ValidadorOab`).

**Arquitetura:** `OabWebServiceClient` (SOAP, atrás de interface stubável) → `ValidadorOab` (formato +
verificação + rigor + matcher, fail-open) → status persistido em `User`/`CadastroPendente` → os 3 UseCases
de cadastro chamam o validador; `CriarEscritorioUseCase` é o único gate.

**Tech:** PHP 8.2, Symfony 7.4, Doctrine ORM 3 (enumType), PHP `SoapClient`, PHPUnit 11 + Foundry v2 + DAMA.

## Global Constraints (verbatim da spec)
- OAB **opcional** em todo cadastro; a conta é **sempre** criada. Único gate: criar escritório exige `confirmada`.
- Fail-open: serviço fora/timeout/XML inválido → `nao_verificada` (nunca lança nos fluxos de cadastro).
- Rigor: não existe → `divergente` (nomeOficial null); existe+nome-bate(lenient)+Regular → `confirmada`; existe+diverge/irregular → `divergente`.
- Backfill: dono de ≥1 escritório → `confirmada`; demais com OAB → `nao_verificada`; sem OAB → null.
- Matcher lenient: normaliza acento/caixa/espaço/pontuação; tokens do nome mais curto ⊆ oficial.
- `strict_types`, `final` (exceto entities), type hints 100%, enums backed string, `===`.
- Isolamento multi-tenant inegociável (a tela admin é Fase 3; aqui nada é tenant-aware exceto o gate de escritório).

---

## Interfaces (assinaturas fixas — consistência entre tarefas)

```php
// App\Auth\Enum\StatusOab
enum StatusOab: string {
    case NaoVerificada = 'nao_verificada';
    case Divergente    = 'divergente';
    case Confirmada    = 'confirmada';
}

// App\Auth\DTO\ConsultaOabResultado (retorno do client)
final class ConsultaOabResultado {
    public function __construct(
        public readonly bool $existe,
        public readonly ?string $nomeOficial,
        public readonly ?string $situacao,
    ) {}
}

// App\Auth\DTO\ResultadoVerificacaoOab (retorno do validador)
final class ResultadoVerificacaoOab {
    public function __construct(
        public readonly StatusOab $status,
        public readonly ?string $nomeOficial,
        public readonly ?string $situacao,
    ) {}
}

// App\Auth\Service\OabIndisponivelException extends \RuntimeException {}

// App\Auth\Service\OabWebServiceClientInterface
interface OabWebServiceClientInterface {
    /** @throws OabIndisponivelException se o serviço falhar/timeout/retornar não-XML */
    public function consultar(string $inscricao, string $uf, string $nome): ConsultaOabResultado;
}

// App\Auth\Service\ValidadorOab
final class ValidadorOab {
    public function validarFormato(?string $numero, ?string $uf): void; // ausente = ok; formato ruim = \InvalidArgumentException
    public function verificar(string $numero, string $uf, string $nome): ResultadoVerificacaoOab; // fail-open
}

// User (App\Entity\Auth\User) — novos acessores
public function getOabStatus(): ?StatusOab; public function setOabStatus(?StatusOab $s): void;
public function getOabNomeOficial(): ?string; public function setOabNomeOficial(?string $n): void;
public function getOabVerificadaEm(): ?\DateTimeImmutable; public function setOabVerificadaEm(?\DateTimeImmutable $d): void;
public function isOabConfirmada(): bool; // === StatusOab::Confirmada

// CadastroPendente (App\Auth\Entity\CadastroPendente) — novos acessores
public function getOabStatus(): ?StatusOab; public function setOabStatus(?StatusOab $s): void;
public function getOabNomeOficial(): ?string; public function setOabNomeOficial(?string $n): void;
```

---

## Tarefa 1 — Config + enum + DTOs (fundação, sem lógica)

**Arquivos:**
- Criar: `app/src/Auth/Enum/StatusOab.php`, `app/src/Auth/DTO/ConsultaOabResultado.php`, `app/src/Auth/DTO/ResultadoVerificacaoOab.php`
- Modificar: `app/config/services.yaml` (params `oab_ws_url`/`oab_ws_timeout` + binds `$oabWsUrl`/`$oabWsTimeout`; em `when@test` `oab_ws_url: ''`), `app/config/packages/framework.yaml` (rate_limiter `oab_verificar: {policy: sliding_window, limit: 10, interval: '1 hour'}`), `.env.prod.example` (documentar `OAB_WS_URL`)

**Passos:**
- [ ] Criar o enum `StatusOab` e os 2 DTOs (readonly, conforme assinaturas).
- [ ] Adicionar os params/binds no `services.yaml` (default `oab_ws_url` = `%env(default:default_oab_ws_url:OAB_WS_URL)%`, `default_oab_ws_url: 'https://www5.oab.org.br/cnaws/service.asmx?WSDL'`, `oab_ws_timeout`/`default_oab_ws_timeout: 4`; `when@test` zera `oab_ws_url`).
- [ ] Rate limiter `oab_verificar` no `framework.yaml`.
- [ ] `docker exec ... php bin/console lint:container` → OK.

**Deliverable:** container linta; nenhuma lógica ainda.

---

## Tarefa 2 — Client SOAP (interface + impl + fake p/ testes)

**Arquivos:**
- Criar: `app/src/Auth/Service/OabWebServiceClientInterface.php`, `app/src/Auth/Service/OabIndisponivelException.php`, `app/src/Auth/Service/OabWebServiceClient.php`
- Criar (test double): `app/tests/Auth/Doubles/OabWebServiceClientFake.php` (implementa a interface; configurável: resultado ou lançar `OabIndisponivelException`)
- Config: `services.yaml` alias `OabWebServiceClientInterface` → `OabWebServiceClient` (e em `when@test`, opcional, apontar para o fake se necessário — senão injeta o fake direto no teste)

**Interfaces — Produz:** `OabWebServiceClientInterface::consultar(...)`, `OabIndisponivelException`.

**Passos:**
- [ ] Criar a interface + a exceção.
- [ ] Implementar `OabWebServiceClient`: guarda `?string $oabWsUrl`, `int $oabWsTimeout`; se URL vazia → `throw OabIndisponivelException`. Senão `new \SoapClient($url, ['connection_timeout' => $timeout, 'stream_context' => ...(timeout)])`, chama `ConsultaAdvogado(['Inscricao'=>..,'Uf'=>..,'Nome'=>..])`, parseia o XML de retorno em `ConsultaOabResultado`. `catch (\SoapFault|\Throwable) → throw OabIndisponivelException`. *(O parse exato do XML/situação será confirmado contra o WSDL real — ver spec §"A resolver".)*
- [ ] Criar o `OabWebServiceClientFake` (para os testes do validador).
- [ ] Teste de contrato **skippável** `app/tests/Auth/External/OabWebServiceClientContractTest.php` com `#[Group('external')]` (bate no serviço real; excluído do CI). Marca `self::markTestSkipped` se `OAB_WS_URL` não setada.

**Deliverable:** client isolável; o teste real fica fora do CI; o fake habilita a Tarefa 3.

**Nota:** o `phpunit.dist.xml` deve **excluir o group `external`** por padrão — conferir/ajustar se necessário.

---

## Tarefa 3 — `ValidadorOab` (o coração; fecha a #4) — TDD pesado

**Arquivos:**
- Criar: `app/src/Auth/Service/ValidadorOab.php`
- Test: `app/tests/Auth/Unit/ValidadorOabTest.php` (usa `OabWebServiceClientFake`)

**Interfaces — Consome:** `OabWebServiceClientInterface`, `ConsultaOabResultado`, `StatusOab`. **Produz:** `ValidadorOab::validarFormato`, `ValidadorOab::verificar → ResultadoVerificacaoOab`.

**Passos (TDD — escrever os testes primeiro):**
- [ ] **Testes `validarFormato`:** `(null,null)` e `('','')` → não lança (opcional); `('123','SP')` → ok; `('abc','SP')` → `\InvalidArgumentException`; `('123','sp'|'S'|'SPP')` → `\InvalidArgumentException`.
- [ ] **Testes `verificar`** (fake configurado):
  - existe + nomeOficial "JOÃO DA SILVA" + situação "Regular", nome digitado "joao da silva" → `Confirmada`.
  - existe + nome "JOÃO DA SILVA SOUZA" + Regular, digitado "João Silva" → **Confirmada** (matcher lenient: tokens de "joao silva" ⊆ oficial).
  - existe + nome "MARIA OLIVEIRA" + Regular, digitado "João Silva" → `Divergente` (nomeOficial preenchido).
  - existe + nome bate + situação "Suspenso" → `Divergente`.
  - não existe (`existe=false`) → `Divergente` com `nomeOficial === null`.
  - fake lança `OabIndisponivelException` → `NaoVerificada` (fail-open, sem propagar).
- [ ] Rodar → falham (classe não existe).
- [ ] Implementar `ValidadorOab` (matcher com normalização via `Transliterator`/`iconv` + comparação de tokens; lista `Regular` case-insensitive).
- [ ] Rodar → passam.

**Deliverable:** toda a lógica de decisão coberta por unit test, sem rede. **A duplicação da #4 morre aqui** (os 3 UseCases vão delegar a este serviço).

---

## Tarefa 4 — `User`: campos de OAB + migration + backfill (risco ALTO)

**Arquivos:**
- Modificar: `app/src/Entity/Auth/User.php` (3 colunas + acessores + `isOabConfirmada()`)
- Criar: `app/migrations/VersionYYYYMMDDHHMMSS.php` (via `make:migration` após mapear a entity)
- Test: `app/tests/Auth/Functional/UserOabBackfillTest.php`

**Passos:**
- [ ] Adicionar em `User`: `#[ORM\Column(type: Types::STRING, enumType: StatusOab::class, nullable: true)] private ?StatusOab $oabStatus = null;` + `oabNomeOficial` (string nullable) + `oabVerificadaEm` (datetime_immutable nullable) + acessores + `isOabConfirmada(): bool { return $this->oabStatus === StatusOab::Confirmada; }`.
- [ ] Gerar migration: `php bin/console make:migration`. **Editar o `up()`** para incluir o **backfill** após os `ADD COLUMN`:
  ```sql
  UPDATE "user" SET oab_status = 'confirmada' WHERE id IN (SELECT DISTINCT criado_por FROM tenant WHERE criado_por IS NOT NULL);
  UPDATE "user" SET oab_status = 'nao_verificada' WHERE oab_status IS NULL AND oab_numero IS NOT NULL;
  ```
- [ ] Aplicar em dev/test: `php bin/console doctrine:migrations:migrate -n` (dev) e garantir schema de teste atualizado.
- [ ] **Teste de backfill** (`UserOabBackfillTest`, KernelTestCase + DBAL): cria via ORM um user-dono (é `tenant.criadoPor`), um user com OAB sem tenant, um user sem OAB; roda o SQL do backfill (ou verifica pós-migration com fixtures) e assere `confirmada` / `nao_verificada` / `null` respectivamente. *(Como a migration roda uma vez, o teste exercita a MESMA regra SQL sobre dados semeados.)*

**Deliverable:** identidade preparada; donos existentes grandfathered (não travam).

---

## Tarefa 5 — `CadastroPendente`: campos de OAB + migration

**Arquivos:**
- Modificar: `app/src/Auth/Entity/CadastroPendente.php` (2 colunas nullable + acessores)
- Criar: migration
- Test: coberto indiretamente pela Tarefa 7 (fluxo público)

**Passos:**
- [ ] Adicionar `oabStatus` (enumType StatusOab, nullable) + `oabNomeOficial` (nullable) + acessores. (Ficam fora do construtor — setados após criar.)
- [ ] `make:migration` + aplicar em dev/test.
- [ ] `php bin/phpunit tests/Auth` (garante que nada quebrou com as colunas novas).

**Deliverable:** o `CadastroPendente` carrega o resultado da verificação Iniciar→Confirmar.

---

## Tarefa 6 — `CriarEscritorioUseCase` (o gate) — TDD

**Arquivos:**
- Modificar: `app/src/Tenant/UseCase/CriarEscritorioUseCase.php` (injeta `ValidadorOab`; remove `validarOab` privado; aplica gate)
- Test: `app/tests/Tenant/Unit/CriarEscritorioUseCaseTest.php` (ou Functional existente — verificar e ajustar)

**Interfaces — Consome:** `ValidadorOab`, `User::isOabConfirmada()`, `User::setOabStatus/...`.

**Passos (testes primeiro):**
- [ ] Testes:
  - criador `isOabConfirmada() === true` → cria o tenant (não re-verifica).
  - criador não-confirmada + `input.oab` que o fake confirma → grava status `confirmada` no user e cria.
  - criador não-confirmada + `input.oab` que o fake diz `divergente`/não-existe → `\DomainException` (não cria) e grava o status.
  - criador não-confirmada + sem OAB → `\DomainException`.
  - fail-open: fake indisponível + criador não-confirmada → `\DomainException` (não vira confirmada; não cria) — mas grava `nao_verificada`.
- [ ] Rodar → falham/ajustam.
- [ ] Implementar: `validarFormato` (se OAB presente) → se não `isOabConfirmada`: `verificar` → grava status/nomeOficial/verificadaEm no criador → se `!== Confirmada` lança `\DomainException('Valide sua OAB para criar um escritório.')`. Mantém o resto (limite RN08, bootstrap).
- [ ] Rodar → passam.

**Deliverable:** OAB fake não abre escritório; advogado real (confirmada) abre.

---

## Tarefa 7 — `IniciarCadastroPublico` + `ConfirmarCadastro` + `AceitarConvitePlataforma` (OAB opcional + status) — TDD

**Arquivos:**
- Modificar: `app/src/Auth/UseCase/IniciarCadastroPublicoUseCase.php`, `app/src/Auth/UseCase/ConfirmarCadastroUseCase.php`, `app/src/Auth/UseCase/AceitarConvitePlataformaUseCase.php` (todos: remover regex inline/privado → `ValidadorOab`; OAB opcional; gravar status)
- Test: `app/tests/Auth/Functional/CadastroPublicoOabTest.php` (+ ajustar testes existentes desses UseCases/controllers)

**Passos (testes primeiro):**
- [ ] `IniciarCadastroPublico`: com OAB confirmada (fake) → `CadastroPendente.oabStatus = confirmada`; com OAB divergente → grava divergente, **não bloqueia**; **sem OAB** → cria pendente com `oabStatus null`, não bloqueia.
- [ ] `ConfirmarCadastro`: pendente `confirmada` → cria `User` **+ Tenant** (como hoje); pendente `divergente`/`nao_verificada`/`null` → cria **só o `User`** (sem Tenant), copiando o status; user cai no estado vazio.
- [ ] `AceitarConvitePlataforma`: OAB opcional; se presente, grava status; nunca bloqueia.
- [ ] Rodar → ajustar → implementar → passar.

**Deliverable:** cadastro nunca é bloqueado por OAB; escritório só nasce com `confirmada`.

---

## Tarefa 8 — Forms/DTOs: OAB opcional + suíte verde

**Arquivos:**
- Modificar: `app/src/Auth/Form/CadastroPublicoType.php` + o Input DTO (remover `NotBlank`/required de OAB e `nomeEscritorio`), forms de convite conforme necessário. `CriarEscritorioType` mantém OAB.
- Test: functional dos controllers de cadastro (happy path sem OAB, com OAB) — ajustar os existentes.

**Passos:**
- [ ] Tornar OAB (e `nomeEscritorio` no público) opcionais no Form + Input (`#[Assert\...]`).
- [ ] Ajustar templates se o campo "obrigatório" estiver marcado no Twig.
- [ ] `php bin/phpunit` (suíte completa) → **verde**.

**Deliverable:** Fase 1 completa; suíte inteira verde.

---

## Fim da Fase 1 — revisão + commit

- [ ] `/review` (feature-review-agent) contra `docs/specs/validacao-oab.md` — foco: gate server-side, fail-open, isolamento, backfill correto, #4 realmente eliminada.
- [ ] Corrigir achados → re-review (é ALTO).
- [ ] Commit atômico (só os arquivos da frente; **nunca `git add -A`**) — comando entregue ao humano.

---

## Auto-revisão do plano (cobertura da spec)
- Cliente SOAP → T2. Validador/rigor/matcher/fail-open → T3. Enum/DTOs/config → T1. Status no User+backfill → T4. Status no CadastroPendente → T5. Gate criar escritório → T6. OAB opcional nos 3 fluxos + confirmar condicional → T7. Forms opcionais → T8. Fecha #4 → T3 consumido por T6/T7.
- **Fora desta fase (planos próprios):** Fase 2 (perfil `/perfil` + estado vazio) e Fase 3 (tela admin `/admin/platform/oab`).
