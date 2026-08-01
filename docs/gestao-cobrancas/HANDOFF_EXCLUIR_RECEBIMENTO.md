# HANDOFF — Excluir recebimento (frente seguinte)

**Aberto em 2026-08-01**, ao fechar a frente `cancelar acordo` (commit local `bcccd097`, não publicado).
Risco: **ALTO** (apaga dinheiro recebido). Exige spec em `docs/specs/` antes de codar, e `/review`.

---

## 1. Por que esta frente existe

Duas coisas convergem nela.

**a) Um portão sem saída que acabei de criar.** `CancelarAcordoUseCase` agora recusa cancelar acordo
com pagamento alocado numa parcela (`AcordoComParcelaPagaException`) — a mensagem manda desfazer o
pagamento primeiro. **Só que desfazer pagamento não existe.** Há apenas
`cobranca_pagamento_registrar` e `cobranca_pagamento_corrigir`, e a correção exige `valorPago`
**positivo** (`CorrigirPagamentoInput.php:29`); o docblock do UseCase diz literalmente *"NÃO há estorno
no MVP"*. Não há rota de exclusão.

**b) A régua que o dono declarou em 01/08:**

> *"pensando em deixar o sistema mais modelável e que seja fácil reverter ações erradas… ações não
> podem ser irreversíveis"* — e vale **também para os escritórios que não usam importação**.

Ou seja: a reversibilidade não pode depender de reimportar a planilha, porque a maioria dos
escritórios não terá planilha nenhuma. Cada ação de dinheiro precisa do próprio desfazer.

**Palavras do dono sobre o desenho desejado:**

> *"na lista de valores 'recebidos' tenha a opção de apagar recebimento. A lista deve ter todos os
> recebimentos. Os que são de acordos ligados ao pagamento do acordo. Aí, para excluir um registro de
> pagamento, resolve o problema quando tiver que cancelar ou romper acordo."*

## 2. O que já está pronto (não refazer)

**A lista já mostra TODOS os recebimentos.** `PagamentoRepository::doCaso` traz todos os pagamentos do
caso, sem filtro de tipo; `_movimentos.html.twig:17` os funde na linha do tempo. Como o `Pagamento`
pertence ao **caso** (não ao acordo), os pagamentos de parcela de acordo já aparecem lá. **Falta só a
ação de apagar.**

## 3. O molde a seguir: `CorrigirPagamentoUseCase`

Ele já faz 90% do que a exclusão precisa, na ordem certa. Ler antes de escrever qualquer coisa:

| Passo | Onde | Observação |
|---|---|---|
| resolve por id + tenant | `PagamentoRepository::findOneByIdDoTenant` | guarda multi-tenant |
| recusa caso encerrado | `CasoEncerradoException` | mesma regra vale para excluir |
| descarta as alocações | `$pagamento->limparAlocacoes()` + orphanRemoval no flush | é o núcleo da exclusão |
| **reconcilia** | `ReconciliadorLiquidacao::reconciliar($configCaso, $obrigacoes, $alocadoPorObrigacao, $data)` | **passo que não pode faltar** |
| registra evento | `RegistrarEventoHistorico` com `flush: true` | transação única |

⚠️ **A reconciliação é o ponto crítico.** Uma obrigação que o pagamento havia **liquidado** precisa
voltar a VIVA (`Obrigacao::reabrir()` — zera `liquidadaEm` e descongela). Sem isso, a dívida fica
quitada no banco com o pagamento apagado: dinheiro que não existe abatendo dívida que existe. O
reconciliador já sabe fazer isso; o que a frente precisa garantir é que ele seja chamado com
`alocadoPorObrigacao` **zerado** para as obrigações tocadas.

## 4. Contrato sugerido (a confirmar na spec)

- **Rota:** `POST /cobrancas/pagamentos/{id}/excluir`, nome `cobranca_pagamento_excluir`.
- **Capacidade:** `resources.cobranca.movimentacao_financeira` (a mesma de registrar/corrigir).
- **CSRF** obrigatório, como as irmãs.
- **Motivo obrigatório?** `CorrigirPagamento` exige `motivoCorrecao`. Apagar é mais grave — provável que
  sim. **Perguntar ao dono.**
