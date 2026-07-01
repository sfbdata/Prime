# Validação de OAB — Plano do Passo 1 (manual-first: modelo + #4, sem gate)

> **Execução (JusPrime):** inline pelo orquestrador (subagentes read-only). Ciclo TDD por tarefa.
> Spec: `docs/specs/validacao-oab.md` (ver **Revisão MANUAL-FIRST**). Container:
> `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit ...'`.

**Contexto:** o SOAP oficial exige uma **chave** que não temos → verificação automática **dormente**.
O Passo 1 é um **refactor não-breaking + fundação**: cria o modelo de status, fecha a **#4** (validação de
formato num `ValidadorOab` único) e passa os fluxos a **gravar** o status — **sem mudar comportamento**
(OAB continua exigida onde já era; **nenhum gate novo**; escritório continua sendo criado como hoje).
As mudanças de comportamento (OAB opcional, gate `confirmada`, `ConfirmarCadastro` condicional) ficam para
o **Passo 3**; a aprovação manual (admin) + perfil para o **Passo 2**.

## Global Constraints
- **Não-breaking:** Passo 1 não bloqueia nem libera nada novo. `verificar` é dormente → sempre `nao_verificada`.
- A **lógica** de rigor/matcher do `ValidadorOab` é 100% testada com um **client FAKE** (que devolve
  existe/nome/situação), mesmo o client de produção sendo o dormente — a lógica fica pronta p/ o dia da chave.
- Backfill: dono de ≥1 escritório → `confirmada`; demais com OAB → `nao_verificada`; sem OAB → null.
- `strict_types`, `final` (exceto entities), type hints 100%, enums backed string, `===`.

## Interfaces (fixas)
```php
enum StatusOab: string { case NaoVerificada='nao_verificada'; case Divergente='divergente'; case Confirmada='confirmada'; }
final class ConsultaOabResultado { public function __construct(public readonly bool $existe, public readonly ?string $nomeOficial, public readonly ?string $situacao) {} }
final class ResultadoVerificacaoOab { public function __construct(public readonly StatusOab $status, public readonly ?string $nomeOficial, public readonly ?string $situacao) {} }
final class OabIndisponivelException extends \RuntimeException {}
interface OabWebServiceClientInterface { /** @throws OabIndisponivelException */ public function consultar(string $inscricao, string $uf, string $nome): ConsultaOabResultado; }
final class ClienteOabIndisponivel implements OabWebServiceClientInterface { public function consultar(...): ConsultaOabResultado { throw new OabIndisponivelException('Verificação de OAB indisponível (sem backend configurado).'); } }
final class ValidadorOab {
    public function validarFormato(?string $numero, ?string $uf): void;        // ausente = ok; formato ruim = \InvalidArgumentException
    public function verificar(string $numero, string $uf, string $nome): ResultadoVerificacaoOab; // fail-open → NaoVerificada
}
// User: getOabStatus/setOabStatus(?StatusOab), getOabNomeOficial/set, getOabVerificadaEm/set, isOabConfirmada(): bool
// CadastroPendente: getOabStatus/setOabStatus(?StatusOab), getOabNomeOficial/set
```

---

### Tarefa 1 — Enum + DTOs + interface + client dormente + fake
**Arquivos (criar):** `app/src/Auth/Enum/StatusOab.php`, `app/src/Auth/DTO/ConsultaOabResultado.php`,
`app/src/Auth/DTO/ResultadoVerificacaoOab.php`, `app/src/Auth/Service/OabIndisponivelException.php`,
`app/src/Auth/Service/OabWebServiceClientInterface.php`, `app/src/Auth/Service/ClienteOabIndisponivel.php`,
`app/tests/Auth/Doubles/OabWebServiceClientFake.php` (configurável: resultado OU lançar exceção).
**Config:** `services.yaml` alias `OabWebServiceClientInterface: '@App\Auth\Service\ClienteOabIndisponivel'`.
- [ ] Criar os 7 arquivos (conforme assinaturas) + o fake.
- [ ] `lint:container` → OK.

### Tarefa 2 — `ValidadorOab` (fecha #4) — TDD com o fake
**Criar:** `app/src/Auth/Service/ValidadorOab.php`. **Test:** `app/tests/Auth/Unit/ValidadorOabTest.php`.
- [ ] **Testes primeiro** (fake configurado):
  - `validarFormato(null,null)`/`('','')` → ok; `('123','SP')` ok; `('abc','SP')`/`('123','sp')` → `\InvalidArgumentException`.
  - `verificar`: existe+nome bate+`Regular` → `Confirmada`; existe+"JOÃO DA SILVA SOUZA" vs "João Silva" → `Confirmada` (lenient); existe+nome diverge → `Divergente` (nomeOficial preenchido); existe+`Suspenso` → `Divergente`; `existe=false` → `Divergente` (nomeOficial null); fake lança `OabIndisponivelException` → `NaoVerificada`.
