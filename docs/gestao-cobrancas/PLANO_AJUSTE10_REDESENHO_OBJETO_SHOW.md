# Ajuste 10 — Redesenho do `cobranca_objeto_show` — Plano de Implementação

> **Para quem executa:** SUB-SKILL OBRIGATÓRIA: use `superpowers:subagent-driven-development` (recomendado) ou
> `superpowers:executing-plans` para executar tarefa a tarefa. Os passos usam checkbox (`- [ ]`).

**Spec (fonte de verdade):** `docs/specs/cobranca-ajuste10-redesenho-objeto-show.md` — leia ANTES.
**Mockup aprovado (referência visual literal):** `docs/gestao-cobrancas/mockup-ajuste10-objeto-show.html`
(abra no navegador; tem os 2 temas e a barra de seleção funcionando).

**Goal:** Reorganizar a página do objeto de cobrança das tabelas do banco para o trabalho do usuário —
6 abas → 3, Pessoa vira card, Receber/Acordar na própria obrigação — sem mudar nenhuma regra de negócio,
e corrigindo de passagem um bug de dinheiro confirmado em produção.

**Architecture:** Nenhuma regra de negócio muda. O trabalho é (a) **expor dado que já existe** e a tela nunca
mostrou (`alocado` por obrigação, via um repositório de lote que já roda em prod), (b) reescrever o template,
(c) um método **aditivo** puro em `CalculadoraHonorarios`. Saldo, exigibilidade e guards de acordo ficam
intocados.

**Tech Stack:** PHP 8.2 · Symfony 7.4 · Doctrine ORM 3 · Twig · Bootstrap 5.3.3 (CDN, sem build) · vanilla JS
· PHPUnit + Foundry v2 + DAMA.

## Global Constraints

- **Todo comando roda no container:** `docker exec jusprime_php_dev bash -c 'cd app && <cmd>'`. **Nunca** rodar
  `php`/`composer`/`bin/console` fora dele.
- **Baseline medida em 2026-07-16:** `tests/Cobranca` = **539 testes, 2000 asserções, OK**. Nenhuma tarefa pode
  reduzir isso; cada tarefa só sobe o número.
- PHPUnit roda com `failOnDeprecation/Notice/Warning` — **um deprecation derruba a suíte**.
- `declare(strict_types=1);` em todo arquivo PHP; classes `final`; `private readonly` nas dependências;
  type hints em 100%; `===`/`!==`; linha em branco antes do `return`; sem `else` após `if` que retorna.
- **Dinheiro sempre em centavos `int`.** Nunca float. Formatação no Twig com `|centavos`.
- Template recebe **Output DTOs**, nunca entidade Doctrine. Dados p/ JS via `data-*` +
  `json_encode|e('html_attr')` — **nunca** `|raw` em `<script>`.
- **Cores:** só `var(--bs-*)` e o accent `--jp-accent`/`--jp-accent-rgb`. Status como fundo usa
  `rgba(var(--...-rgb), α)` — **nunca hex claro** (quebra o tema escuro).
- **Multi-tenant:** toda query filtra tenant; o objeto já vem resolvido por tenant no controller (404 se não).
- **Nomes de método de teste em `camelCase`** (`restanteDescontaOAlocado`), como manda o CLAUDE.md raiz e como
  faz o resto da suíte (476 camelCase × 0 snake_case). *A primeira versão deste plano trazia os exemplos em
  `snake_case` por engano e contaminou T1–T3; foi corrigido. Se algum exemplo ainda aparecer em snake_case,
  **a convenção do projeto governa, não o exemplo**.*
- Commits: imperativo em português, máx. 72 chars, sem ponto final. **Nunca** push/merge/rebase.
- **Cadência do módulo:** ao fim de cada tarefa → rodar a suíte → **MOSTRAR o smoke ao humano** → ele aprova →
  só então commitar. Não pule o smoke.

---

## Estrutura de arquivos

| Arquivo | Responsabilidade | Tarefa |
|---|---|---|
| `app/src/Cobranca/DTO/ObrigacaoOutput.php` | ganha `alocado`, `acordoSubstitutoId`, `restante()`, `quitada()` | T1, T2 |
| `app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php` | carrega o mapa de alocações (1 query) e agrupa substituídas | T1, T2 |
| `app/src/Cobranca/DTO/GrupoAcordoObrigacoesOutput.php` | ganha `substituidas` | T2 |
| `app/src/Cobranca/Service/CalculadoraHonorarios.php` | ganha `brutoParaRecuperar` (inverso do rateio) | T3 |
| `app/templates/cobranca/objeto/show.html.twig` | reescrito: 3 abas, card, Dívida unificada | T4 |
| `app/templates/cobranca/objeto/_partials/_pessoa_card.html.twig` | **novo** — card da pessoa + vínculos | T4 |
| `app/templates/cobranca/objeto/_partials/_divida.html.twig` | **novo** — Dívida (avulsas + grupos + substituídas) | T4 |
| `app/templates/cobranca/objeto/_partials/_movimentos.html.twig` | **novo** — pagamentos + liquidações + acordos encerrados | T4 |
| `app/public/css/cobrancas.css` | componentes novos (`.jp-*`) | T4 |
| `app/src/Cobranca/Form/AcordoCriarType.php` | `opcoes/valoresObrigacoes` passam a usar o remanescente | T6 |
| `app/src/Cobranca/Service/MontadorModaisCaso.php` | injeta alocações; data do pagamento = hoje; forms sob demanda | T6, T7, T9 |
| `app/public/js/pasta-arquivos.js` | bug do `#pastaTabs` | T7 |

**Por que 3 partials novos:** `show.html.twig` tem 941 linhas hoje e é o maior problema de manutenção do
módulo. O próprio módulo já extrai (`_documentos.html.twig`, `_acoes_modais*.html.twig`). Cada partial fica
abaixo de ~200 linhas e tem uma responsabilidade.

---

## Task 1: `alocado` e `restante` por obrigação

**O porquê:** hoje a tela do objeto não sabe quanto já foi pago em cada obrigação. Sem isso não existe
"falta R$ 800,00" nem o prefill do Receber (T5). A tela do **acordo** já faz isso desde o ajuste 7 —
`MontarDetalheAcordoUseCase.php:39` é o precedente a copiar. **Não invente query nova.**

**Files:**
- Modify: `app/src/Cobranca/DTO/ObrigacaoOutput.php`
- Modify: `app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php:35-45` (construtor) e `:60`
- Test: `app/tests/Cobranca/Unit/ObrigacaoOutputTest.php` (**existe** — adicionar; **não editar os antigos**)
- Test: `app/tests/Cobranca/Unit/MontarDetalheCasoUseCaseTest.php` (**NÃO existe — criar**; copie a estrutura
  de `app/tests/Cobranca/Unit/MontarDetalheAcordoUseCaseTest.php`, que já mocka repositórios do mesmo jeito)

**Interfaces:**
- Consumes: `AlocacaoPagamentoRepository::somasPorObrigacaoDosCasos(array $casoIds, Tenant $tenant): array<int,int>` (já existe, `:74`)
- Produces: `ObrigacaoOutput::$alocado: int`, `ObrigacaoOutput::restante(): int`, `ObrigacaoOutput::quitada(): bool`, `ObrigacaoOutput::fromEntity(Obrigacao $o, int $alocado = 0): self`

- [ ] **Step 1: Escrever o teste que falha (restante e quitada)**

Em `app/tests/Cobranca/Unit/ObrigacaoOutputTest.php`, **adicione** (não mexa nos testes existentes):

```php
#[Test]
public function restanteDescontaOAlocadoDoValorAtual(): void
{
    $obrigacao = $this->obrigacaoCom(valorOriginal: 120000, encargos: 0);

    $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 40000);

    self::assertSame(40000, $output->alocado);
    self::assertSame(80000, $output->restante());
    self::assertFalse($output->quitada());
}

#[Test]
public function restanteTemPisoZeroQuandoSuperAlocada(): void
{
    // Alocação manual não tem teto por obrigação (beco conhecido, spec §10):
    // o DTO não pode devolver negativo para a tela.
    $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

    $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 130000);

    self::assertSame(0, $output->restante());
    self::assertTrue($output->quitada());
}

#[Test]
public function quitadaQuandoAlocadoCobreExatamenteOValor(): void
{
    $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 20000);

    $output = ObrigacaoOutput::fromEntity($obrigacao, alocado: 120000);

    self::assertSame(0, $output->restante());
    self::assertTrue($output->quitada());
}

#[Test]
public function alocadoDefaultZeroPreservaOComportamentoAntigo(): void
{
    $obrigacao = $this->obrigacaoCom(valorOriginal: 100000, encargos: 0);

    $output = ObrigacaoOutput::fromEntity($obrigacao);

    self::assertSame(0, $output->alocado);
    self::assertSame(100000, $output->restante());
}
```

