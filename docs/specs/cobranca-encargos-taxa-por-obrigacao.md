# Cobrança — Taxa por-obrigação com espelho R$ ↔ % (ao vivo)

> **Resolve** o "Ponto de produto a confirmar na revisão da spec" de `cobranca-encargos-ao-vivo.md` §7
> (override por-obrigação vs. só caso/carteira) escolhendo o **override por-obrigação** (a opção mais completa).
> **Supersede a decisão D2** de `cobranca-encargos-configuraveis-cascata.md` (honorários NÃO por-obrigação):
> honorários passam a ter override próprio na obrigação.
> **Não muda o motor** `CalculadoraEncargos` — a paridade ao centavo com a contabilidade (Apêndice A) fica intacta.
> Risco: **ALTO** (caminho do dinheiro). Data: 2026-07-21. Branch: `cobranca-encargos-cascata`.

## 1. Objetivo (na voz do dono)

O sistema é de **registro e acompanhamento** das cobranças; a **contabilidade** é a autoridade do dinheiro. Por
isso o dono quer, **por obrigação específica**:

1. Editar a **taxa (%)** de juros/multa/correção/honorários **daquela obrigação**, e o valor seguir **ao vivo**
   (crescendo com o tempo) — sem congelar.
2. Ver, ao lado da %, o **valor em R$** que ela produz **hoje**.
3. Poder **alterar o R$** (arredondar/aumentar/diminuir); o sistema **deriva a % equivalente** e passa a guardar
   **a %** — não o valor. O valor volta a crescer a partir dessa % nova.

Confirmação do dono (2026-07-21): **"editei o R$ hoje" = "fixei a % equivalente à data de hoje", e daí pra frente
cresce a partir dela.** A quantização (item §5) foi **aceita**.

## 2. O que já existe (base reaproveitada, NÃO reimplementar)

- **Colunas de taxa por-obrigação já existem** e são nullable (`null = herda o caso`):
  `taxaJurosMensalBp`, `regimeJuros`, `taxaMultaBp`, `baseMulta`, `taxaCorrecaoBp`, `baseCorrecao`,
  `baseHonorarios`, `carenciaHonorariosDias`, `toleranciaJurosMultaDias`
  (`app/src/Cobranca/Entity/Obrigacao.php` §NÍVEL 3 da cascata).
- **`ResolvedorConfigEncargos::resolver(Obrigacao)`** já aplica esses overrides campo-a-campo sobre o caso.
- **Conversores R$↔% já existem e provados:** `CalculadoraEncargos::taxaDeValor(base, R$)` (R$→bp, meio-p/-cima)
  e `valorDeTaxa(base, bp)` (bp→R$, meio-p/-cima). Falta só a variante do **juros** que considera `dias`.
- **Config editável no nível do caso/carteira** já tem UI (`EditarConfiguracaoCasoUseCase`,
  `EditarConfiguracaoCarteiraUseCase`, forms `EditarConfiguracaoCarteiraType`/`CriarCarteiraType`).

## 3. Os dois furos a fechar (o trabalho real)

### 3.1. Cálculo ao vivo passa a ser **por-obrigação**

Hoje a hidratação ao vivo resolve a config **no nível do caso** (`resolverDoCaso($caso)`) e aplica a mesma a
TODAS as obrigações — logo **ignora a taxa própria** da obrigação. Isso é uma **divergência latente**: a linha de
detalhe (`MontarDetalheCasoUseCase:109`) já usa `resolver($o)` (por-obrigação), mas saldo/FIFO/dashboard/modais
usam `resolverDoCaso`. Chamadores hoje em `resolverDoCaso`:
`CalculadoraSaldo`, `AutoAlocadorFifo`, `MontadorModaisCaso`, `MontarDashboardCobrancaUseCase`,
`MontarDetalheAcordoUseCase`, `CriarAcordoUseCase`, `CorrigirPagamentoUseCase`, `RegistrarPagamentoUseCase`,
`MontarDetalheCasoUseCase:74`.

