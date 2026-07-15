# Spec — Ajuste 5: Editar + Excluir Obrigações (correção de cadastro auditada)

> Módulo **App\Cobranca**, já em produção. Rodada de ajustes 2026-07-13.
> Risco: **MÉDIO** — mexe em valores que alimentam o **saldo derivado** (tensão com a invariável 20).
> **Sem migração de dados** (hard delete + eventos backed-string + edição in-place; nenhuma coluna nova).

## Objetivo

Permitir **corrigir** uma obrigação cadastrada errada (erro de digitação/importação) — editando todos os
campos de cadastro, inclusive os encargos reconhecidos — e **excluir** uma obrigação lançada por engano,
sempre com **guardas** que impedem corromper o histórico financeiro, **motivo obrigatório** e **registro
no histórico** (evento operacional) + auditoria técnica (a entidade `Obrigacao` já é `Auditavel`).

## Enquadramento na invariável 20 (NÃO violar o espírito)

A **invariável 20** restringe o **saldo** ("é derivado das obrigações exigíveis e liquidações; nunca um
valor manual independente"). Ela **não proíbe corrigir o `valorOriginal`/`vencimento` de uma obrigação** —
proíbe recálculo automático de encargos e saldo digitado. A SPEC §10 admite ("ajuste manual excepcional
pode ser permitido, desde que exija motivo e preserve histórico"; l.664/821). Portanto:

- Editar `valorOriginal`/`vencimentoOriginal`/`encargosReconhecidos` é **correção de cadastro** explícita,
  distinta de "movimento operacional" (pagamento/acordo). O **saldo continua derivado** (`CalculadoraSaldo`):
  ao mudar `valorExigivel()` da obrigação, o saldo do caso muda por derivação, nunca por coluna manual.
- Para preservar o espírito: **(a)** motivo obrigatório; **(b)** evento no histórico com o **antes/depois**
  no payload `dados` + auditoria técnica automática; **(c)** guardas que bloqueiam quando a obrigação já
  tem rastro irreversível (pagamento alocado abaixo do novo valor, é parcela de acordo, foi substituída,
  caso encerrado).

## Decisões fechadas (com o humano, 2026-07-14)

1. **Encargos UNIFICADOS no modal de Editar.** O botão/ação **"Reconhecer valor" é aposentado**: os
   encargos passam a ser editados dentro de "Editar obrigação". Consequência: `ReconhecerValorAtualizado*`
   (UseCase/Input/Type), a rota `cobranca_obrigacao_reconhecer`, a action do controller, o modal
   `#modalReconhecerValor` + seu JS, a entrada `reconhecerValor` do `MontadorModaisCaso` e os testes de
   reconhecer **são removidos**. O **case de enum `TipoEventoHistorico::ValorAtualizadoReconhecido`
   permanece** (hidrata eventos históricos já gravados em prod — não reintroduzir remoção).
2. **Excluir = HARD DELETE** (`ObrigacaoRepository::remover`, já existe). Seguro porque os guards garantem
   ZERO rastro financeiro (sem alocação, não é parcela, não substituída). Registra evento
   `ObrigacaoExcluida` (com snapshot do que foi apagado no `dados`) ANTES de remover, no mesmo flush.
3. **Motivo obrigatório** em Editar **e** Excluir.

## Modelo (inalterado) — recap

`Obrigacao` (`cobranca_obrigacao`): `descricao`, `valorOriginal` (centavos), `vencimentoOriginal` (date),
`encargosReconhecidos` (centavos), `referenciaExterna` (nullable), `acordoOrigem` (é parcela?),
`acordoSubstituto` (foi substituída?). Métodos: `valorExigivel() = valorOriginal + encargosReconhecidos`;
`ehParcela()` (acordoOrigem !== null); `foiSubstituida()` (acordoSubstituto !== null). Implementa
`Auditavel` (mutação auditada tecnicamente).

Saldo (`CalculadoraSaldo::saldoExigivel`) = Σ `valorExigivel()` das obrigações **exigíveis**
(`doCasoExigiveis`, exclui substituídas e parcelas de acordo não-vigente) − Σ alocações a elas − Σ
liquidações. Total já pago numa obrigação = `AlocacaoPagamentoRepository::totalAlocadoEmObrigacoes([$id], $tenant)`.

## Guardas (valem para EDITAR e EXCLUIR, salvo indicado)

| Condição | Editar | Excluir | Exceção (→ flash) |
|---|---|---|---|
| Caso encerrado (`caso.estaEncerrado()`) | ❌ bloqueia | ❌ bloqueia | `CasoEncerradoException` (reusa) |
| Travada por acordo **VIGENTE** (`participaDeAcordoVigente()`) | ❌ bloqueia | ❌ bloqueia | `ObrigacaoDeAcordoException` (nova) |
| Tem pagamento alocado (`totalAlocado > 0`) | ⚠️ permitido, mas… | ❌ bloqueia | Excluir: `ObrigacaoComPagamentoException` (nova) |
| Novo `valorExigivel()` < total alocado | ❌ bloqueia | — | `ValorAbaixoDoAlocadoException` (nova) |
| Obrigação de outro tenant | 404 (`findOneByIdDoTenant`) | 404 | `ObrigacaoNaoEncontradaException`/404 |

> **Trava VIGENTE-AWARE (decisão + correção de bug 2026-07-14).** A trava vale só quando a obrigação
> participa de um acordo **VIGENTE** (`StatusAcordo::ehVigente()` = Ativo/Cumprido): parcela de acordo
> ativo/cumprido, ou original substituída por acordo ativo/cumprido — via `Obrigacao::participaDeAcordoVigente()`.
> **Acordo rompido/cancelado NÃO trava:** a obrigação original volta ao saldo (fica editável de novo) e a
> parcela vira histórico (`ObrigacaoOutput::parcelaDeAcordoDesfeito` — greyed + badge "Acordo desfeito",
> editável/excluível). Isso é coerente com o design (`StatusAcordo`: "originais voltam, parcelas saem;
> sem reversão imperativa") e com o `ObrigacaoOutput.substituidaPorAcordo`, que já era vigente-aware. A UI
> mostra um **cadeado + tooltip** ("gerida pelo acordo") nas travadas por acordo vigente (explica por que
> não há botão editar).
>
> **Nota (regressão consciente):** hoje "Reconhecer valor" permite reconhecer encargos numa parcela de
> acordo (só barra caso encerrado). Ao unificar em Editar (que bloqueia obrigação travada por acordo
> vigente), encargos de parcela **de acordo vigente** deixam de ser editáveis por aqui — parcelas são
> geridas pelo acordo (item 7). Aceito.

## Escopo — o que muda

### A. Editar obrigação
- **DTO `EditarObrigacaoInput`**: `obrigacaoId` (NotNull+Positive, vem da rota), `descricao`
  (NotBlank+Length 255), `valorOriginal` (NotNull+Positive, centavos), `vencimentoOriginal` (NotNull),
  `referenciaExterna` (Length 255, opcional), `encargosReconhecidos` (PositiveOrZero, centavos),
  `motivo` (**NotBlank**+Length 255).
- **Form `EditarObrigacaoType`**: espelha `RegistrarObrigacaoType` + `encargosReconhecidos` (CentavosType,
  required false, empty_data '0') + `motivo` (TextType, obrigatório). `data_class` = o Input.
- **UseCase `EditarObrigacaoUseCase::executar(EditarObrigacaoInput, Tenant, User): Obrigacao`**:
  1. `obrigacaoRepository->findOneByIdDoTenant` → `ObrigacaoNaoEncontradaException`.
  2. Guards: caso encerrado → `CasoEncerradoException`; `participaDeAcordoVigente()` (parcela ativa OU
     substituída por acordo vigente) → `ObrigacaoDeAcordoException`; novo `valorExigivel`
     (valorOriginal+encargos do input) < total alocado → `ValorAbaixoDoAlocadoException`.
  3. Captura o **antes** (snapshot dos 5 campos), aplica os setters, registra evento
     `ObrigacaoEditada` (descrição com o motivo; `dados` = `{antes:{...}, depois:{...}, motivo}`), `flush: true`.
  Injeta `ObrigacaoRepository`, `AlocacaoPagamentoRepository`, `RegistrarEventoHistorico`.
- **Controller** `ObrigacaoController::editar` — rota `POST /cobrancas/obrigacoes/{id}/editar`
  (`cobranca_obrigacao_editar`). Molde igual a `reconhecerValor` (gate `gerenciar`, `findOneByIdDoTenant`
  → 404, captura `objetoId` antes, PRG, try/catch das `\DomainException` → flash, redirect ao objeto).

### B. Excluir obrigação
- **UseCase `ExcluirObrigacaoUseCase::executar(int $obrigacaoId, string $motivo, Tenant, User): void`**
  (sem DTO/Form dedicado — só id da rota + `motivo` do POST + CSRF manual, como o `excluirDocumento`):
  1. `findOneByIdDoTenant` → `ObrigacaoNaoEncontradaException`.
  2. Guards: caso encerrado; `participaDeAcordoVigente()` → `ObrigacaoDeAcordoException`;
     `totalAlocado > 0` → `ObrigacaoComPagamentoException`.
  3. Registra evento `ObrigacaoExcluida` (snapshot no `dados` + motivo, `flush: false`), depois
     `obrigacaoRepository->remover($obrigacao, flush: true)` — evento + delete no mesmo commit.
- **Controller** `ObrigacaoController::excluir` — rota `POST /cobrancas/obrigacoes/{id}/excluir`
  (`cobranca_obrigacao_excluir`). CSRF manual (token `excluir_obrigacao_{id}`) como no `excluirDocumento`;
  motivo do corpo do POST (validar não-vazio → flash+redirect se vazio). Captura `objetoId` ANTES de remover.

### C. Novas exceções (`app/src/Cobranca/Exception/`, todas `final`, `\DomainException`)
- `ObrigacaoDeAcordoException(int $obrigacaoId)` — "Obrigação %d participa de um acordo (parcela ou
  substituída) e não pode ser editada/excluída diretamente."
- `ObrigacaoComPagamentoException(int $obrigacaoId, int $totalAlocado)` — "Obrigação %d tem pagamentos
  alocados e não pode ser excluída."
- `ValorAbaixoDoAlocadoException(int $obrigacaoId, int $novoExigivel, int $totalAlocado)` — "O valor
  exigível ficaria abaixo do total já pago/alocado nesta obrigação."

### D. Novos eventos (`TipoEventoHistorico` + `label()`; SEM migração)
- `ObrigacaoEditada = 'obrigacao_editada'` → "Obrigação editada".
- `ObrigacaoExcluida = 'obrigacao_excluida'` → "Obrigação excluída".

### E. UI (aba Obrigações)
- Na célula de ações de cada linha (`objeto/show.html.twig` `#tab-obrigacoes`): **substituir** o botão
  "Reconhecer valor" por **"Editar"** (lápis) + **"Excluir"** (lixeira), ambos gated por
  `has_permission('resources.cobranca.gerenciar') and not caso.encerrado` e escondidos quando
  `o.substituidaPorAcordo` ou `o.ehParcelaAcordo` (o servidor também barra — defesa em profundidade).
- Modais reutilizáveis `#modalEditarObrigacao` (form pré-preenchido por JS via `data-*` da linha:
  descrição, valor, vencimento, referência, encargos) e `#modalExcluirObrigacao` (motivo + confirmação),
  no padrão `data-acao-url` já usado pelo Reconhecer. Remover `#modalReconhecerValor`.
- `MontadorModaisCaso`: trocar `reconhecerValor` por `editarObrigacao` (view). `ObrigacaoOutput` já expõe
  todos os campos necessários para pré-preencher (id, descricao, valorOriginal, encargosReconhecidos,
  vencimentoOriginal, referenciaExterna).

### F. Remoções (unificação — decisão 1)
- Apagar: `ReconhecerValorAtualizadoUseCase`, `ReconhecerValorAtualizadoInput`,
  `ReconhecerValorAtualizadoType`, action `ObrigacaoController::reconhecerValor` + rota, modal
  `#modalReconhecerValor` + JS, entrada `reconhecerValor` do `MontadorModaisCaso`.
- Testes: apagar `ReconhecerValorAtualizadoUseCaseTest`; remover do `ObrigacaoMutacaoControllerTest` os
  casos de reconhecer (happy + IDOR). **Manter** o enum case `ValorAtualizadoReconhecido`.

## Testes

- **Unit `EditarObrigacaoUseCaseTest`**: happy (5 campos alterados + evento `ObrigacaoEditada` com
  antes/depois); guard caso encerrado; guard parcela; guard substituída; guard valorExigivel<alocado
  (mock `totalAlocadoEmObrigacoes`); tenant (`ObrigacaoNaoEncontradaException`).
- **Unit `ExcluirObrigacaoUseCaseTest`**: happy (registra evento + `remover`); guard alocado>0; guard
  parcela/substituída; guard caso encerrado; tenant.
- **Functional `ObrigacaoMutacaoControllerTest`** (novos casos): editar happy (persiste + volta ao objeto);
  editar sem capacidade (redirect ≠ caso); editar IDOR → 404; editar CSRF; excluir happy (linha some);
  excluir com pagamento → bloqueado (flash, não remove); excluir IDOR → 404; excluir CSRF.
- **Regressão:** suíte `tests/Cobranca` + global verdes; N+1 não regride (nada novo no loop de saldo).

## Fatias de implementação (cada uma: TDD → smoke → /review → commit)

1. **Fatia A — Editar (inclui aposentar Reconhecer):** exceções + eventos + DTO/Form/UseCase de editar +
   controller `editar` + UI (botão Editar + modal) + REMOÇÃO do Reconhecer (código+modal+JS+testes).
2. **Fatia B — Excluir:** `ExcluirObrigacaoUseCase` + controller `excluir` (CSRF manual) + UI (botão
   Excluir + modal de confirmação com motivo) + testes.

## Critério de conclusão

- Editar corrige os 5 campos (com motivo), auditado, respeitando os guards; saldo se ajusta por derivação.
- Excluir remove de vez uma obrigação sem rastro financeiro (com motivo), auditado; bloqueia o resto.
- "Reconhecer valor" deixou de existir (unificado em Editar). Enum legado preservado.
- Isolamento tenant provado; suíte verde; sem migração.

## Fora de escopo (follow-ups)

- Edição de **parcelas de acordo** e do próprio acordo → **item 7** (acordo inteligente + editar).
- Histórico visual do "antes/depois" na timeline (hoje `dados` não é renderizado) — a descrição textual do
  evento já resume a mudança.
