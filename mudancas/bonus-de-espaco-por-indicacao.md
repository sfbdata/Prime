---
tipo: novidade
---
## Changelog
- Bônus de espaço por indicação: a conta nova que chegou por indicação e confirmou o e-mail ganha 5 GB extras por 30 dias, uma vez só. O bônus soma ao limite do plano (ou ao limite definido pelo Master) e vence sozinho pela data; nada é apagado.
- Página do espaço (Contribua): linha "Inclui 5 GB de bônus até dd/mm/aaaa" enquanto o bônus vale.
- Painel do Master: a conta mostra a origem (indicação) e a validade do bônus.
- Nova tabela `contribua_bonus_de_espaco` (migration `Version20261026151600`, reversível). Contas já confirmadas antes desta versão não recebem o bônus.

## Para o cliente
- Quem cria a conta com o cupom de uma indicação e confirma o e-mail ganha 5 GB extras no primeiro mês.
