# SPEC — Ajuste 6: Registrar pagamento com auto-alocação FIFO + divisão dívida/honorários ao vivo

> Módulo `App\Cobranca` **já em produção**. Rodada de ajustes pós-prod (2026-07-14). Risco **MÉDIO** (fluxo financeiro: a alocação alimenta saldo/honorários derivados — invariável 20 / §18).
> Fonte do pedido: `docs/gestao-cobrancas/AJUSTES_BACKLOG.md` §6.
> Base: branch `gestao-cobrancas`, HEAD `0d438b0`, working tree limpo, `tests/Cobranca` 425/425, global 1740/1740.

## 1. Problema (diagnóstico confirmado no código)

Ao registrar um pagamento, o [AlocadorPagamento](../../app/src/Cobranca/Service/AlocadorPagamento.php) exige que a **Σ das alocações feche EXATAMENTE com a parte-dívida derivada** (`AlocadorPagamento.php:71-73`), NÃO com o valor pago:

```php
[$valorDivida, $valorHonorarios] = $this->calculadoraHonorarios->ratearPagamento($caso, $valorPago);
// ...
if ($soma !== $valorDivida) {
    throw new PagamentoInconsistenteException($soma, $valorDivida);
}
```

Na forma de honorários **`acrescido_divida`** (§18), `ratearPagamento` separa o bruto pago em `[dívida, honorários]` (`CalculadoraHonorarios.php:49-64`: `hon = round(total·pb/(10000+pb))`, `dívida = total − hon`). Então o **alvo que o usuário precisa alocar (a parte-dívida) é invisível** — ele digita o bruto mas tem que somar a dívida líquida à mão, linha a linha. Qualquer centavo errado → `PagamentoInconsistenteException` impossível de acertar às cegas. Além do tédio de distribuir manualmente.

O rótulo do campo por-obrigação hoje é **`'Valor alocado (R$)'`** ([AlocacaoPagamentoType.php:32](../../app/src/Cobranca/Form/AlocacaoPagamentoType.php)) — a origem literal da queixa "'valor alocado' não ficou claro".

## 2. Objetivo

1. **Auto-alocação FIFO por padrão** (dívida mais antiga/vencida primeiro) — gera as alocações automaticamente, somando exatamente a parte-dívida. Mata o "alvo invisível".
2. **Alocação manual** vira opção avançada (toggle "Alocar manualmente"), preservando o fluxo atual intacto para quem quer distribuir por obrigação específica.
3. **Prévia ao vivo** ao digitar o valor pago: "Dívida: R$X · Honorários: R$Y" (+ quebra por obrigação do FIFO), via **endpoint GET** (fonte única da regra de centavos).
4. Mesmíssimo comportamento no **"Corrigir pagamento"**.
5. Rótulo/help text do campo revistos.

## 3. Decisões de produto (fechadas com o humano — 2026-07-14)

- **D1 — Sobrepagamento = BLOQUEAR com aviso.** Quando a parte-dívida excede o **saldo exigível derivado do caso** (`CalculadoraSaldo::saldoExigivel`, que já abate alocações E liquidações), o registro é **rejeitado** com erro claro; a prévia ao vivo já sinaliza antes do submit. Não gera saldo negativo, não polui dashboard/alertas. (Como consequência, **não há "sobra" a absorver**: `valorDivida` é inteiro exato e, sendo ≤ saldo, cabe integralmente na distribuição gulosa — ver §5.)
- **D2 — Prévia via ENDPOINT GET no servidor.** Chama o `CalculadoraHonorarios` real (uma só regra de centavos, §18, sem 2ª implementação em JS que possa divergir) e devolve também a quebra FIFO por obrigação (que depende de dados server-side: alocado por obrigação).

## 4. O que NÃO muda (invariantes preservadas)

- **Sem migration.** Nenhuma coluna nova. Tudo é derivado; as `AlocacaoPagamento` já existem, o saldo por-obrigação é derivável.
- **`AlocadorPagamento::montar` continua sendo o único ponto que valida (Σ == valorDivida) e constrói as entidades.** O FIFO só **gera** os `AlocacaoPagamentoInput`; o pipeline a jusante (`montar`) revalida — defesa em profundidade, uma só regra de fechamento.
- **`ratearPagamento` intacto** (a dívida já absorve o resto do arredondamento de honorários — `CalculadoraHonorarios.php:63`).
- **Ordem FIFO já existe:** `ObrigacaoRepository::doCasoExigiveis` retorna `ORDER BY o.vencimentoOriginal ASC` (`ObrigacaoRepository.php:112`) — mais antiga primeiro, já exclui substituídas/parcelas de acordo não-vigente.
- **Gate, PRG, CSRF stateless, tenant-safety (findOneByIdDoTenant→404)** dos controllers de escrita: sem alteração de padrão.
- **Manual = comportamento atual bit-a-bit** quando o toggle está ligado.

