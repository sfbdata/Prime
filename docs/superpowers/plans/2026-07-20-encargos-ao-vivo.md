# Encargos "ao vivo" — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) ou
> superpowers:executing-plans para implementar tarefa-a-tarefa. Steps usam checkbox (`- [ ]`).

## 🔖 STATUS & COMO RETOMAR (execução autônoma até o fim)

**Feito:** **F1→F5 COMPLETAS** (encargos ao vivo, ponta a ponta). Pronto para o humano publicar (nada
pushado/mergeado/deployado). Commits em `cobranca-encargos-cascata`:
- **F1** `fd7ae79`+`aa03a30`. **F2** (leitura ao vivo em TODOS os leitores) `7f8f2ab` `24c456a` `a28b102`
  `6185c29` `182565b`; revisão + fixes `39339af`.
- **F3** (snapshot na liquidação) `fe648ed` (liquidadaEm + liquidar/reabrir + exigivelVivo/M1) ·
  `c5ca2a0` (ReconciliadorLiquidacao + Registrar/Corrigir) · `6a2e35d` (substituídas no acordo);
  verificação adversarial multi-agente (5 lentes) → 3 bugs de dinheiro corrigidos `b46dba0`
  (divergência data-base FIFO×reconciliador, INV-V2 re-liquidação, guard da substituída).
- **F4** (remover o velho) `a5583f7` (cron+freio+predicados órfãos) · `5035dc4` (congelamento manual em
  editar/registrar/importar); revisão + fixes `ee70bda` (editar Liquidada RECONCILIA/reabre em vez de
  corromper; docs/comentários; guard de config).

**Verde:** `tests/Cobranca` **804/804**, suíte global **2168/2168**. Motor `CalculadoraEncargos` e
`ConfigEncargos` **byte-idênticos** em toda a feature (paridade ao centavo da F1/F6 de 4.317 linhas
herdada) + caminho vivo provado ao centavo (`EncargosVivosTest` linhas reais 188d/240d; tela via
`ObjetoShowControllerTest`). Migração `liquidada_em` (`Version20260720221116`, só ADD COLUMN nullable)
aplicada em **dev** e **saas_test**; **prod = humano**.

**Smoke real (F5, 2026-07-20, caso controlado no dev):** objeto aberto **cresce ao vivo** (juros R$ 13,60
p/ 240 dias, não-congelado) e pago **congela na data** (snapshot R$ 9,99 ≠ recálculo, indicador de
congelado), em **claro e escuro**. Dados de smoke removidos após a captura.

**➡️ RETOMAR EM: nada pendente do escopo F1–F5.** Restam decisões/operações do HUMANO abaixo.

**🚩 DECISÕES/OPERAÇÕES DO HUMANO (fora do código F1–F5):**
1. **Publicação:** push/merge/deploy da branch (rebuild prod via script; aplicar `Version20260720221116`).
2. **A1 — migração de dados legados (deploy, dinheiro):** no dump de prod, **~3262/3295** obrigações
   ABERTAS têm `encargos_congelados_em` != null pelo modelo ANTIGO. No ao vivo elas devem CRESCER, mas a
   hidratação pula congelada → **feature nasce inerte** até LIMPAR a flag legada das que devem ficar vivas
   (≠ da §11, que só zera cache de juros). Predicado seguro: limpar `encargos_congelados_em` das obrigações
   NÃO liquidadas (`liquidada_em IS NULL`) e NÃO substituídas por acordo vigente. É operação em dados de
   PROD ⇒ do humano (montar+revisar+rodar), idealmente junto com o deploy.
3. **Config load-bearing:** conferir carteiras SEM taxa antes do go-live (SQL no `SMOKE_ENCARGOS.md`).
4. **A2 / override de taxa por-obrigação (§7/§11 — "confirmar na revisão"):** editar/registrar encargo à
   mão NÃO persiste no ao vivo (é recomputado na leitura); os campos de encargo nos modais ficaram
   vestigiais. Decisão do dono: (a) restringir edição de encargo à taxa do caso/carteira e esconder esses
   campos, ou (b) implementar o override por-obrigação (campo de taxa na obrigação) como follow-up.