> **Nota:** o helper `obrigacaoCom(...)` provavelmente já existe no arquivo sob outro nome — **leia o teste
> antes** e reuse o que estiver lá em vez de criar um segundo helper. Se não existir, extraia dos testes atuais.

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ObrigacaoOutputTest'
```
Esperado: FAIL — `fromEntity()` não aceita 2º argumento / `restante()` não existe.

- [ ] **Step 3: Implementar no `ObrigacaoOutput`**

Adicione o parâmetro **no fim** do construtor (depois de `acordoOrigemId`), com default — assim nenhum
chamador por argumento nomeado quebra:

```php
        /** Acordo que gerou esta obrigação (null = não é parcela) — agrupa a aba Obrigações (Ajuste 8). */
        public readonly ?int $acordoOrigemId = null,
        /**
         * Σ das alocações de pagamento nesta obrigação (centavos) — DERIVADO (invariável 20), nunca coluna.
         * Carregado em LOTE pelo UseCase (`somasPorObrigacaoDosCasos`); default 0 mantém os chamadores antigos.
         */
        public readonly int $alocado = 0,
    ) {
    }

    /**
     * Quanto ainda falta receber nesta obrigação (centavos), com PISO 0: alocação manual não tem teto por
     * obrigação, então uma super-alocada devolveria negativo e poluiria a tela (spec §10, ajuste 10).
     */
    public function restante(): int
    {
        return max(0, $this->valorAtual - $this->alocado);
    }

    /** Alocado cobre o exigível — espelha `ParcelaAcordoResumoOutput::quitada`. */
    public function quitada(): bool
    {
        return $this->alocado >= $this->valorAtual;
    }

    public static function fromEntity(Obrigacao $o, int $alocado = 0): self
    {
```

E, no `return new self(...)` do `fromEntity`, acrescente a última linha:

```php
            acordoOrigemId: $origem?->getId(),
            alocado: $alocado,
        );
```

- [ ] **Step 4: Rodar e ver passar (inclusive os 5 antigos)**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ObrigacaoOutputTest'
```
Esperado: PASS. **Se algum teste antigo precisou de edição, o default está errado — volte ao Step 3.**

- [ ] **Step 5: Teste que trava o N+1 (uma query, não N)**

Este é o teste mais importante da tarefa: ele impede que alguém "conserte" isso com um loop depois.
Em `app/tests/Cobranca/Unit/MontarDetalheCasoUseCaseTest.php`:

```php
#[Test]
public function carregaAsAlocacoesEmUmaUnicaQuery(): void
{
    $caso = $this->casoPersistido();   // reuse o helper do arquivo; se não houver, monte o caso como os outros testes

    $this->alocacaoRepository
        ->expects(self::once())            // ← o ponto do teste: UMA vez, não uma por obrigação
        ->method('somasPorObrigacaoDosCasos')
        ->with([$caso->getId()], $caso->getTenant())
        ->willReturn([]);

    $this->useCase->executar($caso);
}
```

- [ ] **Step 6: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter MontarDetalheCasoUseCaseTest'
```
Esperado: FAIL — o UseCase nem conhece o repositório.

- [ ] **Step 7: Injetar e usar no `MontarDetalheCasoUseCase`**

No `use` do topo:
```php
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
```

No construtor, como **9ª** dependência:
```php
        private readonly AlertasCobranca $alertasCobranca,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
    ) {
    }
```

Troque a linha `:60` (o `array_map` das obrigações) por:

```php
        // Aba Obrigações (Ajuste 8): as parcelas de acordo VIGENTE saem da lista solta e viram grupo.
        // Ajuste 10: UMA query para o mapa `obrigacaoId => alocado` do caso inteiro — mesmo padrão de
        // `MontarDetalheAcordoUseCase:39`. Nunca por obrigação (N+1).
        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos(
            [$caso->getId()],
            $caso->getTenant(),
        );

        $obrigacoes = array_map(
            static fn ($o) => ObrigacaoOutput::fromEntity($o, $alocadoPorObrigacao[$o->getId()] ?? 0),
            $this->obrigacaoRepository->doCaso($caso),
        );
```

> **Por que `$caso->getTenant()` e não um parâmetro novo:** mantém `executar(CasoCobranca $caso)` intacto,
> logo `MontarDetalheObjetoUseCase:30` e `ObjetoController:91` **não mudam**. É o que `CalculadoraSaldo::saldoExigivel:56` já faz.

- [ ] **Step 8: Rodar e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
```
Esperado: OK, **≥ 543 testes** (539 + 4 novos). Zero deprecations.

- [ ] **Step 9: Commit**

```bash
git add app/src/Cobranca/DTO/ObrigacaoOutput.php app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php app/tests/Cobranca/Unit/ObrigacaoOutputTest.php app/tests/Cobranca/Unit/MontarDetalheCasoUseCaseTest.php
git commit -m "Expor alocado e restante por obrigacao no detalhe do caso"
```

---

## Task 2: expor as obrigações substituídas no grupo do acordo

**O porquê:** `agruparPorAcordo` **descarta** as substituídas (`:136-138`, um `continue`), então a tela nunca
mostra que existem. É a maior fonte de confusão do módulo: a dívida "some" e ninguém entende. O redesenho as
mostra recolhidas, com a legenda de que **voltam ao total se o acordo for rompido**.

**Files:**
- Modify: `app/src/Cobranca/DTO/ObrigacaoOutput.php` (ganha `acordoSubstitutoId`)
- Modify: `app/src/Cobranca/DTO/GrupoAcordoObrigacoesOutput.php` (ganha `substituidas`)
- Modify: `app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php:122-176` (`agruparPorAcordo`)
- Test: `app/tests/Cobranca/Unit/MontarDetalheCasoUseCaseTest.php`

**Interfaces:**
- Consumes: `ObrigacaoOutput::$alocado` (T1)
- Produces: `ObrigacaoOutput::$acordoSubstitutoId: ?int`; `GrupoAcordoObrigacoesOutput::$substituidas: list<ObrigacaoOutput>`

- [ ] **Step 1: Escrever o teste que falha**

```php
#[Test]
public function grupoDoAcordoCarregaAsObrigacoesQueEleSubstituiu(): void
{
    // Acordo vigente que substituiu Janeiro e Fevereiro e gerou 3 parcelas.
    $caso = $this->casoComAcordoVigente(
        substituidas: ['Janeiro/2026', 'Fevereiro/2026'],
        parcelas: ['Parcela 1 de 3', 'Parcela 2 de 3', 'Parcela 3 de 3'],
    );

    $detalhe = $this->useCase->executar($caso);

    self::assertCount(1, $detalhe->gruposAcordo);
    $grupo = $detalhe->gruposAcordo[0];

    self::assertCount(3, $grupo->parcelas);
    self::assertCount(2, $grupo->substituidas);
    self::assertSame(
        ['Janeiro/2026', 'Fevereiro/2026'],
        array_map(static fn ($o) => $o->descricao, $grupo->substituidas),
    );

    // E não podem ter vazado para a lista solta.
    self::assertSame([], array_map(
        static fn ($o) => $o->descricao,
        array_filter($detalhe->obrigacoesAvulsas, static fn ($o) => $o->substituidaPorAcordo),
    ));
}

#[Test]
public function valorTotalDoGrupoContinuaSomandoSoAsParcelasVivas(): void
{
    // Blindagem: `valorTotal` é o que bate com o saldo derivado. Expor as substituídas NÃO pode
    // inflá-lo — elas estão FORA do saldo (spec §4.6, armadilha de aritmética).
    $caso = $this->casoComAcordoVigente(
        substituidas: ['Janeiro/2026'],       // 120000
        parcelas: ['Parcela 1 de 2', 'Parcela 2 de 2'],   // 60000 cada
    );

    $grupo = $this->useCase->executar($caso)->gruposAcordo[0];

    self::assertSame(120000, $grupo->valorTotal);   // 2 × 60000, e NÃO 240000
}
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter MontarDetalheCasoUseCaseTest'
```
Esperado: FAIL — `$grupo->substituidas` não existe.

- [ ] **Step 3: `acordoSubstitutoId` no `ObrigacaoOutput`**

No construtor, junto do `acordoOrigemId`:
```php
        /** Acordo que substituiu esta obrigação (null = não substituída) — agrupa as trocadas (Ajuste 10). */
        public readonly ?int $acordoSubstitutoId = null,
```
E no `fromEntity`, no `new self(...)`:
```php
            acordoSubstitutoId: $substituto?->getId(),
```
> Repare que `$substituto` **já está** na variável local no topo do `fromEntity` — não busque de novo.
> Atenção: `substituidaPorAcordo` só é `true` se o acordo é **vigente**, mas `acordoSubstitutoId` é o id
> cru (mesmo de acordo rompido). O agrupamento do Step 5 usa os dois juntos — é de propósito.