## 5. Núcleo — o serviço de auto-alocação FIFO

**Novo serviço read-only** `App\Cobranca\Service\AutoAlocadorFifo` (não persiste, não flusha; espelha o estilo do `AlocadorPagamento`).

```php
/**
 * @return AlocacaoPagamentoInput[]  (obrigacaoId + valor), Σ == parte-dívida do valor pago
 * @throws PagamentoExcedeSaldoException  quando a parte-dívida > saldo exigível do caso (D1)
 */
public function alocar(
    CasoCobranca $caso,
    int $valorPago,
    Tenant $tenant,
    ?Pagamento $pagamentoEmCorrecao = null,  // correção: ignora as alocações do próprio pagamento
): array;
```

### Algoritmo
1. `[$valorDivida, $_] = $calculadoraHonorarios->ratearPagamento($caso, $valorPago)`. (Nas formas ≠ `acrescido_divida`, `valorDivida == valorPago`.)
2. `$exigiveis = $obrigacaoRepository->doCasoExigiveis($caso)` — já em `vencimentoOriginal ASC`.
3. `$alocado = $alocacaoRepository->somasPorObrigacaoDosCasos([$casoId], $tenant)` — `obrigacaoId => Σ alocado`.
   **Correção:** subtrair, por obrigação, as alocações do `$pagamentoEmCorrecao` (elas serão reescritas — não podem contar contra a sala disponível).
4. Por obrigação derivamos **dois** números (na ordem FIFO):
   - **teto** — `saldoBruto += valorExigivel() − alocadoRestante[id]` (**sem** piso por obrigação, pode ser negativo sob super-alocação);
   - **distribuição** — `sala[id] = max(0, min(valorExigivel(), valorExigivel() − alocadoRestante[id]))` (piso 0 **e** teto no próprio exigível — defesa contra `alocadoRestante` espúrio negativo).
5. **Teto real (D1)** = **`saldoExigivel`** do caso *como se o pagamento em correção não existisse* = `saldoBruto − liquidado`, onde `liquidado = liquidacaoRepository->totalReconhecidoNoCaso($caso)` (liquidação é **por-caso**, não por-obrigação). Isso é **idêntico por construção** a `CalculadoraSaldo::saldoExigivel` (`Σ valorExigivel − Σ alocado − liquidado`) — **não** usar `max(0,…)` por obrigação no teto (senão uma obrigação super-alocada infla o saldo e D1 liberaria pagamento que zera o saldo real p/ negativo — furo pego na revisão da Fatia 1). Vale sempre `saldoExigivel ≤ Σ sala`.
   - **Se `valorDivida > saldoExigivel`** → `throw new PagamentoExcedeSaldoException($valorDivida, $saldoExigivel)`.
6. **Distribuição gulosa** (venc ASC): `restante = valorDivida`; para cada obrigação, `take = min(sala[id], restante)`; se `take > 0`, emite `AlocacaoPagamentoInput(id, take)`; `restante -= take`. Como `valorDivida ≤ saldoExigivel ≤ Σ sala`, o `restante` chega a 0 **exatamente** dentro das obrigações — a última obrigação tocada recebe o resíduo inteiro; **nenhuma sobra fracionária surge** (dinheiro é int; a única "sobra" possível seria sobrepagamento, já barrado em 5).
7. Retorna a lista (na ordem FIFO).

> **Ordem obrigatória na correção (Fatia 2):** o `CorrigirPagamentoUseCase` deve chamar `alocar(…, $pagamento)` **ANTES** de `limparAlocacoes()`/flush — assim a query `somasPorObrigacaoDosCasos` ainda enxerga as alocações do próprio pagamento, que o passo 3 subtrai. Inverter a ordem faria `alocadoRestante` ficar negativo (o clamp do passo 4 evita over-alocação, mas o teto ficaria inflado).

### Casos de borda
- **Sem obrigações exigíveis** (`Σ sala == 0`) e `valorDivida > 0` → `saldoExigivel ≤ 0 < valorDivida` → bloqueia ("não há saldo a alocar"). Correto.
- **`valorPago` ≤ 0** não ocorre (o Form/DTO exige positivo); defensivamente, lista vazia cairia no `montar` como Σ0 == valorDivida0.
- **Liquidação já zerou o caso** → `saldoExigivel == 0` → qualquer pagamento novo bloqueia. Correto (nada mais a recuperar).