- **Evento novo:** `TipoEventoHistorico::PagamentoExcluido` (hoje só existem `PagamentoRegistrado` e
  `PagamentoCorrigido`). A linha precisa ser **autocontida**: valor, data e onde estava alocado — o
  pagamento não existirá mais para consultar.
- **Apagar de verdade ou marcar como cancelado?** A régua do dono é reversibilidade. Vale considerar
  soft-delete, pelo mesmo raciocínio que fez o acordo cancelado ser escondido em vez de apagado nesta
  frente. **Decisão do dono.**

## 5. Perguntas para o dono ANTES de codar

1. Apagar recebimento exige **motivo**?
2. Apaga de verdade ou fica escondido (reversível)?
3. Quem pode apagar — qualquer um com movimentação financeira, ou só gestor?
4. Pagamento **importado** (quando o 4º relatório existir) pode ser apagado à mão, ou só a importação
   manda nele?

## 6. Como se prova (o que a frente NÃO pode deixar passar)

Cada teste validado **reintroduzindo o defeito que guarda** — e conferindo que a prova mede algo real
(nesta frente que fecha, DOIS asserts passavam com o defeito presente: um verificava um estado que o
cenário nunca produzia, outro chamava um método sem chamador de produção).

- **o saldo do caso sobe exatamente o valor apagado** — nem mais, nem menos;
- obrigação que estava **liquidada por aquele pagamento volta a VIVA** e os juros voltam a correr;
- pagamento parcial: o `alocado` da obrigação zera e o `restante` volta ao cheio;
- **depois de apagar, cancelar o acordo passa** — é o fecho do portão (§1a);
- isolamento: pagamento de outro tenant → 404;
- caso encerrado → recusa.

**Viés de confirmação = a contabilidade.** Ver [[reference_cobranca_relatorios_contabil]]: o 4º
relatório (*Receitas detalhadas por unidade/cliente*) é a fonte da verdade sobre pagamento e **ainda
não é importado**. Enquanto não for, todo recebimento é digitado à mão e sem conferência externa — mais
uma razão para poder apagar.

## 7. Estado do repositório ao abrir esta frente

- `master` local em **`bcccd097`** — a frente `cancelar acordo` commitada e **NÃO publicada**.
- Suíte **3113/3113**; `lint:container`, `lint:twig`, `doctrine:schema:validate --skip-sync` verdes.
- Migration `Version20260801150000` **já aplicada no dev**, pendente em produção.
- ⏳ **Smoke do dono pendente** (caso 193: 5 linhas na dívida, nenhum acordo visível).
## 8. A frente que vem DEPOIS desta (e por que a ordem importa)

**D6 — "o importe é a verdade absoluta"**, esclarecida pelo dono em 01/08, ao fim do dia:

> *"No caso de importação, os dados da planilha vão sobrescrever acordo rompido. O acordo do sistema
> tem que estar alinhado com o da planilha. O importe é sempre a verdade… não precisa nem dizer que o
> importe mudou esse estado, pois já é implícito que o importe é a verdade absoluta."*

Está especificada em **§3.2 da spec `cobranca-cancelar-acordo.md`**, com o furo de dinheiro que ela
precisa resolver: reativar um acordo tira do exigível as originais, e um pagamento recebido nelas
durante a janela **para de abater a dívida** — o devedor passaria a ser cobrado pelo que já pagou.
Medido: exigíveis 5 → 31, bruto 88961 → 88445.

**E o dono fechou o raciocínio (01/08):** *"é o estado ATUAL, porque temos que implementar o importe da
planilha de receita também."* A *Receitas detalhadas* traz **NN, data e valor** — ela diz, por
obrigação, o que foi pago. Com ela, a pergunta "para onde vai o dinheiro quando o acordo volta" vira
dado, não decisão do sistema.

🔑 **Ordem das três frentes, e o porquê:**

1. **Excluir recebimento** (esta) — o desfazer. Sem ele, erro de dinheiro não tem conserto.
2. **Importar Receitas detalhadas** — passa a dizer, por NN, o que foi pago.
3. **D6, reativação por importação** — só depois de (2). Escrita antes, o rateio do pagamento seria
   chute (FIFO); depois, é leitura da verdade. Escrever antes = escrever duas vezes.
