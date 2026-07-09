# Spec — Cobranças Etapa 3: Pagamentos, Liquidações e Honorários

> Risco **ALTO** (aritmética financeira: rateio proporcional + saldo derivado; multi-tenant). Alvo de revisão reforçada. Fonte de regras: `FEATURE_GESTAO_COBRANCAS_SPEC_FINAL.md` (SPEC) e `PLAN.md` §8/Etapa 3. Nomenclatura oficial (SPEC §4) preservada. Continua o núcleo da Etapa 2 (`docs/specs/cobranca-etapa2-caso-saldo.md`).

## Storytelling (derivado da SPEC — nenhuma decisão de negócio pendente, PLAN §3.1)

**RegistrarPagamento** — Quem: gestor autorizado. O quê: registrar um pagamento monetário (total ou parcial) confirmado manualmente (SPEC §11), **alocando explicitamente** quanto abateu cada obrigação do caso, com **sugestão FIFO** por vencimento pré-preenchida. Pré: caso existe no MESMO tenant e NÃO está encerrado; toda obrigação alocada pertence ao MESMO caso (invariável 12 — pagamento não atravessa casos). Regra de honorários: quando a carteira/caso é `acrescido_divida`, o valor pago pelo devedor é **rateado proporcionalmente** entre dívida-do-credor e honorários do escritório (SPEC §18), fechando exatamente em centavos. Pós: `Pagamento` com composição (`valorDivida`, `valorEncargos`, `valorHonorarios`) + N `AlocacaoPagamento`; `Σ alocações === valorDivida + valorEncargos`; saldo derivado do caso cai; evento `pagamento_registrado`. Erros: caso não encontrado / de outro tenant; caso encerrado; obrigação de outro caso (invariável 12); soma das alocações diverge da recuperação da dívida.

**CorrigirPagamento** — Quem: gestor. O quê: corrigir um pagamento já registrado quando necessário (valor errado, alocação errada), **SEM conceito de estorno no MVP** (PLAN §8/§6): a correção reescreve a composição/alocações do pagamento e exige `motivoCorrecao`; a mudança é **rastreável pela auditoria técnica existente** (`Auditavel` → `audit_log`, SPEC §22) e reflete imediatamente no saldo derivado. Pré: pagamento existe no tenant; caso não encerrado; obrigações da nova alocação são do mesmo caso. Pós: composição/alocações atualizadas, `motivoCorrecao` preenchido, evento `pagamento_corrigido` (motivo, de→para); saldo re-derivado. Erros: pagamento não encontrado / outro tenant; obrigação de outro caso; soma diverge; motivo vazio.

**RegistrarLiquidacao** — Quem: gestor. O quê: registrar uma redução **não monetária** do saldo (bem móvel/imóvel/direito/outra forma aceita, SPEC §11), informando **quanto do saldo foi reconhecido como extinto**. Regra central: o **valor atribuído ao bem** e o **valor reconhecido para liquidação da dívida podem ser diferentes** (§11); o saldo é reduzido pelo `valorReconhecido`, não pelo `valorAtribuidoBem`. Pré: caso existe no tenant e não está encerrado; `valorReconhecido > 0`. Pós: `Liquidacao` (tipo, descrição do bem, valorAtribuidoBem?, valorReconhecido, data); saldo derivado cai pelo `valorReconhecido`; evento `liquidacao_registrada`. Erros: caso não encontrado / outro tenant; caso encerrado; valor reconhecido não positivo.

## Entidades novas (namespace `App\Cobranca\Entity`, PK int, `TenantAware`, **`Auditavel`** — tocam dinheiro)

> Estilo idêntico ao das entidades da Etapa 2: PK `int` autogerada, propriedades declaradas manualmente, `criadoEm` no construtor, `#[ORM\PreUpdate]` para `atualizadoEm`, getters/setters `self`. **Valores sempre em CENTAVOS inteiros.** Toda entidade nova implementa `Auditavel` (guard `AuditavelCoberturaTest`) — são movimentos financeiros (§22).