**Mandato do dono (2026-07-20):** seguir **autônomo até o final**. Por fase: implementar (TDD) → `/review`
(`feature-review-agent` contra a spec) → corrigir → testes direcionados no container → estabilizar → **commit local**.
Ao terminar F5: parar em "pronto para o humano publicar" — **NUNCA push/merge/deploy** (é do humano).

**Gotchas que já custaram tempo (não repetir):**
- Rodar TODO teste no container: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit ...'`.
- **`MockClock` a partir de `new \DateTimeImmutable('YYYY-MM-DD')`, NUNCA de string** — string cai em fuso diferente do
  vencimento e o `diff` erra 1 dia (juros off-by-one). Ver `EncargosVivosTest`.
- **Config é do CHAMADOR:** `$config = $resolvedor->resolverDoCaso($caso); $encargosVivos->hidratar($config, $obrs);`
  (o serviço `EncargosVivos` NÃO resolve config; é aplicador puro).
- **F2 religa TODOS os leitores JUNTOS** (saldo, FIFO, acordo, dashboard, DTO) — exibição e saldo não podem divergir.
- **Config da carteira é load-bearing** (sem valor guardado de rede): configurar TOPLIFE (juros 1%, multa 2%, hon
  20%/15%, carência 30) antes de qualquer smoke que valide números.
- `ConfigEncargos::padraoTopLife(2000)`=TL I (20%), `padraoTopLife(1500)`=TL II (15%). Fórmula provada ao centavo
  contra a planilha nova (spec §2) — NÃO mexer no motor `CalculadoraEncargos`.
- Só há uma decisão de produto adiada (§7/§11 spec: override de taxa por-obrigação) — **fora do escopo F1–F5**, é
  follow-up. Se algo forçar essa decisão no meio, PARAR e sinalizar; senão, seguir.

---

**Goal:** Tornar os encargos de obrigação em aberto **calculados ao vivo** (vencimento→hoje), removendo o cron e o
congelamento manual; o relógio só para ao **liquidar** (pagar/acordar).

**Architecture:** Um serviço `EncargosVivos` **hidrata em memória** (sem flush) os encargos de cada obrigação Viva
para `hoje` (via `ClockInterface`), reusando o motor `CalculadoraEncargos` (fórmula inalterada, provada ao centavo) e
a config resolvida 1× por caso. Todos os leitores de exigível (saldo, FIFO, acordo, dashboard, DTO) passam a ler
obrigações já hidratadas — `valorExigivel()` não muda de assinatura. Liquidada/Substituída guardam snapshot na data
de corte.

**Tech Stack:** PHP 8.2, Symfony 7.4, Doctrine ORM 3, PHPUnit 11, `psr/clock` (`ClockInterface`), Foundry v2, DAMA.

## Global Constraints (verbatim da spec + CLAUDE.md)

- Aritmética de dinheiro **100% inteira em centavos**; `float` proibido no caminho do dinheiro.
- **Fórmula inalterada** (spec §5): juros pró-rata diária `dias/30` half-down; multa 2% fixa half-up; correção 0;
  honorários 20%(I)/15%(II) sobre composta após carência 30d half-up.
- `hoje` **sempre injetado** via `ClockInterface` — nunca `new \DateTimeImmutable()` no caminho do dinheiro.
- Nada de encargo de obrigação **Viva** é persistido (INV-V1). Só **Liquidada/Substituída** têm snapshot.
- Testes rodam em `APP_ENV=test` com `failOnDeprecation/Notice/Warning` — um deprecation derruba a suíte.
- Todo comando roda no container: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit ...'`.
- Git: commit local OK (staging por arquivo, revisando diff); **push/merge/deploy = humano**.
- Multi-tenant: toda query filtra por tenant; nunca afrouxar.
- Config de carteira (juros/multa/honorários) é **pré-requisito** para qualquer smoke que valide números.

---

## Mapa de arquivos

**Criar:**
- `app/src/Cobranca/Service/EncargosVivos.php` — hidrata encargos em memória p/ a data de referência do estado.
- `app/tests/Cobranca/Unit/EncargosVivosTest.php` — testes do serviço com `MockClock`.
- (F3) `app/src/...` migração Doctrine para `liquidadaEm` (nome `VersionYYYYMMDDHHMMSS`).