- [ ] **Step 4: `substituidas` no `GrupoAcordoObrigacoesOutput`**

```php
    /**
     * @param list<ObrigacaoOutput> $parcelas
     * @param list<ObrigacaoOutput> $substituidas
     */
    public function __construct(
        public readonly int $acordoId,
        public readonly \DateTimeImmutable $dataAcordo,
        public readonly string $statusLabel,
        public readonly string $statusBadgeClass,
        public readonly int $qtdParcelas,
        public readonly int $qtdSubstituidas,
        public readonly int $valorTotal,
        public readonly array $parcelas,
        /**
         * As obrigações que ESTE acordo tirou do saldo (Ajuste 10). Ficam recolhidas na tela: voltam ao
         * exigível por derivação se o acordo for rompido/cancelado. NÃO entram em `valorTotal` — estão
         * fora do saldo, e somá-las divergiria do saldo derivado.
         */
        public readonly array $substituidas = [],
    ) {
    }
```

- [ ] **Step 5: Coletar em vez de descartar, no `agruparPorAcordo`**

Troque o bloco `(1)` (o `continue` de `:136-138`) por uma coleta, e monte os grupos com ela:

```php
        $parcelasPorAcordo = [];
        $substituidasPorAcordo = [];
        $avulsas = [];

        foreach ($obrigacoes as $obrigacao) {
            // (1) Substituída por acordo vigente sai da lista solta e vira anexo do acordo que a
            //     substituiu (Ajuste 10) — antes era descartada e a dívida "sumia" sem explicação.
            if ($obrigacao->substituidaPorAcordo) {
                if ($obrigacao->acordoSubstitutoId !== null) {
                    $substituidasPorAcordo[$obrigacao->acordoSubstitutoId][] = $obrigacao;
                }

                continue;
            }

            // (2) Parcela viva de acordo vigente → grupo daquele acordo.
            if ($obrigacao->acordoOrigemId !== null && isset($vigentes[$obrigacao->acordoOrigemId])) {
                $parcelasPorAcordo[$obrigacao->acordoOrigemId][] = $obrigacao;

                continue;
            }

            // (3) O resto segue na lista solta.
            $avulsas[] = $obrigacao;
        }
```

E no `new GrupoAcordoObrigacoesOutput(...)`, acrescente **depois** de `parcelas`:
```php
                parcelas: $parcelas,
                substituidas: $substituidasPorAcordo[$acordoId] ?? [],
            );
```

> **Não toque no cálculo de `$valorTotal`** (`:158-161`): ele soma só as parcelas vivas e é o que bate com o
> saldo derivado. O teste do Step 1 blinda isso.

- [ ] **Step 6: Rodar e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
```
Esperado: OK, ≥ 545 testes.

- [ ] **Step 7: Commit**

```bash
git add app/src/Cobranca/DTO/ObrigacaoOutput.php app/src/Cobranca/DTO/GrupoAcordoObrigacoesOutput.php app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php app/tests/Cobranca/Unit/MontarDetalheCasoUseCaseTest.php
git commit -m "Expor no grupo do acordo as obrigacoes que ele substituiu"
```

---

## Task 3: `brutoParaRecuperar` — o inverso do rateio de honorários

**O porquê (leia ou vai errar):** na forma `acrescido_divida`, `ratearPagamento` rateia o **bruto** digitado.
Se o botão "Receber" pré-preencher R$ 1.200,00 numa obrigação de R$ 1.200,00 com 10% de honorários, o rateio
dá **dívida R$ 1.090,91 + honorários R$ 109,09** e **a obrigação não quita** — sobram R$ 109,09 e o usuário
não entende por quê. O prefill tem que ser o **bruto** cuja parte-dívida é exatamente o alvo.

**A fórmula já foi verificada:** `T = arredondarFracao(D · (10000 + pb), 10000)` fecha o round-trip em
**13.000.000 de casos** (D de 1 a 200.000 centavos × 65 percentuais), **zero falhas** (verificação de
2026-07-16). O teste do Step 1 é a rede que mantém isso verdadeiro.

**Files:**
- Modify: `app/src/Cobranca/Service/CalculadoraHonorarios.php`
- Test: `app/tests/Cobranca/Unit/CalculadoraHonorariosTest.php` (existe — adicionar)

**Interfaces:**
- Produces: `CalculadoraHonorarios::brutoParaRecuperar(CasoCobranca $caso, int $dividaAlvoCentavos): int`

- [ ] **Step 1: Escrever os testes que falham (round-trip é o principal)**

```php
#[Test]
public function brutoParaRecuperarFechaORoundTripDoRateio(): void
{
    // A propriedade que importa: o bruto sugerido rateia de volta para EXATAMENTE o alvo.
    // Dupla-arredondamento não se valida por inspeção — só por varredura.
    $caso = $this->casoCom(FormaHonorarios::AcrescidoDivida, '10.00');
    $calc = new CalculadoraHonorarios();

    foreach ([1, 2, 99, 100, 101, 105, 333, 80000, 120000, 199999] as $alvo) {
        $bruto = $calc->brutoParaRecuperar($caso, $alvo);
        [$divida, ] = $calc->ratearPagamento($caso, $bruto);

        self::assertSame($alvo, $divida, "round-trip falhou para alvo={$alvo}");
    }
}

#[Test]
public function brutoParaRecuperarAcrescentaOsHonorariosAoAlvo(): void
{
    $caso = $this->casoCom(FormaHonorarios::AcrescidoDivida, '10.00');

    // R$1.200,00 de dívida + 10% => R$1.320,00 de boleto.
    self::assertSame(132000, (new CalculadoraHonorarios())->brutoParaRecuperar($caso, 120000));
}

#[Test]
public function brutoParaRecuperarDevolveOAlvoQuandoAFormaNaoAcresce(): void
{
    // Nas outras formas o devedor paga só a dívida — espelha `ratearPagamento`.
    foreach ([FormaHonorarios::RetidoRecuperado, FormaHonorarios::CobradoSeparado, FormaHonorarios::SemPercentual] as $forma) {
        $caso = $this->casoCom($forma, '10.00');

        self::assertSame(120000, (new CalculadoraHonorarios())->brutoParaRecuperar($caso, 120000));
    }
}

#[Test]
public function brutoParaRecuperarDevolveOAlvoQuandoNaoHaPercentual(): void
{
    $caso = $this->casoCom(FormaHonorarios::AcrescidoDivida, null);

    self::assertSame(120000, (new CalculadoraHonorarios())->brutoParaRecuperar($caso, 120000));
}

#[Test]
public function brutoParaRecuperarDevolveOAlvoQuandoEleNaoEPositivo(): void
{
    $caso = $this->casoCom(FormaHonorarios::AcrescidoDivida, '10.00');
    $calc = new CalculadoraHonorarios();

    self::assertSame(0, $calc->brutoParaRecuperar($caso, 0));
    self::assertSame(-5, $calc->brutoParaRecuperar($caso, -5));
}
```

> Reuse o helper de montar caso que já existe no arquivo (`casoCom`/similar) — **leia o teste antes**.

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter CalculadoraHonorariosTest'
```
Esperado: FAIL — `brutoParaRecuperar()` não existe.

- [ ] **Step 3: Implementar**

Coloque **logo depois** de `ratearPagamento` (são inversos; ficam juntos):

```php
    /**
     * INVERSO de `ratearPagamento` (Ajuste 10, spec §5.1): dado o quanto se quer recuperar de DÍVIDA
     * (centavos), devolve o valor BRUTO a cobrar do devedor — dívida + honorários — na forma
     * `acrescido_divida`: `T = D · (10000+pb)/10000`. Nas demais formas (e sem percentual) o devedor paga
     * só a dívida, então devolve o próprio alvo — espelhando `ratearPagamento`.
     *
     * Existe porque o alvo é INVISÍVEL para o gestor: ele quer quitar uma obrigação de R$1.200 e precisa
     * digitar R$1.320. Pré-preencher R$1.200 rateia para R$1.090,91 e a obrigação NÃO quita.
     *
     * Garantia (coberta por teste): `ratearPagamento($caso, brutoParaRecuperar($caso, $d))[0] === $d`.
     */
    public function brutoParaRecuperar(CasoCobranca $caso, int $dividaAlvoCentavos): int
    {
        if ($dividaAlvoCentavos <= 0 || $caso->getFormaHonorarios() !== FormaHonorarios::AcrescidoDivida) {
            return $dividaAlvoCentavos;
        }

        $pb = $this->basisPoints($caso);

        if ($pb === 0) {
            return $dividaAlvoCentavos;
        }

        return $this->arredondarFracao($dividaAlvoCentavos * (10000 + $pb), 10000);
    }
```

