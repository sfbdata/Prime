# Aba Financeiro da pasta_show — fatia 2 do redesenho

Alvo: a revisão de **28/08/2026** do handoff (`docs/design/pasta-show/README.md`, seção
"Mudanças desta revisão") + o desenho aprovado `Pasta 1A.dc.html`.

Continua [[project-pasta-show-redesenho-desenho]], cuja fatia 1 entregou cabeçalho + aba Dados.
As outras 4 abas (Metas, Processo, Detalhes, Documentos) seguem fora desta fatia.

## O que a revisão pede (6 itens)

| # | Item | Situação antes desta fatia |
|---|---|---|
| 1 | Faixa de 4 cards iguais: Contrato · Pró-bono · Valor da causa · Média por CPF | Faixa Bootstrap de 5 colunas, com Arquivos na primeira |
| 2 | Card **Pagamentos** no trilho | **Não existe modelo** — construído nesta fatia |
| 3 | Arquivos sai do centro e vira card do trilho, no máximo 3 itens | Lista completa na faixa do topo |
| 4 | Contrato vira **selo clicável** | Já é botão, com visual de `btn-outline` |
| 5 | "Observações financeiras" → **Relatório financeiro** | Cartão chamado "Observações" |
| 6 | Remove "Financeiro do caso" do trilho da aba **Dados** | Entregue na fatia 1, em produção |

## Decisões do dono, antes de escrever (28/08)

1. **Fatia completa** — a aba inteira passa para a linguagem do redesenho (`ps-`, tokens claro/escuro),
   não só o rearranjo dos blocos.
2. **Construir o modelo de pagamentos agora** — entidade, migration, rotas, UseCases e testes.
   (As outras opções eram adiar o card ou pendurá-lo nas parcelas da Cobrança; a segunda mostraria a
   dívida do devedor no lugar do honorário do escritório.)
3. **A sublinha da Média por CPF continua sendo o NOME do cliente**, não a contagem que o desenho
   mostra: a média é das pastas de **um** cliente (o principal), e dizer "4 clientes vinculados"
   ao lado dela induz a erro. Desvio deliberado do desenho, autorizado.

## O que a produção diz sobre esta aba (medido em 28/08)

| | |
|---|---|
| pastas | 1.077 |
| com valor da causa | 1 |
| arquivos financeiros (categoria `CONTRATO`) | **0** |
| observações financeiras | 2, em 2 pastas |
| contrato `PENDENTE` | 1.070 (7 `REGULAR`) |
| pró-bono | 2 |

**O estado vazio é o estado real.** A aba será julgada pelo que mostra sem nada dentro, e o selo
vermelho de "Pendente" vai aparecer em 99,4% das pastas. O teto de 3 arquivos no trilho é
inofensivo hoje (nenhuma pasta tem arquivo financeiro) e continua reversível: o gerenciador
completo é a aba Documentos, que lista `pasta.documentos` **sem filtrar categoria** — arquivo
financeiro aparece lá.

## Modelo novo — `pasta_pagamento`

### Storytelling

1. **Quem** — quem cuida da pasta (usuário do escritório com permissão `edit` sobre ela).
2. **O quê** — registrar o que o cliente combinou pagar pelo caso (honorários, reembolso de custas)
   e acompanhar o que entrou e o que vence, sem sair da pasta. Hoje isso vive em texto livre: em
   toda a produção há **2** observações financeiras.
3. **Pré-condições** — pasta existe, é do tenant do usuário, usuário pode editá-la.
4. **Fluxo principal** — informa descrição, valor e vencimento → grava como pendente → a tela
   recalcula previsto/recebido e reordena os próximos vencimentos.
5. **Alternativos** — valor não-positivo, descrição vazia, data inválida → recusa com mensagem;
   pasta de outro escritório → 404 (nunca 403: 403 confirmaria que existe); sem permissão → 403.
6. **Pós-condições** — uma linha em `pasta_pagamento`; os totais do card mudam.
7. **Regras não óbvias**
   - **Previsto** = soma de todos; **Recebido** = soma dos que têm `pago_em`.
   - Estado é **derivado**, não gravado: `pago_em` preenchido → Pago; vazio e vencimento no passado
     → Vencido; vazio e vencimento no futuro → Pendente. Gravar o estado criaria a possibilidade de
     ele divergir da data, que é o defeito clássico deste repositório.
   - **Dinheiro nunca passa por float.** Entrada em pt-BR normalizada por `ValorEmReais` (extraído do
     `AtualizarValorCausaUseCase`, que já resolvia isso); soma em **centavos inteiros**;
     `decimal(15,2)` no banco.
   - Sem **editar**: o desenho não mostra edição de linha. Corrigir = excluir e lançar de novo.

### UseCases

| UseCase | O que faz |
|---|---|
| `RegistrarPagamentoDaPastaUseCase` | valida e grava um pagamento pendente |
| `AlternarQuitacaoDoPagamentoUseCase` | marca pago (data de hoje) ⇄ desfaz |
| `ExcluirPagamentoDaPastaUseCase` | remove a linha |

### Rotas (JSON, padrão da `PastaController`: permissão → CSRF → UseCase → JSON)

- `POST /pasta/{id}/pagamento` — `pasta_pagamento_registrar`
- `POST /pasta/{id}/pagamento/{pagamentoId}/quitacao` — `pasta_pagamento_alternar_quitacao`
- `POST /pasta/{id}/pagamento/{pagamentoId}/excluir` — `pasta_pagamento_excluir`

## Armadilhas conhecidas desta tela (não repetir)

- **Modal dentro de `.ps-paineis` fica preso abaixo do backdrop** — ancestral com `transform`
  (mesmo vindo de animação) vira bloco de contenção de `position: fixed`. O modal de "Adicionar
  pagamento" nasce **fora** de `.ps-page`, junto dos outros. Há teste travando o invariante.
- **`.ps-page a { color }` pinta botão-âncora** — qualquer link novo passa por
  `a:not(.btn):not(.ps-btn)`.
- **Suíte verde é cega para aparência.** O que dá para testar é arranjo com combinador de filho
  direto; borda, fonte e cor ficam para o smoke do dono.

## Testes

- `PastaFinanceiroArranjoTelaTest` — reescrito para a faixa de 4 cards e para os cards do trilho.
- `PastaDadosArranjoTelaTest` — sai o cartão `financeiro` do trilho da aba Dados.
- `PastaPagamentoControllerTest` — criar/quitar/excluir, CSRF, permissão e **isolamento de tenant**.
- `RegistrarPagamentoDaPastaUseCaseTest` e `PastaPagamentosOutputTest` — regras de valor e totais.
