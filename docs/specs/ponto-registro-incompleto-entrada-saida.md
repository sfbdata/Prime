# Ponto — registro incompleto passa a ser só falta de entrada ou de saída

**Risco:** ALTO (ponto eletrônico, muda banco de horas retroativo de todos os escritórios)
**Data:** 2026-08-31
**Decisão:** do dono, em duas perguntas respondidas antes de qualquer linha de código.
**Antecedente:** `docs/specs/ponto-abono-nao-perdoa-jornada.md` (frente de 05/08/2026, em produção)

## O pedido

> "se tem entrada e saída conta normalmente, apenas quando não tem a entrada ou a saída que conta
> como registro incompleto, nesse caso pode deixar a badge amarelo. pois **é permitido trabalhar sem
> tirar o almoço** e nesse caso não precisa do aviso, é só contar normal."

O pedido chegou como "tirar a mensagem *Registro incompleto*". Não é mudança de tela: é mudança da
**regra** que decide quais dias o sistema apura. O selo amarelo continua existindo — só passa a
aparecer em menos dias.

## O que muda

`CalculadoraJornada::registroIncompleto()` deixa de perguntar à escala qual a "forma" do dia
(`JornadaResolver::tiposEsperadosNoDia()`) e passa a decidir só pelas batidas:

| # | Situação | Incompleto? | Por quê |
|---|---|---|---|
| 1 | Nenhuma batida | **não** | Ausência, não registro incompleto. Continua gerando falta cheia. Invariante herdado da frente de 05/08 — sem ele a regra apagaria toda falta do sistema. |
| 2 | Falta `entrada` | **sim** | Sem começo não há jornada a medir. |
| 3 | Falta `saida` | **sim** | Sem fim não há jornada a medir. |
| 4 | Tem `entrada` e `saida`, sem `repouso` nem `retorno` | **não** | **É a mudança.** Trabalhar sem tirar almoço é permitido; o span inteiro é a jornada. |
| 5 | Tem `entrada` e `saida` + as duas do intervalo | **não** | Inalterado: desconta o intervalo medido. |
| 6 | Tem `entrada` e `saida` + **uma só** do intervalo | **sim** | Quem bateu `repouso` provou que **saiu** para almoçar, mas não quanto tempo ficou fora. Contar o span inteiro creditaria esse almoço — o defeito exato que 05/08 removeu. |

A linha 4 é a única inversão de comportamento. A linha 6 é a fronteira que o dono escolheu
explicitamente quando perguntado (o literal do pedido a deixaria contando o almoço).

**O dia de hoje sem saída continua fora disso**: `FolhaPontoBuilder` já o trata antes
(`$diaHoje && !$temSaida` → mostra horas, não calcula saldo, não marca pendência).

## Efeito medido em produção (2026-08-31, antes de escrever)

Dias úteis com batida que **deixam** de ser incompletos: **75**, de 8 pessoas, entre 10/04 e 17/08 —
todos com apenas `entrada` + `saida`. Hoje valem `0`; passam a ser apurados:

| | dias | minutos | |
|---|---|---|---|
| viram crédito | 10 | **+623** | +10h23m |
| viram débito | 62 | **−11.776** | −196h16m |
| dentro da tolerância / sem meta | 3 | 0 | |
| **líquido** | **75** | **≈ −11.150** | **≈ −186h no banco de horas da equipe** |

O débito é grande porque **56 dos 75 dias têm span de 4h a 8h** — não são "trabalhou sem almoço"
(esses são 14 dias, span ≥ 8h). São dias em que a pessoa bateu `entrada` e depois bateu `saida` no
lugar de `repouso`, e não bateu mais nada. **O dono foi informado desse número e escolheu contar
normal assim mesmo**, com a opção alternativa ("credita mas nunca debita", efeito +10h23m) na mesa.
O caminho de correção é o colaborador pedir *Esquecimento de Registro*, que repõe a batida.

Dias que **continuam** incompletos (não mudam): 18 só `entrada` · 6 `entrada+repouso+retorno` ·
3 `repouso+retorno+saida` · 3 `entrada+repouso` · 1 `saida` · 1 `repouso` · 1 `retorno`.
Pela regra 6, seguem incompletos: 5 dias `entrada+repouso+saida` + 1 `entrada+retorno+saida`
(contá-los creditaria +9h47m de almoço não medido).

⚠️ **Folha reimpressa de mês já emitido sai diferente da assinada** — mesma classe de impacto do
deploy de 05/08 e do de horas pagas. Vale para PDF, XLSX, tela do colaborador e ficha do admin: os
quatro passam por `FolhaPontoBuilder::buildRows`, que é o caminho único.

## Escopo

**Muda:**
- `app/src/Ponto/Service/CalculadoraJornada.php` — `registroIncompleto()` (regra + assinatura, que
  deixa de precisar de `$user`, `$data` e `$jornadaTenant`) e os dois comentários que descrevem a
  regra antiga.
- `app/src/Ponto/Service/FolhaPontoBuilder.php` — chamada.
- Testes: `CalculadoraJornadaTest`, `FolhaPontoBuilderTest`, `FolhaPontoRegressaoFolhasReaisTest`.

**Não muda:**
- O selo amarelo do `_folha_table.html.twig` (texto, cor e tooltip ficam como estão).
- `calcularMinutosTrabalhados()` — já faz o certo nos dois casos (4 batidas → dois spans;
  entrada+saída → span inteiro).
- O tratamento de justificativa: dia incompleto continua não sendo abonado.
- `JornadaResolver::tiposEsperadosNoDia()` fica no lugar (a dica de "próxima batida" da tela do
  colaborador nasce do mesmo `resolverBatidasEsperadasHoje`), mas **deixa de decidir saldo**.

## Como a revisão confere

1. As 6 linhas da tabela acima têm teste unitário direto em `CalculadoraJornadaTest`.
2. `FolhaPontoRegressaoFolhasReaisTest` continua rodando os 61 dias reais de 06 e 07/2026 juntos, com
   os totais dos meses recalculados — cada dia que mudou de valor é justificado no comentário.
3. Prova por reintrodução: desfazer a regra 4 e a regra 6, uma de cada vez, tem de derrubar teste.
4. Invariante que não pode cair: dia **sem batida nenhuma** continua valendo `-carga` (falta).