**Modificar (leitura → vivo, F2):**
- `app/src/Cobranca/Service/CalculadoraSaldo.php` — hidratar antes de somar `valorExigivel()`.
- `app/src/Cobranca/Service/AutoAlocadorFifo.php` — operar sobre obrigações hidratadas.
- `app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php` — hidratar o caso antes de montar DTOs/saldo.
- `app/src/Cobranca/UseCase/MontarDetalheAcordoUseCase.php` — idem para parcelas/substituídas.
- `app/src/Cobranca/UseCase/MontarDashboardCobrancaUseCase.php` — hidratar exigíveis por caso antes de agregar.
- `app/src/Cobranca/Form/AcordoCriarType.php` — remanescente sobre exigível vivo.
- `app/src/Cobranca/Entity/Obrigacao.php` — (F3) campo `liquidadaEm` + getters/setters; (F4) remover freio.

**Modificar (liquidação/remoção, F3–F4):**
- `app/src/Cobranca/UseCase/RegistrarPagamentoUseCase.php` — snapshot + `liquidadaEm` na quitação total.
- `app/src/Cobranca/UseCase/CriarAcordoUseCase.php` — snapshot das substituídas na `dataAcordo`.
- `app/src/Cobranca/UseCase/CorrigirPagamentoUseCase.php` — reabrir (limpar `liquidadaEm`) se desfizer a quita.
- `app/src/Cobranca/UseCase/EditarObrigacaoUseCase.php` — remover `congelarEncargos`.
- `app/src/Cobranca/UseCase/RegistrarObrigacaoUseCase.php` — remover `congelarEncargos`.
- `app/src/Cobranca/UseCase/ImportarRelatorioCarteiraUseCase.php` — remover `congelarEncargos`.
- `app/src/Cobranca/Command/AtualizarEncargosCommand.php` — remover (+ schedule).
- `app/src/Cobranca/Exception/ReducaoDeEncargosBloqueadaException.php` — remover.

---

## Fase F1 — Infra: `EncargosVivos` + relógio (SEM mudança de comportamento)

Entrega isolada e testável: o serviço existe e é provado com `MockClock`; nenhum caminho de leitura muda ainda.

### Task 1: Serviço `EncargosVivos` (hidratação em memória)

**Files:**
- Create: `app/src/Cobranca/Service/EncargosVivos.php`
- Test: `app/tests/Cobranca/Unit/EncargosVivosTest.php`

**Interfaces:**
- Consumes: `CalculadoraEncargos::calcular(int, \DateTimeImmutable, ConfigEncargos, \DateTimeImmutable): array{juros,multa,correcao,honorarios}`; `ResolvedorConfigEncargos::resolverDoCaso(CasoCobranca): ConfigEncargos`; `Obrigacao::{getValorOriginal(),getVencimentoOriginal(),definirEncargos(int,int,int,int,\DateTimeImmutable),encargosCongelados()}`; `Psr\Clock\ClockInterface::now(): \DateTimeImmutable`.
- Produces: `EncargosVivos::hidratarCaso(CasoCobranca $caso, iterable $obrigacoes): void` — preenche em memória (sem flush) os encargos de cada obrigação **não congelada** (Viva) para `clock.now()`. Congeladas (Liquidada/Substituída) são deixadas intactas.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php
declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\EncargosVivos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(EncargosVivos::class)]
final class EncargosVivosTest extends TestCase
{
    #[TestDox('Hidrata a obrigação Viva com o juros de HOJE (relógio fixo), sem persistir')]
    public function testHidrataObrigacaoVivaParaHoje(): void
    {
        // Config TOPLIFE I: juros 1% a.m., multa 2%, honorários 20%, carência 30, base composta.
        $config = $this->configTopLifeI();
        $resolvedor = $this->createMock(ResolvedorConfigEncargos::class);
        $resolvedor->method('resolverDoCaso')->willReturn($config);

        $clock = new MockClock('2026-07-20');
        $sut = new EncargosVivos($clock, $resolvedor, new CalculadoraEncargos());

        // Linha real TOPLIFE II NN:60006: P=170,00, venc 13/01/2026 → 188 dias em 20/07/2026.
        // (Config aqui usa 20% p/ simplificar a asserção de que ELE calcula; o número exato de honorário
        //  não importa para este teste — importa que juros = 170 * 1% * 188/30 = 1065 e que NADA foi flushado.)
        $obrigacao = $this->obrigacaoViva(valorOriginal: 17000, venc: '2026-01-13');
        $caso = $this->createStub(CasoCobranca::class);

        $sut->hidratarCaso($caso, [$obrigacao]);

        self::assertSame(1065, $obrigacao->getJuros(), 'juros vivo = 170 * 1% * 188/30, half-down');
        self::assertSame(340, $obrigacao->getMulta(), 'multa fixa 2% do principal');
        self::assertSame(0, $obrigacao->getCorrecao());
        self::assertSame(17000 + 1065 + 340 + 0, $obrigacao->valorExigivel(), 'exigível reflete o vivo');
    }

