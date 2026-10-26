---
tipo: novidade
---
## Changelog
- Painel do Master, seção "Recompensas": novo bloco "Extrato de pagamentos e liberações" com os 60 lançamentos mais recentes (data, indicador, tipo e valor) e o botão "Exportar CSV" com o extrato completo.
- Menu ⋮ de cada indicador ganha "Lançar crédito ou estorno" (valor em reais, negativo para estornar, com observação), "Ver extrato deste indicador" (filtra o extrato, com o botão para mostrar todos) e "Baixar extrato (CSV)".
- O saldo e o "A pagar" passam a somar os créditos e estornos manuais. O saldo nunca fica negativo: estorno maior que o crédito zera o saldo.
- O extrato é um livro: lançamento não se edita nem se apaga, só se corrige com outro de sinal oposto. Cada pedido leva uma chave única, então um duplo clique ou reenvio não lança duas vezes. Tudo entra na auditoria da plataforma.
- O CSV usa ponto e vírgula, abre com acentos no Excel e protege células que começam com =, +, - ou @ contra fórmulas. Não traz dado de quem foi indicado.
- Nova tabela `lancamento_de_recompensa` (dado da plataforma, sem escritório). Ninguém é pago nesta entrega: a baixa de pagamento fica para a próxima.

## Para o cliente
- Nada muda para os escritórios. Só o Master vê, lança e exporta o extrato de recompensas.
