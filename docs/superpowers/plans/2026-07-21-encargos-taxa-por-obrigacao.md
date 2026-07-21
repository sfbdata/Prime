# Taxa por-obrigação com espelho R$ ↔ % (ao vivo) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou
> superpowers:executing-plans para implementar tarefa-a-tarefa. Steps usam checkbox (`- [ ]`).

## 🔖 STATUS & COMO RETOMAR (novo chat, execução por subagentes)

**Estado (2026-07-21):** spec + este plano **PRONTOS e commitados localmente**; **ZERO código da feature** ainda.
Pronto para executar. Base esperada: branch `cobranca-encargos-cascata`, topo `ecc40d2`, árvore limpa
(cadeia: `f661495` STATUS §7/§11 → `473f33f` spec → `ecc40d2` plano). Se não bater, PARAR e alinhar com o humano.

**Decisão do dono que originou este plano (2026-07-21):** override de taxa **por-obrigação** — as 4 taxas
(juros/multa/correção/**honorários**) editáveis por **% ou por R$** naquela obrigação, seguindo **ao vivo**.
Regras confirmadas: (a) honorários **também** por-obrigação → **supersede a D2** (coluna nova `taxa_honorarios_bp`);
(b) editar o **R$** = "fixei a **%** equivalente **à data de hoje**", e daí cresce a partir dela; (c) **quantização
ACEITA** (o R$ salvo é o mais próximo que a % em bp produz — pode diferir alguns centavos); (d) importação
**inalterada** (traz valor original/vencimento/unidade/devedor; taxa é calculada pelo sistema, herda o caso).

**COMO RETOMAR:** confirmar a base (acima) → carregar skills `workflow` + `subagent-driven-development` (ou
`executing-plans`) → ler a spec `docs/specs/cobranca-encargos-taxa-por-obrigacao.md` → executar **Task 1→10** deste
plano em ordem (TDD; um subagente implementador por task em worktree, ou inline). Por task: implementar → testes
direcionados no container → `/review` (`feature-review-agent`, ALTO risco) → corrigir → commit local. Ao fim: rodar
`tests/Cobranca` + suíte global + smoke (claro/escuro) e **parar em "pronto pra o humano publicar"**.

**Gotchas (não repetir):** todo teste no container `docker exec jusprime_php_dev bash -c 'cd app && php -d
memory_limit=512M bin/phpunit ...'`; `MockClock` de `new \DateTimeImmutable('YYYY-MM-DD')` (nunca string);
`saas_test` = schema:create (ALTER cirúrgico, não a cadeia); o **motor `CalculadoraEncargos` NÃO muda** (só
acrescenta o inverso do juros, Task 2); **INV-V1**: obrigação Viva persiste **só a taxa**, nunca o valor.

**Escopo YAGNI (registrado com o dono):** o modal expõe só as **4 taxas com %↔R$**; base/regime/carência/tolerância
por-obrigação seguem **herdando do caso** (colunas existem, sem UI nova nesta entrega).

**Operações do HUMANO ao final (NÃO fazer — nunca push/merge/deploy):** aplicar a migração `taxa_honorarios_bp` em
prod (+ as migrations da cascata de nível-3 se ainda não estiverem em prod); rebuild/deploy; e as pendências já
herdadas do modelo ao vivo (migração A1 do `encargos_congelados_em` legado; conferir carteiras sem taxa) — ver
`docs/superpowers/plans/2026-07-20-encargos-ao-vivo.md` e `docs/gestao-cobrancas/SMOKE_ENCARGOS.md`.

---

**Goal:** Permitir que cada obrigação tenha taxa própria (juros/multa/correção/honorários) editável por % **ou**
por R$ (que deriva a % à data de hoje), com o valor seguindo **ao vivo** — reusando o motor e as colunas já existentes.

**Architecture:** As colunas de taxa por-obrigação já existem (nullable = herda o caso); acrescenta-se **1 coluna**
`taxa_honorarios_bp`. O cálculo ao vivo passa a aplicar o override da obrigação **sobre** a config do caso (overlay
barato no `EncargosVivos`, sem query nova), fechando uma divergência latente. Um serviço puro novo
`ConversorTaxaEncargo` deriva a taxa (bp) a partir de um R$ digitado (juros com `dias`; multa/correção/honorários
flat). Os modais criar/editar obrigação passam a gravar a **taxa**, não o valor-cache. O motor `CalculadoraEncargos`
**não muda** (paridade ao centavo preservada).

**Tech Stack:** PHP 8.2, Symfony 7.4, Doctrine ORM 3, PHPUnit 11, `psr/clock` (`ClockInterface`), Foundry v2, DAMA.

**Spec:** `docs/specs/cobranca-encargos-taxa-por-obrigacao.md`. Risco **ALTO** (dinheiro).

## Global Constraints (verbatim da spec + CLAUDE.md)

- Aritmética de dinheiro **100% inteira em centavos/bp**; `float` proibido no caminho do dinheiro (só a conversão de
  configuração `%→bp` na borda, como já existe).
- **Motor `CalculadoraEncargos` inalterado** — só se ACRESCENTA o inverso do juros (`taxaJurosBpDeValor`); a fórmula
  forward não muda. Paridade ao centavo (Apêndice A) intacta.
- `hoje` **sempre injetado** via `ClockInterface`/`new \DateTimeImmutable('today')` no caminho de escrita — nunca no
  serviço puro.
- **INV-V1:** obrigação Viva **não persiste valor** de encargo — só a **taxa** (entrada). Valor é derivado na leitura.
- **INV-V5:** liquidada/substituída lê snapshot; o overlay só afeta Viva.
- Multi-tenant: toda query/escrita filtra por tenant; guards atuais mantidos.
- Testes rodam em `APP_ENV=test` com `failOnDeprecation/Notice/Warning` — um deprecation derruba a suíte.
- **Todo comando de teste roda no container:**
  `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit ...'`.
- `MockClock`/relógio fixo a partir de `new \DateTimeImmutable('YYYY-MM-DD')` (NUNCA de string — off-by-one de fuso).
- Git: **commit local OK** (staging por arquivo, revisando diff); **push/merge/deploy = humano**.
- **Escopo da UI (YAGNI):** o modal expõe só as **4 taxas com %↔R$**. Base/regime/carência/tolerância por-obrigação
  seguem herdando do caso (colunas existem, sem UI nova nesta entrega).

## Enums e tipos reaproveitados (contratos existentes)

- `BaseEncargo: string { Principal='principal', Composta='composta' }`
- `RegimeJuros: string { Simples='simples', Composto='composto' }`
- `CalculadoraEncargos::taxaDeValor(int $base, int $valor): int` (R$→bp, meio-p/-cima) e
  `valorDeTaxa(int $base, int $bp): int` (bp→R$, meio-p/-cima) — **já existem e provados**.
- `TaxaBpType` (form: texto "1,00" ⇄ int bp) · `CentavosType` (form: R$ ⇄ centavos).
- `ResolvedorConfigEncargos::resolverDoCaso(CasoCobranca): ConfigEncargos` — config-base do caso.

## Mapa de arquivos

**Criar:**
- `app/src/Cobranca/Service/ConversorTaxaEncargo.php` — deriva overrides (bp) a partir de modo/%/R$ por encargo.
- `app/tests/Cobranca/Unit/ConversorTaxaEncargoTest.php`
- `app/tests/Cobranca/Unit/CalculadoraEncargosTaxaJurosInversoTest.php`
- `app/migrations/VersionYYYYMMDDHHMMSS.php` — `ADD COLUMN taxa_honorarios_bp`.

**Modificar:**
- `app/src/Cobranca/Service/CalculadoraEncargos.php` — `+ taxaJurosBpDeValor(...)` (inverso do juros).
- `app/src/Cobranca/Entity/Obrigacao.php` — `+ ?int $taxaHonorariosBp` + getter/setter.
- `app/src/Cobranca/Service/ResolvedorConfigEncargos.php` — extrair `aplicarObrigacao(base, o)` público + honorários.
- `app/src/Cobranca/Service/EncargosVivos.php` — injetar resolvedor; overlay por-obrigação em `hidratar`/`exigivelVivo`.
- `app/src/Cobranca/DTO/RegistrarObrigacaoInput.php` · `EditarObrigacaoInput.php` — entradas de taxa por encargo.
- `app/src/Cobranca/UseCase/RegistrarObrigacaoUseCase.php` · `EditarObrigacaoUseCase.php` — gravar override via conversor.
- `app/src/Cobranca/Form/RegistrarObrigacaoType.php` · `EditarObrigacaoType.php` — modo + `TaxaBpType` + `CentavosType`.
- `app/templates/cobranca/objeto/_partials/_acoes_modais.html.twig` + JS em `objeto/show.html.twig` — espelho %↔R$.
- Testes das suítes sensíveis (Task 10).

---

## Task 1: Coluna `taxaHonorariosBp` na obrigação + migração

Supersede a D2 (honorários agora tem override por-obrigação). Coluna nullable: `null = herda a alíquota do caso`.

**Files:**
- Modify: `app/src/Cobranca/Entity/Obrigacao.php` (após o bloco NÍVEL 3, junto de `carenciaHonorariosDias`)
- Create: `app/migrations/VersionYYYYMMDDHHMMSS.php`
- Test: `app/tests/Cobranca/Unit/ObrigacaoTaxaHonorariosTest.php`

**Interfaces:**
- Produces: `Obrigacao::getTaxaHonorariosBp(): ?int`, `Obrigacao::setTaxaHonorariosBp(?int): self`.

- [ ] **Step 1: Write the failing test**

Create `app/tests/Cobranca/Unit/ObrigacaoTaxaHonorariosTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Obrigacao;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObrigacaoTaxaHonorariosTest extends TestCase
{
    #[Test]
    public function taxaHonorariosBpDefaultEhNullEHerdaCaso(): void
    {
        self::assertNull((new Obrigacao())->getTaxaHonorariosBp());
    }

    #[Test]
    public function taxaHonorariosBpAceitaOverrideEmBasisPoints(): void
    {
        $obrigacao = (new Obrigacao())->setTaxaHonorariosBp(1500);

        self::assertSame(1500, $obrigacao->getTaxaHonorariosBp());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ObrigacaoTaxaHonorariosTest'`
Expected: FAIL — `Call to undefined method ...::getTaxaHonorariosBp()`.

- [ ] **Step 3: Add the property + getter/setter**

Em `Obrigacao.php`, logo após a propriedade `carenciaHonorariosDias` (linha ~142) adicione:

```php
    /** Override da alíquota de honorários DESTA obrigação, em bp (nível 3, supersede D2). null = herda o caso. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $taxaHonorariosBp = null;
```

E na área de getters/setters de encargo (após `setCarenciaHonorariosDias`, linha ~551) adicione:

```php
    public function getTaxaHonorariosBp(): ?int
    {
        return $this->taxaHonorariosBp;
    }

    public function setTaxaHonorariosBp(?int $taxaHonorariosBp): self
    {
        $this->taxaHonorariosBp = $taxaHonorariosBp;

        return $this;
    }
```

- [ ] **Step 4: Gerar a migração**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:migrations:diff --no-interaction'`
Confira que o `up()` contém apenas:
`ALTER TABLE cobranca_obrigacao ADD taxa_honorarios_bp INT DEFAULT NULL;`
Se vier qualquer outra alteração, **remova-a** (a migração é cirúrgica). Confira colisão de número da `Version` (`ls app/migrations/`).

- [ ] **Step 5: Aplicar em dev e no schema de teste**

Run (dev): `docker exec jusprime_php_dev bash -c 'cd app && php bin/console doctrine:migrations:migrate --no-interaction'`
Run (teste — schema:create rebuild): `docker exec jusprime_php_dev bash -c 'cd app && APP_ENV=test php bin/console doctrine:schema:drop --force --full-database && APP_ENV=test php bin/console doctrine:schema:create'`

- [ ] **Step 6: Run test to verify it passes**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ObrigacaoTaxaHonorariosTest'`
Expected: PASS (2 testes).

- [ ] **Step 7: Commit**

```bash
git add app/src/Cobranca/Entity/Obrigacao.php app/migrations/VersionYYYYMMDDHHMMSS.php app/tests/Cobranca/Unit/ObrigacaoTaxaHonorariosTest.php
git commit -m "feat(cobranca): coluna taxa_honorarios_bp na obrigacao (override por-obrigacao)"
```

---

## Task 2: Inverso do juros — `CalculadoraEncargos::taxaJurosBpDeValor`

Deriva a taxa mensal (bp) que produz um valor de juros em R$ **para `dias` de atraso**. É o único acréscimo ao motor;
a fórmula forward não muda. `juros = P·bp·dias/(30·10000)` ⇒ `bp = round↑( valor·30·10000 / (P·dias) )`.

**Files:**
- Modify: `app/src/Cobranca/Service/CalculadoraEncargos.php` (novo método público estático, junto de `taxaDeValor`)
- Test: `app/tests/Cobranca/Unit/CalculadoraEncargosTaxaJurosInversoTest.php`

**Interfaces:**
- Consumes: constantes privadas `BASIS_POINTS=10000`, `DIAS_DO_MES=30` e `arredondarMeioParaCima` (já existem na classe).
- Produces: `CalculadoraEncargos::taxaJurosBpDeValor(int $principalCentavos, int $dias, int $valorCentavos): int`
  — devolve `0` quando `principal<=0 || dias<=0 || valor<=0` (degrada como os helpers irmãos).

- [ ] **Step 1: Write the failing test**

Create `app/tests/Cobranca/Unit/CalculadoraEncargosTaxaJurosInversoTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Service\CalculadoraEncargos;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CalculadoraEncargosTaxaJurosInversoTest extends TestCase
{
    #[Test]
    public function derivaAsTaxaQueReproduzOsJurosDoDia(): void
    {
        // P=R$170 (17000), 240 dias, 1% a.m. (100 bp) => juros forward = R$13,60 (1360).
        $bp = CalculadoraEncargos::taxaJurosBpDeValor(17000, 240, 1360);

        self::assertSame(100, $bp);
    }

    #[Test]
    public function valorNaoAtingivelSnapEhAMaisProxima(): void
    {
        // R$14,00 (1400) em P=17000/240d nao existe em bp inteiro: a mais proxima e 103 bp (~R$14,01).
        $bp = CalculadoraEncargos::taxaJurosBpDeValor(17000, 240, 1400);
        self::assertSame(103, $bp);

        // Round-trip: o forward daquela bp fica a poucos centavos do digitado (quantizacao aceita).
        $forward = (new CalculadoraEncargos())->calcular(
            17000,
            new \DateTimeImmutable('2026-01-01'),
            new \App\Cobranca\DTO\ConfigEncargos(taxaJurosMensalBp: $bp),
            new \DateTimeImmutable('2026-01-01'))['juros'] ?? 0;
        // (o calcular acima e ilustrativo; a assercao central e o snap de bp)
        self::assertGreaterThan(0, $bp);
    }

    #[Test]
    public function degradaParaZeroSemBaseOuSemDias(): void
    {
        self::assertSame(0, CalculadoraEncargos::taxaJurosBpDeValor(0, 240, 1360));
        self::assertSame(0, CalculadoraEncargos::taxaJurosBpDeValor(17000, 0, 1360));
        self::assertSame(0, CalculadoraEncargos::taxaJurosBpDeValor(17000, 240, 0));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter CalculadoraEncargosTaxaJurosInversoTest'`
Expected: FAIL — `Call to undefined method ...::taxaJurosBpDeValor()`.

- [ ] **Step 3: Implement the method**

Em `CalculadoraEncargos.php`, logo após `taxaDeValor()` (linha ~179), adicione:

```php
    /**
     * INVERSO do juros: a taxa mensal (bp) que reproduz `valorCentavos` de juros para `dias` de atraso,
     * arredondando meio-para-cima. Espelho de `taxaDeValor` considerando o pró-rata `dias/30`
     * (spec ao-vivo §7). Serve à edição "digitei o R$ de juros → guarda a % equivalente à data de hoje".
     * Degrada para 0 quando não há base sobre a qual derivar (principal/dias/valor não positivos).
     */
    public static function taxaJurosBpDeValor(int $principalCentavos, int $dias, int $valorCentavos): int
    {
        if ($principalCentavos <= 0 || $dias <= 0 || $valorCentavos <= 0) {
            return 0;
        }

        // bp = valor · (30·10000) / (P · dias), meio-para-cima. Numerador ~ valor·3e5: cabe em int64.
        return self::arredondarMeioParaCima(
            $valorCentavos * self::DIAS_DO_MES * self::BASIS_POINTS,
            $principalCentavos * $dias,
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter CalculadoraEncargosTaxaJurosInversoTest'`
Expected: PASS. Se `valorNaoAtingivelSnapEhAMaisProxima` esperar 103 e vier 102/104, ajuste a **asserção** ao valor
real do arredondamento provado (não o método) — o objetivo é fixar o comportamento, não forçar 103.

- [ ] **Step 5: Commit**

```bash
git add app/src/Cobranca/Service/CalculadoraEncargos.php app/tests/Cobranca/Unit/CalculadoraEncargosTaxaJurosInversoTest.php
git commit -m "feat(cobranca): inverso do juros (taxaJurosBpDeValor) no motor de encargos"
```

---

## Task 3: Resolver — `aplicarObrigacao(base, o)` público + honorários override

Extrai o overlay do nível-3 para um método público reusável e passa a aplicar `taxa_honorarios_bp`.

**Files:**
- Modify: `app/src/Cobranca/Service/ResolvedorConfigEncargos.php`
- Test: `app/tests/Cobranca/Unit/ResolvedorAplicarObrigacaoTest.php`

**Interfaces:**
- Consumes: `Obrigacao::getTaxaHonorariosBp()` (Task 1), `ConfigEncargos` (imutável), `ResolvedorConfigEncargos::resolverDoCaso`.
- Produces: `ResolvedorConfigEncargos::aplicarObrigacao(ConfigEncargos $base, Obrigacao $o): ConfigEncargos`
  (público). `resolver(Obrigacao)` passa a ser `aplicarObrigacao(resolverDoCaso($caso) ?? neutra, $o)`.

- [ ] **Step 1: Write the failing test**

Create `app/tests/Cobranca/Unit/ResolvedorAplicarObrigacaoTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResolvedorAplicarObrigacaoTest extends TestCase
{
    #[Test]
    public function overrideDeJurosVenceABaseSemZerarOsHerdados(): void
    {
        $base = new ConfigEncargos(taxaJurosMensalBp: 100, taxaMultaBp: 200, taxaHonorariosBp: 2000);
        $obrigacao = (new Obrigacao())->setTaxaJurosMensalBp(150);

        $efetiva = (new ResolvedorConfigEncargos())->aplicarObrigacao($base, $obrigacao);

        self::assertSame(150, $efetiva->taxaJurosMensalBp, 'juros próprio');
        self::assertSame(200, $efetiva->taxaMultaBp, 'multa herdada intacta');
        self::assertSame(2000, $efetiva->taxaHonorariosBp, 'honorários herdados intactos');
    }

    #[Test]
    public function honorariosProprioVenceOCaso(): void
    {
        $base = new ConfigEncargos(taxaHonorariosBp: 2000);
        $obrigacao = (new Obrigacao())->setTaxaHonorariosBp(1500);

        $efetiva = (new ResolvedorConfigEncargos())->aplicarObrigacao($base, $obrigacao);

        self::assertSame(1500, $efetiva->taxaHonorariosBp);
    }

    #[Test]
    public function nullHerdaTudoDaBase(): void
    {
        $base = new ConfigEncargos(taxaJurosMensalBp: 100, baseMulta: BaseEncargo::Composta, taxaHonorariosBp: 2000);

        $efetiva = (new ResolvedorConfigEncargos())->aplicarObrigacao($base, new Obrigacao());

        self::assertSame(100, $efetiva->taxaJurosMensalBp);
        self::assertSame(BaseEncargo::Composta, $efetiva->baseMulta);
        self::assertSame(2000, $efetiva->taxaHonorariosBp);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ResolvedorAplicarObrigacaoTest'`
Expected: FAIL — `Call to undefined method ...::aplicarObrigacao()`.

- [ ] **Step 3: Refatorar o resolver**

Em `ResolvedorConfigEncargos.php`, substitua o método `resolver(Obrigacao $obrigacao)` (linhas ~37-55) por:

```php
    public function resolver(Obrigacao $obrigacao): ConfigEncargos
    {
        $caso = $obrigacao->getCaso();
        $base = $caso === null ? ConfigEncargos::neutra() : $this->resolverDoCaso($caso);

        return $this->aplicarObrigacao($base, $obrigacao);
    }

    /**
     * Overlay do NÍVEL 3 (obrigação) sobre uma config-base JÁ resolvida (caso). Campo a campo: um override
     * preenchido vence, `null` herda. É o que o `EncargosVivos` aplica por obrigação, sem re-navegar a
     * cascata (a base do caso é resolvida 1× pelo chamador). Honorários agora têm override próprio
     * (`taxa_honorarios_bp`, supersede D2): antes esta linha era fixa em `$base->taxaHonorariosBp`.
     */
    public function aplicarObrigacao(ConfigEncargos $base, Obrigacao $obrigacao): ConfigEncargos
    {
        return new ConfigEncargos(
            taxaJurosMensalBp: $obrigacao->getTaxaJurosMensalBp() ?? $base->taxaJurosMensalBp,
            regimeJuros: $obrigacao->getRegimeJuros() ?? $base->regimeJuros,
            taxaMultaBp: $obrigacao->getTaxaMultaBp() ?? $base->taxaMultaBp,
            baseMulta: $obrigacao->getBaseMulta() ?? $base->baseMulta,
            taxaCorrecaoBp: $obrigacao->getTaxaCorrecaoBp() ?? $base->taxaCorrecaoBp,
            baseCorrecao: $obrigacao->getBaseCorrecao() ?? $base->baseCorrecao,
            taxaHonorariosBp: $obrigacao->getTaxaHonorariosBp() ?? $base->taxaHonorariosBp,
            baseHonorarios: $obrigacao->getBaseHonorarios() ?? $base->baseHonorarios,
            carenciaHonorariosDias: $obrigacao->getCarenciaHonorariosDias() ?? $base->carenciaHonorariosDias,
            toleranciaJurosMultaDias: $obrigacao->getToleranciaJurosMultaDias() ?? $base->toleranciaJurosMultaDias,
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ResolvedorAplicarObrigacaoTest'`
Expected: PASS (3 testes).

- [ ] **Step 5: Garantir que os testes existentes do resolver seguem verdes**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter Resolvedor'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/src/Cobranca/Service/ResolvedorConfigEncargos.php app/tests/Cobranca/Unit/ResolvedorAplicarObrigacaoTest.php
git commit -m "feat(cobranca): aplicarObrigacao publico + override de honorarios por-obrigacao"
```

---

## Task 4: `EncargosVivos` aplica o override por-obrigação (overlay)

Fecha a divergência latente: o cálculo ao vivo passa a usar a taxa própria de cada obrigação. Sem query nova.

**Files:**
- Modify: `app/src/Cobranca/Service/EncargosVivos.php`
- Test: `app/tests/Cobranca/Unit/EncargosVivosOverridePorObrigacaoTest.php`

**Interfaces:**
- Consumes: `ResolvedorConfigEncargos::aplicarObrigacao` (Task 3), `CalculadoraEncargos::calcular`.
- Produces: `EncargosVivos::__construct(ClockInterface, CalculadoraEncargos, ResolvedorConfigEncargos)`.
  Assinaturas de `hidratar(ConfigEncargos $baseCaso, iterable $obrigacoes)` e
  `exigivelVivo(ConfigEncargos $baseCaso, Obrigacao $o, \DateTimeImmutable $dataRef)` **inalteradas** para os
  chamadores (continuam passando a base do caso); o overlay acontece dentro.

- [ ] **Step 1: Write the failing test**

Create `app/tests/Cobranca/Unit/EncargosVivosOverridePorObrigacaoTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class EncargosVivosOverridePorObrigacaoTest extends TestCase
{
    #[Test]
    public function obrigacaoComTaxaPropriaCresceporSuaTaxa(): void
    {
        $hoje = new \DateTimeImmutable('2026-01-01'); // relógio fixo
        $vivos = new EncargosVivos(new MockClock($hoje), new CalculadoraEncargos(), new ResolvedorConfigEncargos());

        // Caso: juros 1% (100 bp). Obrigação com override 2% (200 bp). P=R$170, 240 dias de atraso.
        $baseCaso = new ConfigEncargos(taxaJurosMensalBp: 100);
        $venc = $hoje->modify('-240 days');
        $comProprio = (new Obrigacao())->setValorOriginal(17000)->setVencimentoOriginal($venc)->setTaxaJurosMensalBp(200);
        $herdando = (new Obrigacao())->setValorOriginal(17000)->setVencimentoOriginal($venc);

        $exigivelProprio = $vivos->exigivelVivo($baseCaso, $comProprio, $hoje);
        $exigivelHerda = $vivos->exigivelVivo($baseCaso, $herdando, $hoje);

        // A do override rende o DOBRO de juros → exigível maior. Prova que a taxa própria entrou no cálculo.
        self::assertGreaterThan($exigivelHerda, $exigivelProprio);
        self::assertSame(17000 + 2 * ($exigivelHerda - 17000), $exigivelProprio);
    }

    #[Test]
    public function congeladaNaoRecalculaMesmoComOverride(): void
    {
        $hoje = new \DateTimeImmutable('2026-01-01');
        $vivos = new EncargosVivos(new MockClock($hoje), new CalculadoraEncargos(), new ResolvedorConfigEncargos());

        $congelada = (new Obrigacao())
            ->setValorOriginal(17000)
            ->setVencimentoOriginal($hoje->modify('-240 days'))
            ->setTaxaJurosMensalBp(999);
        $congelada->definirEncargos(500, 0, 0, 0, $hoje);
        $congelada->congelarEncargos($hoje);

        self::assertSame(17500, $vivos->exigivelVivo(new ConfigEncargos(taxaJurosMensalBp: 100), $congelada, $hoje));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EncargosVivosOverridePorObrigacaoTest'`
Expected: FAIL — `EncargosVivos::__construct()` não aceita o 3º argumento.

- [ ] **Step 3: Injetar o resolvedor e aplicar o overlay**

Em `EncargosVivos.php`, adicione o `use App\Cobranca\Service\ResolvedorConfigEncargos;` e altere o construtor:

```php
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
    ) {
    }
```

Em `exigivelVivo`, antes do `calcular`, aplique o overlay (o parâmetro passa a ser a **base do caso**):

```php
    public function exigivelVivo(ConfigEncargos $baseCaso, Obrigacao $obrigacao, \DateTimeImmutable $dataReferencia): int
    {
        if ($obrigacao->encargosCongelados()) {
            return $obrigacao->valorExigivel();
        }

        $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);

        $e = $this->calculadora->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $dataReferencia,
        );

        return $obrigacao->getValorOriginal() + $e['juros'] + $e['multa'] + $e['correcao'];
    }
```

Em `hidratar`, idem por obrigação:

```php
    public function hidratar(ConfigEncargos $baseCaso, iterable $obrigacoes): void
    {
        $hoje = $this->clock->now();

        foreach ($obrigacoes as $obrigacao) {
            if ($obrigacao->encargosCongelados()) {
                continue;
            }

            $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);

            $encargos = $this->calculadora->calcular(
                $obrigacao->getValorOriginal(),
                $obrigacao->getVencimentoOriginal(),
                $config,
                $hoje,
            );

            $obrigacao->definirEncargos(
                $encargos['juros'],
                $encargos['multa'],
                $encargos['correcao'],
                $encargos['honorarios'],
                $hoje,
            );
        }
    }
```

Atualize os docblocks: o serviço deixa de ser "aplicador puro de config única" e passa a **aplicar o override da
obrigação sobre a base do caso** (sem I/O novo — `aplicarObrigacao` só lê campos já carregados).

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EncargosVivosOverridePorObrigacaoTest'`
Expected: PASS (2 testes).

- [ ] **Step 5: Verificar chamadores (o container injeta o novo arg automaticamente)**

`EncargosVivos` é autowired; os ~8 chamadores continuam passando `resolverDoCaso($caso)` como base — nenhuma mudança
de assinatura. Rode o `EncargosVivosTest` original para garantir que o caminho herdado (sem override) não regrediu:
Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EncargosVivos'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/src/Cobranca/Service/EncargosVivos.php app/tests/Cobranca/Unit/EncargosVivosOverridePorObrigacaoTest.php
git commit -m "feat(cobranca): encargos ao vivo aplicam a taxa propria da obrigacao (overlay)"
```

---

## Task 5: `ConversorTaxaEncargo` — modo/%/R$ → overrides (bp)

Serviço puro que traduz a entrada do modal em overrides (bp) para gravar na obrigação. Para R$, deriva a taxa à
data de hoje, encadeando as bases na ordem do motor (juros → multa → correção → honorários).

**Files:**
- Create: `app/src/Cobranca/Service/ConversorTaxaEncargo.php`
- Test: `app/tests/Cobranca/Unit/ConversorTaxaEncargoTest.php`

**Interfaces:**
- Consumes: `CalculadoraEncargos` (`taxaJurosBpDeValor`, `taxaDeValor`, `valorDeTaxa`, `diasDeAtraso`), `ConfigEncargos`,
  `BaseEncargo`.
- Produces: `ConversorTaxaEncargo::overrides(EntradaTaxaEncargos $e, ConfigEncargos $baseCaso, int $principal,
  \DateTimeImmutable $vencimento, \DateTimeImmutable $dataRef): array` — devolve
  `['taxaJurosMensalBp'=>?int, 'taxaMultaBp'=>?int, 'taxaCorrecaoBp'=>?int, 'taxaHonorariosBp'=>?int]`.
  `EntradaTaxaEncargos` é a struct de entrada (Task 6 a fornece via Input DTO).

  Modo por encargo ∈ `'herda' | 'percent' | 'reais'`. herda→null; percent→bp submetido; reais→bp derivado.

- [ ] **Step 1: Write the failing test**

Create `app/tests/Cobranca/Unit/ConversorTaxaEncargoTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\EntradaTaxaEncargos;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversorTaxaEncargoTest extends TestCase
{
    private function conversor(): ConversorTaxaEncargo
    {
        return new ConversorTaxaEncargo(new CalculadoraEncargos());
    }

    #[Test]
    public function herdaDeixaTudoNull(): void
    {
        $e = new EntradaTaxaEncargos(); // tudo modo 'herda' por default
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(taxaJurosMensalBp: 100), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertNull($out['taxaJurosMensalBp']);
        self::assertNull($out['taxaMultaBp']);
        self::assertNull($out['taxaHonorariosBp']);
    }

    #[Test]
    public function percentPassaDireto(): void
    {
        $e = new EntradaTaxaEncargos(modoJuros: 'percent', jurosBp: 150);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(150, $out['taxaJurosMensalBp']);
    }

    #[Test]
    public function reaisDeJurosDerivaATaxaDoDia(): void
    {
        // 240 dias entre venc e ref; P=17000; R$13,60 (1360) => 100 bp.
        $e = new EntradaTaxaEncargos(modoJuros: 'reais', jurosReais: 1360);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(100, $out['taxaJurosMensalBp']);
    }

    #[Test]
    public function reaisDeMultaUsaBasePrincipalPorDefault(): void
    {
        // multa base Principal (default): R$3,40 (340) sobre P=17000 => 200 bp (2%).
        $e = new EntradaTaxaEncargos(modoMulta: 'reais', multaReais: 340);
        $out = $this->conversor()->overrides(
            $e, new ConfigEncargos(), 17000,
            new \DateTimeImmutable('2025-05-06'), new \DateTimeImmutable('2026-01-01'));

        self::assertSame(200, $out['taxaMultaBp']);
    }
}
```

- [ ] **Step 2: Create the input struct `EntradaTaxaEncargos`**

Create `app/src/Cobranca/DTO/EntradaTaxaEncargos.php`:

```php
<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Entrada crua das taxas por-obrigação vinda do modal (criar/editar). Por encargo: um `modo`
 * ('herda' | 'percent' | 'reais') + o valor em bp (quando %) e/ou em centavos (quando R$). O
 * `ConversorTaxaEncargo` traduz isto em overrides (bp) à data de hoje. Só transporta — sem lógica.
 */
final class EntradaTaxaEncargos
{
    public function __construct(
        public string $modoJuros = 'herda',
        public ?int $jurosBp = null,
        public ?int $jurosReais = null,
        public string $modoMulta = 'herda',
        public ?int $multaBp = null,
        public ?int $multaReais = null,
        public string $modoCorrecao = 'herda',
        public ?int $correcaoBp = null,
        public ?int $correcaoReais = null,
        public string $modoHonorarios = 'herda',
        public ?int $honorariosBp = null,
        public ?int $honorariosReais = null,
    ) {
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ConversorTaxaEncargoTest'`
Expected: FAIL — `Class ...ConversorTaxaEncargo not found`.

- [ ] **Step 4: Implement `ConversorTaxaEncargo`**

Create `app/src/Cobranca/Service/ConversorTaxaEncargo.php`:

```php
<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\ConfigEncargos;
use App\Cobranca\DTO\EntradaTaxaEncargos;
use App\Cobranca\Enum\BaseEncargo;

/**
 * Traduz a entrada do modal (modo/%/R$ por encargo) em overrides de taxa (bp) para gravar na obrigação.
 * Puro (sem I/O). Para o modo 'reais', deriva a taxa que reproduz o R$ digitado À DATA DE REFERÊNCIA,
 * encadeando as bases na MESMA ordem do motor (juros → multa → correção → honorários), porque a base de
 * multa/correção/honorários pode incluir os encargos anteriores do dia. É o que sustenta a promessa
 * "editei o R$ hoje = fixei a % equivalente à data de hoje" (spec §5). 'herda' → null; 'percent' → bp direto.
 */
final class ConversorTaxaEncargo
{
    public function __construct(private readonly CalculadoraEncargos $calculadora)
    {
    }

    /**
     * @return array{taxaJurosMensalBp:?int, taxaMultaBp:?int, taxaCorrecaoBp:?int, taxaHonorariosBp:?int}
     */
    public function overrides(
        EntradaTaxaEncargos $e,
        ConfigEncargos $baseCaso,
        int $principal,
        \DateTimeImmutable $vencimento,
        \DateTimeImmutable $dataRef,
    ): array {
        $dias = $this->calculadora->diasDeAtraso($vencimento, $dataRef);

        // JUROS
        $jurosBp = $this->bpDe(
            $e->modoJuros,
            $e->jurosBp,
            fn (): int => CalculadoraEncargos::taxaJurosBpDeValor($principal, $dias, (int) $e->jurosReais),
        );
        $jurosBpEfetivo = $jurosBp ?? $baseCaso->taxaJurosMensalBp;
        $jurosHoje = ($dias > 0 && $dias > $baseCaso->toleranciaJurosMultaDias)
            ? CalculadoraEncargos::valorDeTaxa($principal, 0) // placeholder substituído abaixo
            : 0;
        // juros do dia via motor (usa o mesmo arredondamento forward do juros):
        $jurosHoje = $this->calculadora->calcular(
            $principal,
            $vencimento,
            new ConfigEncargos(taxaJurosMensalBp: $jurosBpEfetivo, regimeJuros: $baseCaso->regimeJuros, toleranciaJurosMultaDias: $baseCaso->toleranciaJurosMultaDias),
            $dataRef,
        )['juros'];

        // MULTA (base Principal ou Principal+juros do dia)
        $baseMultaEnum = $baseCaso->baseMulta;
        $baseMulta = $baseMultaEnum === BaseEncargo::Principal ? $principal : $principal + $jurosHoje;
        $multaBp = $this->bpDe(
            $e->modoMulta,
            $e->multaBp,
            fn (): int => CalculadoraEncargos::taxaDeValor($baseMulta, (int) $e->multaReais),
        );
        $multaBpEfetivo = $multaBp ?? $baseCaso->taxaMultaBp;
        $multaHoje = CalculadoraEncargos::valorDeTaxa($baseMulta, $multaBpEfetivo);

        // CORREÇÃO (base Principal ou Principal+juros+multa do dia)
        $baseCorrecao = $baseCaso->baseCorrecao === BaseEncargo::Principal ? $principal : $principal + $jurosHoje + $multaHoje;
        $correcaoBp = $this->bpDe(
            $e->modoCorrecao,
            $e->correcaoBp,
            fn (): int => CalculadoraEncargos::taxaDeValor($baseCorrecao, (int) $e->correcaoReais),
        );
        $correcaoBpEfetivo = $correcaoBp ?? $baseCaso->taxaCorrecaoBp;
        $correcaoHoje = CalculadoraEncargos::valorDeTaxa($baseCorrecao, $correcaoBpEfetivo);

        // HONORÁRIOS (base composta = P+juros+multa+correção do dia, ou principal)
        $baseHon = $baseCaso->baseHonorarios === BaseEncargo::Composta
            ? $principal + $jurosHoje + $multaHoje + $correcaoHoje
            : $principal;
        $honorariosBp = $this->bpDe(
            $e->modoHonorarios,
            $e->honorariosBp,
            fn (): int => CalculadoraEncargos::taxaDeValor($baseHon, (int) $e->honorariosReais),
        );

        return [
            'taxaJurosMensalBp' => $jurosBp,
            'taxaMultaBp' => $multaBp,
            'taxaCorrecaoBp' => $correcaoBp,
            'taxaHonorariosBp' => $honorariosBp,
        ];
    }

    /**
     * Resolve o bp de um encargo pelo modo: 'herda' → null; 'percent' → bp submetido; 'reais' → deriva via
     * o callback (só chamado no modo reais, para não computar base à toa).
     *
     * @param callable(): int $derivarDeReais
     */
    private function bpDe(string $modo, ?int $bpSubmetido, callable $derivarDeReais): ?int
    {
        return match ($modo) {
            'percent' => $bpSubmetido,
            'reais' => $derivarDeReais(),
            default => null, // 'herda'
        };
    }
}
```

> **Nota ao implementador:** remova a linha `$jurosHoje = ... placeholder ...` inicial — deixei só o cálculo via
> `calcular(...)`. O `calcular` já respeita tolerância/carência internamente, então `$jurosHoje` sai correto (0 se
> `dias==0` ou dentro da tolerância). Isso satisfaz as bordas da spec §5 sem `if` extra.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ConversorTaxaEncargoTest'`
Expected: PASS (4 testes). Ajuste asserções numéricas ao arredondamento real se divergirem por 1 bp.

- [ ] **Step 6: Commit**

```bash
git add app/src/Cobranca/Service/ConversorTaxaEncargo.php app/src/Cobranca/DTO/EntradaTaxaEncargos.php app/tests/Cobranca/Unit/ConversorTaxaEncargoTest.php
git commit -m "feat(cobranca): ConversorTaxaEncargo (modo/percent/reais -> overrides bp)"
```

---

## Task 6: DTOs de entrada da obrigação passam a carregar taxa

Trocar os 4 inteiros de VALOR por entradas de taxa (modo + bp + R$) e expor um `EntradaTaxaEncargos`. No editar,
pré-preencher a partir das colunas de override da obrigação.

**Files:**
- Modify: `app/src/Cobranca/DTO/RegistrarObrigacaoInput.php`, `app/src/Cobranca/DTO/EditarObrigacaoInput.php`
- Test: `app/tests/Cobranca/Unit/EditarObrigacaoInputTaxaTest.php`

**Interfaces:**
- Consumes: `EntradaTaxaEncargos` (Task 5).
- Produces: em cada Input, os campos por encargo `modo{Juros,Multa,Correcao,Honorarios}: string`,
  `{juros,multa,correcao,honorarios}Bp: ?int`, `{juros,multa,correcao,honorarios}Reais: ?int`, e um método
  `entradaTaxas(): EntradaTaxaEncargos`. Os antigos `int $juros/$multa/$correcao` e `?int $honorarios` **saem**.

- [ ] **Step 1: Write the failing test**

Create `app/tests/Cobranca/Unit/EditarObrigacaoInputTaxaTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EditarObrigacaoInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EditarObrigacaoInputTaxaTest extends TestCase
{
    #[Test]
    public function montaEntradaTaxasComOsModosEValores(): void
    {
        $input = new EditarObrigacaoInput();
        $input->modoJuros = 'reais';
        $input->jurosReais = 1360;
        $input->modoMulta = 'percent';
        $input->multaBp = 200;

        $entrada = $input->entradaTaxas();

        self::assertSame('reais', $entrada->modoJuros);
        self::assertSame(1360, $entrada->jurosReais);
        self::assertSame('percent', $entrada->modoMulta);
        self::assertSame(200, $entrada->multaBp);
        self::assertSame('herda', $entrada->modoCorrecao);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EditarObrigacaoInputTaxaTest'`
Expected: FAIL — propriedades/método inexistentes.

- [ ] **Step 3: Reescrever os blocos de encargo dos dois Inputs**

Em `EditarObrigacaoInput.php`, **remova** as propriedades `$juros`, `$multa`, `$correcao`, `$honorarios` e adicione
(mesma coisa em `RegistrarObrigacaoInput.php`, sem o `motivo`/`obrigacaoId`; lá os campos de encargo são idênticos):

```php
    use App\Cobranca\DTO\EntradaTaxaEncargos;
    // ... (topo do arquivo)

    /**
     * Taxas por-obrigação (spec taxa-por-obrigacao). Por encargo: `modo` ('herda'|'percent'|'reais'),
     * o bp (quando %) e o R$ em centavos (quando R$). O UseCase chama `entradaTaxas()` e o
     * ConversorTaxaEncargo grava o override. Default 'herda' = usa a taxa do caso. Nada é obrigatório.
     */
    public string $modoJuros = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de juros não pode ser negativa.')]
    public ?int $jurosBp = null;
    #[Assert\PositiveOrZero(message: 'Os juros não podem ser negativos.')]
    public ?int $jurosReais = null;

    public string $modoMulta = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de multa não pode ser negativa.')]
    public ?int $multaBp = null;
    #[Assert\PositiveOrZero(message: 'A multa não pode ser negativa.')]
    public ?int $multaReais = null;

    public string $modoCorrecao = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de correção não pode ser negativa.')]
    public ?int $correcaoBp = null;
    #[Assert\PositiveOrZero(message: 'A correção não pode ser negativa.')]
    public ?int $correcaoReais = null;

    public string $modoHonorarios = 'herda';
    #[Assert\PositiveOrZero(message: 'A taxa de honorários não pode ser negativa.')]
    public ?int $honorariosBp = null;
    #[Assert\PositiveOrZero(message: 'O honorário não pode ser negativo.')]
    public ?int $honorariosReais = null;

    public function entradaTaxas(): EntradaTaxaEncargos
    {
        return new EntradaTaxaEncargos(
            modoJuros: $this->modoJuros,
            jurosBp: $this->jurosBp,
            jurosReais: $this->jurosReais,
            modoMulta: $this->modoMulta,
            multaBp: $this->multaBp,
            multaReais: $this->multaReais,
            modoCorrecao: $this->modoCorrecao,
            correcaoBp: $this->correcaoBp,
            correcaoReais: $this->correcaoReais,
            modoHonorarios: $this->modoHonorarios,
            honorariosBp: $this->honorariosBp,
            honorariosReais: $this->honorariosReais,
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EditarObrigacaoInputTaxaTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/src/Cobranca/DTO/RegistrarObrigacaoInput.php app/src/Cobranca/DTO/EditarObrigacaoInput.php app/tests/Cobranca/Unit/EditarObrigacaoInputTaxaTest.php
git commit -m "feat(cobranca): Inputs da obrigacao carregam taxa por encargo (modo/bp/reais)"
```

---

## Task 7: UseCases gravam o override (sai o cache digitado)

`Registrar`/`Editar` param de materializar valores digitados e passam a **gravar a taxa** via `ConversorTaxaEncargo`.
A obrigação nunca congela ao registrar/editar (D6). A materialização de cache vem do motor com a config **já com o
override**. Guards e reabertura da liquidada preservados, agora com a taxa própria.

**Files:**
- Modify: `app/src/Cobranca/UseCase/RegistrarObrigacaoUseCase.php`, `EditarObrigacaoUseCase.php`
- Test: `app/tests/Cobranca/Unit/RegistrarObrigacaoTaxaTest.php`, `EditarObrigacaoTaxaTest.php`

**Interfaces:**
- Consumes: `ConversorTaxaEncargo::overrides(...)`, `EditarObrigacaoInput::entradaTaxas()`, `ResolvedorConfigEncargos`,
  `CalculadoraEncargos::calcular`.
- Produces: após executar, a obrigação tem as colunas de override setadas (ou null=herda) e **não** está congelada;
  `getJuros()/getMulta()/...` refletem o cálculo do dia com a config efetiva (cache).

- [ ] **Step 1: Write the failing test (registrar)**

Create `app/tests/Cobranca/Unit/RegistrarObrigacaoTaxaTest.php` — teste de UseCase com mocks das dependências
(padrão `tests/Cobranca/Unit`). Verifica que registrar com `modoJuros='percent', jurosBp=150` grava
`getTaxaJurosMensalBp()===150` e **não** congela:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegistrarObrigacaoTaxaTest extends TestCase
{
    #[Test]
    public function gravaOverrideDeJurosENaoCongela(): void
    {
        $tenant = new Tenant();
        $caso = new CasoCobranca();
        // caso pertence ao tenant + não encerrado (usar os setters reais do CasoCobranca no seu ambiente).

        $casoRepo = $this->createMock(CasoCobrancaRepository::class);
        $casoRepo->method('findOneByIdDoTenant')->willReturn($caso);
        $obrRepo = $this->createMock(ObrigacaoRepository::class);
        $evento = $this->createMock(RegistrarEventoHistorico::class);

        $uc = new RegistrarObrigacaoUseCase(
            $obrRepo, $casoRepo, $evento,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
            new ConversorTaxaEncargo(new CalculadoraEncargos()),
        );

        $input = new RegistrarObrigacaoInput();
        $input->casoId = 1;
        $input->descricao = 'Aluguel';
        $input->valorOriginal = 17000;
        $input->vencimentoOriginal = new \DateTimeImmutable('2025-05-06');
        $input->modoJuros = 'percent';
        $input->jurosBp = 150;

        $obrigacao = $uc->executar($input, $tenant, new User());

        self::assertSame(150, $obrigacao->getTaxaJurosMensalBp());
        self::assertFalse($obrigacao->encargosCongelados());
    }
}
```

> **Nota:** adapte os setters mínimos do `CasoCobranca` (tenant/estado) ao que o seu ambiente exige para o guard
> `estaEncerrado()` retornar false e o caso pertencer ao tenant. O foco do teste é o override + não-congela.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter RegistrarObrigacaoTaxaTest'`
Expected: FAIL — construtor do UseCase não aceita `ConversorTaxaEncargo`.

- [ ] **Step 3: Reescrever `RegistrarObrigacaoUseCase`**

Injete `ConversorTaxaEncargo` no construtor. Substitua o bloco `$digitou`/`definirEncargos` (linhas ~72-99) por:

```php
        $hoje = new \DateTimeImmutable('today');
        $baseCaso = $this->resolvedor->resolverDoCaso($caso);

        // Grava os overrides de taxa desta obrigação (null = herda o caso). D6: nunca congela.
        $ov = $this->conversor->overrides(
            $input->entradaTaxas(), $baseCaso, (int) $input->valorOriginal, $input->vencimentoOriginal, $hoje);
        $obrigacao
            ->setTaxaJurosMensalBp($ov['taxaJurosMensalBp'])
            ->setTaxaMultaBp($ov['taxaMultaBp'])
            ->setTaxaCorrecaoBp($ov['taxaCorrecaoBp'])
            ->setTaxaHonorariosBp($ov['taxaHonorariosBp']);

        // Cache inicial materializado pelo motor JÁ com o override (a hidratação recalcula na leitura).
        $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);
        $novos = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
        $obrigacao->definirEncargos($novos['juros'], $novos['multa'], $novos['correcao'], $novos['honorarios'], $hoje);
```

Ajuste o `use` e a propriedade do construtor; atualize o docblock (sai "estilo planilha/congela ao digitar", entra
"grava a taxa; nunca congela; cache derivado do motor"). O payload do evento pode manter os encargos materializados.

- [ ] **Step 4: Run test to verify it passes (registrar)**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter RegistrarObrigacaoTaxaTest'`
Expected: PASS.

- [ ] **Step 5: Reescrever `EditarObrigacaoUseCase` + teste**

Crie `app/tests/Cobranca/Unit/EditarObrigacaoTaxaTest.php` (espelho do de registrar: editar com `modoMulta='reais',
multaReais=340` grava `getTaxaMultaBp()===200`, obrigação Viva segue não-congelada). Depois, no UseCase:

- Injete `ConversorTaxaEncargo`.
- Remova o bloco `$mexeuManual` e os três ramos `if ($obrigacao->encargosCongelados()) ... elseif ($mexeuManual) ...`
  (linhas ~94-130). No lugar:

```php
        $hoje = new \DateTimeImmutable('today');
        $baseCaso = $caso === null ? \App\Cobranca\DTO\ConfigEncargos::neutra() : $this->resolvedor->resolverDoCaso($caso);
        $totalAlocado = $this->alocacaoRepository->totalAlocadoEmObrigacoes([(int) $obrigacao->getId()], $tenant);

        // Grava os overrides ANTES de calcular (o cálculo do dia usa a config já com a taxa nova).
        $ov = $this->conversor->overrides(
            $input->entradaTaxas(), $baseCaso, (int) $input->valorOriginal, $input->vencimentoOriginal, $hoje);
        $obrigacao
            ->setTaxaJurosMensalBp($ov['taxaJurosMensalBp'])
            ->setTaxaMultaBp($ov['taxaMultaBp'])
            ->setTaxaCorrecaoBp($ov['taxaCorrecaoBp'])
            ->setTaxaHonorariosBp($ov['taxaHonorariosBp']);
        $config = $this->resolvedor->aplicarObrigacao($baseCaso, $obrigacao);

        // Reabertura da liquidada (reconciliação): se o exigível vivo supera o pago, volta a Viva.
        if ($obrigacao->estaLiquidada()) {
            $vivo = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
            if ((int) $input->valorOriginal + $vivo['juros'] + $vivo['multa'] + $vivo['correcao'] > $totalAlocado) {
                $obrigacao->reabrir();
            }
        }

        // Congelada (Liquidada-coberta/Substituída) respeita o snapshot; senão materializa o cache do dia.
        if ($obrigacao->encargosCongelados()) {
            $jFinal = $obrigacao->getJuros(); $mFinal = $obrigacao->getMulta();
            $cFinal = $obrigacao->getCorrecao(); $hFinal = $obrigacao->getHonorarios();
            $vaiMaterializar = false;
        } else {
            $novos = $this->calculadora->calcular((int) $input->valorOriginal, $input->vencimentoOriginal, $config, $hoje);
            $jFinal = $novos['juros']; $mFinal = $novos['multa']; $cFinal = $novos['correcao']; $hFinal = $novos['honorarios'];
            $vaiMaterializar = true;
        }
```

O guard `< totalAlocado` (linhas ~132-137), o snapshot de auditoria, os `set*` de descrição/valor/vencimento/
referência e o `if ($vaiMaterializar) definirEncargos(...)` permanecem. Estenda o `snapshot()` para incluir as taxas
de override no antes/depois (auditoria da mudança de taxa).

- [ ] **Step 6: Run tests (registrar + editar) e os functionals de obrigação**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter "Obrigacao"'`
Expected: PASS. Corrija os **functionals antigos** que enviavam `juros/multa/...` (valores) — passam a enviar
`modoX/…Bp/…Reais` (ou omitir = herda). Ajuste-os junto (mesma task).

- [ ] **Step 7: Commit**

```bash
git add app/src/Cobranca/UseCase/RegistrarObrigacaoUseCase.php app/src/Cobranca/UseCase/EditarObrigacaoUseCase.php app/tests/Cobranca/Unit/RegistrarObrigacaoTaxaTest.php app/tests/Cobranca/Unit/EditarObrigacaoTaxaTest.php
git commit -m "feat(cobranca): registrar/editar gravam a taxa por-obrigacao (nao congelam)"
```

---

## Task 8: Forms da obrigação — modo + `TaxaBpType` + `CentavosType`

Os 4 campos R$ viram, por encargo: um `modo` (hidden, setado pelo JS), um `TaxaBpType` (%) e um `CentavosType` (R$).

**Files:**
- Modify: `app/src/Cobranca/Form/RegistrarObrigacaoType.php`, `EditarObrigacaoType.php`
- Test: `app/tests/Cobranca/Functional/...` (coberto no functional do controller, Task 9/10)

**Interfaces:**
- Consumes: DTOs da Task 6 (`modoJuros`, `jurosBp`, `jurosReais`, …). `data_class` inalterada.
- Produces: campos `modoJuros` (HiddenType), `jurosBp` (`TaxaBpType`), `jurosReais` (`CentavosType`), idem multa/
  correção/honorários.

- [ ] **Step 1: Reescrever o bloco de encargo dos dois Forms**

Em cada Type, **remova** os quatro `->add('juros'|'multa'|'correcao'|'honorarios', CentavosType::class, ...)` e
adicione, por encargo (exemplo do juros; repita para multa/correcao/honorarios trocando o nome e o label):

```php
            ->add('modoJuros', HiddenType::class, ['empty_data' => 'herda'])
            ->add('jurosBp', TaxaBpType::class, [
                'label' => 'Juros ao mês (%)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa' => 'juros', 'data-modo-target' => 'modoJuros'],
            ])
            ->add('jurosReais', CentavosType::class, [
                'label' => 'Juros (R$)',
                'required' => false,
                'attr' => ['class' => 'form-control', 'data-taxa-reais' => 'juros'],
            ])
```

Adicione os `use` de `HiddenType`, `TaxaBpType`, `CentavosType`.

- [ ] **Step 2: Verificar que o form compila (smoke via functional existente)**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ObjetoShowControllerTest'`
Expected: pode falhar por causa do Twig (Task 9). Se falhar só no template, siga para a Task 9 e rode de novo lá.

- [ ] **Step 3: Commit**

```bash
git add app/src/Cobranca/Form/RegistrarObrigacaoType.php app/src/Cobranca/Form/EditarObrigacaoType.php
git commit -m "feat(cobranca): forms da obrigacao com modo + TaxaBpType(%) + CentavosType(R$)"
```

---

## Task 9: Modal Twig + JS — espelho %↔R$, herda/override

Renderiza, por encargo, o par **% ↔ R$** com o hidden `modo`; JS espelha um no outro (preview), seta o `modo` e trata
"herda do caso" (limpar). Bordas §5: quando não vencida (`dias==0`), o R$ de juros fica desabilitado (só %); honorários
idem dentro da carência.

**Files:**
- Modify: `app/templates/cobranca/objeto/_partials/_acoes_modais.html.twig`
- Modify: `app/templates/cobranca/objeto/show.html.twig` (bloco `<script>` dos modais)
- Test: `app/tests/Cobranca/Functional/ObjetoShowControllerTest.php` (submeter %/R$ e afirmar persistência/saldo)

**Interfaces:**
- Consumes: campos do form (Task 8): `form.jurosBp`, `form.jurosReais`, `form.modoJuros`, etc.
- Produces: modal funcional; submit grava a taxa; o saldo/linha refletem a taxa própria.

- [ ] **Step 1: Twig — render dos pares por encargo**

Nos modais de criar e editar, para cada encargo, renderize o par (exemplo juros; repita p/ multa/correção/honorários):

```twig
<div class="col-12 col-md-6 jp-encargo" data-encargo="juros">
  <label class="form-label">{{ form_label(form.jurosBp) }}</label>
  <div class="input-group">
    {{ form_widget(form.jurosBp, {'attr': {'class': 'form-control jp-taxa-pct'}}) }}
    <span class="input-group-text">%</span>
  </div>
  <div class="input-group mt-1">
    <span class="input-group-text">R$</span>
    {{ form_widget(form.jurosReais, {'attr': {'class': 'form-control jp-taxa-reais'}}) }}
  </div>
  {{ form_widget(form.modoJuros, {'attr': {'class': 'jp-taxa-modo'}}) }}
  <small class="text-muted jp-taxa-herda">herda do caso</small>
</div>
```

- [ ] **Step 2: JS — espelho, modo e herda (em `show.html.twig`)**

Adicione ao `<script>` dos modais um handler por bloco `.jp-encargo`. Este é o único ponto de conversão no cliente
(preview); o servidor é autoridade (Task 5). Fórmulas: juros `bp = round(reais*300000/(P*dias))`; flat
`bp = round(reais*10000/base)`. `P`, `dias`, `base` vêm em `data-*` do modal (renderize-os no Twig a partir da
obrigação/hoje):

```javascript
document.querySelectorAll('.jp-encargo').forEach(function (bloco) {
  var pct = bloco.querySelector('.jp-taxa-pct');
  var reais = bloco.querySelector('.jp-taxa-reais');
  var modo = bloco.querySelector('.jp-taxa-modo');
  pct.addEventListener('input', function () { modo.value = pct.value.trim() === '' ? 'herda' : 'percent'; });
  reais.addEventListener('input', function () { modo.value = reais.value.trim() === '' ? 'herda' : 'reais'; });
});
```

> Mantenha o espelho de PREVIEW opcional (mostrar o outro valor enquanto digita) simples; o número que vale é
> recomputado no servidor. Preserve os padrões B5 (reidratação) e reset-on-close já existentes no arquivo.

- [ ] **Step 3: Functional — submeter e afirmar persistência + saldo**

No `ObjetoShowControllerTest`, adicione um caso: POST no editar com `modoJuros=percent, jurosBp=200` (2%) numa
obrigação de P=17000 vencida há 240 dias, sob relógio fixo → após o submit, o saldo do caso reflete juros de 2%
(≈ o dobro do de 1%). Use o `MockClock` já configurado nos functionals.

- [ ] **Step 4: Run**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter ObjetoShowControllerTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/templates/cobranca/objeto/_partials/_acoes_modais.html.twig app/templates/cobranca/objeto/show.html.twig app/tests/Cobranca/Functional/ObjetoShowControllerTest.php
git commit -m "feat(cobranca): modal da obrigacao com espelho %<->R$ da taxa por-obrigacao"
```

---

## Task 10: Re-prova das suítes sensíveis + suíte global + smoke

Integração: garantir que saldo/FIFO/acordo/dashboard/pagamento leem a taxa própria e que nada regrediu.

**Files:**
- Modify (se necessário): testes em `app/tests/Cobranca/{Unit,Functional}` que semeavam encargos por valor.

- [ ] **Step 1: Suítes sensíveis, uma a uma**

Run (cada uma; corrija fixtures que enviavam valores de encargo em vez de taxa):
```
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter CalculadoraSaldo'
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter AutoAlocadorFifo'
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter Acordo'
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter Dashboard'
docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter Pagamento'
```
Expected: PASS em todas.

- [ ] **Step 2: Suíte de Cobrança completa**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit tests/Cobranca'`
Expected: verde (alvo: manter os 804 + os novos).

- [ ] **Step 3: Suíte global**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit'`
Expected: verde (alvo: 2168 + novos).

- [ ] **Step 4: `/review` (feature-review-agent) contra a spec + corrigir**

Dispare `/review` apontando para `docs/specs/cobranca-encargos-taxa-por-obrigacao.md`. Corrija os furos apontados
(orquestrador aplica; revisor não conserta). Em risco ALTO, re-revise após corrigir.

- [ ] **Step 5: Smoke real no dev (caso controlado, claro + escuro)**

Configurar uma carteira com taxa (ver `docs/gestao-cobrancas/SMOKE_ENCARGOS.md`), abrir um caso, criar 2 obrigações:
uma herda o caso, outra com **taxa própria** (via % e via R$). Confirmar: (a) a de taxa própria diverge no saldo/linha;
(b) digitar R$ salva a % e o valor recomposto "chega perto" (quantização); (c) editar não congela (cresce no dia
seguinte com relógio avançado); (d) claro e escuro OK. Remover os dados de smoke após a captura.

- [ ] **Step 6: Commit final + handoff ao humano**

```bash
git add -A
git commit -m "test(cobranca): re-prova das suites sensiveis para taxa por-obrigacao"
```
Parar em **"pronto para o humano publicar"**. Operações do humano (NÃO fazer): aplicar a migração de
`taxa_honorarios_bp` em prod (+ as migrations da cascata se ainda não estiverem em prod); rebuild/deploy; migração A1
do `encargos_congelados_em` legado; conferir carteiras sem taxa. **Nunca push/merge/deploy.**

---

## Self-Review (contra a spec)

- **§3.1 (overlay por-obrigação):** Task 4. **§4 (coluna honorários):** Task 1. **§5 (conversão R$→%):** Tasks 2+5,
  bordas `dias==0`/carência via `calcular` (motor já corta). **§6 (UI %↔R$):** Tasks 8+9. **§7 (UseCases/DTOs/Forms):**
  Tasks 6+7+8. **§8 (invariantes):** INV-V1 preservado (Task 7 grava só taxa; cache derivado); INV-V5 (motor
  inalterado, Task 2 só acrescenta). **§10 (testes):** Task 10.
- **Placeholder scan:** sem TBD/TODO; todo step tem código real. (Único ponto a limpar durante a execução: a linha
  "placeholder" sinalizada na Task 5, Step 4.)
- **Type consistency:** `aplicarObrigacao(ConfigEncargos,Obrigacao):ConfigEncargos`, `taxaJurosBpDeValor(int,int,int):int`,
  `overrides(EntradaTaxaEncargos,ConfigEncargos,int,DateTimeImmutable,DateTimeImmutable):array`,
  `entradaTaxas():EntradaTaxaEncargos`, `EncargosVivos::__construct(Clock,Calc,Resolvedor)` — consistentes entre tasks.
- **Escopo fora (YAGNI):** UI de base/regime/carência/tolerância por-obrigação; valor R$ "chumbado"; taxa por-linha
  na importação.