- [ ] **Step 4: Rodar e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
```
Esperado: OK, ≥ 550 testes.

> **Se o round-trip falhar em algum alvo, PARE.** Não "conserte" com `+1`: significa que a premissa da spec
> §5.1 caiu e a decisão volta ao humano.

- [ ] **Step 5: Commit**

```bash
git add app/src/Cobranca/Service/CalculadoraHonorarios.php app/tests/Cobranca/Unit/CalculadoraHonorariosTest.php
git commit -m "Adicionar brutoParaRecuperar: inverso do rateio de honorarios"
```

---

## Task 4: reescrever o template — 3 abas, card da pessoa, Dívida unificada

**Referência literal:** abra `docs/gestao-cobrancas/mockup-ajuste10-objeto-show.html` no navegador. Ele é o
alvo visual **aprovado**, em Bootstrap 5.3 real com os tokens do módulo, e já está validado nos dois temas.
Traduza-o para Twig — não reinvente o layout.

**Files:**
- Modify: `app/templates/cobranca/objeto/show.html.twig` (941 → ~300 linhas)
- Create: `app/templates/cobranca/objeto/_partials/_pessoa_card.html.twig`
- Create: `app/templates/cobranca/objeto/_partials/_divida.html.twig`
- Create: `app/templates/cobranca/objeto/_partials/_movimentos.html.twig`
- Modify: `app/public/css/cobrancas.css` (acrescentar os `.jp-*` do mockup)
- Modify: `app/src/Cobranca/Twig/CobrancaExtension.php` (filtro `tempo_relativo` — **não existe hoje**)
- Test: `app/tests/Cobranca/Functional/ObjetoShowControllerTest.php` (**existe** — este é o nome real)
- Test: `app/tests/Cobranca/Unit/CobrancaExtensionTest.php` (**NÃO existe — criar**)

**Interfaces:**
- Consumes: `ObrigacaoOutput::$alocado/restante()/quitada()` (T1), `GrupoAcordoObrigacoesOutput::$substituidas` (T2)

**Regras não-negociáveis desta tarefa:**
1. **Abas: exatamente 3** — Cobrança · Documentos · Histórico. Documentos e Histórico ficam **byte-idênticos**
   ao que são hoje: só mudam de posição. **Não refatore o file manager nesta tarefa.**
2. O container das abas continua `id="objetoTabs"` (T7 depende disso).
3. **Copiar o CSS do mockup para `cobrancas.css`** — não deixar `<style>` inline no template.
4. Nenhuma cor hardcoded; status como fundo = `rgba(var(--...-rgb), α)`.
5. `|centavos` em todo dinheiro; `tabular-nums` nas colunas de valor.
6. Ordenação (spec §4.7): Dívida **da mais antiga para a mais nova**; Movimentos **do mais recente para o mais
   antigo**; cada seção **declara a direção** no chip do cabeçalho. Data é **coluna própria**.
7. Gates de permissão preservados **exatamente** como hoje: `has_permission('resources.cobranca.gerenciar')`,
   `has_permission('resources.cobranca.movimentacao_financeira')`, `can_access_module('pastas')`, `caso.encerrado`.
   **Receber exige `movimentacao_financeira`; Acordar exige `gerenciar`. Não unificar.**

- [ ] **Step 1: Teste functional que falha (a estrutura nova)**

```php
#[Test]
public function paginaDoObjetoTemAsTresAbasEOCardDaPessoa(): void
{
    $this->clienteLogadoCom('resources.cobranca.gerenciar');
    $objeto = $this->objetoComCobranca();

    $crawler = $this->client->request('GET', "/cobrancas/objetos/{$objeto->getId()}");

    self::assertResponseIsSuccessful();
    self::assertCount(3, $crawler->filter('#objetoTabs .nav-link'), 'devem ser exatamente 3 abas');
    self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-cobranca"]');
    self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-documentos"]');
    self::assertSelectorExists('#objetoTabs [data-bs-target="#tab-historico"]');
    // Pessoa deixou de ser aba e virou card.
    self::assertSelectorNotExists('#objetoTabs [data-bs-target="#tab-pessoas"]');
    self::assertSelectorExists('.jp-pessoa-card');
    // A subnav do módulo voltou (B3).
    self::assertSelectorExists('.cobranca-subnav');
}

#[Test]
public function aDividaMostraOQuantoFaltaQuandoHaPagamentoParcial(): void
{
    $this->clienteLogadoCom('resources.cobranca.gerenciar', 'resources.cobranca.movimentacao_financeira');
    $objeto = $this->objetoComObrigacaoParcialmentePaga(valor: 120000, pago: 40000);

    $crawler = $this->client->request('GET', "/cobrancas/objetos/{$objeto->getId()}");

    self::assertStringContainsString('800,00', $crawler->filter('.jp-obr-restante')->text());
}
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ObjetoShowControllerTest'
```
Esperado: FAIL — hoje são 6 abas e não existe `.jp-pessoa-card`.

- [ ] **Step 3: Criar o filtro `tempo_relativo`**

**Ele não existe** — o projeto só tem `formatar_bytes` (`src/Twig/ArquivoIconeExtension.php:29`) e `centavos`
(`src/Cobranca/Twig/CobrancaExtension.php:21`). O tempo relativo é a metade do eixo temporal da spec §4.7
("há 128 dias" / "em 25 dias"), então ganha filtro próprio, ao lado do `centavos`.

Teste primeiro, em `app/tests/Cobranca/Unit/CobrancaExtensionTest.php` (**arquivo novo** — a extensão do
módulo não tem teste hoje; siga o padrão de attributes PHPUnit de `app/tests/CLAUDE.md`):

```php
#[Test]
#[DataProvider('casosDeTempoRelativo')]
public function tempoRelativoDescreveADistanciaAteHoje(string $data, string $esperado): void
{
    $hoje = new \DateTimeImmutable('2026-07-16');

    self::assertSame($esperado, (new CobrancaExtension())->tempoRelativo(new \DateTimeImmutable($data), $hoje));
}

public static function casosDeTempoRelativo(): iterable
{
    yield 'vencida ha muito'   => ['2026-03-10', 'há 128 dias'];
    yield 'vencida ontem'      => ['2026-07-15', 'há 1 dia'];      // singular
    yield 'vence hoje'         => ['2026-07-16', 'hoje'];
    yield 'vence amanha'       => ['2026-07-17', 'em 1 dia'];      // singular
    yield 'vence longe'        => ['2026-08-10', 'em 25 dias'];
}
```

Implementação em `src/Cobranca/Twig/CobrancaExtension.php`:

```php
            new TwigFilter('centavos', $this->centavos(...)),
            new TwigFilter('tempo_relativo', $this->tempoRelativo(...)),
```

```php
    /**
     * Distância em dias até hoje, em português ("há 128 dias" / "em 25 dias" / "hoje") — o eixo temporal das
     * listas do objeto (Ajuste 10, spec §4.7). `$hoje` é injetável só para teste.
     */
    public function tempoRelativo(\DateTimeInterface $data, ?\DateTimeInterface $hoje = null): string
    {
        $hoje ??= new \DateTimeImmutable('today');
        $dias = (int) $hoje->diff($data)->format('%r%a');

        if ($dias === 0) {
            return 'hoje';
        }

        if ($dias < 0) {
            $passados = abs($dias);

            return sprintf('há %d %s', $passados, $passados === 1 ? 'dia' : 'dias');
        }

        return sprintf('em %d %s', $dias, $dias === 1 ? 'dia' : 'dias');
    }
```

> **Cuidado:** compare **datas**, não instantes. `$hoje` tem de ser `'today'` (meia-noite), senão "vence hoje"
> vira "há 0 dias" ou "em 1 dia" dependendo da hora. O teste com `$hoje` fixo blinda isso.

Rode: `docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter CobrancaExtensionTest'` → PASS.

- [ ] **Step 4: CSS — copiar os componentes do mockup**

Do mockup, copie para o fim de `app/public/css/cobrancas.css` o bloco marcado
`/* ═══ NOVO — componentes do redesenho ═══ */`: `.jp-money`, `.jp-resumo*`, `.jp-pessoa-*`, `.jp-secao*`,
`.jp-lista*`, `.jp-obr*`, `.jp-acordo*`, `.jp-substituidas-toggle`, `.jp-selbar*`, `.jp-alerta*`, `.jp-mov*`,
`.jp-vazio*`, `.jp-chip*`, `.jp-ordem`. **Não** copie `.jp-demo-bar` (é andaime do mockup).

- [ ] **Step 5: Criar `_pessoa_card.html.twig`**