**Decisão (abordagem "overlay"):** `EncargosVivos` aplica o override da obrigação **sobre a config-base do caso**,
por obrigação, no momento de hidratar/ler. Extrai-se do `resolver(Obrigacao)` o corpo do nível-3 para um método
público do resolvedor — `aplicarObrigacao(ConfigEncargos $base, Obrigacao $o): ConfigEncargos` — e
`resolver(Obrigacao)` passa a ser `aplicarObrigacao(resolverDoCaso($caso), $o)`.

- **Custo:** o overlay **não faz consulta nova** (só lê campos já carregados da obrigação) — não reintroduz N+1.
  A config-base do caso continua resolvida **1× por caso** (evita re-navegar carteira).
- **Blast radius mínimo:** os chamadores continuam passando a base do caso; quem passa a aplicar o nível-3 é o
  `EncargosVivos` (em `hidratar` e `exigivelVivo`). Fecha a divergência num lugar só.
- **Invariante:** liquidada/substituída (congelada) segue lendo o snapshot — o overlay só afeta **Viva**.

### 3.2. Espelho R$ ↔ % no modal (escreve a **taxa**, não o valor)

Hoje os 4 campos "(R$)" do modal (`RegistrarObrigacaoType`/`EditarObrigacaoType` → `juros/multa/correcao/
honorarios` em `CentavosType`) escrevem nas **colunas de valor** (cache), que a hidratação **descarta** na leitura
(vestigial). Passam a operar como **entrada de taxa**:

- Cada encargo exibe **% e R$** (o R$ é o que aquela % produz **hoje**).
- Editar **%** → recalcula o R$ (preview em JS; servidor é autoridade).
- Editar **R$** → deriva a **% equivalente à data de hoje** e **grava a taxa** (coluna de override). O valor volta
  a ser derivado (cresce).
- **Campo em branco = herda do caso** (coluna override fica `null`), com indicador "herda do caso" + ação de
  voltar a herdar (limpa o override).

## 4. Modelo de dados

- **Reusar** todas as colunas de taxa por-obrigação já existentes (§2).
- **+1 coluna nova** (supersede D2): `taxa_honorarios_bp` INTEGER **NULL** em `cobranca_obrigacao` — override da
  alíquota de honorários da obrigação, em basis points. `null = herda a alíquota do snapshot do caso`.
  - No `ResolvedorConfigEncargos::aplicarObrigacao`, a linha hoje fixa
    `taxaHonorariosBp: $base->taxaHonorariosBp` passa a `$obrigacao->getTaxaHonorariosBp() ?? $base->taxaHonorariosBp`.
  - Entidade: propriedade `?int $taxaHonorariosBp = null` + getter/setter nullable.
- **Colunas de valor** (`juros/multa/correcao/honorarios` em centavos) permanecem, mas são **puro cache** da
  leitura ao vivo — o modal **não escreve mais nelas**. `valorExigivel()` e a coluna-sombra `encargos_reconhecidos`
  seguem como estão (INV-E1/§10.3 da cascata).
- **Migração:** 1 `ADD COLUMN taxa_honorarios_bp INTEGER NULL` — sem default, sem backfill (null = herda). Aplicada
  pelo **humano** no deploy (ver §9). Banco de teste é `schema:create`; em dev/`saas_test`, `ALTER` cirúrgico.

## 5. Conversão R$ → % (o miolo aritmético)

Serviço **puro** novo `ConversorTaxaEncargo` (sem I/O, 100% inteiro em centavos/bp), testado isolado. Deriva a
taxa (bp) que produz um R$ **na data de referência** (hoje), por encargo:

- **Juros** (pró-rata diária, depende de `dias`): a partir de `juros = P·bp·dias / (30·10000)`, deriva
  `bp = round( valor · 30 · 10000 / (P · dias) )` (variante do `taxaDeValor` com `dias`, citada em ao-vivo §7).
  Arredondamento **consistente** com o forward, coberto por teste round-trip.
  - **Borda `dias == 0`** (não vencida ou vencendo hoje): juros = 0 e **não há % derivável de um R$**. Editar o R$
    de juros fica **desabilitado**; só a **%** é editável (passa a produzir juros quando vencer). Idem `P <= 0`.