### Nova exceção
`App\Cobranca\Exception\PagamentoExcedeSaldoException extends \DomainException`, construtor `(int $valorDivida, int $saldoDisponivel)`, mensagem com os dois valores. Capturada nos controllers como flash `danger` (mesmo padrão do `PagamentoInconsistenteException`).

## 6. UseCases — auto por padrão, manual sob demanda

Ambos ganham um ramo antes do `montar`:

```php
$alocacoesInput = $input->alocarManualmente
    ? $input->alocacoes
    : $this->autoAlocadorFifo->alocar($caso, (int) $input->valorPago, $tenant /*, $pagamento p/ correção */);

[$valorDivida, $valorHonorarios, $alocacoes] = $this->alocador->montar($caso, (int) $input->valorPago, $alocacoesInput, $tenant);
// ... resto idêntico (Pagamento, evento, flush) ...
```

- **RegistrarPagamentoUseCase**: `alocar($caso, $valorPago, $tenant)`.
- **CorrigirPagamentoUseCase**: `alocar($caso, $valorPago, $tenant, $pagamento)` — passa o pagamento sendo corrigido para excluir suas alocações da sala. O restante (guardar composição anterior, `limparAlocacoes`, reescrever, evento `PagamentoCorrigido`) fica igual.

Nenhuma outra assinatura pública muda.

## 7. DTO e Form

### Input DTOs (`RegistrarPagamentoInput`, `CorrigirPagamentoInput`)
- **Novo campo** `public bool $alocarManualmente = false;` (default = auto).
- A validação `#[Assert\Count(min: 1)]` de `alocacoes` passa a ser **condicional**: exigir ≥1 alocação **somente quando `alocarManualmente === true`**, via `#[Assert\Callback]` no DTO (em auto, `alocacoes` vem vazia e é preenchida pelo serviço). `#[Assert\Valid]` permanece (valida cada linha quando houver).

### Forms (`RegistrarPagamentoType`, `CorrigirPagamentoType`)
- **Novo campo** `alocarManualmente` (`CheckboxType`, `required: false`, `label: 'Alocar manualmente (avançado)'`, mapeado ao DTO).
- `alocacoes` (CollectionType) permanece; a UI o **oculta por padrão** e o revela quando o checkbox liga (JS).

### Rótulo/help (D fechada — atende a queixa literal)
- `AlocacaoPagamentoType.php`: label `'Valor alocado (R$)'` → **`'Valor nesta obrigação (R$)'`** (só visível no modo manual).
- Help text do modal Registrar (`_acoes_modais_financeiro.html.twig:30`): trocar por algo como *"O sistema distribui o pagamento automaticamente pela dívida mais antiga (FIFO); os honorários são calculados à parte. Marque 'Alocar manualmente' para distribuir você mesmo."*

## 8. Prévia ao vivo — endpoint GET (D2)

**Duas rotas GET read-only** no `PagamentoController` (gate `resources.cobranca.movimentacao_financeira`; `findOneByIdDoTenant`→404; **sem CSRF** por ser GET, padrão 8A):

| Rota | Nome | Escopo |
|---|---|---|
| `GET /cobrancas/casos/{id}/pagamento/previa?valor=<centavos>` | `cobranca_pagamento_previa` | registrar (caso) |
| `GET /cobrancas/pagamentos/{id}/previa?valor=<centavos>` | `cobranca_pagamento_previa_corrigir` | corrigir (exclui o próprio pagamento) |

**Resposta JSON** (idêntica nas duas; a de correção passa o `$pagamento` ao serviço):
```json
{
  "valorPago": 120000,
  "divida": 109091,
  "honorarios": 10909,
  "saldoDisponivel": 100000,
  "excede": true,
  "excedeEm": 9091,
  "alocacoes": [
    {"obrigacaoId": 12, "descricao": "Parcela 1", "vencimento": "2026-03-01", "valor": 40000},
    {"obrigacaoId": 13, "descricao": "Parcela 2", "vencimento": "2026-04-01", "valor": 60000}
  ]
}
```
- `divida`/`honorarios` sempre vêm do `CalculadoraHonorarios` real.
- Quando **não excede**, `alocacoes` traz a quebra FIFO; quando **excede**, `excede=true` + `excedeEm` e o `alocacoes` pode vir vazio ou parcial (a UI mostra o aviso e desabilita o submit). Decidir no detalhe da implementação: mais simples é o serviço lançar/ sinalizar excesso e o controller montar o JSON `excede` sem quebra. **Escolha:** o controller chama um método de **preview** que NÃO lança (retorna estrutura com `excede`), para a rota GET; o `alocar()` (que lança) fica no caminho de escrita. Extrair um núcleo comum `derivar()` no serviço que devolve `{divida, honorarios, saldoDisponivel, excede, alocacoes}`; `alocar()` chama `derivar()` e lança se `excede`.