Do mockup, o bloco `<!-- ══ CARD DA PESSOA (era uma aba) ══ -->`. Ele recebe `objeto` e `caso` por herança de
contexto. Regras:
- estado vazio: se `not objeto.temCobradaAtual`, mostrar o vazio **sem** mandar o usuário para a aba Pessoas
  (ela não existe mais) — o botão de vincular fica no próprio card;
- os botões *Vincular pessoa* / *Nova pessoa* e o *Trocar* mantêm os mesmos modais de hoje
  (`#modalVincularPessoaObjeto`, `#modalNovaPessoa`, `#modalAlterarPessoa`);
- vínculos encerrados aparecem em `.jp-vinculo-linha.encerrado`, com o motivo.

- [ ] **Step 6: Criar `_divida.html.twig`**

Do mockup, o bloco `<!-- DÍVIDA -->`. A linha de obrigação, em Twig (o padrão a repetir):

```twig
<div class="jp-obr {{ o.restante > 0 and o.vencimentoOriginal < date() ? 'is-vencida' }} {{ o.quitada ? 'is-quitada' }}"
     data-valor-centavos="{{ o.restante }}">
    {% if podeAcordar %}
        <input class="form-check-input mt-0 jp-check" type="checkbox"
               value="{{ o.id }}" data-valor-centavos="{{ o.restante }}"
               aria-label="Selecionar {{ o.descricao }}">
    {% else %}<span></span>{% endif %}

    <div class="jp-obr-data">
        <div class="jp-obr-data-dia">{{ o.vencimentoOriginal|date('d/m/Y') }}</div>
        <div class="jp-obr-data-rel {{ o.vencimentoOriginal < date() ? 'is-atrasado' }}">{{ o.vencimentoOriginal|tempo_relativo }}</div>
    </div>

    <div class="jp-obr-desc">
        {{ o.descricao }}
        {% if o.alocado > 0 and not o.quitada %}
            <span class="jp-obr-sub">{{ o.alocado|centavos }} já recebidos</span>
        {% endif %}
    </div>

    <div class="jp-obr-valor jp-money">
        {{ o.valorAtual|centavos }}
        {% if o.alocado > 0 and not o.quitada %}
            <span class="jp-obr-restante">falta {{ o.restante|centavos }}</span>
        {% endif %}
    </div>

    <div class="jp-obr-acoes">…</div>
</div>
```

**Duas decisões desta tarefa, explícitas:**
- **`podeAcordar`** = `has_permission('resources.cobranca.gerenciar') and not caso.encerrado and
  o.acordoOrigemId is null and not o.substituidaPorAcordo`. **INV-U1: parcela NUNCA tem checkbox nem
  "Acordar"** — `CriarAcordoUseCase:108` barra (acordo sobre acordo duplica dívida no saldo).
- **`tempo_relativo`** é o filtro criado no Step 3 desta tarefa (não existia).

O grupo de acordo e o `collapse` das substituídas saem do mockup (`<!-- Acordo vigente -->` e
`#substituidas`), com `grupo.substituidas` (T2).

- [ ] **Step 7: Criar `_movimentos.html.twig`**

Do mockup: `<!-- MOVIMENTOS -->` + `<!-- ACORDOS ENCERRADOS -->`. **Não** implemente ainda o "abateu
Janeiro e Fevereiro" — isso é a Task 10 (opcional) e depende de dado que o `PagamentoOutput` não tem.
Renderize sem essa linha.

- [ ] **Step 8: Reescrever `show.html.twig`**

Estrutura final: subnav → content-header (com o `⋯` do mockup) → cockpit → alertas acionáveis →
`include _pessoa_card` → 3 abas (Cobrança = `include _divida` + `include _movimentos`; Documentos =
o `include _documentos.html.twig` **de hoje**; Histórico = a timeline **de hoje**) → os
`_acoes_modais*.html.twig` **de hoje** → JS.

**Preserve intacto:** os ~390 linhas de JS (`initGeradorAcordo`, `initPreviaPagamento`, hidratação dos modais
reutilizáveis) — esta tarefa **move**, não reescreve. Regra do projeto: *nunca mover e reescrever junto.*

- [ ] **Step 9: Rodar os testes**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca && php bin/phpunit tests/Twig'
```
Esperado: OK.

- [ ] **Step 10: SMOKE REAL — obrigatório, MOSTRAR ao humano**

Suba a página no dev e confira **nos dois temas**: as 3 abas; o card; a Dívida com data em coluna e o chip de
ordenação; o grupo do acordo; o `collapse` das substituídas; Documentos e Histórico **funcionando como antes**
(especialmente o upload e o arrastar-soltar).
> **Gotcha:** o modal `#modalAlertaPonto` intercepta cliques no Playwright — remova via `browser_evaluate`.

**Pare aqui e mostre ao humano antes de commitar.**

- [ ] **Step 11: Commit**

```bash
git add app/templates/cobranca/objeto/ app/public/css/cobrancas.css app/src/Cobranca/Twig/CobrancaExtension.php app/tests/Cobranca
git commit -m "Redesenhar pagina do objeto: 3 abas, card da pessoa, divida unica"
```

---

## Task 5: "Receber" e "Acordar" na própria obrigação

**Files:**
- Modify: `app/templates/cobranca/objeto/_partials/_divida.html.twig`
- Modify: `app/templates/cobranca/objeto/show.html.twig` (JS)
- Modify: `app/src/Cobranca/UseCase/MontarDetalheCasoUseCase.php` (expõe o bruto sugerido)
- Modify: `app/src/Cobranca/DTO/ObrigacaoOutput.php` (`brutoSugerido`)
- Test: `app/tests/Cobranca/Functional/ObjetoShowControllerTest.php`

**Interfaces:**
- Consumes: `CalculadoraHonorarios::brutoParaRecuperar` (T3), `ObrigacaoOutput::restante()` (T1)
- Produces: `ObrigacaoOutput::$brutoSugerido: int`

> **Decisão de desenho:** o bruto é calculado no **servidor** (o UseCase já tem o caso e o snapshot de
> honorários) e vai para o `data-bruto-centavos` da linha. **Não** replique a fórmula em JS — duas
> implementações de dinheiro divergem, e a regra do módulo é fonte única de centavos no servidor.

- [ ] **Step 1: Teste que falha**

```php
#[Test]
public function receberPrePreencheOBrutoComHonorariosAcrescidos(): void
{
    $this->clienteLogadoCom('resources.cobranca.gerenciar', 'resources.cobranca.movimentacao_financeira');
    // Honorários acrescidos 10%; obrigação de R$1.200 sem pagamento.
    $objeto = $this->objetoComHonorariosAcrescidos(percentual: '10.00', valorObrigacao: 120000);

    $crawler = $this->client->request('GET', "/cobrancas/objetos/{$objeto->getId()}");

    // R$1.320,00: o que rateia de volta para R$1.200,00 de dívida.
    self::assertSame('132000', $crawler->filter('.jp-obr[data-bruto-centavos]')->attr('data-bruto-centavos'));
}

#[Test]
public function parcelaDeAcordoVigenteNaoOfereceAcordar(): void
{
    // INV-U1 / INV-I: acordo sobre acordo duplica dívida no saldo ao romper.
    $this->clienteLogadoCom('resources.cobranca.gerenciar');
    $objeto = $this->objetoComAcordoVigente();

    $crawler = $this->client->request('GET', "/cobrancas/objetos/{$objeto->getId()}");

    self::assertCount(0, $crawler->filter('.jp-acordo .jp-check'), 'parcela não pode ter checkbox');
    self::assertCount(0, $crawler->filter('.jp-acordo [data-acao="acordar"]'), 'parcela não pode ter Acordar');
}

#[Test]
public function semPermissaoFinanceiraOReceberSome(): void
{
    $this->clienteLogadoCom('resources.cobranca.gerenciar');   // só gerenciar
    $objeto = $this->objetoComCobranca();

    $crawler = $this->client->request('GET', "/cobrancas/objetos/{$objeto->getId()}");

    self::assertCount(0, $crawler->filter('[data-acao="receber"]'));
    self::assertGreaterThan(0, $crawler->filter('[data-acao="acordar"]')->count());
}
```

- [ ] **Step 2: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter ObjetoShowControllerTest'
```

- [ ] **Step 3: `brutoSugerido` no DTO**

No construtor do `ObrigacaoOutput`, **depois de `alocado`**:
```php
        /**
         * Valor BRUTO a cobrar para quitar o `restante` desta obrigação (centavos) — já com os honorários
         * acrescidos quando a forma é `acrescido_divida` (Ajuste 10, spec §5.1). É o prefill do "Receber":
         * o alvo é invisível ao gestor (quitar R$1.200 exige digitar R$1.320). Calculado no SERVIDOR, pelo
         * UseCase, que é quem conhece o snapshot de honorários do caso — o DTO continua burro.
         */
        public readonly int $brutoSugerido = 0,
