---
tipo: novidade
---
## Changelog
- Painel do Master, seção "Recompensas": "Dar baixa (registrar pagamento)" no menu ⋮ de cada indicador. O Master registra um pagamento que fez fora do sistema, com valor (padrão: o saldo), forma (PIX, transferência, desconto na mensalidade ou crédito de espaço), observação e comprovante opcional em PDF, PNG, JPG ou WEBP de até 5 MB. O BlueJus não movimenta dinheiro: ele só registra e confere.
- A baixa só é aceita para indicador liberado, não bloqueado, sem contas em análise, com nome e CPF ou CNPJ cadastrados e valor até o saldo. Dois pedidos ao mesmo tempo nunca pagam acima do saldo, e repetir o mesmo pedido (duplo clique) não paga duas vezes. As colunas "Saldo" e "Já pago" da lista e o KPI "Já pago" passam a refletir os pagamentos.
- Novo item "Dados para pagamento" no menu ⋮: nome, CPF ou CNPJ (conferido pelos dígitos verificadores) e chave PIX de quem recebe. A lista mostra só os 2 últimos dígitos do documento. O sistema guarda o valor bruto e não calcula imposto; o cadastro é o que o contador usa.
- O extrato mostra o tipo "Pagamento", a forma e um clipe para abrir o comprovante (só o Master consegue). O CSV do extrato ganha a coluna "Forma".
- Nova tabela `dados_de_recebimento_de_recompensa` e três colunas nulas em `lancamento_de_recompensa` (forma e comprovante); nenhum dado existente é alterado. Comprovantes ficam em `recompensa-comprovantes` (arquivo da plataforma, fora dos escritórios).

## Para o cliente
- Nada muda para os escritórios. Só o Master registra pagamentos de recompensa e vê os comprovantes.
