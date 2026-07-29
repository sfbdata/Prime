# Casos de referência do TJDFT — como foram colhidos e o que significam

Arquivo: [`casos-referencia-tjdft.json`](casos-referencia-tjdft.json).

É a **verdade** contra a qual `CalculadoraAtualizacaoMonetaria` (Parte 3 do plano) será medida, ao
centavo. Os números do bloco `esperado` **não foram calculados aqui** — foram transcritos do que a
calculadora oficial devolveu. Se um deles parecer errado, ele ainda assim é a verdade: o objetivo é
reproduzir o TJDFT, não corrigi-lo.

## De onde vieram

Calculadora pública do TJDFT, `https://juriscalc.tjdft.jus.br/publico/calculos`, operada pelo
navegador em 29/07/2026 (autorização pontual do dono, registrada na spec §2, decisão 6).

Cada caso foi **preenchido na interface** — não montado à mão contra a API. Ao clicar em `Calcular`,
a SPA faz `POST https://juriscalc.tjdft.jus.br/public/calculos` (público, sem autenticação) e o
demonstrativo da aba 2 é a renderização da resposta. Foram capturados o **payload que a interface
produziu** (`payloadEnviado`) e a **resposta do servidor**, e é dela que sai o `esperado`. Isso evita
dois erros: inventar um payload que a interface nunca geraria, e transcrever número já arredondado
da tela quando a resposta traz mais casas.

### A interface mentiu uma vez — por isso cada caso tem `payloadEnviado`

No campo de percentual dos juros fixos, a tela mostrava `2,00%` e o servidor recebia `0,01`. O
componente só leva o valor digitado para o payload quando o campo dispara **`change`**, e mudança
programática de foco não dispara `change` — só a saída de foco de verdade (Tab). O caso 10 foi
capturado errado na primeira rodada e passou despercebido: o número era plausível, a soma fechava,
nada acusava.

O que pegou foi **conferir o `payloadEnviado` contra a `entrada` declarada**, campo a campo, nos 22
casos. É por isso que `payloadEnviado` ficou gravado aqui: sem ele não há como saber se o caso mede
o que diz medir. Quem recapturar deve refazer essa conferência — não confie na tela.

## Mapa da tela (medido em 29/07/2026)

| Seção | Componente Angular | Campos |
|---|---|---|
| Configuração | — | `dataFinalCalculo` (date, em branco = hoje), `numeroProcesso`, `credor`, `devedor`, select `indiceString` (`TJDFT` · `INPC`) |
| Valores | `jhi-valor-para-calcular-publico` | `valor` (máscara R$), `dataInicio` (date), `descricao` + botão `Adicionar valor` |
| Juros | `jhi-configuracao-calculo-juros-publico` | select `tipoInicioIncidenciaDosJuros` (`A_PARTIR_DOS_VALORES` · `A_PARTIR_DA_CITACAO_OUTRA` · `DATA_FIXA`), radios `tipoDeJuros0/1/2` (legais · percentual fixo · sem juros), `dataInicio`/`dataFim` |
| Multas | `jhi-multa-publico` | radios `tipoDeMulta0/1` (percentual · monetária); percentual → `percentual`; monetária → `dataMulta` + `valorMulta` + acordeão **Juros da multa** |
| Honorários | `jhi-honorarios-publico` | radios `tipoHonorarios0/1`; monetário → `dataInicio` + `valorHonorario` + acordeão **Juros do honorário** |
| Consectários 523 | `jhi-multa-e-honorario-publico` | radios `tipoDoConsectario5230/1/2` (multa · honorário · ambas) + `percentual` (10,00% por padrão) |
| Custas | `jhi-custas-publico` | `field_valor`, `field_dataInicio`, `field_descricao` |

## Divergências entre a spec e a tela real

Achados da Parte 1 que corrigem/completam a spec — as Partes 5 e 6 precisam deles:

1. **Custas têm `Data início`.** A spec §5.1 descreve custas como `Valor` + `Descrição`. A tela tem
   também uma data, e a resposta mostra que ela serve de termo inicial da **correção monetária** da
   custa. O que o manual (p. 21) veda é **juros e multa** sobre custas, não a correção.
2. **Multa monetária tem data e juros próprios.** A spec só previu termos próprios para o
   *honorário* em R$. A multa monetária tem `Data para aplicação da multa` e um acordeão
   `Juros da multa` com os mesmos três tipos.
3. **O rótulo do termo inicial é `A partir da data dos valores`**, não "A partir do(s) Valor(es)
   Devido(s)" como está na spec §5.1.