```

E `fromEntity` ganha o 3º parâmetro, repassando:
```php
    public static function fromEntity(Obrigacao $o, int $alocado = 0, int $brutoSugerido = 0): self
```
```php
            alocado: $alocado,
            brutoSugerido: $brutoSugerido,
        );
```

> Os defaults mantêm verdes tanto os 5 testes originais quanto os 4 da T1.

- [ ] **Step 4: Calcular no `MontarDetalheCasoUseCase`**

Injete `CalculadoraHonorarios` como **10ª** dependência e troque o closure do `array_map` da T1 (Step 7) por:

```php
        $obrigacoes = array_map(
            function (Obrigacao $o) use ($caso, $alocadoPorObrigacao): ObrigacaoOutput {
                $alocado = $alocadoPorObrigacao[$o->getId()] ?? 0;
                $restante = max(0, $o->valorExigivel() - $alocado);

                return ObrigacaoOutput::fromEntity(
                    $o,
                    $alocado,
                    // O prefill do "Receber": bruto cuja parte-dívida é exatamente o restante (spec §5.1).
                    $this->calculadoraHonorarios->brutoParaRecuperar($caso, $restante),
                );
            },
            $this->obrigacaoRepository->doCaso($caso),
        );
```

Acrescente o `use App\Cobranca\Entity\Obrigacao;` no topo se ainda não houver.

> **Por que o `restante` é recalculado aqui** em vez de ler `$saida->restante()`: o DTO ainda não existe neste
> ponto. A fórmula é a mesma (`max(0, valorExigivel − alocado)`) e `valorExigivel()` é a fonte única
> (`Obrigacao.php:90`) — não duplique a regra em outro lugar.

- [ ] **Step 5: UI — botões, barra de seleção e prefill**

No `_divida.html.twig`, os botões (marque com `data-acao` para os testes):
```twig
{% if podeMovimentar and not o.quitada %}
    <button type="button" class="btn btn-sm btn-outline-success" data-acao="receber"
            data-obrigacao-id="{{ o.id }}" data-bruto-centavos="{{ o.brutoSugerido }}"
            data-bs-toggle="modal" data-bs-target="#modalRegistrarPagamento">
        <i class="bi bi-cash me-1"></i>Receber
    </button>
{% endif %}
{% if podeAcordar %}
    <button type="button" class="btn btn-sm btn-outline-primary" data-acao="acordar" data-obrigacao-id="{{ o.id }}">
        <i class="bi bi-handshake me-1"></i>Acordar
    </button>
{% endif %}
```

JS novo em `show.html.twig` (a barra de seleção sai do mockup; o resto é hidratação):
- **Receber** → abre `#modalRegistrarPagamento` com: `alocarManualmente` marcado, **uma** linha de alocação
  para a obrigação clicada com valor = `restante`, e o campo de valor pago = `data-bruto-centavos`
  (formatado pt-BR com o `fmtReais` que já existe). A prévia ao vivo (`initPreviaPagamento`) recalcula sozinha.
- **Acordar** (linha) → marca só aquele checkbox e abre `#modalCriarAcordo`.
- **Barra de seleção** → soma `data-valor-centavos` (= `restante`), e "Fazer acordo com estas" marca as
  correspondentes no modal e abre.
- Se não houver nenhuma acordável, o botão da seção nasce `disabled` com tooltip (spec §5.2).

> **Cuidado (bug real, já pago caro no ajuste 7):** `parseCentavos` do projeto é pt-BR — **ponto é milhar**.
> Ao escrever valores em input, use vírgula decimal (`fmtReais`), senão 1320.00 vira R$ 132.000,00.

- [ ] **Step 6: Rodar os testes**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
```

- [ ] **Step 7: SMOKE REAL — MOSTRAR ao humano**

Clique "Receber" numa obrigação com honorários acrescidos e **confira que a prévia mostra a dívida batendo
exatamente com o restante da obrigação**. Marque 2 obrigações e confira a barra e o total. **Confirme que
parcela de acordo não tem checkbox.**

**Pare e mostre antes de commitar.**

- [ ] **Step 8: Commit**

```bash
git add app/src/Cobranca app/templates/cobranca app/tests/Cobranca
git commit -m "Adicionar Receber e Acordar na propria obrigacao"
```

---

## Task 6: acordo sobre obrigação parcialmente paga (bug de dinheiro)

**LEIA A SPEC §5.3 ANTES.** Bug **confirmado em produção** por revisão adversarial, com prova em SQL no dev
(caso 295): acordar uma obrigação parcialmente paga faz o pagamento **evaporar do saldo**, porque a original
sai do exigível levando a alocação junto — e o form **sugere o valor cheio**.

> ⚠️ **NÃO conserte mexendo em `CalculadoraSaldo`.** A regra de saldo está certa e alimenta o batch do
> Dashboard. **Decisão do humano (D7): não bloquear** — renegociar o resto é fluxo legítimo. O conserto é de
> **sugestão e informação**.

**Files:**
- Modify: `app/src/Cobranca/Form/AcordoCriarType.php:86-115`
- Modify: `app/src/Cobranca/Service/MontadorModaisCaso.php:55-64`
- Modify: `app/templates/cobranca/caso/_acoes_modais.html.twig` (aviso no `#modalCriarAcordo`)
- Test: `app/tests/Cobranca/Unit/AcordoCriarTypeTest.php` (**NÃO existe — criar**)
- Test: `app/tests/Cobranca/Functional/AcordoSobreObrigacaoPagaTest.php` (**criar**) — o teste que documenta o
  saldo. **Não existe pasta `Integration/`**: a suíte só tem `Unit/` e `Functional/`. Um teste que exercita
  saldo real + acordo real precisa de banco → vai em `Functional/` (DAMA faz rollback por teste).

**Interfaces:**
- Consumes: `AlocacaoPagamentoRepository::somasPorObrigacaoDosCasos` (T1)
- Produces: `AcordoCriarType::opcoesObrigacoes(array $obrigacoes, array $alocadoPorObrigacao = []): array<string,int>`;
  `AcordoCriarType::valoresObrigacoes(array $obrigacoes, array $alocadoPorObrigacao = []): array<int,int>`

- [ ] **Step 1: Teste que DOCUMENTA o comportamento do domínio (não muda com D7)**

```php
#[Test]
public function acordoSobreObrigacaoPagaTrocaOSaldoPeloTotalNegociado(): void
{
    // Documenta o comportamento REAL do domínio (spec §5.3): a substituída sai do exigível levando a
    // alocação junto, e o saldo passa a ser o total negociado. NÃO bloqueamos (D7) — renegociar o
    // remanescente é legítimo. Este teste existe para que a mudança seja CONSCIENTE se alguém mexer.
    $caso = $this->casoComObrigacao(valor: 120000);
    $this->registrarPagamento($caso, 40000);

    self::assertSame(80000, $this->calculadoraSaldo->saldoExigivel($caso), 'saldo antes do acordo');

    // O gestor renegocia o REMANESCENTE (o que a UI passa a sugerir depois deste ajuste).
    $this->criarAcordo($caso, substituindo: [$this->obrigacao], totalNegociado: 80000, parcelas: 2);

    self::assertSame(80000, $this->calculadoraSaldo->saldoExigivel($caso), 'o remanescente é preservado');
}
```

- [ ] **Step 2: Teste que prova o conserto (o valor sugerido)**

```php
#[Test]
public function valoresObrigacoesSugereORemanescenteENaoOValorCheio(): void
{
    // O bug: sugeria 120000 numa obrigação com 40000 já pagos, fazendo o gestor renegociar
    // R$400 que o devedor JÁ PAGOU (spec §5.3).
    $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 120000, encargos: 0);

    $valores = AcordoCriarType::valoresObrigacoes([$obrigacao], [7 => 40000]);

    self::assertSame([7 => 80000], $valores);
}

#[Test]
public function valoresObrigacoesSemAlocacaoSegueNoValorExigivel(): void
{
    $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 120000, encargos: 20000);

    self::assertSame([7 => 140000], AcordoCriarType::valoresObrigacoes([$obrigacao], []));
}

#[Test]
public function opcoesObrigacoesSinalizaOQueJaFoiRecebido(): void
{
    $obrigacao = $this->obrigacaoCom(id: 7, valorOriginal: 120000, encargos: 0, descricao: 'Abril/2026');

    $opcoes = AcordoCriarType::opcoesObrigacoes([$obrigacao], [7 => 40000]);

    $label = array_key_first($opcoes);
    self::assertStringContainsString('800,00', $label, 'mostra o remanescente');
    self::assertStringContainsString('400,00 já recebidos', $label, 'avisa que houve pagamento');
}
```