- **Multa / Correção** (proporção flat sobre a base): `bp = taxaDeValor(base, valor)` (já existe). A **base** é a
  resolvida (`Principal` ou `Principal + juros`) — logo o **juros do dia** é calculado **antes** da multa, e
  juros+multa **antes** da correção (mesma ordem do motor).
- **Honorários** (base composta `P+juros+multa+correção` do dia, ou principal; só após carência):
  `bp = taxaDeValor(baseHonDoDia, valor)`. Fora do exigível (INV-E2). **Borda `dias <= carência`**: honorário = 0,
  editar R$ desabilitado; só a % é editável.

**Quantização (aceita pelo dono):** como o que se guarda é a **taxa** em bp (passo 0,01%), o R$ salvo é o mais
próximo que aquela % produz — pode diferir alguns centavos do digitado (ex.: R$170/240d, digitar R$14,00 salva
~1,03% e exibe R$14,01). Não é bug: é consequência de "guardar a taxa, não o valor". Documentar no tooltip/ajuda.

## 6. UI (modal criar/editar obrigação)

Segue o que a cascata §11 já previa (`_acoes_modais.html.twig` + JS em `objeto/show.html.twig`):

- Por encargo (juros, multa, correção, honorários): par **% ↔ R$**; editar um recalcula o outro em JS (preview),
  **servidor revalida e é autoridade** (recomputa a % da R$ à data de hoje, grava a taxa).
- Estado **"herda do caso"** explícito por encargo, com ação de limpar o override (volta a `null`).
- Bordas §5 (juros/honorários com R$ desabilitado quando `dias==0`/dentro da carência) refletidas na UI.
- Preserva os padrões B5 (reidratação do form) e reset-on-close já existentes.
- **Linha da obrigação** (`objeto/_partials/_divida.html.twig`): já exibe encargos separados; **conferir** que
  passa a refletir a **taxa própria** (via §3.1) — sem re-desenhar a linha (fora de escopo se já correta).

## 7. UseCases / Forms / DTOs

- **`RegistrarObrigacaoType` / `EditarObrigacaoType`**: os 4 campos `CentavosType` (R$) viram entrada de **taxa**
  (par %↔R$ por encargo) + os campos de base/regime/carência/tolerância **daquela obrigação** (todos opcionais →
  null = herda). Honorários ganha o par %↔R$ (usa `taxa_honorarios_bp`).
- **DTOs `RegistrarObrigacaoInput` / `EditarObrigacaoInput`**: trocam os 4 inteiros de valor por **overrides de
  taxa** (nullable, em bp) + as demais colunas de override. Validação `#[Assert]` (bp >= 0; nullable).
- **`RegistrarObrigacaoUseCase` / `EditarObrigacaoUseCase`**: **sai** a lógica `$digitou`/`$mexeuManual` e o
  `definirEncargos(...)` a partir de valores digitados. Passa a **setar as colunas de override** (via
  `ConversorTaxaEncargo` quando a entrada veio em R$). A obrigação **nunca congela** ao registrar/editar (D6
  ao-vivo mantido); a materialização inicial de cache pode ser feita pela hidratação normal ou por um
  `definirEncargos` derivado da config resolvida (nunca do valor digitado).
  - **Reconciliação da Liquidada** (reabrir se o exigível vivo supera o pago) e os **guards** (caso encerrado,
    acordo vigente, valor < alocado) permanecem; passam a usar a config **com o override** da obrigação.
- **Histórico:** registrar a mudança de taxa no evento `ObrigacaoEditada` (antes/depois inclui as taxas de
  override). Enum `TipoEventoHistorico::ValorAtualizadoReconhecido` já existe se for útil distinguir.