    #[TestDox('Não toca obrigação congelada (Liquidada/Substituída): mantém o snapshot')]
    public function testNaoTocaCongelada(): void
    {
        $resolvedor = $this->createMock(ResolvedorConfigEncargos::class);
        $resolvedor->expects(self::never())->method('resolverDoCaso');
        $clock = new MockClock('2026-07-20');
        $sut = new EncargosVivos($clock, $resolvedor, new CalculadoraEncargos());

        $congelada = $this->obrigacaoViva(valorOriginal: 17000, venc: '2026-01-13');
        $congelada->definirEncargos(999, 111, 0, 222, new \DateTimeImmutable('2026-02-01'));
        $congelada->congelarEncargos(new \DateTimeImmutable('2026-02-01'));
        $caso = $this->createStub(CasoCobranca::class);

        $sut->hidratarCaso($caso, [$congelada]);

        self::assertSame(999, $congelada->getJuros(), 'snapshot intacto');
        self::assertSame(111, $congelada->getMulta());
    }

    private function obrigacaoViva(int $valorOriginal, string $venc): Obrigacao
    {
        // Construir via reflexão/factory conforme o construtor real de Obrigacao (ver a entidade ao executar).
        // O teste é Unit puro (sem kernel); usar o mesmo caminho de instância dos outros *UseCaseTest unitários.
        return ObrigacaoTestFactory::viva($valorOriginal, new \DateTimeImmutable($venc));
    }

    private function configTopLifeI(): \App\Cobranca\DTO\ConfigEncargos
    {
        // Montar ConfigEncargos com juros 100bp, multa 200bp, correção 0, honorários 2000bp, carência 30,
        // bases conforme o default TOPLIFE (ver ConfigEncargos ao executar para os nomes exatos dos parâmetros).
        return ConfigEncargosTestFactory::topLifeI();
    }
}
```

> Nota de execução: `ObrigacaoTestFactory`/`ConfigEncargosTestFactory` acima são atalhos de leitura — ao executar,
> instanciar `Obrigacao`/`ConfigEncargos` pelo construtor real (ver `app/src/Cobranca/Entity/Obrigacao.php` e
> `app/src/Cobranca/DTO/ConfigEncargos.php`), espelhando como os `*Test` unitários existentes já os criam.

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EncargosVivosTest'`
Expected: FAIL — `Class "App\Cobranca\Service\EncargosVivos" not found`.

- [ ] **Step 3: Implementar o serviço**