- [ ] **Step 3: Rodar e ver falhar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit --filter "AcordoCriarTypeTest"'
```

- [ ] **Step 4: Implementar no `AcordoCriarType`**

```php
    /**
     * @param list<Obrigacao>  $obrigacoes
     * @param array<int,int>   $alocadoPorObrigacao Mapa `obrigacaoId => Σ alocado` (centavos).
     *
     * @return array<string, int>
     */
    public static function opcoesObrigacoes(array $obrigacoes, array $alocadoPorObrigacao = []): array
    {
        $opcoes = [];
        foreach ($obrigacoes as $o) {
            $alocado = $alocadoPorObrigacao[(int) $o->getId()] ?? 0;
            $remanescente = max(0, $o->valorExigivel() - $alocado);
            $valor = number_format($remanescente / 100, 2, ',', '.');

            $label = sprintf(
                '%s — venc %s — R$ %s',
                $o->getDescricao(),
                $o->getVencimentoOriginal()->format('d/m/Y'),
                $valor,
            );

            // Ajuste 10 (spec §5.3): o gestor não pode renegociar às cegas um valor que já foi pago.
            if ($alocado > 0) {
                $label .= sprintf(' (R$ %s já recebidos)', number_format($alocado / 100, 2, ',', '.'));
            }

            $opcoes[$label] = $o->getId();
        }

        return $opcoes;
    }

    /**
     * Mapa `id → REMANESCENTE (centavos)` das substituíveis, para o gerador somar a seleção no JS (via
     * `data-valor-centavos`). Ajuste 10 (spec §5.3): era o valor exigível CHEIO, que fazia o gestor
     * renegociar o que o devedor já pagou — o pagamento então evaporava do saldo, porque a substituída
     * sai do exigível levando a alocação junto (`CalculadoraSaldo:57`).
     *
     * @param list<Obrigacao> $obrigacoes
     * @param array<int,int>  $alocadoPorObrigacao
     *
     * @return array<int, int>
     */
    public static function valoresObrigacoes(array $obrigacoes, array $alocadoPorObrigacao = []): array
    {
        $valores = [];
        foreach ($obrigacoes as $o) {
            $id = (int) $o->getId();
            $valores[$id] = max(0, $o->valorExigivel() - ($alocadoPorObrigacao[$id] ?? 0));
        }

        return $valores;
    }
```

- [ ] **Step 5: Alimentar o mapa no `MontadorModaisCaso`**

Injete `AlocacaoPagamentoRepository` e, em `deMutacao`:

```php
        $substituiveis = $this->obrigacaoRepository->doCasoSubstituiveis($caso);

        // Ajuste 10 (spec §5.3): o gerador precisa do REMANESCENTE, não do valor cheio — senão sugere
        // renegociar o que já foi pago. Uma query em lote, mesmo mapa do detalhe do caso.
        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos(
            [$caso->getId()],
            $caso->getTenant(),
        );

        $opcoesObrigacoes = AcordoCriarType::opcoesObrigacoes($substituiveis, $alocadoPorObrigacao);
        $valoresObrigacoes = AcordoCriarType::valoresObrigacoes($substituiveis, $alocadoPorObrigacao);
```

- [ ] **Step 6: Aviso no modal**

Em `_acoes_modais.html.twig`, dentro do `#modalCriarAcordo`, um alerta que o JS mostra quando alguma
selecionada tem `alocado > 0`:

```twig
<div class="alert alert-warning d-none py-2 small" id="avisoAcordoComPagamento">
    <i class="bi bi-info-circle me-1"></i>
    Uma das dívidas escolhidas <strong>já recebeu pagamento</strong>. O valor sugerido é o que
    <strong>ainda falta</strong> — não o valor original.
</div>
```

- [ ] **Step 7: Rodar e ver passar**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca'
```

- [ ] **Step 8: SMOKE REAL — MOSTRAR ao humano**

Obrigação de R$ 1.200 com R$ 400 pagos → abrir "Novo acordo" → **a opção deve dizer R$ 800,00 e "(R$ 400,00
já recebidos)"**, o total deve nascer 800,00 e o aviso deve aparecer.

- [ ] **Step 9: Commit**

```bash
git add app/src/Cobranca app/templates/cobranca app/tests/Cobranca
git commit -m "Sugerir o remanescente ao acordar obrigacao ja paga"
```

---

## Task 7: as 4 correções pequenas (B1–B4)

**Files:**
- Modify: `app/src/Cobranca/DTO/RegistrarPagamentoInput.php:25` ou `MontadorModaisCaso.php:104` (B1)
- Modify: `app/public/js/pasta-arquivos.js:481` (B2)
- Modify: `app/templates/cobranca/objeto/show.html.twig` (B3 — feito na T4; **conferir**)
- Modify: controllers de mutação de Cobrança (B4)
- Test: `app/tests/Cobranca/Functional/`

- [ ] **Step 1: B1 — data do pagamento pré-preenchida**

Espelhe o que o contato já faz (`MontadorModaisCaso:66-67`). Em `financeiros()`:
```php
        $pagamentoHoje = new RegistrarPagamentoInput();
        $pagamentoHoje->data = new \DateTimeImmutable('today');
```
e passe ao `create(RegistrarPagamentoType::class, $pagamentoHoje)`.

Teste:
```php
#[Test]
public function modalDePagamentoAbreComADataDeHoje(): void
{
    $this->clienteLogadoCom('resources.cobranca.gerenciar', 'resources.cobranca.movimentacao_financeira');
    $objeto = $this->objetoComCobranca();

    $crawler = $this->client->request('GET', "/cobrancas/objetos/{$objeto->getId()}");

    self::assertSame(
        (new \DateTimeImmutable('today'))->format('Y-m-d'),
        $crawler->filter('#modalRegistrarPagamento input[type=date]')->attr('value'),
    );
}
```

- [ ] **Step 2: B2 — a aba grudada (BUG REAL, REPRODUZIDO)**

**Leia a spec §2.1 antes** — ela registra dois erros meus que já foram corrigidos e que, se você seguir a
versão antiga, te fazem quebrar o módulo Pastas.

**Como reproduzir (feito em 2026-07-16, funciona):**
```
sessionStorage.setItem('fmTab_295', '1')   // 295 = id do CASO do objeto 296
```
recarregue `/cobrancas/objetos/296` → abre em **Documentos**. Confirmado.

⚠️ **A chave é `fmTab_<casoId>`, NÃO `<objetoId>`** — `data-pasta-id="{{ casoId }}"`
(`cobranca/caso/_documentos.html.twig:6`) e `pastaId = fm.dataset.pastaId` (`pasta-arquivos.js:13`).
Objeto 296 → caso 295; objeto 297 → caso 296. **Usar a chave errada dá falso negativo** — foi o que aconteceu
comigo.

**A causa:** o `clear` (`:481`) procura `#pastaTabs`. Esse id **EXISTE** — em
`app/templates/pasta/show.html.twig:320`. O script é **compartilhado**: em Pastas o clear funciona; na página
do objeto o container é `#objetoTabs`, então ali o clear erra o alvo e a flag nunca sai.

🚫 **NÃO remova o seletor como "código morto"** — ele é o seletor **certo da página de Pastas**. Removê-lo
importa a grudação para um módulo que hoje funciona.

**O conserto tem de:**
1. servir às **duas** páginas (sugestão: subir do próprio botão com `.closest('.nav-tabs')` em vez de id fixo);
2. **preservar** a função legítima (`:487` — *"restaura aba/pasta após reloads de excluir/editar/upload"*):
   depois de subir arquivo, a página recarrega e o usuário **deve** voltar para Documentos;
3. matar só a **grudação**: não voltar quando o usuário escolheu outra aba.

**Prove os dois lados:**
- **grudação morreu:** abra Documentos → clique em Cobrança → reload → abre **Cobrança**;
- **restauração viva:** abra Documentos → suba um arquivo (a página recarrega) → volta em **Documentos**;
- **Pastas não regrediu:** o mesmo par de provas em `pasta/show`.

- [ ] **Step 3: B3 — conferir a subnav**

Já entra na T4. Confirme que o teste `assertSelectorExists('.cobranca-subnav')` passa e que o item ativo é
"Carteiras".

- [ ] **Step 4: B4 — redirect volta para a seção certa**

Os POSTs redirecionam para `cobranca_objeto_show` sem fragmento e caíam na aba Pessoas — **que não existe
mais**. Como a aba Cobrança agora é a default, o comportamento já melhora sozinho; acrescente o **fragmento**
(`#secao-divida` / `#secao-movimentos`) no redirect das mutações correspondentes, para o usuário voltar
olhando o que acabou de fazer.

- [ ] **Step 5: Rodar tudo**

```bash
docker exec jusprime_php_dev bash -c 'cd app && php bin/phpunit tests/Cobranca && php bin/phpunit tests/Pasta'
```

- [ ] **Step 6: SMOKE (inclusive a página de Pasta) e commit**