- **Pagamento** (`cobranca_pagamento`): `tenant` nn, `caso`(CasoCobranca) nn, `data`(date_immutable), composição em centavos — `valorDivida`(principal recuperado, default 0), `valorEncargos`(encargos reconhecidos recuperados, default 0), `valorHonorarios`(honorários realizados atribuídos ao pagamento; ≠0 só quando `acrescido_divida`, default 0) —, `motivoCorrecao`(string 255, nullable), timestamps, `criadoPor`(User, SET NULL). `OneToMany AlocacaoPagamento` (`cascade: ['persist','remove']`, `orphanRemoval: true`). Métodos ricos: `valorRecuperadoDivida()` = `valorDivida + valorEncargos` (o que abate o saldo do credor; **= Σ alocações**); `valorTotalRecebido()` = `valorRecuperadoDivida() + valorHonorarios` (bruto pago pelo devedor no `acrescido_divida`).
- **AlocacaoPagamento** (`cobranca_alocacao_pagamento`): `tenant` nn, `pagamento`(Pagamento) nn, `obrigacao`(Obrigacao) nn, `valor`(int centavos — quanto deste pagamento abateu esta obrigação: principal+encargos combinados). Entidade de associação **é `TenantAware`** (defesa em profundidade, PLAN §6) **e `Auditavel`**.
- **Liquidacao** (`cobranca_liquidacao`): `tenant` nn, `caso`(CasoCobranca) nn, `tipo`(TipoLiquidacao), `descricaoBem`(string 255), `valorAtribuidoBem`(int centavos, nullable — valor do bem), `valorReconhecido`(int centavos — reduz o saldo; **pode diferir** de `valorAtribuidoBem`, §11), `data`(date_immutable), timestamps, `criadoPor`.

## Enum novo
- **TipoLiquidacao** (`string`, `label()`): `dinheiro` / `bem_movel` / `bem_imovel` / `outro` (SPEC §11).

## Serviço central novo — `CalculadoraHonorarios` (read-only, sem persistir; centavos int)

Toda aritmética em centavos inteiros, sem float onde possível; fechamento garantido (a soma bate com o total). Lê o **snapshot** do caso (`caso.formaHonorarios` + `caso.percentualHonorarios`, §18.2/§18.3 — nunca recalcula caso antigo). Honorários **não entram na própria base** (§18.5).

- `projetados(CasoCobranca $caso, int $baseDividaCentavos): int` — honorários esperados sobre a base de dívida reconhecida: `base * p` para formas com percentual; `0` para `sem_percentual`.
- `ratearPagamento(CasoCobranca $caso, int $valorTotalPagoCentavos): array{0:int,1:int}` — **rateio proporcional** (SPEC §18, forma `acrescido_divida`): o devedor paga dívida+honorários juntos; separa em `[valorDivida, valorHonorarios]` que **somam exatamente** ao total. `hon = round(total · p/(1+p))`; `divida = total − hon` (fecha em centavos por construção). Formas `retido_recuperado`/`cobrado_separado`/`sem_percentual`: o devedor paga só a dívida → retorna `[total, 0]` (honorários tratados à parte, não embutidos no pagamento).
- `realizadosSobreRecuperacao(CasoCobranca $caso, int $valorRecuperadoDividaCentavos): int` — honorários **realizados** a partir da dívida efetivamente recuperada (para relatório/dashboard, §18.7): `recuperado * p` para formas com percentual; `0` para `sem_percentual`. (No `acrescido_divida` equivale ao `valorHonorarios` embutido; no `retido_recuperado` é a parcela retida; no `cobrado_separado` é o honorário **gerado** — recebimento efetivo é do futuro Financeiro, §18.8/§19.)

> Aritmética do percentual: `percentualHonorarios` é `decimal(5,2)` (string, ex.: `"10.00"`) → basis points `pb = (int) round(percentual*100)` (10.00 → 1000). `base·p = intdiv(base·pb + 5000, 10000)` (round-half-up, inteiro). Rateio: `hon = intdiv(total·pb + (10000+pb)/2, 10000+pb)`, `divida = total − hon`. Nunca há float na conta final.

## Extensão de `CalculadoraSaldo` (orquestrador-owned; contrato central da Etapa 2 estendido)