```php
<?php
declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use Psr\Clock\ClockInterface;

/**
 * Preenche EM MEMÓRIA (sem flush) os encargos de cada obrigação VIVA para a data de referência de hoje,
 * reusando o motor puro `CalculadoraEncargos`. Congeladas (Liquidada/Substituída) mantêm o snapshot.
 * Config resolvida 1× por caso (evita N+1 — override é por-caso). `hoje` vem do relógio injetado
 * (determinístico e testável).
 */
final class EncargosVivos
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ResolvedorConfigEncargos $resolvedor,
        private readonly CalculadoraEncargos $calculadora,
    ) {
    }

    /** @param iterable<Obrigacao> $obrigacoes */
    public function hidratarCaso(CasoCobranca $caso, iterable $obrigacoes): void
    {
        $hoje = $this->clock->now();
        $config = null;

        foreach ($obrigacoes as $obrigacao) {
            if ($obrigacao->encargosCongelados()) {
                continue; // Liquidada/Substituída: snapshot é a verdade.
            }

            $config ??= $this->resolvedor->resolverDoCaso($caso);

            $e = $this->calculadora->calcular(
                $obrigacao->getValorOriginal(),
                $obrigacao->getVencimentoOriginal(),
                $config,
                $hoje,
            );

            $obrigacao->definirEncargos($e['juros'], $e['multa'], $e['correcao'], $e['honorarios'], $hoje);
        }
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EncargosVivosTest'`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add app/src/Cobranca/Service/EncargosVivos.php app/tests/Cobranca/Unit/EncargosVivosTest.php
git commit -m "F1: servico EncargosVivos hidrata encargos ao vivo (relogio injetavel)"
```

### Task 2: Provar a paridade ao centavo do caminho vivo (verificação)

**Files:**
- Test: `app/tests/Cobranca/Unit/EncargosVivosTest.php` (adiciona casos-prova)

**Interfaces:** Consumes Task 1.

- [ ] **Step 1: Adicionar teste-prova com linhas reais (relógio fixo em 20/07/2026)**

```php
    #[TestDox('Paridade ao centavo com a planilha real (20/07): linhas TOPLIFE II verificadas')]
    public function testParidadeComPlanilhaReal(): void
    {
        $clock = new MockClock('2026-07-20');
        $resolvedor = $this->createMock(ResolvedorConfigEncargos::class);
        $resolvedor->method('resolverDoCaso')->willReturn($this->configTopLifeII()); // hon 15%
        $sut = new EncargosVivos($clock, $resolvedor, new CalculadoraEncargos());

        // NN:60006 — P=170,00, venc 13/01/2026 → 188 dias → jur 10,65 · mul 3,40 · hon 27,61 · tot 211,66.
        $o = $this->obrigacaoViva(17000, '2026-01-13');
        $sut->hidratarCaso($this->createStub(CasoCobranca::class), [$o]);
        self::assertSame(1065, $o->getJuros());
        self::assertSame(340, $o->getMulta());
        self::assertSame(2761, $o->getHonorarios());
        self::assertSame(21166, $o->totalComHonorarios());
    }
```

- [ ] **Step 2: Rodar e ver passar**

Run: `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit --filter EncargosVivosTest'`
Expected: PASS (3 testes). Se falhar, conferir os parâmetros de `ConfigEncargos` (base composta, carência 30).

- [ ] **Step 3: Commit**

```bash
git add app/tests/Cobranca/Unit/EncargosVivosTest.php
git commit -m "F1: prova ao centavo do caminho vivo (linha real TOPLIFE II)"
```

---

## Fase F2 — Virar a leitura para o vivo (a fase grande, coordenada)

> **Regra da fase (INV-V5 / risco):** exibição e saldo têm de virar **juntos** — nunca exibição viva com saldo
> guardado. Cada task abaixo é um leitor; ao final, rodar a suíte inteira antes de F3.

> **Nota F1 (implementada, `fd7ae79`/`aa03a30`):** o serviço ficou como aplicador puro
> `EncargosVivos::hidratar(ConfigEncargos $config, iterable $obrigacoes)` — a config é resolvida pelo CHAMADOR
> (não pelo serviço). `tests/Cobranca` 799/799.

Padrão de transformação (aplicado a cada leitor, contra o arquivo real): **onde hoje se carrega obrigações de um caso
e se lê `valorExigivel()`/getters, injetar `EncargosVivos` + `ResolvedorConfigEncargos`, resolver a config 1× por
caso (`$config = $resolvedor->resolverDoCaso($caso)`) e chamar `$encargosVivos->hidratar($config, $obrigacoes)` logo
após o carregamento, antes de somar/mapear.** Injeção via construtor (autowire).

### Task 3: `CalculadoraSaldo` soma sobre o vivo

**Files:** Modify `app/src/Cobranca/Service/CalculadoraSaldo.php`; Test `app/tests/Cobranca/Functional/CalculadoraSaldoTest.php` (ou Unit existente).