- **Importação** (`ImportarRelatorioCarteiraUseCase`): **inalterada** — segue herdando a taxa do caso (sem override
  por-obrigação na importação nesta entrega).

## 8. Invariantes

- **INV-V1 (preservado):** obrigação Viva **não persiste valor** de encargo — só a **taxa** (entrada) é gravada.
  O valor exibido é sempre `vencimento + config(com override) + hoje`.
- **INV-V2/V3 (preservados):** liquidada/substituída lê snapshot; saldo/exigível são função de `hoje`.
- **INV-V5 (preservado):** o motor `CalculadoraEncargos` **não muda**; paridade ao centavo intacta. O overlay só
  troca **qual** `ConfigEncargos` entra no motor.
- **Multi-tenant:** toda leitura/escrita da obrigação segue filtrando por tenant (guards atuais mantidos).
- **Honorários fora do exigível (INV-E2):** override de honorários muda o "total com honorários", **nunca** o saldo.

## 9. Migração e deploy (do HUMANO)

1. Aplicar a migração `ADD COLUMN taxa_honorarios_bp` (dev/`saas_test` por `ALTER` cirúrgico; prod pelo humano).
   Conferir colisão de número da `Version...` (falha silenciosa conhecida).
2. **Confirmar que as colunas de taxa da cascata (nível-3) já existem no schema de PROD** — se a cascata não foi
   publicada em prod, o deploy inclui também as migrations que as criam (não só a de honorários).
3. Publicação segue as pendências já registradas do modelo ao vivo (rebuild prod + migração `liquidada_em`;
   migração A1 de limpeza do `encargos_congelados_em` legado; conferir carteiras sem taxa) — ver
   `docs/gestao-cobrancas/SMOKE_ENCARGOS.md` e o plano `docs/superpowers/plans/2026-07-20-encargos-ao-vivo.md`.
- **Nada de push/merge/deploy pelo agente.**

## 10. Verificação e testes (ALTO risco)

- **Unit `ConversorTaxaEncargoTest`:** R$→bp→R$ round-trip por encargo; juros ancorado no `dias` (relógio fixo);
  bordas `dias==0`, `P<=0`, `dias<=carência`; quantização (o R$ recomputado é o mais próximo, documentado).
- **Unit do overlay:** `aplicarObrigacao(base, obrigacao)` — override presente vence a base; `null` herda; campo-a-
  campo (juros próprio não zera multa herdada); honorários via `taxa_honorarios_bp`.
- **Unit `EncargosVivosTest`:** obrigação com taxa própria cresce por **sua** taxa (relógio fixo, dias reais);
  duas obrigações do mesmo caso com taxas diferentes divergem corretamente.
- **Unit dos UseCases** `Registrar`/`Editar`: entrada em % grava bp; entrada em R$ deriva bp; branco = null (herda);
  editar não congela; reabertura da liquidada e guards com override.
- **Functional** do modal (controller do objeto): submeter %/R$, persistir a taxa, e o saldo/linha refletirem a
  taxa própria (relógio fixo).
- **Re-prova** das suítes sensíveis (saldo, FIFO, acordo, dashboard, pagamento, `ObjetoShowControllerTest`): agora
  leem a taxa própria via overlay.
- **Alvo:** `tests/Cobranca` verde + suíte global verde. Rodar no container:
  `docker exec jusprime_php_dev bash -c 'cd app && php -d memory_limit=512M bin/phpunit ...'`.
  `MockClock` a partir de `new \DateTimeImmutable('YYYY-MM-DD')` (nunca de string — off-by-one de fuso).

## 11. Fora de escopo (YAGNI)

- **Valor R$ exato "chumbado"** (guardar valor-âncora em vez de taxa) — o dono aceitou a quantização; não fazer.
- **Override de taxa na importação** por linha do relatório.
- **Re-desenho** da linha da obrigação, se já exibe encargos corretamente após §3.1.
- Regime de juros composto por-obrigação além do que a coluna `regimeJuros` já permite (sem UI nova dedicada).
