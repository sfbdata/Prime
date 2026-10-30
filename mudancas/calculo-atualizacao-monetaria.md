---
tipo: novidade
---
## Changelog
- Nova aba Cálculo (`/calculo`) no menu lateral: atualização monetária de valores devidos com correção por INPC até 08/2024 e IPCA depois (Lei 14.905/2024), juros de 1% ao mês até 29/08/2024 e taxa legal (Selic menos IPCA) depois, incidência dos juros, meses futuros projetados pela média de 12 meses, geração de parcelas, abatimentos, multa, honorários e art. 523 §1º do CPC, com demonstrativo na tela.
- Cálculo: botão Baixar PDF com o demonstrativo no mesmo formato do exemplo, com código de verificação no rodapé, e botão Atualizar índices (séries do Banco Central). A série Selic mensal (SGS 4390) passa a ser importada junto com INPC e IPCA; o comando `app:importar-indices-monetarios` e o botão usam a mesma rotina.
- Permissão nova `modules.calculo.view` (migration `Version20261030110000`, só INSERT): concedida por padrão aos perfis que já veem Pastas.
