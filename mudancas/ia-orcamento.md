---
tipo: novidade
---
## Changelog
- BlueJus IA (ia-anthropic-ligar, PR C, sem migration): o orçamento mensal em US$ de cada escritório passa a valer. `DisponibilidadeDeInteligencia::para()` ganha, depois da cota mensal, o caso `orcamento_atingido` ("Orçamento mensal da BlueJus IA atingido; volta no dia 1º"), que responde 429 com o código `limite_atingido`, como as cotas. Vale nos modos `incluso` e `adicional`; orçamento vazio não trava; o worker (`paraProcessamento`) não confere o orçamento.
- BlueJus IA: no modo `limite_gratis` o orçamento é a parte grátis e NÃO bloqueia; o painel do Master mostra "acima do grátis". Padrão adotado em 24/10/2026, o dono pode rever.
- BlueJus IA: serviço `CustoDaIa` com o custo estimado em centavos de dólar, pelo preço de tabela de `ModeloDeIa` (modelo fora da lista paga o preço do Opus 5.5; região `us` custa 1,1x; arredonda para cima). O gasto do mês soma todas as análises com tokens desde o dia 1º, inclusive as excluídas e as que falharam, numa consulta agrupada por modelo com tenant explícito.
- Painel do Master, aba "Configurações da I.A.": colunas "Análises no mês" e "Gasto no mês (US$)" na tabela de escritórios, com os selos "orçamento atingido" e "acima do grátis" (uma consulta para todos os escritórios).
- `/admin/inteligencia`: bloco "Consumo do mês" com as análises e o limite mensal, o gasto estimado, o orçamento (quando houver) e o modo de cobrança, só do próprio escritório.

## Para o cliente
- O administrador do escritório vê o consumo da BlueJus IA no mês: quantas análises, o gasto estimado e o orçamento.
- Quando o orçamento mensal da BlueJus IA é atingido, a IA para e volta no dia 1º do mês seguinte.
