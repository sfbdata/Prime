---
tipo: novidade
---
## Changelog
- Pasta: aba "Cadastro da pasta" na Controladoria. Informa réu, CPF, qualificação, e-mail e endereço (salva mesmo sem e-mail ou endereço; o cadastro fica com pendência até ter CPF e qualificação), contrato, unidade, titular do extrato e data-limite de prescrição. Unidade e titular vêm da Cobrança quando a pasta nasceu de uma judicialização, e podem ser corrigidos à mão.
- Pasta: novos alertas na Controladoria: duas pastas no mesmo contrato ou na mesma unidade, titular do extrato diferente do réu, prescrição próxima ou vencida e cadastro do réu incompleto. Migration `Version20261031120000` (só ADD COLUMN).