**Interfaces:** Consumes `EncargosVivos::hidratarCaso`.

- [ ] **Step 1:** Reescrever o teste de saldo com `MockClock` fixo: seed obrigação com `vencimento` conhecido (sem `definirEncargos`), assertar que `saldoExigivel` = soma dos exigíveis VIVOS calculados p/ a data do mock. (Ver o teste atual para o formato; trocar valores fixos por valores derivados da fórmula sob relógio fixo.)
- [ ] **Step 2:** Rodar — ver falhar (saldo ainda lê valores não hidratados = 0/stale).
- [ ] **Step 3:** Injetar `EncargosVivos` no construtor; em `saldoExigivel`/`saldoBruto`, após `doCasoExigiveis($caso)`, chamar `$this->encargosVivos->hidratarCaso($caso, $obrigacoes)` antes do `foreach` que soma.
- [ ] **Step 4:** Rodar — ver passar.
- [ ] **Step 5:** Commit `F2: saldo soma sobre encargos vivos`.

### Task 4: `AutoAlocadorFifo` sobre o vivo

**Files:** Modify `app/src/Cobranca/Service/AutoAlocadorFifo.php`; Test `AutoAlocadorFifoTest`.
- [ ] Mesmo ciclo TDD: relógio fixo; hidratar as obrigações do caso antes de calcular sala/saldo bruto; asserções passam a bater com o exigível vivo. Commit `F2: FIFO aloca sobre exigivel vivo`.

### Task 5: `MontarDetalheCasoUseCase` (tela do objeto)

**Files:** Modify `app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php`; Test `ObjetoShowControllerTest` + o UseCase test.
- [ ] Hidratar as obrigações do caso antes de montar `ObrigacaoOutput`/saldo. Ajustar os functional que semeiam `definirEncargos` fixo → agora derivam do vencimento+config sob relógio fixo (usar `MockClock` no ambiente de teste). Commit `F2: detalhe do caso exibe encargos vivos`.

### Task 6: `MontarDetalheAcordoUseCase`

**Files:** Modify `.../MontarDetalheAcordoUseCase.php`; Test `MontarDetalheAcordo*Test`.
- [ ] Parcelas Vivas hidratam (D4: parcela cresce se atrasar); substituídas usam snapshot. Commit `F2: acordo exibe parcelas vivas`.

### Task 7: `MontarDashboardCobrancaUseCase`

**Files:** Modify `.../MontarDashboardCobrancaUseCase.php`; Test `MontarDashboard*Test`.
- [ ] Hidratar `exigiveisDosCasos` por caso antes de agregar. **Validar performance** no dataset real depois (risco §10.2). Commit `F2: dashboard agrega sobre exigivel vivo`.

### Task 8: `AcordoCriarType` (remanescente no form)

**Files:** Modify `app/src/Cobranca/Form/AcordoCriarType.php`; Test o functional de criar acordo.
- [ ] Remanescente = exigível vivo − alocado. Commit `F2: form de acordo usa exigivel vivo`.

### Task 9: Suíte inteira verde + prova ao centavo

- [ ] Rodar `tests/Cobranca` inteiro e a suíte global sob `MockClock` onde aplicável.
- [ ] Rodar o script de verificação do §2 da spec (contra a planilha nova) — alvo TL II 412/412, TL I ≥ 1963/1964.
- [ ] Commit (se houver ajustes) `F2: suite verde + paridade ao centavo`.

---

## Fase F3 — Snapshot na liquidação (`liquidadaEm`)

### Task 10: Campo `liquidadaEm` + migração

**Files:** Modify `app/src/Cobranca/Entity/Obrigacao.php` (campo `?\DateTimeImmutable $liquidadaEm` + get/set); Create migração.
- [ ] Adicionar coluna nullable `liquidada_em`. `php bin/console make:migration` no container; revisar SQL (só ADD COLUMN nullable); aplicar em dev. Commit `F3: campo liquidadaEm na obrigacao`.

### Task 11: Snapshot na quitação total