- [ ] Rodar → falha. Implementar (matcher: normaliza via `\Transliterator`/`iconv` + tokens do menor ⊆ maior; `Regular` case-insensitive). Rodar → passa.
- [ ] `php bin/phpunit tests/Auth/Unit/ValidadorOabTest.php` verde.

### Tarefa 3 — `User`: campos de OAB + migration + backfill (ALTO)
**Modificar:** `app/src/Entity/Auth/User.php`. **Criar:** migration + `app/tests/Auth/Functional/UserOabBackfillTest.php`.
- [ ] Em `User`: `#[ORM\Column(enumType: StatusOab::class, nullable: true)] private ?StatusOab $oabStatus = null;` + `oabNomeOficial` (string nullable) + `oabVerificadaEm` (datetime_immutable nullable) + acessores + `isOabConfirmada(): bool`.
- [ ] `make:migration`; **editar `up()`** para backfill após os ADD COLUMN:
  `UPDATE "user" SET oab_status='confirmada' WHERE id IN (SELECT DISTINCT criado_por FROM tenant WHERE criado_por IS NOT NULL);`
  `UPDATE "user" SET oab_status='nao_verificada' WHERE oab_status IS NULL AND oab_numero IS NOT NULL;`
- [ ] `doctrine:migrations:migrate -n` (dev) + schema de teste.
- [ ] Teste do backfill (semeia dono / user-com-oab / user-sem-oab via ORM+DBAL; roda a regra SQL; assere confirmada/nao_verificada/null).

### Tarefa 4 — `CadastroPendente`: campos de OAB + migration
**Modificar:** `app/src/Auth/Entity/CadastroPendente.php`. **Criar:** migration.
- [ ] Add `oabStatus` (enumType, nullable) + `oabNomeOficial` (nullable) + acessores (fora do construtor).
- [ ] `make:migration` + aplicar dev/test. `php bin/phpunit tests/Auth` verde.

### Tarefa 5 — Refactor dos 4 UseCases → `ValidadorOab` + gravar status (SEM mudar comportamento)
**Modificar:** `CriarEscritorioUseCase`, `AceitarConvitePlataformaUseCase`, `IniciarCadastroPublicoUseCase`,
`ConfirmarCadastroUseCase`. **Test:** ajustar os existentes + `app/tests/Auth/Functional/CadastroStatusOabTest.php`.
- [ ] Injetar `ValidadorOab`; **remover** os `validarOab` privados/inline (fecha #4) → `validarFormato`.
- [ ] Onde há OAB: chamar `verificar` e **gravar** `oabStatus`/`oabNomeOficial`/`oabVerificadaEm` no `User`
  (Criar/Aceitar) ou no `CadastroPendente` (Iniciar); `ConfirmarCadastro` copia do pendente p/ o `User`.
- [ ] **Não** adicionar gate; **não** tornar OAB opcional ainda (Passo 3). CriarEscritorio segue exigindo
  OAB (formato) e criando; a única diferença é que agora grava `nao_verificada` (dormente) e usa o validador.
- [ ] Testes: os 4 fluxos seguem criando conta/escritório como hoje **e** gravam o status esperado
  (`nao_verificada` com OAB; null sem OAB onde aplicável). Ajustar asserts existentes que dependiam do `validarOab` privado.

### Tarefa 6 — Suíte verde + revisão + commit
- [ ] `php bin/phpunit` (completa) → verde.
- [ ] `/review` (feature-review-agent) contra a spec — foco: **#4 realmente eliminada**, backfill correto,
  não-breaking (nada bloqueia/libera novo), status gravado certo, sem vazamento.
- [ ] Corrigir → re-review (ALTO) → commit atômico (só os arquivos da frente; **nunca `git add -A`**).

---
## Cobertura da spec (Passo 1)
- #4 (validação única) → T2/T5. Modelo de status → T1/T3/T4. Backfill grandfather → T3. Client dormente
  plugável → T1. Fluxos gravam status, sem mudar comportamento → T5.
- **Passo 2** (admin review + perfil) e **Passo 3** (OAB opcional + gate + confirmar condicional + estado vazio) → planos próprios.