4. **O gerador de parcelas mensais não é um botão separado.** Depois de `Adicionar valor`, o
   formulário mantém valor e descrição e **avança a data em um mês**; repetir o clique gera a série.
   É o comportamento do manual p. 11.
5. **A correção não é pro rata die.** A resposta traz `periodizacao: "Mensal"` e `proRata: false` —
   o dia do mês da data do valor não altera o fator.
6. **A correção para no mês anterior ao da data final.** Com `dataFinalCalculo = 31/12/2025` a
   resposta traz `dataFinalDaCorrecao: "11/2025"`. Não é falta de índice publicado (em 29/07/2026 o
   BCB já tinha até 06/2026): é a convenção do tribunal.

## Como ler o `esperado`

Oito campos, todos em **centavos** (`int`), somando exatamente `totalCentavos`:

| Campo | Origem na resposta oficial |
|---|---|
| `principalCentavos` | `itensDeSaida[Totais].valorInicial` — soma dos valores lançados, sem nada |
| `correcaoCentavos` | `itensDeSaida[Totais].valorDaCorrecao` |
| `jurosCentavos` | `itensDeSaida[Totais].juros` — juros **dos valores** apenas |
| `multasCentavos` | `saldoFinal.saldoMulta.total + saldoFinal.saldoMultaMonetaria.total` (principal + juros da própria multa) |
| `honorariosCentavos` | `saldoFinal.saldoHonorario.total + saldoFinal.saldoHonorarioMonetario.total` |
| `consectario523Centavos` | `saldoFinal.saldoMultaArt523.total + saldoFinal.saldoHonorariosArt523.total` |
| `custasCentavos` | `saldoFinal.saldoCustas.total` |
| `totalCentavos` | `saldoFinal.total` |

Cada caso traz `observacoes.conferencia`, que registra se a soma das sete parcelas bate com o total.
**Se algum caso vier `false`, é achado, não ruído** — significa que a decomposição acima perdeu
dinheiro em algum lugar e a Parte 3 precisa descobrir onde antes de confiar no caso.

`observacoes` também guarda o que permite descobrir a fórmula quando o total não bater: os índices
aplicados por período (`indicesDeCorrecao`), o fator de correção com 11 casas
(`valorDoFatorDeCorrecao`), o percentual de juros acumulado, e a `dataFinalDaCorrecao`.

## As medições auxiliares — e o que já se sabe da fórmula

O plano (passo 4 da Parte 1) pedia o **subtotal por período** nos casos 4, 5 e 6. O demonstrativo
**não publica subtotal por período**: publica as faixas (`INPC de 01/2020 até 08/2024`) e um único
fator acumulado. Para não deixar a Parte 3 deduzindo a composição no escuro, foram feitas cinco
medições extras na mesma calculadora, isolando cada trecho, em
[`medicoes-auxiliares-tjdft.json`](medicoes-auxiliares-tjdft.json). Elas **não** fazem parte dos 22
casos e não devem entrar no `DataProvider` do teste de fidelidade.

O que essas medições já provam (conferido por aritmética, não por suposição):

- **Os juros legais são simples e incidem sobre o valor já corrigido.** Caso 6: base corrigida
  R$ 1.218,02 × 15% = R$ 182,70, exatamente o transcrito. Não há capitalização — o próprio payload
  confirma (`capitalizado: false`).
- **Os segmentos de juros somam-se linearmente na virada.** Caso 2 = 0,665724597; aux-02a
  (1% até 30/08/2024) = 0,556451613; aux-02b (taxa legal a partir de 30/08/2024) = 0,109272984. A
  soma dá o valor do caso 2 **na última casa decimal**.
- **Os juros têm pró-rata por dia; a correção monetária não.** O 55,645161% do aux-02a é 55% de
  meses cheios + 0,6451613%, que é 20/31 de um mês. Já a correção vem com `proRata: false` e
  `periodizacao: "Mensal"`.
- **Uma faixa de correção cuja competência inicial é igual à final rende fator zero** (aux-04a). É o
  dado que decide se a competência de ponta entra no produtório — não dá para inferir isso do texto
  `de 08/2024 até 08/2024`.

## O caso 22 depende do dia

`22-data-final-em-branco` foi capturado com a data final vazia, então o resultado é o do dia
**29/07/2026** (`capturadoEm`). Para a Parte 3, o motor precisa receber um relógio injetável e o
teste tem de fixá-lo nessa data — senão o caso passa a falhar sozinho no dia seguinte.

## Recapturar

Os casos usam datas finais fixas no passado, então os números só mudam se o IBGE revisar índice já
publicado. Se for preciso recapturar, refaça pela **interface**, não pela API, e atualize
`capturadoEm`.