**Files:** Modify `app/src/Cobranca/UseCase/RegistrarPagamentoUseCase.php`; Test `RegistrarPagamento*Test`.
- [ ] TDD: pagar o exigível vivo integral → obrigação materializa encargos na `dataPagamento` (`definirEncargos(...,dataPagamento)` + `congelarEncargos(dataPagamento)` + `liquidadaEm=dataPagamento`) e persiste; no dia seguinte (avança o `MockClock`) o valor NÃO cresce e segue quitada. Commit `F3: quitacao total congela na data do pagamento`.

### Task 12: Snapshot das substituídas no acordo

**Files:** Modify `app/src/Cobranca/UseCase/CriarAcordoUseCase.php`; Test `CriarAcordo*Test`.
- [ ] Ao substituir, materializar cada substituída na `dataAcordo` + congelar. Commit `F3: acordo congela substituidas na data do acordo`.

### Task 13: Reabrir na correção de pagamento

**Files:** Modify `app/src/Cobranca/UseCase/CorrigirPagamentoUseCase.php`; Test `CorrigirPagamento*Test`.
- [ ] Se a correção deixa `alocado < exigível`, limpar `liquidadaEm` + `descongelarEncargos()` → volta a Viva (avança o relógio, cresce). Commit `F3: corrigir pagamento reabre obrigacao liquidada`.

---

## Fase F4 — Remover o velho

### Task 14: Remover congelamento manual (edição/registro/import)

**Files:** Modify `EditarObrigacaoUseCase.php`, `RegistrarObrigacaoUseCase.php`, `ImportarRelatorioCarteiraUseCase.php`; ajustar os testes que afirmavam "digitou/importou → congela".
- [ ] Remover as chamadas `congelarEncargos` desses caminhos (a obrigação permanece Viva; edição só muda inputs). Migrar os testes desses ramos. Commit `F4: editar/registrar/importar nao congelam mais`.

### Task 15: Remover cron + freio de redução

**Files:** Delete `Command/AtualizarEncargosCommand.php` + entrada de schedule; Delete `Exception/ReducaoDeEncargosBloqueadaException.php`; remover `idsParaAtualizarEncargos`/predicados órfãos do repositório se não usados; remover `AtualizarEncargosCommandTest`.
- [ ] Grep por referências antes de apagar; suíte verde depois. Commit `F4: remover cron de encargos e freio de reducao`.

---

## Fase F5 — Verificação final

### Task 16: Prova ao centavo + suíte + smoke

- [ ] Rodar o script do §2 da spec contra a planilha nova: TL II 412/412, TL I ≥ 1963/1964.
- [ ] `tests/Cobranca` verde + suíte global verde.
- [ ] **Configurar as carteiras TOPLIFE em dev** (juros 1%, multa 2%, hon 20%/15%, carência 30) e smoke real: objeto 117 (aberto, cresce ao vivo) + um caso com pagamento total (congela na data) + um acordo (parcela cresce; substituída congelada); claro e escuro.
- [ ] Commit `F5: verificacao final ao centavo + smoke`.

---

## Self-Review (cobertura da spec)

- Spec §3 D1 (ao vivo, nada guardado) → F1 + F2. ✅
- §3 D2 (para ao liquidar) → F3. ✅
- §3 D3 (editar não congela) → F4 Task 14. ✅ (override de taxa por-obrigação = follow-up, §11 spec, fora deste plano)
- §3 D4 (parcela cresce) → F2 Task 6. ✅
- §3 D5 (fórmula mantida) → F1 (reusa CalculadoraEncargos). ✅
- §3 D6 (sai cron + congelar manual) → F4. ✅
- §5 (data de referência por estado) → F1 (Viva=hoje) + F3 (Liquidada/Substituída=corte). ✅
- §6.2 (todos os leitores) → F2 Tasks 3–8. ✅
- §8 invariantes → cobertas por F1–F4; INV-V5 prova → Task 2, 9, 16. ✅
- §10 riscos: config load-bearing (Task 16 pré-req carteiras); performance dashboard (Task 7 validação). ✅

**Placeholders:** os "TestFactory" no F1 são atalhos de leitura, com nota de execução explícita para instanciar pelo
construtor real — não são código final ambíguo. F2–F4 descrevem o **padrão de transformação** por arquivo (o executor
lê o arquivo real e aplica); é o formato adequado para refactor de consumidores existentes.