Passa a **subtrair** os movimentos da Etapa 3 (SPEC §10, invariável 20 — saldo sempre derivado):
- `saldoExigivel(caso)` = `Σ obrigação.valorExigivel()` **− Σ alocações de pagamento do caso − Σ liquidação.valorReconhecido do caso`. (Substituição por acordo entra na Etapa 4.)
- `saldoVencido(caso, ?hoje)` = `Σ exigível das vencidas − Σ alocações às obrigações vencidas − Σ liquidação do caso`, **piso 0** (`max(0, …)`; liquidação/pagamento amortizam o vencido primeiro).
- `saldoConsolidadoObjeto(objeto)` = inalterado na fórmula (Σ `saldoExigivel` dos casos ativos), mas agora reflete os abatimentos.
- Novas dependências injetadas: `AlocacaoPagamentoRepository`, `LiquidacaoRepository`. Fonte de verdade continua sendo obrigações + movimentos; **nunca coluna de saldo**.

## Repositórios (stubs no andaime; queries agregadas do saldo são contrato compartilhado)
- **PagamentoRepository**: `salvar`/`remover`/`findOneByIdDoTenant`.
- **AlocacaoPagamentoRepository**: `salvar`/`remover` + `totalAlocadoNoCaso(caso): int` (Σ `valor` das alocações cujo pagamento pertence ao caso; escopo por tenant do caso — join) + `totalAlocadoEmObrigacoes(int[] $ids, Tenant): int` (Σ `valor` alocado às obrigações informadas; escopo por tenant). Consumidas pela `CalculadoraSaldo` (contrato).
- **LiquidacaoRepository**: `salvar`/`remover`/`findOneByIdDoTenant` + `totalReconhecidoNoCaso(caso): int` (Σ `valorReconhecido`; escopo por tenant do caso). Consumida pela `CalculadoraSaldo`.

## Exceptions novas
- `PagamentoNaoEncontradoException` (CorrigirPagamento).
- `ObrigacaoDeOutroCasoException` (invariável 12 — alocação a obrigação fora do caso do pagamento).
- `PagamentoInconsistenteException` (Σ alocações ≠ `valorDivida + valorEncargos`).
- (Caso encerrado reusa `CasoEncerradoException` da Etapa 2; `valorReconhecido ≤ 0` é barrado no DTO `#[Assert\Positive]`.)

## Migration
`cobranca_pagamento`, `cobranca_alocacao_pagamento`, `cobranca_liquidacao` (dinheiro = `INT` centavos). Aplicar dev+test via `doctrine:migrations:execute --up <Versão>` (evita as migrations-fantasma do dump de prod).

## Purga (anti-drift `PurgaCoberturaSchemaTest`)
As 3 tabelas são `tenant_id` → entrar na `ORDEM_DELECAO` **antes** do bloco Etapa 2 (movimentos → caso). Ordem FK-safe: `cobranca_alocacao_pagamento` (filha de pagamento+obrigação) → `cobranca_pagamento` (filha do caso) → `cobranca_liquidacao` (filha do caso) → [Etapa 2: evento/obrigação/caso …]. Semear as 3 no `PurgarEscritorioUseCaseTest`.

## Testes
- **CalculadoraHonorarios** (unit, núcleo financeiro): rateio proporcional fecha com o total em centavos (vários percentuais, valores que dão dízima); as **4 formas** (§18: acrescido/retido/cobrado/sem_percentual); base sem honorário-na-base (§18.5); percentual nulo/zero.
- **CalculadoraSaldo** (unit, atualizado): exigível cai por alocações e liquidações; vencido com piso 0; consolidado modo B reflete abatimentos.
- **RegistrarPagamento** (unit): sugestão FIFO; rateio quando `acrescido_divida`; `Σ alocações === valorRecuperadoDivida`; **pagamento não atravessa casos** (invariável 12 → `ObrigacaoDeOutroCasoException`); caso encerrado rejeita; evento `pagamento_registrado`.
- **CorrigirPagamento** (unit): correção sem estorno reflete no saldo; exige motivo; rastreável (Auditavel).
- **RegistrarLiquidacao** (unit): `valorReconhecido` reduz o saldo e **≠ `valorAtribuidoBem`** (§11); valor não positivo barrado; evento `liquidacao_registrada`.
- **Cross-tenant DB** (functional): pagamento/liquidação de outro escritório rejeitados; alocação não cruza tenant. Estende `CasoCobrancaIsolamentoTenantTest` ou novo `MovimentosCobrancaIsolamentoTenantTest`.
- **Fronteira Financeiro** (§19): nenhuma entidade de caixa/recebido/repasse — só efeitos sobre a dívida e honorários projetado/realizado.

## Invariáveis cobertas: 12, 18, 20, 22 (+ 1/23/24 multi-tenant transversal).
## Fora de escopo (etapas seguintes): acordos e substituição de obrigações (E4), judicialização/encerramento/próxima ação/revisão/alertas (E5), honorários recebidos/faturamento/repasse (Financeiro, §19), telas/dashboard (E8/E9).