```bash
git add app/src/Cobranca app/public/js/pasta-arquivos.js app/templates app/tests
git commit -m "Corrigir data do pagamento, aba grudada, subnav e redirect"
```

---

## Task 8: erros de validação inline nos modais (B5)

**A maior fatia e a de maior risco.** Hoje um erro vira flash e **apaga tudo que o usuário digitou**
(`AutorizacaoCobranca::flashErrosDoForm:106-115`). Os controllers são POST-only.

> **Faça esta tarefa sozinha, num commit próprio.** Ela muda o contrato de ~10 controllers e não pode
> contaminar o diff do redesenho.

**Files:**
- Modify: `app/src/Cobranca/Controller/AutorizacaoCobranca.php`
- Modify: os controllers de mutação de Cobrança (Obrigacao, Pagamento, Liquidacao, Acordo, Caso, Pessoa, AcaoCobranca)
- Modify: `app/templates/cobranca/caso/_acoes_modais*.html.twig`
- Test: `app/tests/Cobranca/Functional/`

- [ ] **Step 1: Teste que falha, por modal**

```php
#[Test]
public function obrigacaoInvalidaReabreOModalComOErroEPreservaODigitado(): void
{
    $this->clienteLogadoCom('resources.cobranca.gerenciar');
    $objeto = $this->objetoComCobranca();

    $this->client->request('POST', "/cobrancas/obrigacoes/registrar/{$objeto->caso->getId()}", [
        'registrar_obrigacao' => ['descricao' => 'Cota de teste', 'valorOriginal' => '', 'vencimentoOriginal' => '2026-03-10'],
    ]);
    $crawler = $this->client->followRedirect();

    // O erro aparece NO CAMPO, não como flash solto...
    self::assertSelectorExists('#modalRegistrarObrigacao .invalid-feedback');
    // ...e o que o usuário digitou continua lá.
    self::assertSame('Cota de teste', $crawler->filter('#modalRegistrarObrigacao input[name*="[descricao]"]')->attr('value'));
}
```

> **A lição do ajuste 9 vale aqui, e é cara:** *"o teste do `criar` passava pelo motivo errado — form inválido
> também dá 302 para a mesma URL sem criar nada."* **Asserte a MENSAGEM e o valor preservado, nunca só o
> redirect.** E prove por **mutação**: quebre a implementação de propósito e confirme que o teste fica vermelho.

- [ ] **Step 2: A estratégia — DECIDIDA pelo humano (2026-07-16): SESSÃO + PRG**

> **Não reabra esta decisão.** As duas opções eram: (a) re-render na própria action; (b) sessão + PRG.
> **Escolhida: (b).**
>
> **Por que (b), e por que (a) agora seria destrutivo:** durante a T5 foi verificado **duas vezes** (por
> implementador e por revisor, lendo o código) que **todos** os controllers de mutação de Cobrança
> **SEMPRE redirecionam** — inclusive em form inválido e em exceção de domínio
> (`AcordoController::criar:238-280` termina no `redirectToRoute` da linha 279, fora de qualquer `if`;
> `PagamentoController::registrar:90` idem). **Foi exatamente esse fato que autorizou o reset dos modais no
> `hidden.bs.modal`** (commits `351dcf8` e `906af4c`).
>
> Adotar (a) quebraria isso: um re-render deixaria o modal aberto com dados, e o reset-ao-fechar passaria a
> **apagar o trabalho do usuário**. (a) e o reset dos modais são **mutuamente incompatíveis**.

**Desenho:**
1. No `catch`/ramo inválido, em vez de só `flashErrosDoForm`: guardar na sessão (a) os **erros por campo** e
   (b) o **payload submetido**, sob uma chave que identifique **qual modal** reabrir.
2. Redirecionar como hoje (PRG preservado, F5 não re-posta).
3. No `ObjetoController::show`, ler-e-consumir (`getFlashBag()`-like, **one-shot**) e passar ao Twig.
4. O template reidrata: reabre aquele modal, repõe os valores digitados e põe os erros nos campos.

**Cuidados que já custaram caro neste módulo:**
- **One-shot obrigatório:** se o estado não for consumido na leitura, o modal reabre com erro em toda
  visita seguinte. Teste isso.
- **A sessão não pode vazar entre abas/objetos:** inclua o id do caso/objeto na chave.
- **Nada de entidade na sessão** — só escalares/arrays.
- **Não quebre o reset dos modais** (`show.html.twig`, `hidden.bs.modal`): a reidratação acontece no
  **load**, o reset no **fechar**. Se colidirem, o usuário perde o que digitou. **Prove no navegador.**

- [ ] **Step 3–N:** implementar **controller a controller**, **um commit por grupo**, suíte verde entre eles.
      Comece por **um** (sugestão: `ObrigacaoController::registrar`, o mais simples), **prove o padrão
      inteiro nele** (incluindo o one-shot e o smoke real), e só então replique. Não faça os 10 de uma vez.

- [ ] **Step final: Commit**

```bash
git commit -m "Reabrir modal com erros inline e preservar o digitado"
```

---

## Task 9: formulários sob demanda (B6)

Todo GET constrói **13–16 FormViews**, mesmo os das abas que o usuário não abre
(`MontadorModaisCaso:69-88,103-107`).

> **Higiene, não UX** — a página não está lenta hoje. **Não faça isto antes da T4**: a estrutura de abas
> define o que é "sob demanda". Se o tempo apertar, esta é a segunda a cair.

- [ ] **Step 1:** medir antes (`docker exec ... php bin/console debug:...` ou o profiler do Symfony no dev) —
      registre o número real de queries e o tempo. **Sem medida antes, não há prova de melhora depois.**
- [ ] **Step 2:** adiar a construção dos forms das abas fechadas (closure/lazy no `MontadorModaisCaso`).
- [ ] **Step 3:** medir depois; suíte verde; commit.

```bash
git commit -m "Construir formularios do objeto sob demanda"
```

---

## Task 10 (OPCIONAL): "o que este pagamento abateu"

**Primeira a cair se o custo apertar** (spec §4.4). Foi proposta pelo redesenho, não pedida pelo humano, e é
a **única query genuinamente nova** do ajuste — `somasPorObrigacaoDosCasos` **não serve** (agrega por
obrigação, não por pagamento).

- [ ] **Step 1:** método novo em `AlocacaoPagamentoRepository`, **em lote por caso** (`array<int, list<...>>`
      = `pagamentoId => alocações com descrição da obrigação`). **Jamais** por pagamento dentro de loop.
- [ ] **Step 2:** `PagamentoOutput` ganha as alocações; `MontarDetalheCasoUseCase` alimenta em 1 query.
- [ ] **Step 3:** `_movimentos.html.twig` mostra "abateu Janeiro/2026 e Fevereiro/2026".
- [ ] **Step 4:** teste de lote (`expects(once())`), suíte verde, smoke, commit.

```bash
git commit -m "Mostrar quais obrigacoes cada pagamento abateu"
```

---

## Ordem e paralelização

**Tudo sequencial.** Nenhuma tarefa roda em paralelo com outra: T1/T2/T5 tocam `ObrigacaoOutput` e
`MontarDetalheCasoUseCase`, e T4/T5/T7 tocam `show.html.twig`. Escrita concorrente no mesmo arquivo é
exatamente o que o workflow do projeto proíbe.

```
T1 (alocado) → T2 (substituídas) → T3 (bruto) → T4 (template) → T5 (Receber/Acordar)
                                                     ↓
                                          T6 (bug do acordo) → T7 (correções)
                                                     ↓
                                          T8 (erros inline) → T9 (forms) → T10 (opcional)
```

T3 pode vir antes de T2 (são independentes), mas **T5 exige T1+T3+T4** e **T6 exige T1**.

## Checklist final (antes de considerar o ajuste pronto)

- [ ] `tests/Cobranca` verde e **acima de 539**; suíte global verde
- [ ] Zero deprecations
- [ ] Smoke real nos **dois temas**, MOSTRADO e aprovado pelo humano
- [ ] Aba Documentos: upload, seções e arrastar-soltar funcionando (T4 mexeu na volta dela)
- [ ] **Página de Pasta** conferida (T7 mexeu em script compartilhado)
- [ ] Reload não força mais a aba Documentos (B2)
- [ ] "Receber" com honorários acrescidos quita a obrigação **exatamente** (prova na prévia)
- [ ] Parcela de acordo não oferece Acordar (INV-U1)
- [ ] Acordo sobre obrigação paga sugere o **remanescente** (spec §5.3)
- [ ] Nenhuma regra de negócio mudou: `CalculadoraSaldo`, `ObrigacaoRepository::doCasoExigiveis/doCasoSubstituiveis`
      e os guards de acordo **intocados** — confirme com `git diff --stat`