### JS (inline em `objeto/show.html.twig`, onde já vive todo o JS de pagamento)
- `input` no `valorPago` → **fetch debounced (~300ms)** ao endpoint da vez → renderiza caixa de prévia: **"Dívida: R$X · Honorários: R$Y"**; se `excede`, mostra aviso vermelho ("Excede o saldo em R$…") e **desabilita o botão de submit**.
- Checkbox "Alocar manualmente": ligado → revela o container de alocações (e, opcional, pré-preenche as linhas com a quebra FIFO como ponto de partida editável); desligado → oculta e limpa.
- Reusa `initColecao` existente para o modo manual.

## 9. Segurança / multi-tenant

- Endpoints GET: mesmo gate de capacidade financeira + `findOneByIdDoTenant` (caso/pagamento) → 404 cross-tenant. `somasPorObrigacaoDosCasos` e `doCasoExigiveis` já filtram tenant.
- `AutoAlocadorFifo` recebe `Tenant` explícito; toda carga é tenant-scoped. As obrigações vêm do próprio caso (identidade), então o `montar` a jusante reconfirma `obrigacao->getCaso() === $caso` (invariável 12).
- Nenhuma capacidade nova; nenhuma rota de escrita nova.

## 10. Plano de implementação (fatias, TDD → smoke → aprovação → suíte+/review → commit)

**Fatia 1 — Serviço + exceção (núcleo puro, provado por unit):**
- `AutoAlocadorFifo` (com `derivar()` + `alocar()`), `PagamentoExcedeSaldoException`.
- `AutoAlocadorFifoTest` (unit, repos mockados / calculadora real): ordem FIFO (mais antiga primeiro), preenchimento parcial, fechamento exato, `acrescido_divida` (dívida < bruto), `sem_percentual` (dívida == bruto), **sobrepagamento → exceção**, liquidação reduz o teto, **correção exclui as próprias alocações**, sem obrigações → bloqueia.

**Fatia 2 — Wiring nos UseCases + DTO/Form (auto default, manual toggle):**
- `alocarManualmente` nos 2 Input DTOs (Callback condicional) e nos 2 Forms; ramo auto/manual nos 2 UseCases; relabel + help text.
- Unit dos UseCases: caminho **auto** gera alocações FIFO; caminho **manual** inalterado; correção auto exclui-próprio.
- Functional `PagamentoMutacaoControllerTest`: **happy auto** numa carteira `acrescido_divida` (envia só `valorPago`, zero linhas de alocação → prova o fim do alvo invisível); **sobrepagamento → flash danger, não persiste**; manual continua passando.

**Fatia 3 — Endpoint de prévia + JS ao vivo + toggle na UI:**
- 2 rotas GET no `PagamentoController` (JSON); template (caixa de prévia, checkbox, ocultar alocações por padrão); JS debounced.
- Functional do endpoint: shape do JSON (não excede / excede), gate de capacidade, IDOR 404, correção exclui-próprio.
- **Smoke visual real no navegador** (dev `localhost:8080`, `farlei.rocha@gmail.com`): digitar valor → ver split ao vivo; registrar em auto; ver bloqueio de sobrepagamento; alternar manual. Objetos com dados reais: **carteira 3**, objeto **117** (161 obrigações). *(Gotcha: remover `#modalAlertaPonto` via `browser_evaluate` antes de clicar.)*

Cada fatia: **mostrar resultado → humano aprova → só então** `php -d memory_limit=512M bin/phpunit tests/Cobranca` + global + `/review` (feature-review-agent contra esta spec) → corrigir → **commit atômico** → próxima.

## 11. Riscos e mitigação
- **Divergência prévia↔submit:** eliminada por D2 (uma só regra em PHP).
- **Correção contando alocações próprias na sala** (falso bloqueio): mitigado pelo parâmetro `$pagamentoEmCorrecao` (§5.3).
- **Liquidação por-caso vs alocação por-obrigação:** o teto usa `saldoExigivel` (abate liquidação), então bloqueio é fiel ao que resta de fato (§5.5).
- **Validação condicional do Form:** coberta por functional (auto sem linhas passa; manual sem linhas falha).
- **Nada em prod até decisão humana** (merge/deploy da rodada são do humano, depois do DJEN).
